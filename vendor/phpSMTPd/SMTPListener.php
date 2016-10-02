<?php
namespace phpSMTPd;

require_once("Debug.php");
require_once("SMTPProtocol.php");

use EventSslContext;
use EventBase;
use EventBufferEvent;
use Event;
use EventListener;
use EventUtil;

 /*
 * Author: Mathieu CARBONNEAUX 
 */

class SMTPListener 
{
  public $listener = null;
  public $connections = [];
  public $hostname = false;
  public $options = [];
  public $base = null;
  public $events = [];
  private $socket = null;

  // socket read/write default timeout
  public $readtimeout = 300;
  public $writetimeout = 300;

  // socket so_keepalive default timming 
  public $tcp_keepidle = 7200;
  public $tcp_keepintvl = 75;
  public $tcp_keepcnt = 9;

  public function __construct($base,$target,$options=null) 
  {
      $this->options=$options;
      if (isset($options['hostname'])) $this->hostname=$options['hostname'];
      else $this->hostname=NetTool::getFQDNHostname();

      if (isset($options['read_timeout'])) $this->readtimeout=$options['read_timeout'];
      if (isset($options['write_timeout'])) $this->writetimeout=$options['write_timeout'];

      debug::printf(LOG_NOTICE, "Starting Listening SMTP on ".$target." at ".$this->hostname."\n");

      $this->base = $base;
      if (!$this->base) 
      {
	  debug::exit_with_error(56,"Couldn't open event base\n");
      }

      if (is_string($target))
      {
	// if string is in form <ip|host>:<port> try to bind the socket
	// before transmit to EventListener
	// without that the EventListener::OPT_CLOSE_ON_FREE is systematiquely set
	if (preg_match("/^(.+):([^:]+)$/",$target,$arr)==1)
	{
	  $target=$this->socketListen($arr[1],$arr[2],false);
	  if ($target===false) 
	    debug::exit_with_error(57,"Couldn't not connect to %s:%s\n",$arr[1],$arr[2]);
	  $this->socket=$target;
	}

	if (!$this->listener = new EventListener($this->base,
						 [$this, 'ev_accept'],
						 $this,
						 EventListener::OPT_REUSEABLE,
						 //EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
						 -1,
						 $target))
	{
            $errno = EventUtil::getLastSocketErrno();
	    debug::printf(LOG_ERR, "Got an error %d (%s) on the listener. Shutting down.\n",
		$errno, EventUtil::getLastSocketError());

	    $this->base->exit(NULL);
	    debug::exit_with_error(57,"Couldn't create listener\n");
	}

	$this->listener->setErrorCallback([$this, 'ev_error_listener']);

      }
      else
      {
        debug::printf(LOG_ERR, "unknown arguments ".print_r($target,true)."\n");
        exit(1);
      }
  }

  public function listen() 
  {
     $this->base->dispatch();
  }

  public function event_add( $name, $fd, $flags, $callback, $timeout = -1 ) 
  {
     $event = new Event($this->base, $fd, $flags, array($this, $callback), $this);
     // to catch notice :
     // PHP Notice:  Event::add(): Added a signal to event base 0x1dadf30 with signals already added to event_base 0x1da4ec0.  Only one can have signals at a time with the epoll backend. 
     ob_start(); 
     if ($timeout==-1) $event->add();
     else $event->add($timeout);
     $output = ob_get_contents();
     ob_end_clean();
     $this->events[ $name ] = $event;
  }

  public function SignalEventTERM($signo) 
  {
    debug::printf(LOG_NOTICE, "Worker caught SIGTERM.\n");
    $this->shutdown($this,SIGTERM);
  }

  private function shutdown($ctx,$signal) 
  {
          debug::printf(LOG_NOTICE, "Worker start shutdown...\n");
	  // Delay shutdown until all accepted requests are completed
	  $this->base->exit(5);
	  $this->base->stop();
          debug::printf(LOG_NOTICE, "Worker shutdown complete...\n");
	  exit(0);
  }

  public function ev_accept($listener, $fd, $address, $ctx) 
  {
     static $id = 0;
     $id += 1;

     $options=array_merge($ctx->options,array(
                       'id'=>$id,
                       'listener'=>$ctx,
		       ));
     $ctx->connections[$id]=new SMTPProtocol($ctx->base,$fd,$address,$options);
     if (!$ctx->connections[$id]) 
     {
	 $this->base->exit(NULL);
	 debug::exit_with_error(58,"Failed creating smtp protocol\n");
     }
  }

  private function socketListen($sockhost,$sockport,$ipv6=true)
  {
    // check if is ip or host
    if (($iptype=NetTool::is_ip($sockhost))!==false)
    {
      $sockip[0]=$sockhost;
      $sockip['type']=$iptype;
    }
    else 
    {
      $sockip=NetTool::gethostbyname($sockhost,$ipv6);
      if ($sockip===false)
      {
	 debug::printf(LOG_ERR,"Cannot resolve <%s>!\n",$sockhost);
	 return false;
      }
    }

    debug::printf(LOG_DEBUG,"Try to bind to %s:%s in %s!\n",$sockip[0],$sockport,$sockip['type']===AF_INET?"ipv4":"ipv6");

    // create the socket
    $socket = socket_create($sockip['type'], SOCK_STREAM, SOL_TCP);

    // set the Socket Address Reuse option
    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

    // set the socket timeout
    $timeout = array('sec'=>$this->readtimeout,'usec'=>0);
    socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,$timeout);
    $timeout = array('sec'=>$this->writetimeout,'usec'=>0);
    socket_set_option($socket,SOL_SOCKET,SO_SNDTIMEO,$timeout);

    // set keepalive tcp option
    socket_set_option($socket,SOL_SOCKET,SO_KEEPALIVE,1);

    // if on linux try to change KeepAlive timing counter
    if (!strncmp("Linux",PHP_OS,5))
    {
      socket_set_option($socket,SOL_TCP   ,NetTool::TCP_KEEPIDLE,$this->tcp_keepidle);
      socket_set_option($socket,SOL_SOCKET,NetTool::TCP_KEEPINTVL,$this->tcp_keepintvl);
      socket_set_option($socket,SOL_SOCKET,NetTool::TCP_KEEPCNT,$this->tcp_keepcnt);
    }

    // bind the socket
    if (!@socket_bind($socket, $sockip[0], $sockport)) 
    {
      debug::printf(LOG_ERR,"Cannot Bind Socket to %s:%s - <%s>!\n",$sockip[0],$sockport,socket_strerror(socket_last_error()));
      socket_close($socket);
      return false;
    }
    debug::printf(LOG_ERR,"Socket bind to %s:%s!\n",$sockip[0],$sockport);
    return $socket;
  }

  function ev_error_listener ($listener, $ctx)
  {
     $errno = EventUtil::getLastSocketErrno();

	/*
	     Errno 11 = EAGAIN or EWOULDBLOCK
		 The socket is marked non-blocking and the receive operation would block, or a receive timeout had been set and the timeout expired before data was received.  POSIX.1-2001 allows either error to
		 be returned for this case, and does not require these constants to have the same value, so a portable application should check for both possibilities.	   

             Errno 104 = ECONNRESET
	     	 Connection reset by peer

	*/
     if ($errno == 11 || $errno == 104) 
     {
	debug::printf(LOG_DEBUG,"Listener Client disconection detected\n");
	$fd=$ctx->listener->fd;
	foreach($ctx->connections as $key => $value)
	{
	   debug::printf(LOG_DEBUG, "check %s connection %s for %s\n",$key,$fd,$value->cnx->fd);
	   if ($value->cnx->fd == $fd)
	   {
	     $address=$ctx->connections[$key]->cnx->address;
	     debug::printf(LOG_NOTICE, "Listener Client has disconected %s:%s\n",$address[0],$address[1]);

	     //debug::print_r(LOG_DEBUG,$value);
	     $ctx->connections[$key]->cnx->ev_close($ctx->connections[$key]->cnx);
	   }
	}
	return;
     }

     if ($errno != 0) 
     {
	 debug::printf(LOG_ERR, "Got an error %d (%s) on the listener. Shutting down.\n",
	     $errno, EventUtil::getLastSocketError());

	 $this->base->exit(NULL);
	 debug::exit_with_error(50,"Exist on Error");
     }

  }
}

