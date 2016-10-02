<?php
namespace phpSMTPd;

include_once("Debug.php");
include_once("ProcessPoolManager.php");
require_once("ExitStatus.php");
require_once("NetTool.php");


use EventBase;

 /*
 * Author: Mathieu CARBONNEAUX 
 */

abstract class ProcessDaemon 
{
  use EventTools;
  use DropRootPrivilege;
  use SetProcessTitle;
  use AttachIPCSHM;
  use HookMgmt;

  public $basedir = null;
  public $config_file = null;
  public $options = array();
  public $max_workers = null; 
  public $argc = 0;
  public $argv = array();
  public $args = array();
  public $maxworker = null;
  public $heartbeat = 1;
  protected $listener = null;
  protected $smtp = null;
  protected $ppm = null;

  // optional opts to be setted in constructor
  protected $opts = array();

  public function __construct($basedir,$defaultconfig,$defaultdaemonname) 
  {
     Debug::$loglevel=LOG_WARNING;
     $this->basedir=$basedir;
     $this->config_file=$this->basedir . "/config/".$defaultconfig;
     debug::open($defaultdaemonname,LOG_PID|LOG_PERROR);
  }

  public function main($argc,$argv)
  {
    $this->argc=$argc;
    $this->argv=$argv;
    $default_opts=  array(
		"stderr",
		"user::",
		"pidfile::",
		"config::",
		"daemon",
		"inetd",
		"listen::",
	    );

    $args=getopt("",array_merge($this->opts,$default_opts));
    $this->args=$args;

    //////////////////////////
    // initialize config
    //////////////////////////
    if (isset($args["config"])) $this->config_file = $args["config"]; 
    $this->loadconfig();
    $this->callHook("LoadConfig",array($this));

    //////////////////////////
    // check php configuration prerequisite
    //////////////////////////
    $this->checkphp();
    $this->callHook("CheckPHP",array($this));

    //////////////////////////
    // check command args
    //////////////////////////
    $this->checkargs($args);
    $this->callHook("CheckArgs",array($this,$args));

    // get the systeme FQDN hostname
    if (!isset($this->options['hostname'])) $this->options['hostname']=NetTool::getFQDNHostname();
    // get the options pid file 
    if (isset($this->options['pidfile'])) $this->pidfile=$this->options['pidfile'];
    // get number of processing unit and use it to define the number of worker
    if (isset($this->options['max_workers'])) $this->max_workers=$this->options['max_workers'];

    //////////////////////////
    // activate the garbage collector
    //////////////////////////
    gc_enable();

    //////////////////////////
    // set the loglevel accordingly to configuration file
    // and reopen syslog with perror if args stderr is set
    //////////////////////////
    if (isset($this->options["log_level"]))
    {
       ob_start();
       $loglevel=@eval("return ".$this->options["log_level"].";");
       $output = ob_get_contents();
       ob_end_clean();
       if ($loglevel<0||$loglevel>LOG_DEBUG||!is_int($loglevel))
       {
	 debug::printf(LOG_ERR, "Loglevel <%s> not known!",$this->options["log_level"]);
	 $this->syntax($args);
       }
       Debug::$loglevel=$loglevel;
    }
    if (isset($this->options["stderr"]))
       debug::open($this->options['daemon_syslogname'],LOG_PID|LOG_PERROR);
    else
       debug::open($this->options['daemon_syslogname'],LOG_PID);

    //////////////////////////
    // start banner
    //////////////////////////
    debug::printf(LOG_NOTICE, "Starting %s Serveur pid:%s listen:%s at <%s>\n",$this->options['daemon_processname'],posix_getpid(),$this->options['listen'],gmdate('r'));
    debug::printf(LOG_INFO, "Using PHP v%s\n",PHP_VERSION);
    debug::printf(LOG_INFO, " With PECL-Event v%s\n",phpversion('event'));
    debug::printf(LOG_INFO, " With %s\n",OPENSSL_VERSION_TEXT);
    debug::printf(LOG_INFO, " With extensions : %s\n",implode(',',get_loaded_extensions()));
    $this->callHook("StartMsg",array($this));

    //////////////////////////
    // fork and daemonize
    //////////////////////////
    if (isset($this->options["daemonize"])) $this->daemonize();
    $this->callHook("Daemonize",array($this));

    //////////////////////////
    // run daemon
    //////////////////////////
    $this->run();

    $this->unlink_pidfile();
    $this->callHook("Exit",array($this));
    exit(0);
  }

  protected function run()
  {
    debug::printf(LOG_NOTICE,"Try to run the daemon\n");
    $this->callHook("Run",array($this));
    // start in listen mode
    if (isset($this->options["daemonmode"])&&$this->options["daemonmode"]=="listen")
    {
      $this->run_listen();
      return true;
    }

    // start in inetd mode
    if (isset($this->options["daemonmode"])&&$this->options["daemonmode"]=="inetd")
    {
      $this->run_inetd();
      return true;
    }

    return false;
  }

  //
  // run in listen mode, use generaly EventListener and EventProcessPoolManager
  //
  protected function run_listen()
  {
      debug::printf(LOG_NOTICE,"Run the daemon in listen mode\n");

      // initialise event_base specificaly to listener to avoid reentrant call to dispatch from HeartBeat event
      // after forking a worker
      $this->listenbase = new EventBase();
      if (!$this->listenbase) 
      {
	  debug::exit_with_error(69,"Couldn't open Listen event base\n");
      }

      $worker=new EventProcessWorker($this->options['daemon_processname']."-Worker",-1,$this->listenbase);
      $this->ppm = new EventProcessPoolManager($worker,$this->options['daemon_processname'],$this->maxworker,$this->options['user']);
      $this->ppm->heartbeat=$this->heartbeat;
      $this->initipc();
      $this->callHook("RunListen",array($this));
      debug::printf(LOG_NOTICE,"Go in dispatch\n");
      $this->ppm->dispatch();
      $this->RemoveIPCSHM();
  }

  //
  // run in inetd mode, use generaly EventBufferEvent on stdin file descriptor
  //
  protected function run_inetd()
  {
      debug::printf(LOG_NOTICE,"Run the daemon in inetd mode\n");
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

      // set the processus title
      $this->setprocesstitle($this->options['daemon_processname']."-inetd client from ".$address);
      // initialise ipc
      $this->initipc();
      // drop root privilege and setgid and setuid
      $this->droprootandsetuid($this->options['user']);
      // call RunInet Hook!
      $this->callHook("RunInetd",array($this,$address));
      debug::printf(LOG_NOTICE,"Go in dispatch\n");
      $this->smtp->listen();
  }
  
  //
  // init ipc
  //
  protected function initipc()
  {
      $this->callHook("InitIPC",array($this));
      $returnipc=$this->InitIPCSHM($this->basedir,$this->options['ipc_sem'],$this->options['ipc_shm'],$this->options['ipc_shm_size']);
      if ($returnipc!==false)
      {
	$this->options["ipc_shm"]=$this->ipc_shm;
	$this->options["ipc_sem"]=$this->ipc_sem;
      }
      return $returnipc;
  }
  //
  // check command arguments 
  //
  protected function checkargs($args)
  {
      if (!isset($this->options['ipc_sem'])&&!isset($this->options['ipc_shm'])&&!isset($this->options['ipc_shm_size']))
      {
	 $this->options['ipc_sem']="scoreboard_sem";
	 $this->options['ipc_shm']="scoreboard";
	 $this->options['ipc_shm_size']=10*1024*1024; // 10mo
      }

      if (isset($args["listen"]))
      {
        $this->options["daemonmode"]="listen";
	if ($args["listen"]!="") $this->options['listen']=$this->args["listen"];
	if (!isset($this->options['listen']))
	{
	  debug::printf(LOG_ERR, "you must specify listen address in command line or in config file!");
	  $this->syntax($args);
	}
        if (isset($args["daemon"])) $this->options["daemonize"]=$args["daemon"];
      }

      if (isset($args["inetd"]))
      {
        $this->options["daemonmode"]="inetd";
      }

      if (!isset($args["listen"])&&!isset($args["inetd"]))
      {
        debug::printf(LOG_ERR, "you must specify inetd or listen mode!");
        $this->syntax($args);
      }

      if (isset($args["listen"])&&isset($args["inetd"]))
      {
        debug::printf(LOG_ERR, "you cannot use inetd and listen mode at the same time!");
        $this->syntax($args);
      }

      if (isset($args["daemon"])&&isset($args["inetd"]))
      {
        debug::printf(LOG_ERR, "you cannot use inetd listen mode with daemon!");
        $this->syntax($args);
      }

      if (isset($args["user"]))
      {
         $this->options['user']=$args["user"];
      }
      if (!isset($this->options['user']))
      {
         debug::printf(LOG_ERR, "user not set in config, default to nobody");
         $this->options['user']='nobody';
      }

      if (isset($args["stderr"])&&isset($args["daemon"]))
      {
        debug::printf(LOG_ERR, "you cannot use stderr and daemon mode!");
        $this->syntax($args);
      }
      if (isset($args["stderr"])&&!isset($args["daemon"]))
      {
         $this->options["stderr"]=true;
      }

      if (isset($args["pidfile"]))
      {
        $this->options['pidfile']=$args["pidfile"]; 
      }

      if (isset($this->options["heartbeat"]))
      {
         $this->heartbeat=$this->options["heartbeat"];
      }
      if (isset($this->options["maxworker"]))
      {
         $this->maxworker=$this->options["maxworker"];
      }
  }

  protected function syntax($args)
  {
      $this->callHook("Syntax",array($this,$args));
      $syntaxerror="Syntax Error: %s [--config=</path/to/config file.ini>] [--pidfile=</path/to/pidfile.pid>] [--inetd] [--listen=<host:port>] [--stderr] [--daemon]\n";
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


  protected function checkphp()
  {
      if ( version_compare( PHP_VERSION, '5.4', '<' ) ) 
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." requires PHP 5.5\n");
      }

      /*
      if (!extension_loaded("apcu")&&ini_get("apc.enable_cli")==1)
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need apcu and apc.enable_cli=1 in php.ini extension http://pecl.php.net/package/APCu\n");
      }
      */

      if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'||strtoupper(substr(PHP_OS, 0, 6))==='CYGWIN') 
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." don't work on windows or in CYGWIN!\n");
      }

      if (!function_exists('cli_set_process_title'))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need cli_set_process_title function, http://php.net/manual/en/function.cli-set-process-title.php\n");
      }

      if (!extension_loaded("pcre"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need pcre extension, http://php.net/manual/en/book.pcre.php\n");
      }

      if (!extension_loaded("posix"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need posix extension, http://php.net/manual/en/book.posix.php\n");
      }

      if (!extension_loaded("pcntl"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need PCNTL extension, http://php.net/manual/en/book.pcntl.php\n");
      }

      if (!extension_loaded("sysvsem"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need sysvsem extension, http://php.net/manual/en/book.sem.php\n");
      }

      if (!extension_loaded("sysvshm"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need sysvshm extension, http://php.net/manual/en/book.sem.php\n");
      }

      if (!extension_loaded("event")||version_compare(phpversion('event'),"1.11.0","<"))
      {
	  debug::exit_with_error(6,$this->options['daemon_processname']." need Event >=1.11.0 extension, get last git version at https://bitbucket.org/osmanov/pecl-event/overview\n");
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

     debug::printf(LOG_NOTICE, "Load Configuration from %s\n",$this->config_file);
     $this->options=parse_ini_file($this->config_file,true);
     if ($this->options===false)
     {
       debug::exit_with_error(1,"Error in loading config file <%s>\n",$this->config_file);
     }

     // check for eval some config params
     foreach($this->options as $key => $value)
     {
       if (is_scalar($value)&&$value!="") 
       {
          if ($this->configparamtoeval($key))
	  {
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
	  }
       }
     }

     if (!isset($this->options['daemon_syslogname'])) $this->options['daemon_syslogname']="SuperListd";
     if (!isset($this->options['daemon_processname'])) $this->options['daemon_processname']="SuperList Daemon";
     if (!isset($this->options['basedir'])) $this->options['basedir']=$this->basedir;
     else $this->basedir=$this->options['basedir'];
     //print_r($this->options);
  }

  protected function configparamtoeval($param)
  {
     $paramstoeval = array( "max_workers", 
			    "ipc_shm_size", 
			    );
     if (in_array($param,$paramstoeval)) return true;
     if ($this->callHook("ParamToEval",array($this,$param))) return true;
     return false;
  }

  private function daemonize()
  {
    debug::printf(LOG_NOTICE, "Daemonize...\n");
    if ( defined( 'STDIN' ) ) {
	    fclose( STDIN );
	    fclose( STDOUT );
	    fclose( STDERR );
    }
    // Detach from calling process
    $pid = pcntl_fork();
    if ( $pid < 0 )
	 debug::exit_with_error(200,"Fork impossible\n");
    if ( $pid > 0 )
	    exit(0);
    @umask(0);
    @posix_setsid();
    $this->create_pidfile();
  }

  private function create_pidfile() 
  {
    if ( ! isset( $this->pidfile ) )
	    return;
    $pid = posix_getpid();
    file_put_contents( $this->pidfile, $pid . PHP_EOL );
    $this->callHook("createPidFile",array($this,$pid));
  }

  private function unlink_pidfile() 
  {
    if ( ! isset( $this->pidfile ) )
	    return;
    if (file_exists($this->pidfile)) unlink( $this->pidfile );
  }
}
