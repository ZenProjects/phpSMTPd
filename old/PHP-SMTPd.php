#!/opt/srv/php/bin/php
<?php


require_once("includes/mailaddressrfc822.php");
require_once("includes/Mail/Mail/RFC822.php");
 /*
 * Original Author: Andrew Rose <hello at andrewrose dot co dot uk>
 * Author: Mathieu CARBONNEAUX 
 *
 * Usage:
 * 1) Prepare cert.pem certificate and privkey.pem private key files.
 * 2) Launch the server script
 * 3) Open TLS connection, e.g.:
 *      $ openssl s_client -connect localhost:25 -starttls smtp -crlf
 * 4) Start testing the commands listed in `cmd` method below.
 */

class PHP_SMTPd 
{
  public $listener = null;
  public $bev = null;
  public $connections = [];
  public $buffers = [];
  public $maxRead = 256000;
  // http://tools.ietf.org/html/rfc1869#section-4.1.2 => 
  // 512 + BODY=8BITMIME : 5+8+1=14 + SIZE=01234567890123456789 : 5+20+1=26 = 552
  public $maxcommandlinesize = 552; 
  public $maxmessagesize = false;
  public $hostname = false;
  public $xclient = false;
  public $xforward = true;
  public $tls = false;
  public $crlf = true;
  public $read_timeout = 300; // 300s
  public $write_timeout = 300; // 300s
  const STATE_CONNECT = 1;
  const STATE_HELO = 2;
  const STATE_HEADER = 3;
  const STATE_DATA = 4;

  public function __construct($connect_string=STDIN) 
  {
      $this->hostname=$this->getFQDNHostname();
      $this->maxmessagesize=15*1024*1024;
      openlog("PHP-SMTPd", LOG_PID, LOG_MAIL);
      $this->debug_printf( "Starting ESMP Serveur on ".$connect_string." at ".$this->hostname."\n");
      cli_set_process_title("PHP-SMTPd daemon");

      $this->ctx = array( 'sslctx' => new EventSslContext(EventSslContext::SSLv3_SERVER_METHOD, [
	  EventSslContext::OPT_LOCAL_CERT  => '/etc/ssl/startssl.ch2o.info.chained.crt',
	  EventSslContext::OPT_LOCAL_PK    => '/etc/ssl/startssl-decrypted.key',
	  //EventSslContext::OPT_PASSPHRASE  => '',
	  EventSslContext::OPT_VERIFY_PEER => false, // change to true with authentic cert
	  EventSslContext::OPT_ALLOW_SELF_SIGNED => true // change to false with authentic cert
      ]));

      $this->base = new EventBase();
      if (!$this->base) 
      {
	  $this->debug_die("Couldn't open event base\n");
      }

      if (is_string($connect_string))
      {
	if (!$this->listener = new EventListener($this->base,
						 [$this, 'ev_accept'],
						 $this->ctx,
						 EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
						 -1,
						 $connect_string))
	{
	    $this->debug_die("Couldn't create listener\n");
	}

	$this->listener->setErrorCallback([$this, 'ev_error_listener']);
      }
      else if (is_resource($connect_string))
      {
        $id = $this->getNewID();
        if (!$this->connections[$id]['cnx'] = new EventBufferEvent($this->base, $connect_string, EventBufferEvent::OPT_CLOSE_ON_FREE))
	{
	    $this->debug_die("Couldn't create bufferevent\n");
	}

	$this->connections[$id]['cnx']->setCallbacks([$this, "ev_read"], NULL, [$this, 'ev_error'], $id);
	$this->connections[$id]['cnx']->enable(Event::READ);

	$address=stream_socket_get_name($connect_string,true);
	if($address!==false)
	{
	  if (preg_match("/([0-9]+[.][0-9]+[.][0-9]+[.][0-9]+)[:]([0-9]+)/",$address,$arr)==1)
	  {
	    $parsed_address=array(0=>$arr[1],1=>$arr[2]);
	    $this->debug_printf("parsed peer address:%s\n",print_r($parsed_address,true));
	  }
	  $this->debug_printf("peer address:%s\n",$address);
          $this->connections[$id]['address']=$address;
	}

	$this->ev_write($id, '220 '.$this->hostname." ESMTP - PHP-SMTPd at ".gmdate('r')." on stdin\r\n");
      }
      else
      {
        $this->debug_printf( "unknown arguments ".print_r($connect_string,true)."\n");
        exit(1);
      }
      $this->base->dispatch();
  }

  public function getNewID() 
  {
      static $id = 0;
      $id += 1;

      $this->connections[$id]['clientData'] = '';
      $this->connections[$id]['state']=self::STATE_CONNECT;
      $this->connections[$id]['clientData'] = "";
      $this->connections[$id]['clientDataLen'] = 0;
      return $id;
  }

  public function ev_accept($listener, $fd, $address, $ctx) 
  {
      $id = $this->getNewID();

      $this->connections[$id]['cnx'] = new EventBufferEvent($this->base, $fd,
	  EventBufferEvent::OPT_CLOSE_ON_FREE);
      if (!$this->connections[$id]['cnx']) 
      {
	  $this->debug_printf( "Failed creating buffer\n");
	  $this->base->exit(NULL);
	  $this->debug_die(1);
      }

      $this->connections[$id]['cnx']->setTimeouts($this->read_timeout,$this->write_timeout);
      $this->connections[$id]['address']=$address;
      $this->connections[$id]['cnx']->setCallbacks([$this, "ev_read"], NULL, [$this, 'ev_error'], $id);
      $this->connections[$id]['cnx']->enable(Event::READ | Event::WRITE);

      $this->ev_write($id, '220 '.$this->hostname." ESMTP - PHP-SMTPd at ".gmdate('r')."\r\n");
  }

  function ev_error_listener ($listener, $ctx)
  {
     ev_error($listener, EventBufferEvent::ERROR, $ctx);
  }
  
  function ev_error($buffer, $events, $ctx) 
  {
     if ($events & (EventBufferEvent::ERROR | 
		   EventBufferEvent::TIMEOUT))
     {
	$errno = EventUtil::getLastSocketErrno();

	if ($errno != 0&&$errno != 11) 
	{
	    $this->debug_printf( "Got an error %d (%s) on the listener. Shutting down.\n",
		$errno, EventUtil::getLastSocketError());

	    $this->base->exit(NULL);
	    $this->debug_die();
	}
	if ($errno == 11 || $errno == 104) 
	{
	   //$this->ev_close();
	   //$this->debug_print_r($listener);
	   $fd=$listener->fd;
	   foreach($this->connections as $key => $value)
	   {
	      if ($value['cnx']->fd == $fd)
	      {
		$address=$this->connections[$key]['address'];
		$this->debug_printf( "Client has disconected %s:%s\n",$address[0],$address[1]);

		//$this->debug_print_r($value);
		$this->ev_close($key);
	      }
	   }
	}
     }
  }

  public function ev_close($id,$drain=false) 
  {
       $output_buffer=$this->connections[$id]['cnx']->getOutput();
       $input_buffer=$this->connections[$id]['cnx']->getInput();
       if ($output_buffer->length>0) 
       {
	     /* We still have to flush data from the other
	      * side, but when that's done, close the other
	      * side. */
	     $this->connections[$id]['cnx']->disable(Event::READ);
	     if ($drain) while($input_buffer->length > 0) $input_buffer->drain($this->maxRead);
	     $this->connections[$id]['cnx']->setCallbacks(NULL, [$this, 'ev_close_on_finished_writecb'], NULL, $id);
       } else {
	     /* We have nothing left to say to the other
	      * side; close it. */
	     $this->connections[$id]['cnx']->disable(Event::READ | Event::WRITE);
	     if ($drain) while($input_buffer->length > 0) $input_buffer->drain($this->maxRead);
	     $this->connections[$id]['cnx']->free();
	     unset($this->connections[$id]);
       }
  }

  public function ev_close_on_finished_writecb($buffer, $id) 
  {
      if ($this->connections[$id]['cnx']->getOutput()->length==0)
      {
	$this->connections[$id]['cnx']->disable(Event::READ | Event::WRITE);
	$this->connections[$id]['cnx']->free(); 
	unset($this->connections[$id]);
      }
  }

  protected function ev_write($id, $string) 
  {
      $this->debug_printf('S('.$id.'): '.$string);
      $this->connections[$id]['cnx']->write($string);
  }

  public function ev_read($buffer, $id) 
  {
      $this->debug_printf("event read length:%s\n",$buffer->input->length);
      while($buffer->input->length > 0) 
      {
	  $lastread= $buffer->input->read($this->maxRead);
	  $lastLen = strlen($lastread);
          $this->debug_printf("event data read:-%s-\n",$lastread);

	  $this->connections[$id]['clientData'] .= $lastread;
	  $this->connections[$id]['clientDataLen'] += $lastLen;

	  if ($this->connections[$id]['state']!=self::STATE_DATA)
	  {
            $this->debug_printf("cmd state:%s clientDataLen:%d\n",$this->connections[$id]['state'],$this->connections[$id]['clientDataLen']);
	    if ($this->connections[$id]['clientDataLen']>$this->maxcommandlinesize)
	    {
	       $this->debug_printf("Command line size (%s) exceeds fixed maximium line size (%s)\n",$this->connections[$id]['clientDataLen'],$this->maxcommandlinesize);
	       $this->ev_write($id, "500 command line size exceeds fixed maximium line size\r\n");
	       $this->ev_close($id);
	       return;
	    }
	    $endofblock=substr($this->connections[$id]['clientData'],$this->connections[$id]['clientDataLen']-2);
	    $endofblock1=substr($this->connections[$id]['clientData'],$this->connections[$id]['clientDataLen']-1);
	    $this->debug_printf("endofblock:<%s> endofblock1:<%s>\n",urlencode($endofblock),urlencode($endofblock1));
	    // read SMTP cmd<CRLF>
	    if($endofblock == "\r\n"||($this->crlf&&$endofblock1 == "\n"))
	    {
		// remove the trailing \r\n
		$line = substr($this->connections[$id]['clientData'], 0, $this->connections[$id]['clientDataLen'] - 2);

		$this->connections[$id]['clientData'] = '';
	        $this->connections[$id]['clientDataLen']=0;
		$this->SMTPcmd($buffer, $id, $line);
	    }
	  }
	  else
	  {
            $this->debug_printf("data state clientDataLen:%d\n",$this->connections[$id]['clientDataLen']);
	    if ($this->connections[$id]['clientDataLen']>$this->maxmessagesize)
	    {
		$this->debug_printf("Messages data size exceeds fixed maximium message size\n");
		$this->ev_write($id, "552 message size exceeds fixed maximium message size\r\n");
		$this->ev_close($id);
		return;
	    }
	    $endofblock=substr($this->connections[$id]['clientData'],$this->connections[$id]['clientDataLen']-5);
	    $endofblock1=substr($this->connections[$id]['clientData'],$this->connections[$id]['clientDataLen']-3);
	    //$this->debug_printf("endofblock:<%s> "endofblock1:<%s>\n",urlencode($endofblock),urlencode($endofblock1));
	    //$this->debug_printf("endofblock:<%s>\n",urlencode($endofblock));
	    // read data to the <CRLF>.<CRLF> final
	    if($endofblock=="\r\n.\r\n"||($this->crlf&&$endofblock1=="\n.\n"))
	    {
	      $this->debug_printf("Messages data has been succesfuly recepted\n");
	      $this->debug_print_r($this->connections[$id]);
	      $this->debug_print_r($this->mimeDecode($this->connections[$id]['clientData']));
	      $this->ev_write($id, "250 Messages data has been recepted\r\n");
	      $this->connections[$id]['state']=self::STATE_HELO;
	      $this->connections[$id]['clientData']="";
	      $this->connections[$id]['clientDataLen']=0;
	    }
	  }
      }
  }


  function debug_die($arg)
  {
     syslog(LOG_ERR,printf("Exit with %s\n",$arg));
     exit($arg);
  }

  function debug_print_r($array)
  {
     syslog(LOG_DEBUG,print_r($array,true));
  }

  function debug_printf()
  {
     $num_args = func_num_args();
     if ($num_args==0) return;
     $args=func_get_args();
     unset($args[0]);
     syslog(LOG_ERR,vsprintf(func_get_arg(0),$args));
  }

  function getFQDNHostname() 
  {
     $fd=popen("hostname -f","r");
     $fdqnhostname=fgets($fd,4096);
     fclose($fd);
     $fdqnhostname=str_replace("\r\n", "", $fdqnhostname);
     $fdqnhostname=str_replace("\n", "", $fdqnhostname);
     if ($fdqnhostname=="") $fdqnhostname=gethostname();
     return $fdqnhostname;
  }

  protected function getrfc822Address($buffer)
  {
    // remove BATV http://en.wikipedia.org/wiki/Bounce_Address_Tag_Validation
    if (preg_match("/^prvs=[0-9]{4}[0-9A-Fa-f]{5,6}=(.*\..*)$/",$buffer,$arr)==1)
	$mail_address=$arr[1];
    preg_match('/[\\w\\.\\-+=*_]*@[\\w\\.\\-+=*_]*/', $mail_address , $regs);
    return $regs[0];
  }

  protected function mimeDecode($buffer)
  {
    $parsed_mail=array();
    $mail = mailparse_msg_create(); 
    mailparse_msg_parse($mail, $buffer); 
    $struct = mailparse_msg_get_structure($mail);  

    foreach ($struct as $key => $st) 
    {
      $this->debug_printf("st:%s\n",$st);
      $section = mailparse_msg_get_part($mail, $st);  
      $info    = mailparse_msg_get_part_data($section);  
      $parsed_mail[$key]['st']=$st;
      $parsed_mail[$key]['struct']=$info;
      if (($info['content-type']!="multipart/alternative")&&
          ($info['content-type']!="multipart/mixed"))
      {
	 $msg_part= mailparse_msg_extract_part($section,$buffer,NULL);
	 //$this->debug_printf("part(%s)=<<<<<\n%s\n>>>>>\n",$key, $msg_part);
	 $parsed_mail[$key]['data']=$msg_part;
      }
    }
    return $parsed_mail;
  }

  protected function SMTPcmd($buffer, $id, $line) 
  {
      switch ($line) 
      {
	  case strncasecmp('EHLO ', $line, 5):
	  case strncasecmp('HELO ', $line, 5):
	      $this->ev_write($id, "250-".$this->hostname." offers many extensions\r\n");
	      $this->ev_write($id, "250-8BITMIME\r\n");
	      if ($this->tls) $this->ev_write($id, "250-STARTTLS\r\n");
	      if ($this->xclient) $this->ev_write($id, "250-XCLIENT NAME ADDR PORT HELO\r\n");
	      if ($this->xforward) $this->ev_write($id, "250-XFORWARD NAME ADDR PORT PROTO HELO IDENT SOURCE\r\n");
	      $this->ev_write($id, "250 SIZE ".$this->maxmessagesize."\r\n");
	      $this->connections[$id]['state']=self::STATE_HELO;
	      $this->connections[$id]['helo-host']=substr($line,4);
	      unset($this->connections[$id]['mail-options']);
	      unset($this->connections[$id]['reverse-path']);
	      unset($this->connections[$id]['rcpt']);
	      break;

	  case strncasecmp('QUIT', $line, 4):
	      $this->ev_write($id, "221 OK quit\r\n");
	      $this->ev_close($id);
	      break;
	  case strncasecmp('MAIL ', $line, 5):
	      if (($this->connections[$id]['state']!=self::STATE_HELO)
		&& ($this->connections[$id]['state']!=self::STATE_HEADER))
	      {
		$this->ev_write($id, "500 Mail before helo\r\n");
		$this->ev_close($id);
		break;
	      }
	      if (preg_match('/^MAIL FROM:<(.*)> *(.*)$/i', $line, $arr)==1)
	      {
		 $this->debug_printf("MAIL: from:<%s> option:<%s>\n",$arr[1],$arr[2]);
		 if (preg_match('/SIZE=([0-9]*)/i', $arr[2], $sizes)==1)
		 {
		   $this->debug_printf("Message estimated size %s are lager than max message size %s ?\n",$sizes[1],$this->maxmessagesize);
		   if ($sizes[1]>$this->maxmessagesize) 
		   {
		     $this->ev_write($id, "552 message size exceeds fixed maximium message size\r\n");
		     break;
		   }
		 }
		 if (is_valid_email_address($arr[1]))
		 {
		   $mail_address=$arr[1];
		   $mail_options=$arr[2];
		   $this->connections[$id]['reverse-path']=$mail_address;
		   $this->connections[$id]['mail-options']=$mail_options;

		   $this->debug_printf("MAIL %s Address are valide\n",$mail_address);
		   $this->connections[$id]['reverse-pathdecoded']=mailparse_rfc822_parse_addresses($mail_address);
		   $this->connections[$id]['reverse-path-address']=$this->getrfc822Address($mail_address);
		   $this->ev_write($id, "250 OK mail\r\n");
		   $this->connections[$id]['state']=self::STATE_HEADER;
		   break;
		 }
	      }
	      $this->ev_write($id, "501 MAIL - Syntax error in parameters or arguments\r\n");
	      break;
	  case strncasecmp('RCPT ', $line, 5):
	      if (($this->connections[$id]['state']!=self::STATE_HELO)
		&& ($this->connections[$id]['state']!=self::STATE_HEADER))
	      {
		$this->ev_write($id, "503 RCPT Bad sequence of commands\r\n");
		$this->ev_close($id);
		break;
	      }
	      if (preg_match('/^RCPT TO:<(.*)>$/i', $line, $arr)==1)
	      {
		 $this->debug_printf("RCPT: to:<%s>\n",$arr[1]);
		 if (is_valid_email_address($arr[1]))
		 {
		   $this->debug_printf("TO %s Address are valide\n",$arr[1]);
		   $this->connections[$id]['rcpt'][]=$arr[1];
		   $this->ev_write($id, "250 OK rcpt\r\n");
		   $this->connections[$id]['state']=self::STATE_HEADER;
		   break;
		 }
	      }
	      $this->ev_write($id, "501 RCPT - Syntax error in parameters or arguments\r\n");
	      break;
	  case strncasecmp('DATA', $line, 4):
	      if ($this->connections[$id]['state']!=self::STATE_HEADER||
		  count($this->connections[$id]['rcpt'])==0||
		  !isset($this->connections[$id]['reverse-path']))
	      {
		$this->ev_write($id, "503 DATA Bad sequence of commands\r\n");
		$this->ev_close($id);
		break;
	      }
	      if (strlen($line)!=4) {
		 $this->ev_write($id, "501 Syntax error in parameters or arguments\r\n");
		 break;
	      }
	      $this->ev_write($id, "354 Start mail input; end with <CRLF>.<CRLF>\r\n");
	      $this->connections[$id]['state']=self::STATE_DATA;
	      break;
	  case strncasecmp('RSET', $line, 4):
	      if (($this->connections[$id]['state']!=self::STATE_HELO)
		&& ($this->connections[$id]['state']!=self::STATE_HEADER))
	      {
		$this->ev_write($id, "503 Bad sequence of commands\r\n");
		$this->ev_close($id);
		break;
	      }
	      $this->connections[$id]['state']=self::STATE_HELO;
	      unset($this->connections[$id]['reverse-path']);
	      unset($this->connections[$id]['rcpt']);
	      unset($this->connections[$id]['mail-options']);
	      $this->ev_write($id, "250 OK rset\r\n");
	      break;
	  case strncasecmp('NOOP', $line, 4):
	      if (($this->connections[$id]['state']!=self::STATE_HELO)
		&& ($this->connections[$id]['state']!=self::STATE_HEADER))
	      {
		$this->ev_write($id, "503 Bad sequence of commands\r\n");
		$this->ev_close($id);
		break;
	      }
	      $this->ev_write($id, "250 OK noop\r\n");
	      break;

	  case strncasecmp('XFORWARD ', $line, 9):
	      if (!$this->xforward) 
	      {
		$this->ev_write($id, "502 Command not implemented\r\n");
		break;
	      }
	      if (($this->connections[$id]['state']!=self::STATE_HELO)
		&& ($this->connections[$id]['state']!=self::STATE_HEADER))
	      {
		$this->ev_write($id, "503 Bad sequence of commands\r\n");
		$this->ev_close($id);
		break;
	      }
	      $xforward=substr($line,9);
	      if (preg_match('/SOURCE=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['source']=$matchs[1]; 
	      }
	      if (preg_match('/IDENT=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['ident']=$matchs[1]; 
	      }
	      if (preg_match('/HELO=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['helo']=$matchs[1]; 
	      }
	      if (preg_match('/PROTO=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['proto']=$matchs[1]; 
	      }
	      if (preg_match('/PORT=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['port']=$matchs[1]; 
	      }
	      if (preg_match('/ADDR=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['addr']=$matchs[1]; 
	      }
	      if (preg_match('/NAME=(\w*)/i', $xforward, $matchs)==1)
	      {
                $this->connections[$id]['xforward']['name']=$matchs[1]; 
	      }
	      //$this->debug_print_r($this->connections[$id]['xforward']);
	      $this->debug_printf("XFORWARD: '%s'\n",$xforward);
	      $this->ev_write($id, "250 OK xforward\r\n");
	      break;
	  case strncasecmp('XCLIENT ', $line, 8):
	      if (!$this->xclient) 
	      {
		$this->ev_write($id, "502 Command not implemented\r\n");
		break;
	      }
	      if (($this->connections[$id]['state']!=self::STATE_HELO)
		&& ($this->connections[$id]['state']!=self::STATE_HEADER))
	      {
		$this->ev_write($id, "503 Bad sequence of commands\r\n");
		$this->ev_close($id);
		break;
	      }
	      $xclient=substr($line,8);
	      $this->connections[$id]['xclient']=$xclient;
	      $this->debug_printf("XCLIENT: <%s>\n",$xclient);
	      $this->ev_write($id, "220 OK xclient\r\n");
	      break;
	  case strncasecmp('STARTTLS', $line, 8):
	      if (!$this->tls) 
	      {
		$this->ev_write($id, "502 Command not implemented\r\n");
		break;
	      }
	      $this->ev_write($id, "220 Ready to start TLS\r\n");
	      $this->connections[$id]['cnx'] = EventBufferEvent::sslFilter($this->base,
		  $this->connections[$id]['cnx'], $this->ctx['sslctx'],
		  EventBufferEvent::SSL_ACCEPTING,
		  EventBufferEvent::OPT_CLOSE_ON_FREE);
	      $this->connections[$id]['cnx']->setCallbacks([$this, "ev_read"], NULL, [$this, 'ev_error'], $id);
	      $this->connections[$id]['cnx']->enable(Event::READ | Event::WRITE);
	      break;

	  case strncasecmp('VRFY', $line, 4):
	  case strncasecmp('EXPN', $line, 4):
	  case strncasecmp('SAML', $line, 4):
	  case strncasecmp('SOML', $line, 4):
	  case strncasecmp('SEND', $line, 4):
	  case strncasecmp('HELP', $line, 4):
	  case strncasecmp('TURN', $line, 4):
	  case strncasecmp('ETRN', $line, 4):
	      $this->ev_write($id, "502 Command not implemented\r\n");
	      break;
	  default:
	      $this->debug_printf( 'unknown command: '.$line."\n");
	      $this->ev_write($id, "500 Syntax error, command unrecognised\r\n");
	      break;
      }
  }
}

if ($argc==2) 
new PHP_SMTPd($argv[1]);
else
new PHP_SMTPd();
