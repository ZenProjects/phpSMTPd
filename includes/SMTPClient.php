<?php
namespace SuperListd;

use EventBufferEvent;
use EventBuffer;
use EventBase;
use EventSslContext;
use Event;
use EventUtil;

include_once("Debug.php");
include_once("NetTool.php");
include_once("SSL.php");
/*
 * Author: Mathieu CARBONNEAUX 
 * Event based SMTP Client
 *
 * Implement client part of SMTP, and implement this SMTP verbs: EHLO/HELO, STARTTLS, XFORWARD, XCLIENT, MAIL FROM, RCPT TO, QUIT
 * no need HELP, SEND, SAML, SOML, TURN, ETRN verbs in this client
 * for the moment no support for VRFY/EXPN, but possible addition to VRFY/EXPN support in future to check address
 * based largely on D.J. Berstein (author of QMAIL) implementation notes: http://cr.yp.to/smtp.html
 * Conform with ESMTP standard RFC 1869 and implement this extension : 8BITMIME, STARTTLS, SIZE, XCLIENT, XFORWARD
 */

class SMTPClient 
{
  const CONNECT 	= 0;
  const HELO 		= 1;
  const STARTTLS	= 2;
  const MAILFROM	= 3;
  const RCPT 		= 4;
  const DATA 		= 5;
  const QUIT 		= 6;
  const CLOSE 		= 7;
  const RESPONSE	= 8;

  const RESPONSE_OK	= true;
  const RESPONSE_KO	= false;
  const RESPONSE_CONT	= 100;

  private static $xforward_name = array("NAME","ADDR","PORT","PROTO","HELO","IDENT","SOURCE");
  private static $xclient_name = array("NAME","ADDR","PROTO","HELO");

  public $readtimeout = 30;	        // socket read timeout
  public $writetimeout = 30;	        // socket write timeout
  public $ipv6 = false;                 // resolve ipv6 first or only ipv4 (ipv4 only by default)
  public $tls = false;                  // activate Starttls Client Support if the server annonce support (tls is off by default)
  public $forceehlo = true;             // send by default EHLO if forceehlo is set to true or if the server as the ESMTP support
  public $ssl_verify_peer = false;      // ssl client verify peer
  public $ssl_allow_self_signed = true; // ssl client accept self signed server certificat

  public $returncode = false;

  // the eventbase used to exchange smtp message to the server
  private $base = null;
  // the eventbufferevent of the connection
  private $bev = null;
  // sslcontext of the starttls connection
  private $sslctx = null;

  // stats machine position
  private $state = self::CONNECT;
  private $tls_activated = false;

  // current recipient to send message
  private $recipient = null; 

  // EHLO server information
  private $server_estmp=false;
  private $server_maxsize=null;
  private $server_tls=false;
  private $server_8bitmime=false;
  private $server_xclient=array();
  private $server_xforward=array();

  // message informations
  private $helohost = null;  // hostname send by EHLO/HELO
  private $mailfrom = null;  // mail from adresse
  private $rcpt = array();   // recipient list to send the massage
  private $data = null;      // the message data
  private $datalen = null;   // the message data len

  // command response parsing return
  private $arguments = array();
  private $code = 0;

  public function __construct($eventbase=null)
  {
    if ($eventbase==null) $this->base = new EventBase();
    else $this->base = $eventbase;
    if (!$this->base) 
       throw new SMTPException(LOG_ERR,"Couldn't open event base\n");
  }

  public function send($relayhost=null,$relayport=25)
  {
    debug::printf(LOG_INFO,"SMTPClient Send begin");

    if ($this->tls === true)
    {
	 // We *must* have entropy. Otherwise there's no point to crypto.
	 if (!EventUtil::sslRandPoll()) 
	     throw new SMTPException(LOG_ERR,"EventUtil::sslRandPoll failed\n");

	 $sslcontext_options = array(
	     //EventSslContext::OPT_CIPHERS  => SSL::DEFAULT_CIPHERS_OLD,
	     //EventSslContext::OPT_VERIFY_PEER => $this->ssl_verify_peer, // per default verify server peer
	     //EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->ssl_allow_self_signed, // per default accepte self signed server certificate
	 );

	 // prepare sslctx for starttls smtp extension
	 $this->sslctx = new EventSslContext(EventSslContext::SSLv23_CLIENT_METHOD, $sslcontext_options);
	 if (!$this->sslctx)
             new SMTPException(LOG_ERR,"SSL Context error!\n");
         debug::printf(LOG_INFO,"STARTTLS option activated, SSLContext defined.\n");
    }
    if ($this->helohost===null)
    {
       $hostname=NetTool::getFQDNHostname();
       debug::printf(LOG_INFO,"HeloHost attribut not set use system hostname:%s!\n",$hostname);
       $this->helohost=$hostname;
    }
    if ($this->mailfrom===null)
       throw new SMTPException(LOG_ERR,"MailFrom attribut not set!\n");
    if ($this->rcpt===null)
       throw new SMTPException(LOG_ERR,"Recipient attribut not set!\n");
    if ($this->data===null)
       throw new SMTPException(LOG_ERR,"Data attribut not set!\n");

    foreach($this->rcpt as $recipient)
    {
      if (preg_match("/^([a-zA-Z0-9_.+-]+)@([a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*)$/",$recipient,$arr)!==1)
         throw new SMTPException(LOG_ERR,"Recipient <%s> attribut malformed!\n",$recipient);
    }
    if ($relayhost!==null) 
    {
       debug::printf(LOG_INFO,"Use the relay host <%s:%s> to send the message...\n",$relayhost,$relayport);
       if (($socket=$this->socketConnect($relayhost,$relayport,$this->ipv6))===false) 
	   throw new SMTPException(LOG_ERR,"Cannot connect to the host %s:%s!\n",$relayhost,$relayport);
       //$this->bev = new EventBufferEvent($this->base, $socket, EventBufferEvent::OPT_DEFER_CALLBACKS);
       $this->bev = new EventBufferEvent($this->base, $socket, 0);
       if (!$this->bev) 
	   throw new SMTPException(LOG_ERR,"Fail when creating socket bufferevent\n");

       $this->bev->setCallbacks(array($this,"readcb"), /* writecb */ NULL, array($this,"eventcb"), $this);
       $this->bev->enable(Event::READ | Event::WRITE);
       $this->bev->setTimeouts($this->readtimeout,$this->writetimeout);
    }

    debug::print_r(LOG_DEBUG,$this->rcpt);
    foreach($this->rcpt as $recipient)
    {
      debug::printf(LOG_INFO,"Send message for the recipient <%s>...\n",$recipient);
      // check if connect by relayhost or by mx
      if ($relayhost===null)
      {
	// connection by mx
        if (preg_match("/^([a-zA-Z0-9_.+-]+)@([a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*)$/",$recipient,$arr)===1)
	{
	   if (($mxs=NetTool::getmx($arr[2]))===false)
	       throw new SMTPException(LOG_ERR,"No MX found for host %s!\n",$arr[2]);
	   debug::print_r(LOG_DEBUG,$mxs);

	   $socket=false;
	   foreach($mxs as $mxhost => $mxweight)
	   {
	     debug::printf(LOG_INFO,"Use the MX host <%s:25> for this recipient to send the message...\n",$mxhost);
	     $socket=$this->socketConnect($mxhost,25,$this->ipv6);
	     if ($socket!==false) break;
	     debug::printf(LOG_ERR,"Cannot connect to <%s> try next one!\n",$mxhost.":25");
	   }
           if ($socket===false)
	         throw new SMTPException(LOG_ERR,"Cannot connect to the mx host!\n");
	}
	else
	   throw new SMTPException(LOG_ERR,"Recipient <%s> malformated!\n",$recipient);

	debug::printf(LOG_DEBUG,"init new EventBufferEvent!\n");
	$this->bev = new EventBufferEvent($this->base, $socket); //, EventBufferEvent::OPT_CLOSE_ON_FREE);
	   // | EventBufferEvent::OPT_DEFER_CALLBACKS);
	if (!$this->bev) 
	   throw new SMTPException(LOG_ERR,"Fail when creating socket bufferevent\n");

	$this->bev->setCallbacks(array($this,"readcb"), /* writecb */ NULL, array($this,"eventcb"), $this);
	$this->bev->enable(Event::READ | Event::WRITE);
        $this->bev->setTimeouts($this->readtimeout,$this->writetimeout);
      }

      // clear all state property
      $this->clear(false);
      $this->recipient=$recipient;

      debug::printf(LOG_DEBUG,"Dispatch begin!\n");
      /* Distribue les événements en attente */
      $this->base->dispatch();
      debug::printf(LOG_DEBUG,"Dispatch end!\n");
      if ($relayhost===null) 
      {
	if (@isset($this->bev)) $this->bev->free();
	$this->bev=null;
        socket_close($socket);
      }
      if ($this->returncode['return']===false)
	 throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$this->returncode['code'],implode(",",$this->returncode['arguments']));
      else
         debug::printf(LOG_INFO,"Message Sended!\n");

    }

    debug::printf(LOG_INFO,"SMTPClient Send end");
    return true;
  }

  private function socketConnect($sockhost,$sockport,$ipv6=true)
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

    debug::printf(LOG_DEBUG,"Try to connect to %s:%s with %s!\n",$sockip[0],$sockport,$sockip['type']===AF_INET?"ipv4":"ipv6");
    $socket = socket_create($sockip['type'], SOCK_STREAM, SOL_TCP);
    $timeout = array('sec'=>$this->readtimeout,'usec'=>0);
    socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,$timeout);
    $timeout = array('sec'=>$this->writetimeout,'usec'=>0);
    socket_set_option($socket,SOL_SOCKET,SO_SNDTIMEO,$timeout);
    if (!@socket_connect($socket, $sockip[0], $sockport)) 
    {
      debug::printf(LOG_ERR,"Cannot Bind Socket to %s:%s - <%s>!\n",$sockip[0],$sockport,socket_strerror(socket_last_error()));
      socket_close($socket);
      return false;
    }
    debug::printf(LOG_ERR,"Connected to %s:%s!\n",$sockip[0],$sockport);
    return $socket;
  }

  public function enableTLS($ssl_verify_peer=true,$ssl_allow_self_signed=true)
  {
     $this->tls=true;
     $this->ssl_verify_peer=$ssl_verify_peer;
     $this->ssl_allow_self_signed=$ssl_allow_self_signed;
  }
 
  public function setHeloHost($helohost)
  {
    debug::printf(LOG_DEBUG,"Set HELOHOST: %s\n",$helohost);
    $this->helohost=$helohost;
  }

  public function setMailFrom($mailfrom)
  {
    debug::printf(LOG_DEBUG,"Set MAILFROM: %s\n",$mailfrom);
    $this->mailfrom=$mailfrom;
  }

  public function addRcpt($rcpt)
  {
    debug::printf(LOG_DEBUG,"Add RECIPIENT: %s\n",$rcpt);
    $this->rcpt[]=$rcpt;
  }

  public function setData($data)
  {
    $this->datalen=strlen($data);
    debug::printf(LOG_DEBUG,"Set data len:%s...\n",$this->datalen);
    $this->data=$data;
  }

  public function clear($all=true)
  {
    if ($all===true)
    {
      debug::printf(LOG_DEBUG,"Clear all Attribut...\n");
      if (isset($this->bev)) $this->bev->free();
      $this->bev = null;
      $this->helohost = null;  
      $this->mailfrom = null; 
      $this->rcpt = array(); 
      $this->data = null;
      $this->datalen = null;
    }
    else
      debug::printf(LOG_DEBUG,"Clear state attribut only...\n");

    $this->server_estmp=false;
    $this->server_maxsize=null;
    $this->server_tls=false;
    $this->server_8bitmime=false;
    $this->server_xclient=array();
    $this->server_xforward=array();

    $this->tls_activated=false;
    $this->recipient = null;
    $this->state = self::CONNECT;
    $this->returncode=false;
    $this->arguments = array();
    $this->code = 0;
  }

  private function ev_close($bev,$drain=false) 
  {
       $output_buffer=$bev->getOutput();
       $input_buffer=$bev->getInput();
       if ($output_buffer->length>0) 
       {
	     /* We still have to flush data from the other
	      * side, but when that's done, close the other
	      * side. */
	     $bev->disable(Event::READ);
	     if ($drain) while($input_buffer->length > 0) $input_buffer->drain(256);
	     $bev->setCallbacks(NULL, [$this, 'ev_close_on_finished_writecb'], NULL, $bev);
       } else {
	     /* We have nothing left to say to the other
	      * side; close it. */
	     $bev->disable(Event::READ | Event::WRITE);
	     if ($drain) while($input_buffer->length > 0) $input_buffer->drain(256);
	     if (is_resource($bev->fd)) socket_close($bev->fd);
	     $bev->free();
       }
  }

  public function ev_close_on_finished_writecb($buffer, $bev) 
  {
      if ($bev->getOutput()->length==0)
      {
	$bev->disable(Event::READ | Event::WRITE);
	if (is_resource($bev->fd)) socket_close($bev->fd);
	$bev->free(); 
      }
  }

  private function starttls($ctx)
  {	
     debug::printf(LOG_INFO,"Entering in TLS Hand check...\n");
     $bev = EventBufferEvent::sslSocket($this->base,
	 $ctx->bev->fd, $this->sslctx,
	 EventBufferEvent::SSL_CONNECTING | EventBufferEvent::SSL_OPEN);
     if (!$bev) 
     {
	 $sslerror=$bev->sslError();
	 debug::printf(LOG_ERR,"Fail when creating socket bufferevent in ssl: %s\n",$sslerror);
         $ctx->bev->free();
         $bev->free();
	 return false;
     }
     $ctx->bev->free();
     $ctx->bev=$bev;
     $ctx->bev->setCallbacks(array($ctx,"readcb"), /* writecb */ NULL, array($ctx,"eventcb"), $ctx);
     $ctx->bev->enable(Event::READ | Event::WRITE);
     $ctx->bev->setTimeouts($this->readtimeout,$this->writetimeout);
     $ctx->tls_activated=true;
     return true;
  }

  /* Fonction de rappel de lecture */
  public function readcb($bev, $ctx) 
  {
      switch ($this->state) 
      {
	 /////////////////////////////////////////////////////////////////////////////////
         case self::CONNECT:
	 $retcode=$this->parse_response($bev,$ctx,220);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"Connect response error, code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 // check if are ESMTP server
	 if (preg_match("/ESMTP/i",$retcode['arguments'][0])==1) 
	 {
	   debug::printf(LOG_INFO,"ESMTP Server detected...\n");
	   $this->server_estmp=true;
	 }
	 debug::printf(LOG_DEBUG,"Connect OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	 $this->state=self::HELO;

	 // send by default EHLO if forceehlo is set to true or if the server as the ESMTP support
	 if ($this->forceehlo===true||$this->estmp===true)
	 {
	   $bev->write("EHLO ".$this->helohost."\r\n");
	   debug::printf(LOG_DEBUG,"SEND: EHLO %s\n",$this->helohost);
	 }
	 // Send HELO
	 else
	 {
	   $bev->write("HELO ".$this->helohost."\r\n");
	   debug::printf(LOG_DEBUG,"SEND: HELO %s\n",$this->helohost);
	 }
	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::HELO:
	 $retcode=$this->parse_response($bev,$ctx,250);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"EHLO <%s>, response error, code: %s arguments: %s \n",$this->helohost,$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 debug::printf(LOG_DEBUG,"EHLO OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);

	 //debug::print_r(LOG_DEBUG,$retcode);
	 // parse extension reponse
	 $this->server_maxsize=0;
	 $this->server_tls=false;
	 $this->server_8bitmime=false;
	 $this->server_xforward=null;
	 $this->server_xclient=null;

	 foreach($retcode['arguments'] as $value)
	 {
	   //debug::printf(LOG_DEBUG,"Check response arguments: %s \n",$value);

	   // http://www.postfix.org/XFORWARD_README.html
	   if (preg_match('/^XFORWARD\s(.+)/i', $value, $arr)==1)
	   {
	     debug::printf(LOG_INFO,"The Server annonce XFORWARD support\n");
	     $xforward_ret=$this->xcmdheloargs_check($arr[1],self::$xforward_name);
	     if (isset($xforward_ret[1]))
	     foreach($xforward_ret[1] as $args)
	        debug::printf(LOG_INFO,"The Server annonce XFORWARD unknown attribut:%s, go to ignore it\n",$args);
	     $this->server_xforward=$xforward_ret;
	     debug::print_r(LOG_DEBUG,$xforward_ret);
	     continue;
	   }

	   // http://www.postfix.org/XCLIENT_README.html
	   if (preg_match('/^XCLIENT\s(.+)/i', $value, $arr)==1)
	   {
	     debug::printf(LOG_INFO,"The Server annonce XCLIENT support\n");
	     $xclient_ret=$this->xcmdheloargs_check($arr[1],self::$xclient_name);
	     if (isset($xclient_ret[1]))
	     foreach($xclient_ret[1] as $args)
	        debug::printf(LOG_INFO,"The Server annonce XCLIENT unknown attribut:%s, go to ignore it\n",$args);
	     $this->server_xclient=$xclient_ret;
	     debug::print_r(LOG_DEBUG,$xclient_ret);
	     continue;
	   }

	   // 8BITMIME support
	   // http://cr.yp.to/smtp/8bitmime.html
	   if (preg_match('/^8BITMIME/i', $value)==1)
	   {
	     debug::printf(LOG_INFO,"The Server annonce 8bit Mime support\n");
	     $this->server_8bitmime=true;
	     continue;
	   }

	   // match startls support
	   // http://en.wikipedia.org/wiki/STARTTLS
	   // https://tools.ietf.org/html/rfc3207
	   if (preg_match('/^STARTTLS/i', $value)==1)
	   {
	     if ($this->tls_activated===true)
	       debug::printf(LOG_INFO,"The Server annonce STARTTLS support in TLS connection, is not conforme to rfc3207!\n");

	     debug::printf(LOG_INFO,"The Server annonce STARTTLS support\n");
	     $this->server_tls=true;
	     continue;
	   }

	   // match size support
	   // http://cr.yp.to/smtp/size.html
	   if (preg_match('/^SIZE ([0-9]*)/i', $value, $sizes)==1)
	   {
	     $this->server_maxsize=$sizes[1];
	     debug::printf(LOG_INFO,"The Server annonce Message Max Size of %s\n",$this->server_maxsize);
	     continue;
	   }
	 }

	 // check message size with server size receved
	 if ($this->server_maxsize>0&&$this->datalen>$this->server_maxsize)
	 {
	   debug::printf(LOG_ERR,"Message Size %s is grether than max message size %s - abort!\n",$this->datalen,$this->server_maxsize);
	   $this->state=self::CLOSE;
	   $bev->write("QUIT\r\n");
	   debug::printf(LOG_DEBUG,"SEND: QUIT\n");
	   break;
	 }
	 debug::print_r(LOG_DEBUG,$retcode['arguments']);

	 // check STARTTLS Support and start tls session if the client is configured to use tls
	 if ($this->tls===true&&$this->server_tls===true&&$this->tls_activated!==true)
	 {
	   $this->state=self::STARTTLS;
	   $bev->write("STARTTLS\r\n");
	   debug::printf(LOG_DEBUG,"SEND: MAIL FROM:<%s>\n",$this->mailfrom);
	   break;
	 }

	 $this->state=self::MAILFROM;
	 $bev->write("MAIL FROM:<".$this->mailfrom.">\r\n");
	 debug::printf(LOG_DEBUG,"SEND: MAIL FROM:<%s>\n",$this->mailfrom);
	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::STARTTLS:
	 $retcode=$this->parse_response($bev,$ctx,220);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"STARTTLS, response error, code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
            sleep(3);
	    return;
	 }
	 debug::printf(LOG_DEBUG,"STARTTLS OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);

	 // go tls handcheck
	 debug::printf(LOG_INFO,"Go in TLS mode...\n");
	 if ($ctx->starttls($ctx)===false)
	 {
	   debug::printf(LOG_ERR,"Error STARTTLS Close connection...\n");
	   $this->ev_close($bev);
	   $arguments=array("STARTTLS error");
	   $this->returncode=array('return'=>false, 'code'=>-1,'arguments'=>$arguments);
	   return;
	 }

	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::MAILFROM:
	 $retcode=$this->parse_response($bev,$ctx,250);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"MAIL FROM <%s>, response error, code: %s arguments: %s \n",$this->mailfrom,$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 debug::printf(LOG_DEBUG,"MAIL FROM OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	 $this->state=self::RCPT;
	 $bev->write("RCPT TO:<".$this->recipient.">\r\n");
	 debug::printf(LOG_DEBUG,"SEND: RCPT TO:<%s>\n",$this->recipient);
	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::RCPT:
	 $retcode=$this->parse_response($bev,$ctx,array(250,251));
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"RCPT TO <%s>, response error, code: %s arguments: %s \n",$this->recipient,$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 debug::printf(LOG_DEBUG,"RCPT TO OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	 $this->state=self::DATA;
	 $bev->write("DATA\r\n");
	 debug::printf(LOG_DEBUG,"SEND: DATA cmd\n");
	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::DATA:
	 $retcode=$this->parse_response($bev,$ctx,354);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"DATA response error, code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 debug::printf(LOG_DEBUG,"DATA OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	 $this->state=self::QUIT;
	 $bev->write($this->data);
	 $bev->write("\r\n.\r\n");
	 debug::printf(LOG_DEBUG,"SEND: Mail Data\n");
	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::QUIT:
	 $retcode=$this->parse_response($bev,$ctx,250);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"DATA Sent response error, code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 debug::printf(LOG_DEBUG,"DATA Sent OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	 $this->state=self::CLOSE;
	 $bev->write("QUIT\r\n");
	 debug::printf(LOG_DEBUG,"SEND: QUIT\n");
	 break;

	 /////////////////////////////////////////////////////////////////////////////////
         case self::CLOSE:
	 $retcode=$this->parse_response($bev,$ctx,221);
	 if ($retcode['return']===self::RESPONSE_CONT) continue;
	 if ($retcode['return']===self::RESPONSE_KO)
	 {
	    $this->ev_close($bev);
            debug::printf(LOG_ERR,"QUIT response error, code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	    $this->returncode=$retcode;
	    return;
	 }
	 debug::printf(LOG_DEBUG,"QUIT OK code: %s arguments: %s \n",$retcode['code'],$retcode['arguments'][0]);
	 $this->state=self::CLOSE;
	 $this->ev_close($bev);
	 break;
      }
  }

  /* Fonction de rappel d'événement */
  public function eventcb($bev, $events, $ctx) 
  {
      if ($events & EventBufferEvent::CONNECTED) 
      {
	  debug::printf(LOG_INFO,"Connected.\n");
          if ($ctx->tls_activated===true)
	  {
	    debug::printf(LOG_NOTICE, "Cipher:%s\n",trim($bev->sslGetCurrentCipher()));
	    debug::printf(LOG_NOTICE, "CipherVersion:%s\n",$bev->sslGetCurrentVersion());
	    debug::printf(LOG_NOTICE, "CipherName:%s\n",$bev->sslGetCurrentCipherName());
	    debug::printf(LOG_NOTICE, "CipherProtocol:%s\n",$bev->sslGetCurrentProtocol());
	    // send secondes EHLO after TLS handcheck are ok
	    $this->state=self::HELO;
	    $this->bev->write("EHLO ".$this->helohost."\r\n");
	    debug::printf(LOG_DEBUG,"SEND in TLS: EHLO %s\n",$this->helohost);
	  }
	  return;
      } 
      elseif ($events & (EventBufferEvent::ERROR))
      {
	  $dnserror=$ctx->bev->getDnsErrorString();
	  $sslerror=$ctx->bev->sslError();
	  $sockcode=EventUtil::getLastSocketErrno();
	  $sockError=EventUtil::getLastSocketError();
	  $arguments=array();
	  if ($sockError!=0) $arguments[]=$sockError.'('.$sockcode.')';
	  if ($sslerror!==false) $arguments[]=$sslerror;
	  if ($dnserror!="") $arguments[]=$dnserror;

	  debug::printf(LOG_ERR,"Erreur msg:<%s>\n",implode(',',$arguments));
	  $this->returncode=array('return'=>false, 'code'=>-1,'arguments'=>$arguments);
	  $this->ev_close($bev);
	  debug::printf(LOG_ERR,"Connection Error - Exit\n");
	  return;
      }
      elseif ($events & (EventBufferEvent::TIMEOUT)) 
      {
	  $code=EventUtil::getLastSocketErrno();
	  $arguments=array("Connection Timeout","SocketErrMsg:".EventUtil::getLastSocketError()."(".$code.")");
	  $this->returncode=array('return'=>false, 'code'=>-1,'arguments'=>$arguments);
	  $this->ev_close($bev);
	  debug::printf(LOG_ERR,"Connection Timeout - Exit\n");
	  return;
      }
      elseif ($events & (EventBufferEvent::EOF)) 
      {
	  $code=EventUtil::getLastSocketErrno();
	  $arguments=array("Connection Close","SocketErrMsg:".EventUtil::getLastSocketError()."(".$code.")");
	  $this->returncode=array('return'=>false, 'code'=>-1,'arguments'=>$arguments);
	  $this->ev_close($bev);
	  debug::printf(LOG_ERR,"Connection Close - Exit\n");
	  return;
      }
      debug::printf(LOG_ERR,"Unknown Event: %s!\n",$events);
  }

  private function parse_response($bev,$ctx,$valid)
  {
    $input = $bev->getInput();

    debug::printf(LOG_DEBUG,"====> Response Len: %s\n",$input->length);
    while(($line = $input->readLine(EventBuffer::EOL_CRLF))!==NULL)
    {
      debug::printf(LOG_DEBUG,"====> Response Read: %s\n",$line);
      /* If we receive an empty line, the connection was closed. */
      if (empty($line)) 
      {
	  debug::printf(LOG_ERR,"Line Empty!\n");
	  //$ctx->base->exit(NULL);
          return array('return'=>self::RESPONSE_KO,'code'=>-1,'arguments'=>$this->arguments);
      }

      /* Read the code and store the rest in the arguments array. */
      $this->code = substr($line, 0, 3);
      $this->arguments[] = trim(substr($line, 4));

      /* Check the syntax of the response code. */
      if (is_numeric($this->code)) 
      {
	  $this->_code = (int)$this->code;
      } 
      else 
      {
	  $this->_code = -1;
	  break;
      }

      /* If this is not a multiline response, we're done. */
      $eor=substr($line, 3, 1);
      if ($eor===" ") break;
    } 

    if ($line===null) 
       return array('return'=>self::RESPONSE_CONT, 'code'=>$this->code,'arguments'=>$this->arguments);

    /* Compare the server's response code with the valid code/codes. */
    if (is_int($valid) && ($this->_code === $valid)) {
	$ret=array('return'=>self::RESPONSE_OK, 'code'=>$this->code,'arguments'=>$this->arguments);
	$this->arguments = array();
	$this->code = 0;
	return $ret;
    } elseif (is_array($valid) && in_array($this->_code, $valid, true)) {
	$ret=array('return'=>self::RESPONSE_OK, 'code'=>$this->code,'arguments'=>$this->arguments);
	$this->arguments = array();
	$this->code = 0;
	return $ret;
    }
    $ret=array('return'=>self::RESPONSE_KO, 'code'=>$this->code,'arguments'=>$this->arguments);
    $this->arguments = array();
    $this->code = 0;
    return $ret;
  }

  private function xcmdheloargs_check($xcmdheloargs,$autorized_attr)
  {
     if (preg_match_all("/\w+/",$xcmdheloargs,$arr)>=1)
     {
	$attrs=array();
	foreach($arr[0] as $args)
	{
	   $xattr=strtoupper($args);
	   if (!in_array($xattr,$autorized_attr)) $attrs[1][]=$xattr;
	   else $attrs[0][]=$xattr;
	}
	return $attrs;
     }
     return false;
  }
}


