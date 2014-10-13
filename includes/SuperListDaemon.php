<?php
namespace SuperListd;

require_once("Debug.php");
require_once("NetTool.php");
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

class SuperListDaemon 
{
  public $basedir;
  public $config_file = null;
  public $options = [];
  public $base = null;
  public $max_workers = 1; 
  public $workers = []; 
  public $events = []; 
  public $nproc = 1;
  public $argc = 0;
  public $argv = null;

  public function __construct($basedir) 
  {
     $this->basedir=$basedir;
     $this->config_file = $this->basedir . "/config/php-smtpd.ini";
     debug::open("SuperListd",LOG_PID|LOG_PERROR);
  }

  public function main($argc,$argv)
  {

      $this->argc=$argc;
      $this->argv=$argv;

      $args=getopt("",array(
		"tls",
		"stderr",
		"crlf",
		"xforward",
		"xclient",
		"daemon",
		"user::",
		"pidfile::",
		"inetd",
		"config::",
		"listen::",
	     ));

      if (isset($args["config"]))
      {
         $this->config_file = $args["config"]; 
      }
      $this->loadconfig();

      // activate the garbage collector
      gc_enable();

      // get the systeme FQDN hostname
      if (!isset($this->options['hostname'])) $this->options['hostname']=NetTool::getFQDNHostname();

      if (isset($args["daemon"])&&isset($args["listen"]))
      {
        $this->daemonize();
      }

      if ( version_compare( PHP_VERSION, '5.4', '<' ) ) 
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." requires PHP 5.4\n");
      }

      if (!extension_loaded("apcu")&&ini_get("apc.enable_cli")==1)
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need apcu and apc.enable_cli=1 in php.ini extension http://pecl.php.net/package/APCu\n");
      }

      if (isset($args["tls"])&&!extension_loaded("openssl"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need openssl extension with --tls options, http://php.net/manual/en/book.openssl.php\n");
      }

      if (!extension_loaded("pcre"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need pcre extension, http://php.net/manual/en/book.pcre.php\n");
      }

      if (!extension_loaded("mailparse"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need mailparse extension, http://php.net/manual/en/book.mailparse.php\n");
      }

      if (!extension_loaded("posix"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need posix extension, http://php.net/manual/en/book.posix.php\n");
      }

      if (!extension_loaded("pcntl"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need PCNTL extension, http://php.net/manual/en/book.pcntl.php\n");
      }

      if (!extension_loaded("event"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need Event extension, http://php.net/manual/en/book.event.php\n");
      }

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
      }

      if (isset($args["crlf"]))
      {
         $this->options['crlf']=true;
      }

      if (isset($args["pidfile"]))
      {
        $this->options['pidfile']=$args["pidfile"]; 
      }
      if (isset($this->options['pidfile'])) $this->pidfile=$this->options['pidfile'];

      // change the processus name viewed in processus list (ps)
      cli_set_process_title($this->options['daemon_processname']);

      // get nomber of processing unit and use it to define the number of worker
      $this->nproc=NetTool::getNproc();
      debug::printf(LOG_NOTICE,"This machine has %s processing unit\n",$this->nproc);
      if (!isset($this->options['max_workers'])) $this->max_workers=$this->nproc;
      else $this->max_workers=$this->options['max_workers'];

      // prepare sslctx for starttls smtp extension
      $this->options['sslctx'] = new EventSslContext(EventSslContext::SSLv3_SERVER_METHOD, [
	  EventSslContext::OPT_LOCAL_CERT  => $this->options['sslctx']['ssl_server_crt'],
	  EventSslContext::OPT_LOCAL_PK    => $this->options['sslctx']['ssl_server_key'],
	  //EventSslContext::OPT_PASSPHRASE  => $this->options['sslctx']['ssl_passphrase'],
	  EventSslContext::OPT_VERIFY_PEER => $this->options['sslctx']['ssl_verify_peer'],
	  EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->options['sslctx']['ssl_allow_self_signed'],
      ]);

      debug::printf(LOG_NOTICE, "Starting %s Serveur pid:%s at <%s>\n",$this->options['daemon_processname'],posix_getpid(),gmdate('r'));

      // initialize eventbase
      $this->base = new EventBase();
      if (!$this->base) 
      {
	 debug::exit_with_error(-1,"Couldn't open event base\n");
      }

      // reopen syslog with perror if args stderr is set
      debug::open($this->options['daemon_syslogname'],LOG_PID);
      if (isset($args["stderr"])&&!isset($args["daemon"]))
      {
         debug::open($this->options['daemon_syslogname'],LOG_PID|LOG_PERROR);
      }

      // drop root privilege and setgid and setuid
      if (isset($args["user"]))
      {
         $this->options['user']=$args["user"];
      }
      if (isset($this->options['user'])&&(posix_getuid()==0||posix_geteuid()==0))
      {
         $pwent=posix_getpwnam($this->options['user']);
	 if ($pwent!=FALSE)
	 {
            posix_setgid($pwent['gid']);
            posix_setuid($pwent['uid']);
	    debug::printf(LOG_NOTICE, "Changed uid to <%s(%s)>\n",$this->options['user'],$pwent['uid']);
	 }
	 else
	 {
	    debug::exit_with_error(6,$this->options['daemon_processname']." user %s not exit\n",$this->options['user']);
	 }
      }

      // create queue directory structure
      $mailqueue=new MailQueue($this->options);
      $mailqueue->makequeue("inbound");
      $mailqueue->makequeue("deferd");
      $mailqueue->makequeue("active");
      $mailqueue->makequeue("remote");

      // start in daemon mode listen or inetd
      if (isset($args["listen"]))
      {
	$this->options['listen']=$args["listen"];

	// to manage kill and propagate to child
	$this->event_add("SIGTERM", SIGTERM, Event::SIGNAL  | Event::PERSIST, 'SignalEventTERM');
	//pcntl_signal(SIGTERM,array($this,"SignalEventTERM"));
	// to manage kill and propagate to child
	$this->event_add("SIGINT", SIGINT, Event::SIGNAL  | Event::PERSIST, 'SignalEventINT');
	//pcntl_signal(SIGINT,array($this,"SignalEventINT"));
	// to manager child return code and death
	$this->event_add("SIGCHLD", SIGCHLD, Event::SIGNAL  | Event::PERSIST, 'SignalEventHLD');
	//pcntl_signal(SIGCHLD,array($this,"SignalEventHLD"));
	// to monitor child each seconds
	$this->event_add("HeartBeat", -1,    Event::TIMEOUT | Event::PERSIST, 'HeartBeatEvent',1);

	// initialise event_base specificaly to listener to avoid reentrant call to dispatch from HeartBeat event
	// after forking a worker
	$this->listenbase = new EventBase();
	if (!$this->listenbase) 
	{
	    debug::exit_with_error(-1,"Couldn't open Listen event base\n");
	}
	$this->options['listen']=$this->options["listen"];
	$this->listener= new SMTPListener($this->listenbase,$this->options["listen"],$this->options);

        $this->supervise_workers();
        $this->create_pidfile();
	$this->base->dispatch();
        $this->unlink_pidfile();
      }
      // start in inetd mode
      else if (isset($args["inetd"]))
      {
	$address=null;
	$address_string=stream_socket_get_name(STDIN,true);
	if($address_string!==false)
	{
	  if (preg_match("/([0-9]+[.][0-9]+[.][0-9]+[.][0-9]+)[:]([0-9]+)/",$address_string,$arr)==1)
	  {
	    $parsed_address=array(0=>$arr[1],1=>$arr[2]);
	    debug::printf(LOG_NOTICE,"Client connected with inetd from this parsed address: %s:%s\n",$parsed_address[0],$parsed_address[1]);
	    $address=$parsed_address;
	  }
	  else
	  {
	    debug::printf(LOG_NOTICE,"Client connected with inetd from this address: %s\n",$address_string);
	  }
	}
	else
	{
	  fprintf(STDERR,"Warning STDIN are not a socket\n");
	  fprintf(STDERR,"Forcing CRLF mode...\n");
	  $address="stdin";
	  $this->options['crlf']=true;
	}
        $options=array_merge($this->options,array('id'=>1));
	$smtp=new SMTPProtocol($this->base,STDIN,$address,$options);
	$smtp->listen();
      }
      else
      {
        fprintf( STDERR, "Syntax Error: %s [--config=</path/to/config file.ini>] [--inetd] [--listen=<host:port>] [--stderr] [--tls] [--crlf] [--xforward] [--xclient] [--daemon]\n",basename($argv[0]));
        exit(1);
      }
  }


  private function loadconfig()
  {
     $basedir=dirname($this->argv[0]);
     if (preg_match("/^[.\/]/",$this->config_file)!=1)
      $this->config_file=$basedir."/".$this->config_file;

     if (!file_exists($this->config_file)) 
     {
       debug::exit_with_error(1,"Error config file <%s> not found!\n",$this->config_file);
     }
     $this->options=parse_ini_file($this->config_file,true);
     foreach($this->options as $key => $value)
     {
       if (is_scalar($value)&&$value!="") 
       {
	  switch ($key)
	  {
	     case "maxRead":
	     case "maxcommandlinesize":
	     case "maxmessagesize":
	     case "max_workers":
	     case "read_timeout":
	     case "write_timeout":
	       $toeval="\$evaled= ".$value.";";
	       //fprintf(STDERR,"toeval:%s\n",$toeval);
	       ob_start();
	       $check=eval($toeval);
	       $output = ob_get_contents();
	       ob_end_clean();
	       if ($check!==FALSE)
	       {
		 $this->options[$key]=$evaled;
		 //fprintf(STDERR,"val:%s=>%s\n",$value,$evaled);
	       }
	       break;
	     default:
	       //fprintf(STDERR,"val:%s\n",$value);
	       break;
	  }
       }
     }
     if (!isset($this->options['daemon_syslogname'])) $this->options['daemon_syslogname']="SuperListd";
     if (!isset($this->options['daemon_processname'])) $this->options['daemon_processname']="SuperList Daemon";
     if (!isset($this->options['basedir'])) $this->options['basedir']=$this->basedir;
     else $this->basedir=$this->options['basedir'];

  }

  protected function event_add( $name, $fd, $flags, $callback, $timeout = -1 ) 
  {
     $event = new Event($this->base, $fd, $flags, array($this, $callback), $this);
     if ($timeout==-1) $event->add();
     else $event->add($timeout);
     $this->events[ $name ] = $event;
  }

  protected function supervise_workers() 
  {
     // Spawn workers to fill empty slots
     while ( count( $this->workers ) < $this->max_workers ) 
     {
	if ( $this->create_worker() ) break;
     }
  }

  private function create_worker() 
  {
      $pid = pcntl_fork();
      if ( $pid === -1 )
	      debug::exit_with_error(-1,"Fork failure\n" );
      if ( $pid === 0 ) {
	      $this->worker_pid = posix_getpid();
	      cli_set_process_title($this->options['daemon_processname']." - Worker");
	      debug::printf(LOG_NOTICE, "Starting SMTP Worker pid:%s at <%s>\n",$this->worker_pid,gmdate('r'));

	      // Re-seed the child's PRNGs
	      srand();
	      mt_srand();

	      // The child process ignores SIGINT (small race here)
	      pcntl_sigprocmask( SIG_BLOCK, array(SIGINT) );

	      // purge global event base
	      // and lets go of the parent's libevent base
	      if (!$this->base->reInit())
	      {
                debug::exit_with_error(-1,"Error EventBase reInit!\n");
	      }
	      // and breaks out of the service event loop
	      if (!$this->base->stop())
	      {
                debug::exit_with_error(-1,"Error EventBase reInit!\n");
	      }
	      // and dismantles the whole event structure
	      foreach ( $this->events as $i => $event ) 
	      {
		      $event->del();
		      $event->free();
		      unset($this->events[$i]);
	      }
	      $this->base=null;
	      unset($this->base);
	      if(gc_enabled()) gc_collect_cycles();

	      // reinit the parent's listen event base
	      if (!$this->listenbase->reInit())
	      {
                debug::exit_with_error(-1,"Error Listen EventBase reInit!\n");
	      }
	      // to manage kill and propagate to child
	      $this->listener->event_add("SIGTERM", SIGTERM, Event::SIGNAL  | Event::PERSIST, 'SignalEventTERM');
	      $this->listener->listen();
	      return true;
      }
      $start_time = microtime( true );
      $this->workers[$pid]['start_time'] = $start_time;
      $this->workers[$pid]['pid'] = $pid;
      return false;
  }

  private function remove_worker( $pid ) 
  {
     if (isset($this->workers[$pid])) 
     {
       $stop_time = microtime( true );
       debug::printf(LOG_NOTICE, "Child pid:%s remove from pool after %0.3fs of execution\n",$pid,$stop_time-$this->workers[$pid]['start_time']);
       unset( $this->workers[$pid]);
     }
  }

  public function HeartBeatEvent($ctx)
  {
     $this->supervise_workers();
  }

  public function SignalEventHLD($ctx)
  {
    while ( true ) 
    {
       $pid = pcntl_wait( $status, WNOHANG );
       if ( $pid < 1 )
	       break;
       debug::printf(LOG_NOTICE, "Child pid:%s as exited with code:%s\n",$pid,$status);
       $this->remove_worker($pid);
    }
  }

  public function SignalEventINT($ctx) 
  {
    debug::printf(LOG_NOTICE, "Prefork caught SIGTERM.\n");
    $this->shutdown($ctx,SIGTERM);
  }

  public function SignalEventTERM($ctx) 
  {
    debug::printf(LOG_NOTICE, "Prefork caught SIGTERM.\n");
    $this->shutdown($ctx,SIGTERM);
  }

  private function shutdown($ctx,$signal) 
  {
          debug::printf(LOG_NOTICE, "Start shutdown...\n");
	  // Delay shutdown until all accepted requests are completed
	  $this->base->stop();
	  foreach ( $this->workers as $pid => $value )
	  {
	    posix_kill( $pid, $signal );
	    $this->remove_worker($pid);
	  }
	  $this->unlink_pidfile();
          debug::printf(LOG_NOTICE, "Prefork service shutdown complete...\n");
	  exit(0);
  }

  private function daemonize()
  {
     if ( defined( 'STDIN' ) ) {
	     fclose( STDIN );
	     fclose( STDOUT );
	     fclose( STDERR );
     }
     // Detach from calling process
     $pid = pcntl_fork();
     if ( $pid < 0 )
	     exit(1);
     if ( $pid > 0 )
	     exit(0);
     @umask(0);
     @posix_setsid();
  }

  private function create_pidfile() 
  {
     if ( ! isset( $this->pidfile ) )
	     return;
     $pid = posix_getpid();
     file_put_contents( $this->pidfile, $pid . PHP_EOL );
  }

  private function unlink_pidfile() 
  {
     if ( ! isset( $this->pidfile ) )
	     return;
     unlink( $this->pidfile );
  }
}

