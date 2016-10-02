<?php
 /*
 * Author: Mathieu CARBONNEAUX 
 */
namespace phpSMTPd;

require_once("Debug.php");
require_once("ExitStatus.php");
require_once("NetTool.php");

use EventSslContext;
use EventBase;
use Event;
use Iterator;
use ArrayAccess;

trait EventTools
{
  public function event_add($base, $name, $fd, $flags, $callback, $timeout = -1 ) 
  {
     $event = new Event($base, $fd, $flags, array($this, $callback), $this);
     // to catch notice :
     // PHP Notice:  Event::add(): Added a signal to event base 0x1dadf30 with signals already added to event_base 0x1da4ec0.  Only one can have signals at a time with the epoll backend. 
     // must be corrected in adding event_base free method
     //ob_start(); 
     if ($timeout==-1) $event->add();
     else $event->add($timeout);
     //$output = ob_get_contents();
     //ob_end_clean();
     $this->events[ $name ] = $event;
  }
}

trait DropRootPrivilege
{
  //
  // drop root right and set the user id and group id
  //
  public function droprootandsetuid($user=null)
  {
    if (posix_getuid()==0||posix_geteuid()==0)
    {
      if ($user!=null)
      {
	 $pwent=posix_getpwnam($user);
	 if ($pwent!=FALSE)
	 {
	    posix_setgid($pwent['gid']);
	    posix_setuid($pwent['uid']);
	    debug::printf(LOG_NOTICE, "Droped root privilege and changed uid to %s <uid:%s/gid:%s>\n",$user,$pwent['uid'],$pwent['gid']);
	 }
	 else
	 {
	    debug::exit_with_error(6,"User %s not exist in /etc/passwd\n",$user);
	 }
      }
    }
  }

}

trait SetProcessTitle
{
  protected function setprocesstitle($processname,$id=-1)
  {
    if ($id>=0)
      cli_set_process_title($processname." #".$id);
    else
      cli_set_process_title($processname);
  }
}


trait AttachIPCSHM
{
  public $ipc_sem = false;
  public $ipc_shm = false;
  public $ipc_sem_name = null;
  public $ipc_shm_name = null;
  public $ipc_key_sem = 0;
  public $ipc_key_shm = 0;

  //
  // attach ipc memory segment and semaphore
  //
  private function InitIPCSHM($basedir,$ipc_sem,$ipc_shm,$ipc_shm_size)
  {
    if ($ipc_sem!=""&&$ipc_shm!=""&&$ipc_shm_size>0)
    {
      $this->ipc_sem_name=$ipc_sem;
      $this->ipc_shm_name=$ipc_shm;

      $this->ipc_key_sem="0x".hash("crc32b",$basedir."/ipc/".$ipc_sem)+0;
      $this->ipc_key_shm="0x".hash("crc32b",$basedir."/ipc/".$ipc_shm)+0;
      debug::printf(LOG_NOTICE, "Init shm %s at 0x%x\n",$basedir."/ipc/".$ipc_shm,$this->ipc_key_shm);
      debug::printf(LOG_NOTICE, "Init sem %s at 0x%x\n",$basedir."/ipc/".$ipc_sem,$this->ipc_key_sem);

      // Attache la ressource SHM resource, notez le cast après
      $this->ipc_shm = shm_attach($this->ipc_key_shm, $ipc_shm_size);
      if($this->ipc_shm === false) {
	  debug::exit_with_error(66,'Unable to create the shared memory segment');
      }

      $this->ipc_sem = sem_get($this->ipc_key_sem);
      if($this->ipc_sem === false) {
	  debug::exit_with_error(67,'Unable to create the shared memory segment');
      }
      return array("ipc_shm"=>$this->ipc_shm,
                   "ipc_sem"=>$this->ipc_sem);
    }
    else
    {
       debug::exit_with_error(1,"ipc_sem, ipc_shm and ipc_shm_size options are not set, abort starting daemon!\n");
    }
    return false;
  }

  private function RemoveIPCSHM()
  {
     if ($this->ipc_sem!==false)
     {
        @shm_remove($this->ipc_shm);
        @shm_detach($this->ipc_shm);
     }
     if ($this->ipc_sem!==false)
     {
        @sem_remove($this->ipc_sem);
        @sem_release($this->ipc_sem);
     }
  }
}

trait HookMgmt
{
  public $hooks = array(); 

  public function addHook($name,$function)
  {
     if (is_callable($function,false,$callmethod))
     {
       $this->hooks[$name]=$function;
       debug::printf(LOG_NOTICE,"hook <%s> set to <%s>\n",$name,$callmethod);
     }
     else
     {
       $backtrace=debug_backtrace();
       $trace=$backtrace[0];
       debug::exit_with_error(ExitStatus::HookCallable,"The hook <%s> function are not callable! file: %s:%s\n",$name,$trace['file'],$trace['line']);
     }
  }
   
  public function callHook($name,$ctx)
  {
     if (isset($this->hooks[$name]))
     {
       $backtrace=debug_backtrace();
       $trace=$backtrace[0];
       $file=strtr($trace['file'],array(Debug::$basedir=>""));
       debug::printf(LOG_DEBUG,"Calling %s Hook in %s:%s",$name,$file,$trace['line']);
       return call_user_func_array($this->hooks[$name],$ctx);
     }
     return false;
  }
}


////////////////////////////
// EventProcessWorker
////////////////////////////

class EventProcessWorker
{
  public $processname = null;
  public $id = -1;

  public $pid = -1;
  public $starttime = -1;
  public $alive = false;

  public $workerbase = null;
  public $mpm_parent = null;

  public $error = 0;
  public $lasterrortime = null;
  public $firsterrortime = null;

  use EventTools;
  use SetProcessTitle;

  public function __construct($processname,$processid=-1,$shareworkerbase=null) 
  {
     $this->processname=$processname;
     $this->id=$processid;
     $this->workerbase=$shareworkerbase;
  }

  static public function getInstance($processname,$processid=-1,$shareworkerbase=null)
  {
    $classname=get_called_class();
    return new $classname($processname,$processid,$shareworkerbase);
  }

  public function start($pid,$mpm_parent)
  {
    // reinit the parent's herited worker event base
    if ($this->workerbase!=null)
    {
      //debug::printf(LOG_DEBUG, "EventProcessWorker reInit herited EventBase...\n");
      if (!$this->workerbase->reInit())
      {
	debug::exit_with_error(ExitStatus::EventBaseReInit,"Error Listen EventBase reInit!\n");
      }
    }
    // or create new one for the child
    else
    {
      //debug::printf(LOG_DEBUG, "EventProcessWorker new EventBase...\n");
      $this->workerbase = new EventBase();
      if (!$this->workerbase) 
      {
	  debug::exit_with_error(ExitStatus::WorkerBase,"Couldn't open worker event base\n");
      }
    }
    $this->mpm_parent=$mpm_parent;
    $this->markHasStarted($pid);
    $this->setprocesstitle($this->processname,$this->id);
    $mpm_parent->callHook("childInit",array($mpm_parent,$this));
    $this->event_add($this->workerbase,"ChildSIGTERM", SIGTERM, Event::SIGNAL  | Event::PERSIST, 'SignalEventTERM');
    $this->workerbase->dispatch();
  }

  public function SignalEventTERM($signum, $ctx) 
  {
    $stop_time = microtime(true);
    debug::printf(LOG_NOTICE, "Worker #%s caught SIGTERM and exit after %0.3fs working...\n",$this->id,$stop_time-$this->starttime);
    exit(0);
  }
  public function markHasStop() 
  {
    $this->starttime=-1;
    $this->pid=-1;
    $this->alive=false;
  }

  public function markHasStarted($pid) 
  {
    $this->starttime = microtime(true);
    $this->pid = $pid;
    $this->alive = true;
  }
}

////////////////////////////
// EventProcessPool
////////////////////////////
class EventProcessPool implements ArrayAccess,Iterator
{
  private $position = 0;
  private $workers = array();

  public function __construct(&$workerclass, $maxworker=null) 
  {
    if (!is_a($workerclass,"SuperListd\EventProcessWorker"))
    {
       debug::exit_with_error(ExitStatus::WorkerClass,'$worker args must be a Worker class\n');
    }

    // get number of processing unit and use it to define the number of worker
    if ($maxworker==null) 
    {
      $this->maxworker=NetTool::getNproc();
      debug::printf(LOG_NOTICE,"I've detected %s processing unit on this machine...\n",$this->maxworker);
    }
    else 
    {
      $this->maxworker=$maxworker;
    }
    debug::printf(LOG_NOTICE,"This EventProcessPool with %s workers was created...\n",$this->maxworker);

    $this->position = 0;
    $this->workerclass=$workerclass;

    for($i=0;$i<$this->maxworker;$i++)
      $this->workers[$i]=$workerclass::getInstance($workerclass->processname,$i,$workerclass->workerbase);
  }

  public function removeWorkerById($workerId) 
  {
    if (isset($this->workers[$workerId]))
    {
      if ($this->workers[$workerId]->pid>1) 
      {
	$stop_time = microtime(true);
	debug::printf(LOG_NOTICE, "Child pid:%s/#%s remove from pool after %0.3fs of execution\n",$this->workers[$workerId]->pid,$workerId,$stop_time-$this->workers[$workerId]->starttime);
      }
      $this->workers[$workerId]->markHasStop();
      return true;
    }
    return false;
  }
  public function getWorkerIdbyPID($pid) 
  {
     foreach($this->workers as $workerId => $worker)
        if ($worker->pid==$pid)
	   return $workerId;
     return false;
  }

  public function rewind()
  {
    $this->position = 0;
  }

  public function current() 
  {
    return $this->workers[$this->position];
  }

  public function next()
  {
    ++$this->position;
  }

  public function key() 
  {
    return $this->position;
  }

  public function valid() 
  {
    return isset($this->workers[$this->position]);
  }

  public function offsetSet($offset, $value) 
  {
    if (is_null($offset)) 
	$this->workers[]=$value;
    else 
	$this->workers[$offset]=$value;
  }

  public function offsetExists($offset) 
  {
    return isset($this->workers[$offset]);
  }

  public function offsetUnset($offset) 
  {
    unset($this->workers[$offset]);
  }

  public function offsetGet($offset) 
  {
   return isset($this->workers[$offset]) ? $this->workers[$offset] : null;
  }    

}

////////////////////////////
// EventProcessPoolManager
////////////////////////////
class EventProcessPoolManager 
{
  public $base = null;
  public $processpoolmanagername = null;
  public $maxworker = 1;
  public $workers = array(); 
  public $events = array(); 
  public $user = null;
  public $heartbeat = 1;

  public static $maxerror = 5; // 5 errors par 30 seconds per worker
  public static $errorperiod = 30;

  use EventTools;
  use DropRootPrivilege;
  use SetProcessTitle;
  use HookMgmt;

  public function __construct(&$workerclass, $processpoolmanagername, $maxworker=null,$user=null) 
  {
    if (!is_a($workerclass,"SuperListd\EventProcessWorker"))
    {
       debug::exit_with_error(ExitStatus::WorkerClass,'$worker args must be a EventProcessWorker class\n');
    }

    // get number of processing unit and use it to define the number of worker
    if ($maxworker==null) 
    {
      $this->maxworker=NetTool::getNproc();
      debug::printf(LOG_NOTICE,"This machine has %s processing unit\n",$this->maxworker);
    }
    else 
    {
      $this->maxworker=$maxworker;
      debug::printf(LOG_NOTICE,"This daemon while start with %s processing unit\n",$this->maxworker);
    }

    $this->processpoolmanagername=$processpoolmanagername;
    $this->user=$user;
    $this->setprocesstitle($this->processpoolmanagername);

    $this->workers = new EventProcessPool($workerclass,$this->maxworker);

    //////////////////////////
    // initialize eventbase
    //////////////////////////
    $this->base = new EventBase();
    if (!$this->base) 
    {
       debug::exit_with_error(ExitStatus::EventBase,"Couldn't open event base\n");
    }

    register_shutdown_function(array($this,"RegistredShutdown"));
  }

  public function RegistredShutdown()
  {
     if (isset($this->base)) $this->shutdown($this,SIGTERM,SIGTERM);
  }

  public function dispatch()
  {
      debug::printf(LOG_INFO,"EventProcessPoolManager start at %s\n",gmdate('r'));

      // to manage kill and propagate to child
      $this->event_add($this->base,"SIGTERM", SIGTERM, Event::SIGNAL  | Event::PERSIST, 'SignalEventTERM');
      // to manage kill and propagate to child
      $this->event_add($this->base,"SIGINT", SIGINT, Event::SIGNAL  | Event::PERSIST, 'SignalEventINT');
      // to manager child return code and death
      $this->event_add($this->base,"SIGCHLD", SIGCHLD, Event::SIGNAL  | Event::PERSIST, 'SignalEventHLD');
      // to monitor child each seconds
      $this->event_add($this->base,"HeartBeat", -1,    Event::TIMEOUT | Event::PERSIST, 'HeartBeat',$this->heartbeat);

      $this->supervise_workers($this);
      $this->base->dispatch();

      $this->callHook("PoolStop",array($this));
  }

  public function HeartBeat($fd, $what, $ctx) 
  {
     $this->callHook("HeartBeat",array($fd,$what,$ctx));
     $this->supervise_workers($ctx);
  }

  private function supervise_workers($ctx) 
  {
     debug::printf(LOG_INFO, "Check if worker are down...\n");
     // Spawn workers to fill empty slots
     for($workerId=0;$workerId<$this->maxworker;$workerId++)
     {
	if ($this->workers[$workerId]->alive!==true)
	  if ($this->create_worker($ctx,$this->workers[$workerId],$workerId)) break;
     }
     unset($worker);
  }

  private function create_worker($ctx,&$worker, $workerId) 
  {
     $pid = pcntl_fork();
     if ( $pid === -1 )
     {
	debug::printf(LOG_ERR, "Fork Failure\n");
	return false;
     }
     if ( $pid === 0 ) 
     {
	$worker_pid = posix_getpid();
	debug::printf(LOG_NOTICE, "Worker #%s with pid:%s started at <%s>\n",$workerId,$worker_pid,gmdate('r'));

	// Re-seed the child's PRNGs
	srand();
	mt_srand();

	// The child process ignores SIGINT (small race here)
	pcntl_sigprocmask( SIG_BLOCK, array(SIGINT) );

	// purge global event base
	// and lets go of the parent's libevent base
	if (!$this->base->reInit())
	{
	  debug::exit_with_error(ExitStatus::EventBaseReInit,"Error EventBase reInit!\n");
	}
	// and breaks out of the service event loop
	if (!$this->base->stop())
	{
	  debug::exit_with_error(ExitStatus::EventBaseReInit,"Error EventBase reInit!\n");
	}

	// and dismantles the whole event structure
	foreach ( $this->events as $i => &$event ) 
	{
	  //$event->del();
	  $event->free();
	  unset($events);
	}

	// start gc collect cycles to free free reference
	if(gc_enabled()) gc_collect_cycles();

	// try to free eventbase
	if (method_exists($this->base,"free")) $this->base->free();
	$this->base=null;
	unset($this->base);

        $this->callHook("childDropPrivilege",array($this,$worker));
	// drop root privilege and setuid/gid privilege of the user
	$this->droprootandsetuid();

        $this->callHook("prechildInit",array($this,$worker));
        // start the worker
	$worker->start($worker_pid,$ctx);
	return true;
     }
     debug::printf(LOG_NOTICE,"EventProcessPoolManager Worker #%s forked with pid:%s at %s\n",$workerId,$pid,gmdate('r'));
     $worker->markHasStarted($pid);
     return false;
  }

  public function SignalEventHLD($signum, $ctx)
  {
    while (true) 
    {
       $pid = pcntl_wait($status, WNOHANG);
       $returncode= pcntl_wexitstatus($status);
       if ($pid < 1) break;
       debug::printf(LOG_NOTICE, "Child pid:%s as exited with code:%s\n",$pid,$returncode);
       $workerId=$this->workers->getWorkerIdbyPID($pid);
       if ($workerId===false)
       {
         debug::printf(LOG_ERR, "Child pid:%s not found in worker list\n",$pid);
	 break;
       }
       $this->workers->removeWorkerById($workerId);
       $this->callHook("childDeath",array($ctx,$workerId,$signum,$returncode,$pid));

       // check if child a existed with error
       if ($returncode!=0||pcntl_wifexited($status)!==true) 
       {
	 $errorperiod=self::$errorperiod;
	 $maxerror=self::$maxerror;
         // if child a existed with error
	 // check if the child as exited more thant 15 time in 30s
	 // and if no error as occured in 300s
	 $now = microtime(true);

	 if ($this->workers[$workerId]->firsterrortime==null)
	  	$this->workers[$workerId]->firsterrortime = $now;
	 if ($this->workers[$workerId]->lasterrortime==null)
	  	$this->workers[$workerId]->lasterrortime = $now;

	 if ($this->workers[$workerId]->lasterrortime>0&&
	     $this->workers[$workerId]->firsterrortime>0&&
	     intval($now-$this->workers[$workerId]->lasterrortime)>$errorperiod)
	 {
	     $this->workers[$workerId]->firsterrortime = $now;
	     $this->workers[$workerId]->lasterrortime = $now;
	     $this->workers[$workerId]->error=0;
             debug::printf(LOG_ERR, "First error after %ss, reset the error counter for this worker #%s\n",$errorperiod,$workerId);
	 }

	 $this->workers[$workerId]->error++;
	 $this->workers[$workerId]->lasterrortime = $now;
	 $nbsec=intval($this->workers[$workerId]->lasterrortime-$this->workers[$workerId]->firsterrortime);
	 debug::printf(LOG_ERR,"Worker #%s Exit Error count:%s for the last %ss\n",$workerId,
	                                                               $this->workers[$workerId]->error,
								       $nbsec);

	 if ($this->workers[$workerId]->error>$maxerror&&$nbsec<=$errorperiod)
	 {
             debug::printf(LOG_ERR, "More than %s errors in %ss for worker %s, shutdown the EventProcessPoolManager...\n",$maxerror,$errorperiod,$workerId);
	     $this->shutdown($ctx,SIGTERM,$signum);
	 }
       }
    }
  }

  public function SignalEventINT($signum, $ctx) 
  {
    debug::printf(LOG_NOTICE, "EventProcessPoolManager caught SIGINT.\n");
    $this->shutdown($ctx,SIGTERM,$signum);
  }

  public function SignalEventTERM($signum, $ctx) 
  {
    debug::printf(LOG_NOTICE, "EventProcessPoolManager caught SIGTERM.\n");
    $this->shutdown($ctx,SIGTERM,$signum);
  }

  private function shutdown($ctx,$signal,$signum) 
  {
    declare(ticks = 1);
    debug::printf(LOG_NOTICE, "Start ProcessPool shutdown...\n");
    $this->callHook("PoolShutdown",array($ctx,$signal,$signum));
    // Delay shutdown until all accepted requests are completed
    $this->base->stop();

    // set alarm to 5s max, after that they send SIGKILL to child that are already alive
    pcntl_signal(SIGALRM, array($this,"shutdownKill"));
    pcntl_alarm(5);

    // send SIGTERM to all child
    $count=0;
    for($workerId=0;$workerId<$this->maxworker;$workerId++)
    {
      if ($this->workers[$workerId]->alive===true&&$this->workers[$workerId]->pid>1)
      {
        debug::printf(LOG_NOTICE, "Send SIGTERM signal to %s...\n",$this->workers[$workerId]->pid);
	posix_kill( $this->workers[$workerId]->pid, SIGTERM );
	$count++;
      }
    }

    // wait the child exit signal
    for($nb=0;$nb<$count;$nb++)
    {
      $pid = pcntl_wait($status);
      $returncode= pcntl_wexitstatus($status);
      if ($pid>1) 
      {
	debug::printf(LOG_NOTICE, "Child pid:%s as exited with code:%s\n",$pid,$returncode);
	$this->workers->removeWorkerById($this->workers->getWorkerIdbyPID($pid));
      }
    }

    $this->base->exit(NULL);
    debug::printf(LOG_NOTICE, "EventProcessPoolManager service shutdown complete...\n");
    unset($this->base);
  }

  public function shutdownKill($signum)
  {
    $this->base->exit(NULL);
    debug::printf(LOG_NOTICE, "EventProcessPoolManager service shutdown killall...\n");
    // send SIGKILL all alive child
    for($workerId=0;$workerId<$this->maxworker;$workerId++)
    {
      if ($this->workers[$workerId]->alive===true&&$this->workers[$workerId]->pid>1)
      {
        debug::printf(LOG_NOTICE, "Send SIGKILL signal to %s...\n",$this->workers[$workerId]->pid);
	posix_kill( $this->workers[$workerId]->pid, SIGKILL );
	$this->workers->removeWorkerById($workerId);
      }
    }
  }
}
