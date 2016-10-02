<?php
namespace phpSMTPd;

require_once("RFC822.php");
require_once("Debug.php");
require_once("MailQueue.php");

use EventSslContext;
use EventBase;
use EventBufferEvent;
use Event;
use EventUtil;

 /*
 * Author: Mathieu CARBONNEAUX 
 * based on original sample from Andrew Rose <hello at andrewrose dot co dot uk>
 *
 * Event based SMTP Server 
 *
 * Implement this SMTP verbs: EHLO/HELO, STARTTLS, XFORWARD, XCLIENT, MAIL FROM, RCPT TO, VRFY, NOOP, QUIT
 * no HELP, SEND, SAML, SOML, TURN, ETRN verbs
 * Conform with ESMTP standard RFC 1869 and implement this extension : 8BITMIME, STARTTLS, SIZE, XCLIENT, XFORWARD
 */

class SMTPProtocol 
{
  // eventbufferevent of the connexion
  public $cnx = null;

  // options
  public $options = [];
  public $hostname = false;
  public $maxRead = 256000;
  // http://tools.ietf.org/html/rfc1869#section-4.1.2 => 
  // 512 + BODY=8BITMIME : 5+8+1=14 + SIZE=01234567890123456789 : 5+20+1=26 = 552
  public $maxcommandlinesize = 552; 
  public $maxmessagesize = false;
  public $xclient = false;
  public $xforward = true;
  public $tls = false;
  public $crlf = true;
  public $read_timeout = 300; // 300s
  public $write_timeout = 300; // 300s
  public $id = false;
  public $address = null;
  public $fd = null;
  public $tlsenabled = false;

  // smtp state machine constant
  const STATE_CONNECT = 1;
  const STATE_HELO = 2;
  const STATE_HEADER = 3;
  const STATE_DATA = 4;

  private static $xforward_name = array("NAME","ADDR","PORT","PROTO","HELO","IDENT","SOURCE");
  private static $xclient_name = array("NAME","ADDR","PROTO","HELO");

  public function __construct(&$base,&$fd,$address=null,&$options=null) 
  {
      $this->base = $base;
      if (!$this->base) 
      {
	  debug::exit_with_error(59,"Couldn't open event base\n");
      }

      $this->options=$options;
      if (isset($options['hostname'])) $this->hostname=$options['hostname'];
      else $this->hostname=NetTool::getFQDNHostname();
      if (isset($options['maxRead'])) $this->maxRead=$options['maxRead'];
      if (isset($options['maxcommandlinesize'])) $this->maxcommandlinesize=$options['maxcommandlinesize'];
      if (isset($options['maxmessagesize'])) $this->maxmessagesize=$options['maxmessagesize'];
      else $this->maxmessagesize=15*1024*1024;
      if (isset($options['xclient'])) $this->xclient=$options['xclient'];
      if (isset($options['xforward'])) $this->xforward=$options['xforward'];
      if (isset($options['tls'])) $this->tls=$options['tls'];
      if (isset($options['crlf'])) $this->crlf=$options['crlf'];
      if (isset($options['read_timeout'])) $this->read_timeout=$options['read_timeout'];
      if (isset($options['write_timeout'])) $this->write_timeout=$options['write_timeout'];
      if (isset($options['sslctx']['context'])) $this->sslctx=$options['sslctx']['context'];
      if (isset($options['id'])) $this->id=$options['id'];
      if (isset($options['listener'])) $this->listener=$options['listener'];



      if ($address==null) 
      {
	$address=stream_socket_get_name($fd,true);
	if($address!==false)
	{
	  if (preg_match("/([0-9]+[.][0-9]+[.][0-9]+[.][0-9]+)[:]([0-9]+)/",$address,$arr)==1)
	  {
	    $parsed_address=array(0=>$arr[1],1=>$arr[2]);
	    //debug::printf(LOG_NOTICE,"Client connected from this parsed address: %s:%s\n",$parsed_address[0],$parsed_address[1]);
	    $this->address=$parsed_address;
	  }
	  else
	  {
	    $this->address=array(0=>$address);
            debug::printf(LOG_DEBUG,"Client connected from this address: %s\n",$address);
	  }
	}
      }
      else
      {
	if (is_array($address)) $this->address=$address;
        else 
	{
	 $this->address=array(0=>$address);
         debug::printf(LOG_NOTICE,"Client connected from this address: %s\n",$address);
	}
      }
      debug::printf(LOG_NOTICE, "Starting ESMP Session on fd:%s id:%s at %s with client at %s:%s\n",$fd,$this->id,$this->hostname,$this->address[0],@$this->address[1]);

      /*
      $this->tt = new \TokyoTyrantTable("127.0.0.1", 1978);
      if (!$this->tt)
      {
	  $this->base->exit(NULL);
	  debug::exit_with_error(60,"Couldn't create TokyoTyrantTable\n");
      }
      */

      if ($this->tls&&!$this->sslctx)
      {
	  debug::exit_with_error(62,"no ssl context with tls option activated\n");
      }

      $ev_options=0;
      // $ev_options |= EventBufferEvent::OPT_CLOSE_ON_FREE;
      if (!$this->cnx = new EventBufferEvent($this->base, $fd,$ev_options))
      {
	  $this->base->exit(NULL);
	  debug::exit_with_error(63,"Couldn't create bufferevent\n");
      }

      $this->fd = $fd;
      $this->state=self::STATE_CONNECT;
      $this->clientData = "";
      $this->clientDataLen = 0;
      $this->tlsenabled = false;
      $this->cnx->setTimeouts($this->read_timeout,$this->write_timeout);
      $this->cnx->setCallbacks([$this, "ev_read"], NULL, [$this, 'ev_error'], $this);
      $this->cnx->enable(Event::READ);
      $this->connect_time=microtime(true);
      $this->ev_write($this, '220 '.$this->hostname." ESMTP - ".$this->options['daemon_processname']." at ".gmdate('r')." on stdin\r\n");
      //var_dump($this);
  }

  public function listen() 
  {
     $this->base->dispatch();
  }

  public function ev_error($buffer, $events, $ctx) 
  {
     /*
     EventBufferEvent::READ = 0x01
     EventBufferEvent::WRITE = 0x02
     EventBufferEvent::EOF = 0x10
     EventBufferEvent::ERROR = 0x20
     EventBufferEvent::TIMEOUT = 0x40
     EventBufferEvent::CONNECTED = 0x80
     */
     if ($events & EventBufferEvent::CONNECTED)
     {

	if ($ctx->tlsenabled===true)
	{
	    debug::printf(LOG_NOTICE, "Cipher           : %s\n",implode("/",preg_split("/\s+/",trim($buffer->sslGetCipherInfo()))));
	    debug::printf(LOG_NOTICE, "CipherVersion    : %s\n",$buffer->sslGetCipherVersion());
	    debug::printf(LOG_NOTICE, "CipherName       : %s\n",$buffer->sslGetCipherName());
	    debug::printf(LOG_NOTICE, "CipherProtocol   : %s\n",$buffer->sslGetProtocol());
	}

	debug::printf(LOG_NOTICE, "Connected.\n");
        return;
     }

     if ($events & EventBufferEvent::EOF)
     {
	$address=$ctx->address;
	debug::printf(LOG_NOTICE, "The client %s:%s has been disconected on EOF\n",$address[0],$address[1]);
	$ctx->ev_close($ctx);
        return;
     }

     if ($events & EventBufferEvent::TIMEOUT)
     {
	$address=$ctx->address;
	debug::printf(LOG_NOTICE, "The client %s:%s has timeouted\n",$address[0],$address[1]);
	$ctx->ev_close($ctx);
        return;
     }

     if ($events & EventBufferEvent::ERROR)
     {
	$errno = EventUtil::getLastSocketErrno();

	/*
	     Errno 2 = ENOENT
	     	 No such file or directory

	     Errno 11 = EAGAIN or EWOULDBLOCK
		 The socket is marked non-blocking and the receive operation would block, or a receive timeout had been set and the timeout expired before data was received.  POSIX.1-2001 allows either error to
		 be returned for this case, and does not require these constants to have the same value, so a portable application should check for both possibilities.	   

             Errno 104 = ECONNRESET
	     	 Connection reset by peer

	*/
	if ($errno == 2 || $errno == 11 || $errno == 104) 
	{
	    $address=$ctx->address;
	    debug::printf(LOG_NOTICE, "The client %s:%s has disconected with errno:%s\n",$address[0],$address[1],$errno);
	    $ctx->ev_close($ctx);
	    return;
	}

	if ($errno != 0) 
	{
	    debug::printf(LOG_ERR, "Got an error %d (%s) on the listener. Shutting down.\n",
		$errno, EventUtil::getLastSocketError());

	    $ctx->base->exit(NULL);
	    debug::exit_with_error(64,"Event Error\n");
	}
     }
     else
     {
       debug::printf(LOG_ERR, "Warning an uncatched Event has occured %s\n",$events);
     }
  }

  public function ev_close($ctx,$drain=false) 
  {
       $output_buffer=$ctx->cnx->getOutput();
       $input_buffer=$ctx->cnx->getInput();
       if ($output_buffer->length>0) 
       {
	     /* We still have to flush data from the other
	      * side, but when that's done, close the other
	      * side. */
	     $ctx->cnx->disable(Event::READ);
	     if ($drain) while($input_buffer->length > 0) $input_buffer->drain($ctx->maxRead);
	     $ctx->cnx->setCallbacks(NULL, [$this, 'ev_close_on_finished_writecb'], NULL, $ctx);
       } else {
	     /* We have nothing left to say to the other
	      * side; close it. */
	     $ctx->cnx->disable(Event::READ | Event::WRITE);
	     if ($drain) while($input_buffer->length > 0) $input_buffer->drain($ctx->maxRead);
	     if (method_exists($ctx->cnx,"close")) $ctx->cnx->close();
	     else bufferclose($ctx->cnx->fd);
	     $ctx->cnx->free();
	     unset($ctx->cnx);
	     if (isset($ctx->listener->connections[$ctx->id])) unset($ctx->listener->connections[$ctx->id]);
	     else exit(0);
       }
  }

  private function bufferclose($fd)
  {
      if ($bev = new EventBufferEvent($this->base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE))
      {
        debug::printf(LOG_DEBUG,"close %s\n",$fd);
        $bev->free();
	unset($bev);
      }
  }

  public function ev_close_on_finished_writecb($buffer, $ctx) 
  {
      if ($ctx->cnx->getOutput()->length==0)
      {
	$ctx->cnx->disable(Event::READ | Event::WRITE);
	//$ctx->bufferclose($ctx->cnx->fd);
	if (method_exists($ctx->cnx,"close")) $ctx->cnx->close();
	else bufferclose($ctx->cnx->fd);
	$ctx->cnx->free(); 
	unset($ctx->cnx);
	if (isset($ctx->listener->connections[$ctx->id])) unset($ctx->listener->connections[$ctx->id]);
	else exit(0);
      }
  }

  protected function ev_write($ctx, $string) 
  {
      debug::printf(LOG_NOTICE,"S(%s) %s\n",$ctx->id,NetTool::toprintable($string));
      $ctx->cnx->write($string);
  }

  public function ev_read($buffer, $ctx) 
  {
      debug::printf(LOG_DEBUG,"event read length:%s\n",$buffer->input->length);
      while($buffer->input->length > 0) 
      {
	  $lastread= $buffer->input->read($ctx->maxRead);
	  $lastLen = strlen($lastread);
          //debug::printf(LOG_DEBUG,"event data read:-%s-\n",$lastread);

	  $ctx->clientData .= $lastread;
	  $ctx->clientDataLen += $lastLen;

	  if ($ctx->state!=self::STATE_DATA)
	  {
            debug::printf(LOG_DEBUG,"cmd state:%s clientDataLen:%d\n",$ctx->state,$ctx->clientDataLen);
	    if ($ctx->clientDataLen>$ctx->maxcommandlinesize)
	    {
	       debug::printf(LOG_ERR,"Command line size (%s) exceeds fixed maximium line size (%s)\n",$ctx->clientDataLen,$ctx->maxcommandlinesize);
	       $this->ev_write($ctx, "500 command line size exceeds fixed maximium line size\r\n");
	       $this->ev_close($ctx);
	       return;
	    }
	    $endofblock=substr($ctx->clientData,$ctx->clientDataLen-2);
	    $endofblock1=substr($ctx->clientData,$ctx->clientDataLen-1);
	    debug::printf(LOG_DEBUG,"endofblock:<%s> endofblock1:<%s>\n",NetTool::toprintable($endofblock),NetTool::toprintable($endofblock1));
	    // read SMTP cmd<CRLF>
	    if($endofblock == "\r\n"||($ctx->crlf&&$endofblock1 == "\n"))
	    {
		// remove the trailing \r\n
		$line = substr($ctx->clientData, 0, $ctx->clientDataLen - 2);

		$ctx->clientData = '';
	        $ctx->clientDataLen=0;
		$this->SMTPcmd($buffer, $ctx, $line);
		return;
	    }
	  }
	  else
	  {
            debug::printf(LOG_DEBUG,"data state clientDataLen:%d\n",$ctx->clientDataLen);
	    if ($ctx->clientDataLen>$ctx->maxmessagesize)
	    {
		debug::printf(LOG_ERR,"Messages data size exceeds fixed maximium message size\n");
		$this->ev_write($ctx, "552 message size exceeds fixed maximium message size\r\n");
		$this->ev_close($ctx);
		return;
	    }
	    $endofblock=substr($ctx->clientData,$ctx->clientDataLen-5);
	    $endofblock1=substr($ctx->clientData,$ctx->clientDataLen-3);
	    //debug::printf(LOG_DEBUG,"endofblock:<%s> "endofblock1:<%s>\n",urlencode($endofblock),urlencode($endofblock1));
	    //debug::printf(LOG_DEBUG,"endofblock:<%s>\n",urlencode($endofblock));
	    // read data to the <CRLF>.<CRLF> final
	    if($endofblock=="\r\n.\r\n"||($ctx->crlf&&$endofblock1=="\n.\n"))
	    {
	      debug::printf(LOG_NOTICE,"Messages data has been succesfuly recepted\n");
	      if ($this->mailhandle($ctx)) $this->ev_write($ctx, "250 Messages data has been recepted\r\n");
	      else 			   $this->ev_write($ctx, "451 Requested action aborted: local error in processing\r\n");
	      debug::printf(LOG_NOTICE,"Messages data enqueued in inbound queue\n");
	      $ctx->state=self::STATE_HELO;
	      $ctx->clientData="";
	      $ctx->clientDataLen=0;
	      unset($ctx->enveloppe['rcpt']);
	      unset($ctx->enveloppe['mailfrom']);
	    }
	  }
      }
  }

  protected function mailhandle($ctx)
  {
     $ret=true;

     if (isset($ctx->listener->connections[$ctx->id])) 
      $connexion_data=$ctx->listener->connections[$ctx->id];
     else 
      $connexion_data=$ctx;
     debug::print_r(LOG_DEBUG,$this->mimeDecode($connexion_data->clientData));

     $enveloppe=array("helo_host" => $connexion_data->enveloppe['helo_host'],
		      "client_ip" => $connexion_data->address[0],
		      "client_port" => $connexion_data->address[1],
		      "connect_time" => $connexion_data->connect_time,
		      "helo_time" => $connexion_data->enveloppe['helo_time'],
		      "data_time" => microtime(true),
		      "sender" => $connexion_data->enveloppe['mailfrom']['reverse_path'],
		      "sender_parsed" => $connexion_data->enveloppe['mailfrom']['reverse_path_address'],
		      "sender_options" => $connexion_data->enveloppe['mailfrom']['mail_options'],
		      "recipients" => $connexion_data->enveloppe['rcpt'],
		      "xforward" => @$connexion_data->enveloppe['xforward'],
		      "xclient" => @$connexion_data->enveloppe['xclient'],
		      "clientDataLen" => $connexion_data->clientDataLen,
		      );

     foreach($ctx->enveloppe as $key => $value)
     {
        if (is_array($value))
	{
	  foreach($value as $key2 => $value2)
	  {
            if (!is_array($value2))
	      debug::printf(LOG_INFO,"Enveloppe In: %s[%s]=%s\n",$key,$key2,$value2);
	    else
	    {
	      foreach($value2 as $key3 => $value3)
	      {
                if (!is_array($value2))
	          debug::printf(LOG_INFO,"Enveloppe In: %s[%s][%s]=%s\n",$key,$key2,$key3,$value3);
		else
	          foreach($value3 as $key4 => $value4)
	          debug::printf(LOG_INFO,"Enveloppe In: %s[%s][%s][%s]=%s\n",$key,$key2,$key3,$key4,$value4);
	      }
	    }
	  }
	}
	else
	      debug::printf(LOG_INFO,"Enveloppe In: %s=%s\n",$key,$value);
     }

     foreach($enveloppe as $key => $value)
     {
        if (is_array($value))
	{
            if (!is_array($value2))
	      debug::printf(LOG_INFO,"Enveloppe Out: %s[%s]=%s\n",$key,$key2,$value2);
	    else
	      foreach($value2 as $key3 => $value3)
	      debug::printf(LOG_INFO,"Enveloppe Out: %s[%s][%s]=%s\n",$key,$key2,$key3,$value3);
	}
	else
	      debug::printf(LOG_INFO,"Enveloppe Out: %s=%s\n",$key,$value);
     }
     $mailqueue=new MailQueue($this->options);
     $ret=$mailqueue->enqueue("inbound",$enveloppe,$connexion_data->clientData);
     return $ret;
  }

  protected function getrfc822Address($buffer)
  {
    $mail_address=$buffer;
    // remove BATV http://en.wikipedia.org/wiki/Bounce_Address_Tag_Validation
    if (preg_match("/^prvs=[0-9]{4}[0-9A-Fa-f]{5,6}=(.*\..*)$/",$mail_address,$arr)==1) $mail_address=$arr[1];
    if (preg_match('/[\\w\\.\\-+=*_]*@[\\w\\.\\-+=*_]*/', $mail_address,$regs)==1) $mail_address=$regs[0];
    return $mail_address;
  }

  protected function mimeDecode($buffer)
  {
    $parsed_mail=array();
    $mail = mailparse_msg_create(); 
    mailparse_msg_parse($mail, $buffer); 
    $struct = mailparse_msg_get_structure($mail);  

    foreach ($struct as $key => $st) 
    {
      debug::printf(LOG_DEBUG,"st:%s\n",$st);
      $section = mailparse_msg_get_part($mail, $st);  
      $info    = mailparse_msg_get_part_data($section);  
      $parsed_mail[$key]['st']=$st;
      $parsed_mail[$key]['struct']=$info;
      if (($info['content-type']!="multipart/alternative")&&
          ($info['content-type']!="multipart/mixed"))
      {
	 $msg_part= mailparse_msg_extract_part($section,$buffer,NULL);
	 //debug::printf(LOG_DEBUG,"part(%s)=<<<<<\n%s\n>>>>>\n",$key, $msg_part);
	 $parsed_mail[$key]['data']=$msg_part;
      }
    }
    return $parsed_mail;
  }

  protected function checkrecipient($recipient)
  {
     if (isset($this->options['listes'][$recipient]))
      return true;
     return false;
  }

  protected function SMTPcmd($buffer, $ctx, $line) 
  {
      if (isset($ctx->listener->connections[$ctx->id])) 
       $connexion_data=$ctx->listener->connections[$ctx->id];
      else 
       $connexion_data=$ctx;

      debug::printf(LOG_NOTICE,"R(%s): %s\n",$ctx->id,NetTool::toprintable($line));
      switch ($line) 
      {
	  case strncasecmp('HELO ', $line, 5):
	      $helo_host=substr($line,5);
	      if (NetTool::gethostbyname($helo_host)===false)
	      {
		$this->ev_write($ctx, "504 <".$helo_host.">: HELO command rejected: need fully-qualified hostname\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      unset($ctx->enveloppe);
	      $ctx->enveloppe['helo_host']=$helo_host;
	      $ctx->enveloppe['helo_time']=microtime(true);

	      debug::printf(LOG_NOTICE,"HELO Host <%s>\n",$helo_host);
	      $this->ev_write($ctx, "250 ".$ctx->hostname." is at your service\r\n");
	      $ctx->state=self::STATE_HELO;
	      break;

	  case strncasecmp('EHLO ', $line, 5):
	      $helo_host=substr($line,5);
	      if (NetTool::gethostbyname($helo_host)===false)
	      {
		$this->ev_write($ctx, "504 <".$helo_host.">: EHLO command rejected: need fully-qualified hostname\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      unset($ctx->enveloppe);
	      $ctx->enveloppe['helo_host']=$helo_host;
	      $ctx->enveloppe['helo_time']=microtime(true);
	      debug::printf(LOG_NOTICE,"EHLO Host <%s>\n",$helo_host);

	      $this->ev_write($ctx, "250-".$ctx->hostname." is at your service, [".$connexion_data->address[0]."]\r\n");
	      $this->ev_write($ctx, "250-8BITMIME\r\n");
	      // not obliged to annonce the VRFY support according to RFC 821 §4.5.1 as is an required in SMTP minimum implementation
	      //$this->ev_write($ctx, "250-VRFY\r\n");
	      if ($ctx->tls&&$ctx->tlsenabled!==true) $this->ev_write($ctx, "250-STARTTLS\r\n");
	      if ($ctx->xclient) $this->ev_write($ctx, "250-XCLIENT ".implode(" ",self::$xclient_name)."\r\n");
	      if ($ctx->xforward) $this->ev_write($ctx, "250-XFORWARD ".implode(" ",self::$xforward_name)."\r\n");
	      $this->ev_write($ctx, "250 SIZE ".$ctx->maxmessagesize."\r\n");
	      $ctx->state=self::STATE_HELO;
	      break;

	  case strncasecmp('QUIT', $line, 4):
	      $this->ev_write($ctx, "221 OK quit\r\n");
	      $this->ev_close($ctx);
	      break;
	  case strncasecmp('MAIL ', $line, 5):
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "500 Mail before helo\r\n");
		$this->ev_close($ctx);
		break;
	      }

	      // check if inbound queue is full
	      if (@shm_get_var($this->options['ipc_shm'],1)===true) 
	      {
		$this->ev_write($ctx, "452 Requested action not taken: insufficient system storage\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      if (preg_match('/^MAIL FROM:<(.*)> *(.*)$/i', $line, $arr)==1)
	      {
		 debug::printf(LOG_NOTICE,"MAIL: from:<%s> option:<%s>\n",$arr[1],$arr[2]);
		 if (preg_match('/SIZE=([0-9]*)/i', $arr[2], $sizes)==1)
		 {
		   debug::printf(LOG_DEBUG,"Message estimated size %s / max message size %s\n",$sizes[1],$ctx->maxmessagesize);
		   if ($sizes[1]>$ctx->maxmessagesize) 
		   {
		     debug::printf(LOG_ERR,"Error message estimated size %s are larger than max message size\n",$sizes[1],$ctx->maxmessagesize);
		     $this->ev_write($ctx, "552 message size exceeds fixed maximium message size\r\n");
		     break;
		   }
		   $ctx->enveloppe['mailfrom']['max_size']=$sizes[1];
		 }
		 if (RFC822::is_valid_email_address($arr[1]))
		 {
		   $mail_address=$arr[1];
		   $mail_options=$arr[2];
		   $ctx->enveloppe['mailfrom']['reverse_path']=$mail_address;
		   $ctx->enveloppe['mailfrom']['mail_options']=$mail_options;

		   debug::printf(LOG_NOTICE,"MAIL <%s> address valid\n",$mail_address);
		   $ctx->enveloppe['mailfrom']['reverse_pathdecoded']=mailparse_rfc822_parse_addresses($mail_address);
		   $ctx->enveloppe['mailfrom']['reverse_path_address']=$this->getrfc822Address($mail_address);
		   $this->ev_write($ctx, "250 OK mail\r\n");
		   $ctx->state=self::STATE_HEADER;
		   break;
		 }
	      }
	      $this->ev_write($ctx, "501 MAIL - Syntax error in parameters or arguments\r\n");
	      break;
	  case strncasecmp('RCPT ', $line, 5):
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "503 RCPT Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      // check if inbound queue is full
	      if (@shm_get_var($this->options['ipc_shm'],1)===true) 
	      {
		$this->ev_write($ctx, "452 Requested action not taken: insufficient system storage\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      if (preg_match('/^RCPT TO:<(.*)>$/i', $line, $arr)==1)
	      {
		 debug::printf(LOG_NOTICE,"RCPT: to:<%s>\n",$arr[1]);
		 if (RFC822::is_valid_email_address($arr[1]))
		 {
		   debug::printf(LOG_NOTICE,"TO <%s> address valid\n",$arr[1]);
		   if (!$this->checkrecipient($arr[1]))
		   {
		     debug::printf(LOG_ERR,"Recipient %s rejected\n",$arr[1]);
		     $this->ev_write($ctx, "550 Requested action not taken: mailbox unavailable\r\n");
		     break;
		   }
		   $ctx->enveloppe['rcpt'][]=$arr[1];
		   $this->ev_write($ctx, "250 OK rcpt\r\n");
		   $ctx->state=self::STATE_HEADER;
		   break;
		 }
	      }
	      $this->ev_write($ctx, "501 RCPT - Syntax error in parameters or arguments\r\n");
	      break;
	  case strncasecmp('DATA', $line, 4):
	      if ($ctx->state!=self::STATE_HEADER||
		  count($ctx->enveloppe['rcpt'])==0||
		  !isset($ctx->enveloppe['mailfrom']))
	      {
		$this->ev_write($ctx, "503 DATA Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      if (strlen($line)!=4) {
		 $this->ev_write($ctx, "501 Syntax error in parameters or arguments\r\n");
		 break;
	      }
	      $this->ev_write($ctx, "354 Start mail input; end with <CRLF>.<CRLF>\r\n");
	      $ctx->state=self::STATE_DATA;
	      break;
	  case strncasecmp('RSET', $line, 4):
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "503 Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      $ctx->state=self::STATE_HELO;
	      unset($ctx->enveloppe['rcpt']);
	      unset($ctx->enveloppe['mailfrom']);
	      $this->ev_write($ctx, "250 OK rset\r\n");
	      break;
	  case strncasecmp('NOOP', $line, 4):
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "503 Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      $this->ev_write($ctx, "250 OK noop\r\n");
	      break;

	  // http://www.postfix.org/XFORWARD_README.html
	  // xforward-command = XFORWARD 1*( SP attribute-name"="attribute-value )
	  // attribute-name = ( NAME | ADDR | PORT | PROTO | HELO | IDENT | SOURCE )
	  // attribute-value = xtext
	  // Attribute values are xtext encoded as per RFC 1891.
	  //
	  // i use NetTool::decode_xtext to decode XFORWARD arguments
	  case strncasecmp('XFORWARD ', $line, 9):
	      if (!$ctx->xforward) 
	      {
		$this->ev_write($ctx, "502 Command not implemented\r\n");
		break;
	      }
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "503 Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      $xforward=substr($line,9);
              $xforward_attrsret=$this->xcmdargs_check($xforward,self::$xforward_name);
	      if (is_array($xforward_attrsret))
	      {
		if (isset($ctx->enveloppe['xforward']))
		  $ctx->enveloppe['xforward']=array_merge($ctx->enveloppe['xforward'],$xforward_attrs);
		debug::printf(LOG_NOTICE,"XFORWARD Attributs <%s> are valid!\n",$xforward);
		$this->ev_write($ctx, "250 XFORWARD attribut are ok\r\n");
	      }
	      else
	      {
		if ($xforward_attrsret!==false)
		{
		  debug::printf(LOG_NOTICE,"XFORWARD Error on <%s>, <%s> attribut not conform\n",$xforward,$xforward_attrsret);
		  $this->ev_write($ctx, "501 XFORWARD Error, <".$xforward_attrsret."> attribut not conform!\r\n");
		}
		else
		{
		  debug::printf(LOG_NOTICE,"XFORWARD Error on <%s>, syntax error!\n",$xforward);
		  $this->ev_write($ctx, "501 XFORWARD Syntax Error !\r\n");
		}
	      }
	      break;

	  // http://www.postfix.org/XCLIENT_README.html
	  // xclient-command = XCLIENT 1*( SP attribute-name"="attribute-value )
	  // attribute-name = ( NAME | ADDR | PORT | PROTO | HELO | LOGIN )
	  // attribute-value = xtext
	  // Attribute values are xtext encoded as per RFC 1891.
	  //
	  // i use NetTool::decode_xtext to decode XFORWARD arguments
	  case strncasecmp('XCLIENT ', $line, 8):
	      if (!$ctx->xclient) 
	      {
		$this->ev_write($ctx, "502 Command not implemented\r\n");
		break;
	      }
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "503 Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      $xclient=substr($line,8);
              $xclient_attrsret=$this->xcmdargs_check($xclient,self::$xclient_name);
	      if (is_array($xclient_attrsret))
	      {
		$ctx->enveloppe['xclient']=$xclient_attrsret;
		debug::printf(LOG_NOTICE,"XCLIENT Attributs <%s> are valid!\n",$xclient);
		$this->ev_write($ctx, "220 XCLIENT attribut are ok\r\n");
	      }
	      else
	      {
		debug::printf(LOG_NOTICE,"XCLIENT Error on <%s>, <%s> attribut not conform\n",$xclient,$xclient_attrsret);
		$this->ev_write($ctx, "501 XCLIENT Error, <".$xclient_attrsret."> attribut not conform!\r\n");
	      }
	      break;
	  case strncasecmp('STARTTLS', $line, 8):
	      if (!$ctx->tls) 
	      {
		$this->ev_write($ctx, "502 Command not implemented\r\n");
		break;
	      }
	      $this->ev_write($ctx, "220 Ready to start TLS\r\n");
	      debug::printf(LOG_NOTICE,"Entering in TLS handchecking...\n");

	      // upgrade the connexion to TLS
	      $ctx->cnx->disable(Event::READ | Event::WRITE);
	      $ev_options= EventBufferEvent::SSL_ACCEPTING|EventBufferEvent::SSL_OPEN;
              // $ev_options |= EventBufferEvent::OPT_CLOSE_ON_FREE;
	      $cnx = EventBufferEvent::sslFilter($ctx->base, $ctx->cnx, $ctx->sslctx,$ev_options);
	      if (!$cnx)
	      {
		  $ctx->cnx->free();
		  $cnx->free();
		  $ctx->ev_close($ctx);
		  debug::exit_with_error(63,"Couldn't create ssl bufferevent\n");
		  break;
	      }
	      //$ctx->cnx->free();
	      $ctx->cnx=$cnx;
	      $ctx->tlsenabled=true;
	      $ctx->cnx->setTimeouts($ctx->read_timeout,$ctx->write_timeout);
	      $ctx->cnx->setCallbacks([$ctx, "ev_read"], NULL, [$ctx, 'ev_error'], $ctx);
	      $ctx->cnx->enable(Event::READ);
	      if(gc_enabled()) gc_collect_cycles();

	      // reset of the connection
	      // go to state HELO
	      $ctx->state=self::STATE_HELO;
	      unset($ctx->enveloppe);
	      break;

	  case strncasecmp('VRFY', $line, 4):
	      if (($ctx->state!=self::STATE_HELO)
		&& ($ctx->state!=self::STATE_HEADER))
	      {
		$this->ev_write($ctx, "503 Bad sequence of commands\r\n");
		$this->ev_close($ctx);
		break;
	      }
	      if (preg_match('/^VRFY <(.*)>$/i', $line, $arr)==1)
	      {
		 debug::printf(LOG_NOTICE,"VRFY: <%s>\n",$arr[1]);
		 if (RFC822::is_valid_email_address($arr[1]))
		 {
		   debug::printf(LOG_NOTICE,"VRFY <%s> address are valid\n",$arr[1]);
		   if (!$this->checkrecipient($arr[1]))
		   {
		     debug::printf(LOG_NOTICE,"VRFY %s address unknown\n",$arr[1]);
		     $this->ev_write($ctx, "550 Requested action not taken: mailbox unavailable\r\n");
		     break;
		   }
		   debug::printf(LOG_NOTICE,"VRFY %s address known\n",$arr[1]);
		   $this->ev_write($ctx, "250 ".$arr[1]."\r\n");
		   break;
		 }
	      }
	      $this->ev_write($ctx, "501 RCPT - Syntax error in parameters or arguments\r\n");
	      break;
	  case strncasecmp('EXPN', $line, 4):
	  case strncasecmp('SAML', $line, 4):
	  case strncasecmp('SOML', $line, 4):
	  case strncasecmp('SEND', $line, 4):
	  case strncasecmp('HELP', $line, 4):
	  case strncasecmp('TURN', $line, 4):
	  case strncasecmp('ETRN', $line, 4):
	      $this->ev_write($ctx, "502 Command not implemented\r\n");
	      break;
	  default:
	      debug::printf(LOG_ERR, 'unknown command: '.$line."\n");
	      $this->ev_write($ctx, "500 Syntax error, command unrecognised\r\n");
	      break;
      }
  }

  private function xcmdargs_check($xcmdargs,$autorized_attr)
  {
     if (preg_match_all("/(\w+)=(\w+)/",$xcmdargs,$arr)>=1)
     {
	debug::print_r(LOG_DEBUG,$arr);
	$attrs=array();
	foreach($arr[1] as $key => $args)
	{
	   $xattr=strtoupper($args);
	   debug::printf(LOG_DEBUG,"Check for attr:<%s>\n",$xattr);
	   if ($xattr=="")
	      return false;
	   if (!in_array($xattr,$autorized_attr))
	     return $xattr;
	   $attrs[$xattr]=NetTool::decode_xtext($arr[2][$key]);
	}
	debug::print_r(LOG_DEBUG,$attrs);
	return $attrs;
     }
     return false;
  }

}

