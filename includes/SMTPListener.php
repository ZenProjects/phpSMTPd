<?php
namespace SuperListd;

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

  public function __construct($base,$connect_string,$options=null) 
  {
      $this->options=$options;
      if (isset($options['hostname'])) $this->hostname=$options['hostname'];
      else $this->hostname=NetTool::getFQDNHostname();

      debug::printf(LOG_NOTICE, "Starting Listening SMTP on ".$connect_string." at ".$this->hostname."\n");

      $this->base = $base;
      if (!$this->base) 
      {
	  debug::exit_with_error(-1,"Couldn't open event base\n");
      }

      if (is_string($connect_string))
      {
	if (!$this->listener = new EventListener($this->base,
						 [$this, 'ev_accept'],
						 $this,
						 EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
						 -1,
						 $connect_string))
	{
            $errno = EventUtil::getLastSocketErrno();
	    debug::printf(LOG_ERR, "Got an error %d (%s) on the listener. Shutting down.\n",
		$errno, EventUtil::getLastSocketError());

	    $this->base->exit(NULL);
	    debug::exit_with_error(-1,"Couldn't create listener\n");
	}

	$this->listener->setErrorCallback([$this, 'ev_error_listener']);

      }
      else
      {
        debug::printf(LOG_ERR, "unknown arguments ".print_r($connect_string,true)."\n");
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
	 debug::exit_with_error(-1,"Failed creating smtp protocol\n");
     }
  }

  function ev_error_listener ($listener, $ctx)
  {
     $errno = EventUtil::getLastSocketErrno();

     if ($errno == 11 || $errno == 104) 
     {
	debug::printf(LOG_DEBUG,"Client disconection detected\n");
	$fd=$ctx->listener->fd;
	foreach($ctx->connections as $key => $value)
	{
	   debug::printf(LOG_DEBUG, "check %s connection %s for %s\n",$key,$fd,$value->cnx->fd);
	   if ($value->cnx->fd == $fd)
	   {
	     $address=$ctx->connections[$key]->cnx->address;
	     debug::printf(LOG_NOTICE, "Client has disconected %s:%s\n",$address[0],$address[1]);

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
	 debug::exit_with_error(-1,"Exist on Error");
     }

  }
}

