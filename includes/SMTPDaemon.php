<?php
namespace SuperListd;

require_once("Debug.php");
require_once("NetTool.php");
require_once("ProcessDaemon.php");
require_once("SMTPListener.php");
require_once("SMTPProtocol.php");
require_once("MailQueue.php");

use EventSslContext;
use EventBase;
use Event;
 /*
 * Author: Mathieu CARBONNEAUX 
 *
 * Usage:
 * 1) Prepare cert.pem certificate and privkey.pem private key files.
 * 2) Launch the server script
 * 3) Open TLS connection, e.g.:
 *      $ openssl s_client -connect localhost:25 -starttls smtp -crlf
 * 4) Start testing the commands listed in `cmd` method below.
 */

class SMTPDaemon extends ProcessDaemon
{
  public $mailqueue = null;
  public $nb_inbound = 0;

  public function __construct($basedir) 
  {
     $this->opts=array(
		"tls",
		"crlf",
		"xforward",
		"xclient",
     );
     parent::__construct($basedir,"php-smtpd.ini","SuperListd");

     $this->addHook("Run",array($this,"HookCreateQueue"));
     $this->addHook("RunListen",array($this,"HookRunListen"));
     $this->addHook("RunInetd",array($this,"HookRunInetd"));
     $this->addHook("CheckArgs",array($this,"HookCheckArgs"));
     $this->addHook("Syntax",array($this,"HookSyntax"));
     $this->addHook("CheckPHP",array($this,"HookCheckPHP"));
     $this->addHook("ParamToEval",array($this,"HookParamToEval"));
  }

  public function HookCreateQueue($ctx)
  {
    debug::printf(LOG_DEBUG,"CreateQueue...\n");
    /////////////////////////////////////////
    // create queue directory structure
    /////////////////////////////////////////
    $this->mailqueue=new MailQueue($this->options);
    $this->mailqueue->makequeue("inbound");
    //$this->mailqueue->makequeue("deferd");
    //$this->mailqueue->makequeue("active");
    //$this->mailqueue->makequeue("remote");

  }

  public function HookRunListen($ctx)
  {
      $this->SuperviseQueue(null, true, $this);
      $this->listener= new SMTPListener($this->listenbase,$this->options["listen"],$this->options);
      $this->ppm->addHook("HeartBeat",array($this,"SuperviseQueue"));
  }

  public function HookRunInetd($ctx,$address)
  {
      // initialize eventbase
      $this->base = new EventBase();
      if (!$this->base) 
      {
	 debug::exit_with_error(65,"Couldn't open event base\n");
      }

      $merged_options=array_merge($this->options,array('id'=>1));
      $this->SuperviseQueue(null, true, $this);
      $this->smtp=new SMTPProtocol($this->base,STDIN,$address,$merged_options);
      // to monitor child each seconds
      $this->event_add($this->base,"HeartBeat", -1,    Event::TIMEOUT | Event::PERSIST, 'SuperviseQueue',1);
  }
  
  public function HookCheckArgs($ctx,$args)
  {
      if (isset($args["xforward"]))
      {
         $this->options['xforward']=true;
      }

      if (isset($args["xclient"]))
      {
         $this->options['xclient']=true;
      }

      if (isset($args["tls"]))
      {
         $this->options['tls']=true;
	 // prepare sslctx for starttls smtp extension
	 $this->options['sslctx'] = new EventSslContext(EventSslContext::SSLv3_SERVER_METHOD, array(
	     EventSslContext::OPT_LOCAL_CERT  => $this->options['sslctx']['ssl_server_crt'],
	     EventSslContext::OPT_LOCAL_PK    => $this->options['sslctx']['ssl_server_key'],
	     //EventSslContext::OPT_PASSPHRASE  => $this->options['sslctx']['ssl_passphrase'],
	     EventSslContext::OPT_VERIFY_PEER => $this->options['sslctx']['ssl_verify_peer'],
	     EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->options['sslctx']['ssl_allow_self_signed'],
	 ));
      }

      if (isset($args["crlf"]))
      {
         $this->options['crlf']=true;
      }
  }

  public function HookSyntax($ctx,$args)
  {
      $syntaxerror="Syntax Error: %s [--config=</path/to/config file.ini>] [--pidfile=</path/to/pidfile.pid>] [--inetd] [--listen=<host:port>] [--stderr] [--daemon] [--tls] [--crlf] [--xforward] [--xclient]\n";
      $address_string=stream_socket_get_name(STDIN,true);
      if ($address_string!==false) 
      {
	debug::printf(LOG_ERR,$syntaxerror,basename($this->argv[0]));
      }
      else 
      {
	fprintf( STDERR, $syntaxerror,basename($this->argv[0]));
      }
      exit(1);
  }

  public function HookCheckPHP($ctx)
  {
      if (!extension_loaded("mailparse"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need mailparse extension, http://php.net/manual/en/book.mailparse.php\n");
      }

      if (isset($this->args["tls"])&&!extension_loaded("openssl"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need openssl extension with --tls options, http://php.net/manual/en/book.openssl.php\n");
      }
  }

  public function HookParamToEval($ctx,$param)
  {
     $paramstoeval = array( "maxRead", 
                            "maxcommandlinesize", 
                            "maxmessagesize", 
			    "max_workers", 
			    "queue_max_msg", 
			    "queue_min_msg", 
			    "ipc_shm_size", 
			    "read_timeout", 
			    "write_timeout");
     //debug::printf(LOG_DEBUG,"CheckParam: %s\n",$param);
     if (in_array($param,$paramstoeval)) return true;
     return false;
  }

  public function SuperviseQueue($fd, $what, $ctx) 
  {
     // monitor inbound queue fullness every 15s
     if (time()%15==0||$what===true)
     {
       if ($this->mailqueue==null)
       {
	 debug::exit_with_error(15,'MailQueue not initialized!');
       }
       $count=$this->mailqueue->QueueCount("inbound");
       if ($count!=$this->nb_inbound) debug::printf(LOG_INFO,"QueueCount queue inbound = %s\n",$count);
       $this->nb_inbound=$count;

       $ipc_sem=$this->ipc_sem;
       $ipc_shm=$this->ipc_shm;
       if (!is_resource($ipc_sem)&&!is_resource($ipc_shm))
       {
	 debug::exit_with_error(15,'IPC not initialized!');
       }
       if (sem_acquire($ipc_sem))
       {
	  if (@shm_get_var($ipc_shm,1)!==true)
	  {
	    if ($this->nb_inbound > $this->options['queue_max_msg']) 
	    {
	      debug::printf(LOG_ERR, "MailQueue <inbound> full!\n");
	      shm_put_var($ipc_shm,1,true);
	    }
	  }

	  if (@shm_get_var($ipc_shm,1)===true)
	  {
	    if ($this->nb_inbound < $this->options['queue_min_msg']) 
	    {
	      debug::printf(LOG_ERR, "MailQueue <inbound> is now below %s!!\n",$this->options['queue_min_msg']);
	      shm_put_var($ipc_shm,1,false);
	    }
	  }

	  if(!sem_release($ipc_sem)) 
	    debug::exit_with_error(15,'Unable to release the semaphore');
       }
       else
       {
	    debug::exit_with_error(15,'Unable to aquire the semaphore');
       }
       /*
       if ($this->nb_inbound>$this->options['queue_max_msg']) apc_store("inbound_full",true);
       if (apc_exists("inbound_full")&&$this->nb_inbound<$this->options['queue_min_msg']) apc_delete("inbound_full");
       */
       /*
       $this->nb_deferd=$this->mailqueue->QueueCount("deferd");
       $this->nb_active=$this->mailqueue->QueueCount("active");
       $this->nb_remote=$this->mailqueue->QueueCount("remote");
       */
     }
  }

}

