<?php
namespace phpSMTPd;

use EventBufferEvent;
use EventBuffer;
use EventBase;
use EventSslContext;
use Event;
use EventUtil;

include_once("Debug.php");
include_once("NetTool.php");
include_once("SSL.php");
include_once("PropertySetter.php");


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

class SMTPConnection
{
  use PropertySetter;

  // the eventbufferevent of the connection
  public $bev = null;
  // socket information
  public $socket = null;

  // smtp stats machine
  public $tls_activated = false;
  public $closed = false;
  public $cached = false;
  public $handcheck = false;
  public $xclient_sent = false;

  // connection server information
  public $server_host=null;
  public $server_ip=null;
  public $server_port=null;

  // EHLO server information
  public $server_estmp=false;
  public $server_maxsize=null;
  public $server_tls=false;
  public $server_8bitmime=false;
  public $server_xclient=array();
  public $server_xforward=array();

  // command response parsing return
  public $server_responses= array();
  public $server_code = 0;

  public function __construct($bev,$socket,$host,$ip,$port)
  {
    $this->bev=$bev;
    $this->socket=$socket;
    $this->server_host=$host;
    $this->server_ip=$host;
    $this->server_port=$port;
    $this->cached=false;
    $this->xclient_sent=false;
    $this->handcheck=false;
    $this->closed=false;
    $this->tls_activated=false;
  }
}

class SMTPClient 
{
  use PropertySetter;

  // cmd state constant
  const HELO 		= 1;
  const STARTTLS	= 2;
  const QUIT 		= 6;
  const CLOSE 		= 7;

  private static $xforward_name = array("NAME","ADDR","PORT","PROTO","HELO","IDENT","SOURCE");
  private static $xclient_name = array("NAME","ADDR","PROTO","HELO");

  // so_keepalive default timming
  protected $tcp_keepidle = 7200; // The maximum number of keepalive probes TCP should send before dropping the connection. This option should not be used in code intended to be portable.
  protected $tcp_keepintvl = 75;  // The time (in seconds) the connection needs to remain idle before TCP starts sending keepalive probes, 
                                  // if the socket option SO_KEEPALIVE has been set on this socket. This option should not be used in code intended to be portable.
  protected $tcp_keepcnt = 9;     // The time (in seconds) between individual keepalive probes. This option should not be used in code intended to be portable.

  // socket default read/write timeout
  protected $readtimeout = 30;	        // socket read timeout in seconds
  protected $writetimeout = 30;	        // socket write timeout in seconds

  protected $ipv6 = false;                 // resolve ipv6 first or only ipv4 (ipv4 only by default)
  protected $tls = false;                  // activate Starttls Client Support if the server annonce support (tls is off by default)
  protected $forceehlo = true;             // send by default EHLO if forceehlo is set to true or if the server as the ESMTP support
  protected $ssl_verify_peer = false;      // ssl client verify peer
  protected $ssl_allow_self_signed = true; // ssl client accept self signed server certificat
  protected $mxport = 25;		   // default port for mx connection
  protected $connectionreuse = true;	   // tel where reuse or not the connection, they impact
  protected $grouprecipient = true;        // one transaction for all recipient or one per recipient for each server

  // the eventbase used to exchange smtp message to the server
  private $base = null;
  // sslcontext of the starttls connection
  private $sslctx = null;

  // socket connection cache
  // contain SMTPConnection object
  private $smtp_connection_cache = array();

  // message informations
  private $helohost = null;    // hostname send by EHLO/HELO
  private $mailfrom = null;    // mail from adresse
  private $rcpt = array();     // recipient list to send the massage
  private $data = null;        // the message data
  private $datalen = null;     // the message data len
  private $xforward = array(); // XFORWARD options to send to the server
  private $xclient = array();  // XCLIENT options to send to the server

  ////////////////////////////////////////////////////////////////////////////
  // Constructor
  ////////////////////////////////////////////////////////////////////////////

  public function __construct($eventbase=null)
  {
    if ($eventbase==null) $this->base = new EventBase();
    else $this->base = $eventbase;
    if (!$this->base) 
       throw new SMTPException(LOG_ERR,"Couldn't open event base\n");

    // We *must* have entropy. Otherwise there's no point to crypto.
    if (!EventUtil::sslRandPoll()) 
	throw new SMTPException(LOG_ERR,"EventUtil::sslRandPoll failed\n");

  }

  ////////////////////////////////////////////////////////////////////////////
  // desctructor
  ////////////////////////////////////////////////////////////////////////////

  public function __destruct()
  {
    if (isset($this->bev)) $this->bev->free();
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send SMTP Message
  ////////////////////////////////////////////////////////////////////////////

  public function sendMessage($relayhost=null,$relayport=25)
  {
    debug::printf(LOG_INFO,"SMTPClient Send begin");

    // force reuseconnection if grouperecipient are set
    if ($this->grouprecipient===true) $this->connectionreuse=true;

    if ($this->tls === true)
    {
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
    if (count($this->rcpt)<=0)
       throw new SMTPException(LOG_ERR,"Rcpt attribut not set!\n");
    if ($this->data===null)
       throw new SMTPException(LOG_ERR,"Data attribut not set!\n");

    debug::print_r(LOG_DEBUG,$this->rcpt);
    foreach($this->rcpt as $recipient)
    {
      debug::printf(LOG_INFO,"Send message for the recipient <%s>...\n",$recipient['recipient']);
      // check if connect by relayhost or by mx
      if ($relayhost===null)
      {
	// connection by mx
	if (($mxs=NetTool::getmx($recipient['domain']))===false)
	    throw new SMTPException(LOG_ERR,"No MX found for host %s!\n",$recipient['domain']);
	debug::print_r(LOG_DEBUG,$mxs);

        $smtp_connection=false;
	foreach($mxs as $mxhost => $mxweight)
	{
	  //$mxhost="127.0.0.1";
	  //$this->mxport=2025;
	  debug::printf(LOG_INFO,"Use the MX host <%s:25> for this recipient to send the message...\n",$mxhost);
	  $smtp_connection=$this->getCachedBufferEvent($mxhost,$this->mxport);
	  if ($smtp_connection!==false) 
	  {
	      // initialisze smtp exchange: receive server helo, send ehlo/helo and do starttls handcheck
              // if the connexion are not already handled (in cache)
	      if ($this->_SMTP_ConnectionHandCheck($smtp_connection)===true) 
	        break; // if the handcheck fail try next mx
	      debug::printf(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));
	  }
	  debug::printf(LOG_ERR,"Cannot connect to <%s> try next one!\n",$mxhost.":25");
	}
	if ($smtp_connection===false)
	      throw new SMTPException(LOG_ERR,"Cannot connect to the mx host!\n");
      }
      else
      {
	 debug::printf(LOG_INFO,"Use the relay host <%s:%s> to send the message...\n",$relayhost,$relayport);
	 $smtp_connection=$this->getCachedBufferEvent($relayhost,$relayport);
	 if ($smtp_connection===false) 
	     throw new SMTPException(LOG_ERR,"Cannot connect to the host %s:%s!\n",$relayhost,$relayport);
	 // initialisze smtp exchange: receive server helo, send ehlo/helo and do starttls handcheck
	 // if the connexion are not already handled (in cache)
	 if ($this->_SMTP_ConnectionHandCheck($smtp_connection)!==true) // if the SMTP handcheck fail abort
		throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));
      }

      if ($this->grouprecipient===true)
      {
	 // first cmd after smtp handcheck
	 if ($smtp_connection->cached===false)
	 {
	    // send mailfrom, for one transaction for all recipient
	    if ($this->_SMTP_MAILFROM($smtp_connection,$this->mailfrom)!==true)
	      throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));
	 }

	 debug::printf(LOG_DEBUG,"send mailfrom, rcpt to and data, one transaction for ALL recipient\n");
	 // send mailfrom, rcpt to and data, one transaction for ALL recipient
	 $retcode=$this->_SMTP_RCPT($smtp_connection,$recipient['recipient']);
	 if ($retcode===false)
	    throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));
      }
      else
      {
	 debug::printf(LOG_DEBUG,"send mailfrom, rcpt to and data, one transaction PER recipient\n");
	 // send mailfrom, rcpt to and data, one transaction PER recipient
	 $retcode=$this->_SMTP_MessageTransaction($smtp_connection,$this->mailfrom,$recipient['recipient'],$this->data);
	 if ($retcode===false)
	    throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));

	 if ($this->connectionreuse===false) $this->_SMTP_QUIT($smtp_connection);
         debug::printf(LOG_INFO,"Message Sended!\n");
      }
    }

    if ($this->connectionreuse===true||$this->grouprecipient===true) 
    {
      if ($this->connectionreuse===true)
      {
	foreach($this->smtp_connection_cache as $key => $smtp_connection)
	{
	  if ($this->grouprecipient===true)
	  {
	    if ($this->_SMTP_DATA($smtp_connection)!==true)
	      throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));
	    if ($this->_SMTP_DATASend($smtp_connection,$this->data)!==true)
	      throw new SMTPException(LOG_ERR,"SMTP Error CODE:%s - \"%s\"\n",$smtp_connection->server_code,implode(",",$smtp_connection->server_responses));
	    debug::printf(LOG_INFO,"Message Sended!\n");
	  }
          $this->_SMTP_QUIT($smtp_connection);
	}
      }
      else
      {
        $this->_SMTP_QUIT($smtp_connection);
      }
    }

    debug::printf(LOG_INFO,"SMTPClient Send end");
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // get smtp connection from cache if in cache or new connection
  ////////////////////////////////////////////////////////////////////////////

  private function getCachedBufferEvent($host,$port)
  {
      $smtp_connection_key=$host.":".$port;
      if ($this->connectionreuse===true)
      {
	debug::printf(LOG_DEBUG,"==> try to get BufferEvent from cache for:%s\n",$smtp_connection_key);
	if (isset($this->smtp_connection_cache[$smtp_connection_key]))
	{
	  debug::printf(LOG_DEBUG,"==> there a BufferEvent in cache for:%s\n",$smtp_connection_key);
	  $smtpconn_cached=$this->smtp_connection_cache[$smtp_connection_key];
	  if (is_a($smtpconn_cached->bev,"EventBufferEvent")&& 
	      is_a($smtpconn_cached->bev->input,"EventBuffer")&& 
	      is_a($smtpconn_cached->bev->output,"EventBuffer")&& 
	      is_resource($smtpconn_cached->socket)) 
	  {
	    debug::printf(LOG_DEBUG,"==> use BufferEvent from cache for:%s\n",$smtp_connection_key);
	    $socket=$smtpconn_cached->socket;

	    // check the connection of this socket
	    $buf=null;
	    if (($ret=@socket_send($socket,$buf,0,0))===0)
	    {
	       debug::printf(LOG_DEBUG,"==> Got BufferEvent from cache for:%s\n",$smtp_connection_key);
	       $smtpconn_cached->cached=true;
	       return $smtpconn_cached;
	    }
	    else 
	       debug::printf(LOG_ERR,"ERROR Socket is closed reopen new one for:%s\n",$smtp_connection_key);
	  }
	  else
	  {
	    debug::printf(LOG_DEBUG,var_dump($smtpconn_cached,true));
	    debug::printf(LOG_ERR,"ERROR BEV or socket not in good type for:%s remove from the cache and reopen new one !\n",$smtp_connection_key);
	    if (is_resource($smtpconn_cached->socket))
	    {
	      socket_close($smtpconn_cached->socket);
	    }
	    if (is_a($smtpconn_cached->bev,"EventBufferEvent"))
	    {
	      $smtpconn_cached->bev->close();
	      $smtpconn_cached->bev->free();
	    }
	    unset($this->smtp_connection_cache[$smtp_connection_key]);
	  }
	}
      }
      $socket=$this->socketConnect($host,$port,$this->ipv6);
      if ($socket===false) return FALSE;

      debug::printf(LOG_DEBUG,"init new EventBufferEvent!\n");
      $options=0;
      //$options |= EventBufferEvent::OPT_CLOSE_ON_FREE;
      //$options |= EventBufferEvent::OPT_DEFER_CALLBACKS;
      $bev = new EventBufferEvent($this->base, $socket[0], $options);
      if (!$bev) 
	 throw new SMTPException(LOG_ERR,"Fail when creating socket bufferevent\n");

      $ret_smtp_connection= new SMTPConnection($bev,$socket[0],$host,$socket[1],$port);
      $ret_smtp_connection->bev->setTimeouts($this->readtimeout,$this->writetimeout);
      $ret_smtp_connection->bev->setCallbacks(array($this,"ev_readcb"), /* writecb */ NULL, array($this,"ev_eventcb"), $ret_smtp_connection);
      $ret_smtp_connection->bev->enable(Event::READ| Event::WRITE);
      
      debug::printf(LOG_DEBUG,var_dump($ret_smtp_connection,true));
      if ($this->connectionreuse===true)
      {
	 debug::printf(LOG_DEBUG,"==> BufferEvent stored in cache for:%s\n",$smtp_connection_key);
	 $this->smtp_connection_cache[$smtp_connection_key]=$ret_smtp_connection;
      }
      return $ret_smtp_connection;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Socket connection to the targer SMTP host
  ////////////////////////////////////////////////////////////////////////////

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

    // create the socket
    $socket = socket_create($sockip['type'], SOCK_STREAM, SOL_TCP);

    // set the socket timeout
    $timeout = array('sec'=>$this->readtimeout,'usec'=>0);
    socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,$timeout);
    $timeout = array('sec'=>$this->writetimeout,'usec'=>0);
    socket_set_option($socket,SOL_SOCKET,SO_SNDTIMEO,$timeout);

    // set keepalive tcp option to on
    socket_set_option($socket,SOL_SOCKET,SO_KEEPALIVE,1);

    // if on linux try to change KeepAlive counter
    if (!strncmp("Linux",PHP_OS,5))
    {
      socket_set_option($socket,SOL_TCP   ,NetTool::TCP_KEEPIDLE,$this->tcp_keepidle);
      socket_set_option($socket,SOL_SOCKET,NetTool::TCP_KEEPINTVL,$this->tcp_keepintvl);
      socket_set_option($socket,SOL_SOCKET,NetTool::TCP_KEEPCNT,$this->tcp_keepcnt);
    }

    // connect the socket
    if (!@socket_connect($socket, $sockip[0], $sockport)) 
    {
      debug::printf(LOG_ERR,"Cannot Bind Socket to %s:%s - <%s>!\n",$sockip[0],$sockport,socket_strerror(socket_last_error()));
      socket_close($socket);
      return false;
    }
    debug::printf(LOG_ERR,"Connected to %s:%s!\n",$sockip[0],$sockport);
    return array($socket,$sockip);
  }

  //////////////////////////////////////////////////////////////////////////
  // Set the application so_keepalive socket option timing,
  // it's the equivalent for the application level of this system parametter :
  // net.ipv4.tcp_keepalive_time = 7200
  // net.ipv4.tcp_keepalive_probes = 9
  // net.ipv4.tcp_keepalive_intvl = 75
  ////////////////////////////////////////////////////////////////////////////

  public function setKeepAliveTiming($keepidle,$keepintvl,$keepcnt)
  {
     // so_keepalive default timming
     $this->tcp_keepidle = 7200;
     $this->tcp_keepintvl = 75;
     $this->tcp_keepcnt = 9;
  }

  ////////////////////////////////////////////////////////////////////////////
  // set socket default read/write timeout
  ////////////////////////////////////////////////////////////////////////////

  public function setTimeout($readtimeout,$writetimeout)
  {
     $this->readtimeout = $readtimeout;	    	    // socket read timeout
     $this->writetimeout = $writetimeout;	    // socket write timeout
  }

  ////////////////////////////////////////////////////////////////////////////
  // set the default port for mx connection
  ////////////////////////////////////////////////////////////////////////////

  public function setDefaultMXPort($port)
  {
   $this->mxport = $port;			
  }

  ////////////////////////////////////////////////////////////////////////////
  // send by default EHLO if forceehlo is set to true or if the server as the ESMTP support
  ////////////////////////////////////////////////////////////////////////////

  public function disableForceEHLO()
  {
    $this->forceehlo = false;             
  }

  ////////////////////////////////////////////////////////////////////////////
  // diable the reuse of the connection for each recipient
  ////////////////////////////////////////////////////////////////////////////

  public function disableConenctionReuse()
  {
    $this->connectionreuse = false;	
  }

  ////////////////////////////////////////////////////////////////////////////
  // disable the use of one transaction for all recipient
  ////////////////////////////////////////////////////////////////////////////

  public function disableGroupRecipient()
  {
    $this->grouprecipient = false;       
  }

  ////////////////////////////////////////////////////////////////////////////
  // enable IPV6
  ////////////////////////////////////////////////////////////////////////////

  public function enableIPV6()
  {
     $this->ipv6=true;
  }
 
  ////////////////////////////////////////////////////////////////////////////
  // enableTLS
  ////////////////////////////////////////////////////////////////////////////

  public function enableTLS($ssl_verify_peer=true,$ssl_allow_self_signed=true)
  {
     $this->tls=true;
     $this->ssl_verify_peer=$ssl_verify_peer;
     $this->ssl_allow_self_signed=$ssl_allow_self_signed;
  }
 
  ////////////////////////////////////////////////////////////////////////////
  // set XClient
  ////////////////////////////////////////////////////////////////////////////

  public function setXClient($xclients)
  {
    debug::printf(LOG_DEBUG,"Set XCLIENT: %s\n",implode(",",$xclients));
    foreach($xclients as $key => $value)
    {
      if (!in_array($key,self::$xclient_name))
      {
	debug::printf(LOG_INFO,"The Server annonce XCLIENT unknown attribut:%s, go to ignore it\n",$key);
      }
      else
        $this->xclient[$key]=$value;
    }
  }

  ////////////////////////////////////////////////////////////////////////////
  // set XForward
  ////////////////////////////////////////////////////////////////////////////

  public function setXForward($xforwards)
  {
    debug::printf(LOG_DEBUG,"Set XFORWARD: %s\n",print_r($xforwards,true));
    foreach($xforwards as $key => $value)
    {
      if (!in_array($key,self::$xforward_name))
      {
	debug::printf(LOG_INFO,"The Server annonce XFORWARD unknown attribut:%s, go to ignore it\n",$key);
      }
      else
        $this->xforward[$key]=$value;
    }
    debug::printf(LOG_DEBUG,"XFORWARD accepted: %s\n",print_r($this->xforward,true));
  }

  ////////////////////////////////////////////////////////////////////////////
  // set HeloHost
  ////////////////////////////////////////////////////////////////////////////

  public function setHeloHost($helohost)
  {
    debug::printf(LOG_DEBUG,"Set HELOHOST: %s\n",$helohost);
    $this->helohost=$helohost;
  }

  ////////////////////////////////////////////////////////////////////////////
  // set MailFrom
  ////////////////////////////////////////////////////////////////////////////

  public function setMailFrom($mailfrom)
  {
    debug::printf(LOG_DEBUG,"Set MAILFROM: %s\n",$mailfrom);
    if (preg_match("/^([a-zA-Z0-9_.+-]+)@([a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*)$/",$mailfrom,$arr)!==1)
       throw new SMTPException(LOG_ERR,"MailFrom <%s> attribut malformed!\n",$mailfrom);
    $this->mailfrom=$mailfrom;
  }

  ////////////////////////////////////////////////////////////////////////////
  // add recipient rcpt property array
  ////////////////////////////////////////////////////////////////////////////

  public function addRcpt($rcpt)
  {
    debug::printf(LOG_DEBUG,"Add RECIPIENT: %s\n",$rcpt);
    if (preg_match("/^([a-zA-Z0-9_.+-]+)@([a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*)$/",$rcpt,$arr)!==1)
       throw new SMTPException(LOG_ERR,"Recipient <%s> attribut malformed!\n",$rcpt);
    $this->rcpt[]=array('recipient'=>$arr[0],'name'=>$arr[1],'domain'=>$arr[2]);
  }

  ////////////////////////////////////////////////////////////////////////////
  // set Data
  ////////////////////////////////////////////////////////////////////////////

  public function setData($data)
  {
    $this->datalen=strlen($data);
    debug::printf(LOG_DEBUG,"Set data len:%s...\n",$this->datalen);
    $this->data=$data;
  }

  ////////////////////////////////////////////////////////////////////////////
  // finish reading (lingering) and close/exit
  ////////////////////////////////////////////////////////////////////////////

  private function ev_close($smtp_connection,$drain=false) 
  {
       debug::printf(LOG_DEBUG,"ev_close...\n");
       if (is_a($smtp_connection->bev,"EventBufferEvent")&& 
	   is_a($smtp_connection->bev->input,"EventBuffer")&& 
	   is_a($smtp_connection->bev->output,"EventBuffer")&&
	   $smtp_connection->closed!==true)
       {
	  debug::printf(LOG_DEBUG,"ev_close bev is open go to close...\n");

	  $output_buffer=$smtp_connection->bev->getOutput();
	  $input_buffer=$smtp_connection->bev->getInput();
	  if ($output_buffer->length>0) 
	  {
		$smtp_connection->bev->disable(Event::READ);
		// drain input if needed
		if ($drain) while($input_buffer->length > 0) $input_buffer->drain(256);

		/* We still have to flush data from the other
		 * side, but when that's done, close the other
		 * side. */
		$smtp_connection->bev->setCallbacks(NULL, [$this, 'ev_close_on_finished_writecb'], NULL, $smtp_connection);
	  } else {
		/* We have nothing left to say to the other
		 * side; close it. */
		$smtp_connection->bev->disable(Event::READ | Event::WRITE);
		// drain input if needed
		if ($drain) while($input_buffer->length > 0) $input_buffer->drain(256);

		// if in connection reuse they not close only exit the loop
		if ($this->connectionreuse!==true)
		{
		  //if (is_resource($this->bev->fd)) socket_close($this->bev->fd);
		  //if (is_resource($this->bev->fd)) socket_close($this->socket);
		  $smtp_connection->bev->close();
		  $smtp_connection->bev->free();
		}
		else
		  $this->base->exit(NULL);
	        debug::printf(LOG_DEBUG,"ev_close bev are closed...\n");
		$smtp_connection->closed=true;
	  }
       }
  }

  ////////////////////////////////////////////////////////////////////////////
  // finish sending, lingering output event
  ////////////////////////////////////////////////////////////////////////////

  public function ev_close_on_finished_writecb($bev, $smtp_connection) 
  {
      // when all the output buffer are drained go to close/exit
      if ($bev->getOutput()->length==0)
      {
	$bev->disable(Event::READ | Event::WRITE);
	$bev->close();
	$bev->free();
	debug::printf(LOG_DEBUG,"ev_close bev are closed...\n");
	$smtp_connection->closed=true;
      }
  }


  ////////////////////////////////////////////////////////////////////////////
  // error/timeout/eof/connect event callback
  ////////////////////////////////////////////////////////////////////////////

  public function ev_eventcb($bev, $events, $smtp_connection) 
  {
      if ($events & EventBufferEvent::CONNECTED) 
      {
	  debug::printf(LOG_INFO,"Connected.\n");
          if ($smtp_connection->tls_activated===true)
	  {
	    $smtp_connection->server_responses[]="TLS Handcheck succesfull!!";
	    $smtp_connection->server_code = true;
	    debug::printf(LOG_INFO,"Now in TLS...\n");
	    /*
	    $smtp_connection_key=$smtp_connection->server_host.":".$smtp_connection->server_port;
	    if (isset($this->smtp_connection_cache[$smtp_connection_key]))
	    {
	       debug::printf(LOG_INFO,"Replace in cache the TLS BufferEvent...\n");
	       $this->smtp_connection_cache[$smtp_connection_key]->bev=$smtp_connection->bev;
	    }
	    */
	    debug::printf(LOG_NOTICE, "Cipher           : %s\n",implode("/",preg_split("/\s+/",trim($bev->sslGetCipherInfo()))));
	    debug::printf(LOG_NOTICE, "CipherVersion    : %s\n",$bev->sslGetCipherVersion());
	    debug::printf(LOG_NOTICE, "CipherName       : %s\n",$bev->sslGetCipherName());
	    debug::printf(LOG_NOTICE, "CipherProtocol   : %s\n",$bev->sslGetProtocol());
	    $this->base->exit(NULL);
	  }
	  return;
      } 
      elseif ($events & (EventBufferEvent::ERROR))
      {
	  $dnserror=$smtp_connection->bev->getDnsErrorString();
	  $sslerror=$smtp_connection->bev->sslError();
	  $sockcode=EventUtil::getLastSocketErrno();
	  $sockError=EventUtil::getLastSocketError();

	  $arguments=array();
	  if ($sockError!=0) 
	  {
	    $arguments[]="Socket Error:".$sockError.'('.$sockcode.')';
	    $smtp_connection->server_error = $sockcode;
	  }
	  else if ($sslerror!==false) 
	  {
	   $arguments[]="SSL Error:".$sslerror;
	   $smtp_connection->server_error = $sslerror;
	  }
	  else if ($dnserror!="") 
	  {
	    $arguments[]="DNS ERROR:".$dnserror;
	    $smtp_connection->server_error = $dnserror;
	  }

	  debug::printf(LOG_ERR,"Erreur msg:<%s>\n",implode(',',$arguments));

	  $smtp_connection->server_responses=$arguments;
	  $smtp_connection->server_code = FALSE;
	  $this->ev_close($smtp_connection);
	  $this->base->exit(NULL);
	  debug::printf(LOG_ERR,"Connection Error - Exit\n");
	  return;
      }
      elseif ($events & (EventBufferEvent::TIMEOUT)) 
      {
	  $code=EventUtil::getLastSocketErrno();
	  $arguments=array("Connection Timeout","SocketErrMsg:".EventUtil::getLastSocketError()."(".$code.")");

	  $smtp_connection->server_responses=$arguments;
	  $smtp_connection->server_error = $code;
	  $smtp_connection->server_code = FALSE;
	  $this->ev_close($smtp_connection);
	  $this->base->exit(NULL);
	  debug::printf(LOG_ERR,"Connection Timeout - Exit\n");
	  return;
      }
      elseif ($events & (EventBufferEvent::EOF)) 
      {
	  /*
	  $code=EventUtil::getLastSocketErrno();
	  $arguments=array("Connection Close","SocketErrMsg:".EventUtil::getLastSocketError()."(".$code.")");

	  $smtp_connection->server_responses=$arguments;
	  $this->server_error = $code;
	  $smtp_connection->server_code= FALSE;
	  */
	  //$this->ev_close($smtp_connection);
	  //$smtp_connection->bev->free();
	  //$this->base->exit(NULL);
	  $smtp_connection->closed=true;
	  debug::printf(LOG_ERR,"Connection Close\n");
	  return;
      }
      debug::printf(LOG_ERR,"Unknown Event: %s!\n",$events);
  }

  ////////////////////////////////////////////////////////////////////////////
  // read response and exit loop
  // store the last response code and argument in
  // $smtp_connection->server_code
  // $smtp_connection->server_responses[]
  //
  // TOTO: add maximum bytes/line response to protect from bad server
  public function ev_readcb($bev, $smtp_connection) 
  {
    $input = $bev->getInput();

    debug::printf(LOG_DEBUG,"====> nb octet(s) in buffer : %s\n",$input->length);
    while(($line = $input->readLine(EventBuffer::EOL_CRLF))!==NULL)
    {
      debug::printf(LOG_DEBUG,"====> Response Read: %s\n",NetTool::toprintable($line));
      /* If we receive an empty line, the connection was closed. */
      if (empty($line)) 
      {
	  debug::printf(LOG_ERR,"Line Empty!\n");
	  $smtp_connection->server_responses[]="Empty line!!";
	  $smtp_connection->server_code = -1;
	  $this->base->exit(NULL);
          return;
      }

      /* Read the server_code and store the rest in the server_responses array. */
      $code = substr($line, 0, 3);
      if ($smtp_connection->server_code!=0&&$smtp_connection->server_code!=$code)
      {
	  $smtp_connection->server_responses[]="Server respond with different code in the same response!!";
	  $smtp_connection->server_code = -1;
	  $this->base->exit(NULL);
	  return;
      }
      $code = substr($line, 0, 3);
      if ($smtp_connection->server_code!=0&&$smtp_connection->server_code!=$code)
      {
	  $smtp_connection->server_responses[]="Server Response with different response code!!";
	  $smtp_connection->server_code = -1;
	  $this->base->exit(NULL);
	  return;
      }
      $smtp_connection->server_responses[] = trim(substr($line, 4));

      /* Check the syntax of the response server_code. */
      if (is_numeric($code)) 
      {
	  $smtp_connection->server_code = (int)$code;
      } 
      else 
      {
	  $smtp_connection->server_responses[]="Response code not numeric!!";
	  $smtp_connection->server_code = -1;
	  $this->base->exit(NULL);
	  return;
      }

      debug::printf(LOG_INFO,"SMTP < %s\n",NetTool::toprintable($line));

      /* If this is not a multiline response, we're done. */
      $eor=substr($line, 3, 1);
      if ($eor===" ") 
      {
	$this->base->exit(NULL);
	return;
      }
    } 
  }

  ////////////////////////////////////////////////////////////////////////////
  // ev_write write a message to the current buffer event and trace the message
  ////////////////////////////////////////////////////////////////////////////

  private function ev_write($smtp_connection,$string,$message=null)
  {
     $ret=$smtp_connection->bev->write($string);
     if ($ret!=true)
     {
       $smtp_connection->server_code=-1;
       $smtp_connection->server_responses[]="write error! socket already close ?";
       debug::printf(LOG_ERR,"Write Error! socket already close ?\n");
     }
     else
     {
       if ($message===null)
       debug::printf(LOG_INFO,"SMTP > %s\n",NetTool::toprintable($string));
       else
       debug::printf(LOG_INFO,"SMTP > %s\n",NetTool::toprintable($message));
     }
     return $ret;
  }

  ////////////////////////////////////////////////////////////////////////////
  // valid response $code with $valid code(s)
  // $valid can be an array or one value
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_IsValidResponse($smtp_connection,$code,$valid)
  {
    /* Compare the server's response code with the valid code/codes. */
    if (is_int($valid) && ($code === $valid)) {
	return true;
    } elseif (is_array($valid) && in_array($code, $valid, true)) {
	return true;
    }
    $smtp_connection->server_code=-1;
    $smtp_connection->server_responses[]="Not Valid Response for this SMTP CMD!";
    return false;
  }

  ////////////////////////////////////////////////////////////////////////////
  // parse xmcd (xclient/xforward) args
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_XCMDHeloArgs_Check($xcmdheloargs,$autorized_attr)
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

  ////////////////////////////////////////////////////////////////////////////
  // reinit server response flag and dispatch to read server response
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_ReadResponses($smtp_connection)
  {
      // init the response property
      $smtp_connection->server_responses=array();
      $smtp_connection->server_code=0;

      // dispatch to receved conection helo 
      $this->base->dispatch();

      debug::print_r(LOG_DEBUG,$smtp_connection->server_responses);
      debug::printf(LOG_DEBUG,"Server Response code:%s\n",$smtp_connection->server_code);
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send complete message transaction ehlo/starttls/mailfrom/rcpt to/data/quit
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_SendMessage($smtp_connection,$mailfrom,$recipient,&$data)
  {
    // read server helo, send ehlo/helo, send starttls if necessary
    if ($this->_SMTP_ConnectionHandCheck($smtp_connection)!=true)
    {
      return false;
    }

    // message transaction
    if ($this->_SMTP_MessageTransaction($smtp_connection,$mailfrom,$recipient,$data)!==true)
    {
      return false;
    }

    // quit server
    if ($this->_SMTP_QUIT($smtp_connection)!==true)
    {
      return false;
    }

  }

  ////////////////////////////////////////////////////////////////////////////
  // Send Connection handcheck (connection helo/client ehlo/starttls)
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_ConnectionHandCheck($smtp_connection)
  {
    debug::printf(LOG_INFO,"Do SMTP handcheck with <%s> host...\n",$smtp_connection->server_host);
    if ($smtp_connection->handcheck===true)
    {
       debug::printf(LOG_ERR,"SMTP Handcheck already done !\n");
       return true;
    }

    // banner helo
    if ($this->_SMTP_ConnectionHello($smtp_connection)!==true)
    {
      $this->ev_close($smtp_connection);
      return false;
    }

    // init tls state
    $smtp_connection->tls_activated=false;
    // closed state
    $smtp_connection->closed=false;

    // ehlo/helo/starttls
    do
    {
      $helo_code=$this->_SMTP_Hello($smtp_connection);
      if      ($helo_code===self::CLOSE)
      {
	$this->ev_close($smtp_connection);
	return false;
      }
      else if ($helo_code===self::QUIT)
      {
	$this->_SMTP_QUIT($smtp_connection);
	return false;
      }
      else if ($helo_code===self::STARTTLS)
      {
	if ($this->_SMTP_STARTTLS($smtp_connection)!==true) return false;
        $sendehloaftertls=true;
      }
      else
      {
	if ($smtp_connection->xclient_sent===false)
	{
	  $retxclient=$this->_SMTP_XCLIENT($smtp_connection,$this->xclient);
	  if      ($retxclient===false)      return false;
	  else if ($retxclient===self::HELO) $sendehloaftertls=true;
	}
	else
	$sendehloaftertls=false;
      }
    } while($sendehloaftertls==true);


    if ($this->_SMTP_XFORWARD($smtp_connection)!==true) return false;

    $smtp_connection->handcheck=true;
    debug::printf(LOG_INFO,"SMTP handcheck done...\n");
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send Repeatable Message Transaction
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_MessageTransaction($smtp_connection,$mailfrom,$recipient,&$data)
  {
    if ($this->_SMTP_MAILFROM($smtp_connection,$mailfrom)!==true)
    {
      return false;
    }
    if ($this->_SMTP_RCPT($smtp_connection,$recipient)!==true)
    {
      return false;
    }
    if ($this->_SMTP_DATA($smtp_connection)!==true)
    {
      return false;
    }
    if ($this->_SMTP_DATASend($smtp_connection,$data)!==true)
    {
      return false;
    }
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // read the connection server hello
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_ConnectionHello($smtp_connection)
  {
      $this->_SMTP_ReadResponses($smtp_connection);
      if ($this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,220)!==true)
      {
        debug::printf(LOG_ERR,"Connect response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
	return false;
      }

       $smtp_connection->server_estmp=false;
      // check if are ESMTP server
      if (preg_match("/ESMTP/i",$smtp_connection->server_responses[0])==1) 
      {
	debug::printf(LOG_INFO,"ESMTP Server detected...\n");
	$smtp_connection->server_estmp=true;
      }

      debug::printf(LOG_DEBUG,"Connect OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
      return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send EHLO/HELO and read/analyse the response
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_Hello($smtp_connection,$forceehlo=false)
  {
    // send by default EHLO if forceehlo is set to true or if the server as the ESMTP support
    if ($this->forceehlo===true||$smtp_connection->estmp===true&&$forceehlo===true)
    {
      $retcode=$this->ev_write($smtp_connection,"EHLO ".$this->helohost."\r\n");
    }
    // Send HELO
    else
    {
      $retcode=$this->ev_write($smtp_connection,"HELO ".$this->helohost."\r\n");
    }

    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,250)!==true)
    {
       debug::printf(LOG_ERR,"EHLO <%s>, response error, code: %s arguments: %s \n",$this->helohost,$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       return self::CLOSE;
    }
    debug::printf(LOG_DEBUG,"EHLO OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);

    //debug::print_r(LOG_DEBUG,$retcode);
    // parse extension reponse
    $smtp_connection->server_maxsize=0;
    $smtp_connection->server_tls=false;
    $smtp_connection->server_8bitmime=false;
    $smtp_connection->server_xclient=array();
    $smtp_connection->server_xforward=array();

    foreach($smtp_connection->server_responses as $value)
    {
      //debug::printf(LOG_DEBUG,"Check response arguments: %s \n",$value);

      // http://www.postfix.org/XFORWARD_README.html
      if (preg_match('/^XFORWARD\s(.+)/i', $value, $arr)==1)
      {
	debug::printf(LOG_INFO,"The Server annonce XFORWARD support\n");
	$xforward_ret=$this->_SMTP_XCMDHeloArgs_Check($arr[1],self::$xforward_name);
	if (isset($xforward_ret[1]))
	foreach($xforward_ret[1] as $args)
	   debug::printf(LOG_INFO,"The Server annonce XFORWARD unknown attribut:%s, go to ignore it\n",$args);
	$smtp_connection->server_xforward=$xforward_ret;
	debug::print_r(LOG_DEBUG,$xforward_ret);
	continue;
      }

      // http://www.postfix.org/XCLIENT_README.html
      if (preg_match('/^XCLIENT\s(.+)/i', $value, $arr)==1)
      {
	debug::printf(LOG_INFO,"The Server annonce XCLIENT support\n");
	$xclient_ret=$this->_SMTP_XCMDHeloArgs_Check($arr[1],self::$xclient_name);
	if (isset($xclient_ret[1]))
	foreach($xclient_ret[1] as $args)
	   debug::printf(LOG_INFO,"The Server annonce XCLIENT unknown attribut:%s, go to ignore it\n",$args);
	$smtp_connection->server_xclient=$xclient_ret;
	debug::print_r(LOG_DEBUG,$xclient_ret);
	continue;
      }

      // 8BITMIME support
      // http://cr.yp.to/smtp/8bitmime.html
      if (preg_match('/^8BITMIME/i', $value)==1)
      {
	debug::printf(LOG_INFO,"The Server annonce 8bit Mime support\n");
	$smtp_connection->server_8bitmime=true;
	continue;
      }

      // match startls support
      // http://en.wikipedia.org/wiki/STARTTLS
      // https://tools.ietf.org/html/rfc3207
      if (preg_match('/^STARTTLS/i', $value)==1)
      {
	if ($smtp_connection->tls_activated===true)
	{
	  debug::printf(LOG_INFO,"The Server annonce STARTTLS support in TLS connection, is not conforme to rfc3207!\n");
	  return self::QUIT;
	}

	debug::printf(LOG_INFO,"The Server annonce STARTTLS support\n");
	$smtp_connection->server_tls=true;
	continue;
      }

      // match size support
      // http://cr.yp.to/smtp/size.html
      if (preg_match('/^SIZE ([0-9]*)/i', $value, $sizes)==1)
      {
	$smtp_connection->server_maxsize=$sizes[1];
	debug::printf(LOG_INFO,"The Server annonce Message Max Size of %s\n",$smtp_connection->server_maxsize);
	continue;
      }
    }

    // check message size with server size receved
    if ($smtp_connection->server_maxsize>0&&$this->datalen>$smtp_connection->server_maxsize)
    {
      debug::printf(LOG_ERR,"Message Size %s is grether than max message size %s - abort!\n",$this->datalen,$smtp_connection->server_maxsize);
      return self::QUIT;
    }
    debug::print_r(LOG_DEBUG,$smtp_connection->server_responses);

    // check STARTTLS Support and start tls session if the client is configured to use tls
    if ($this->tls===true&&$smtp_connection->server_tls===true&&$smtp_connection->tls_activated!==true)
    {
      debug::printf(LOG_NOTICE,"Try to Start STARTTLS handcheck...\n");
      return self::STARTTLS;
    }

    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send STARTTLS cmd and upgrade the socket connection to TLS (after STARTTLS)
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_STARTTLS($smtp_connection)
  {	
    $retcode=$this->ev_write($smtp_connection,"STARTTLS\r\n");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,220)!==true)
    {
       debug::printf(LOG_ERR,"STARTTLS <%s>, response error, code: %s arguments: %s \n",$this->helohost,$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       return self::CLOSE;
    }
    debug::printf(LOG_DEBUG,"STARTTLS OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);

    debug::printf(LOG_INFO,"Entering in TLS Hand check...\n");
    $ev_options= EventBufferEvent::SSL_CONNECTING | EventBufferEvent::SSL_OPEN;
    $bev = EventBufferEvent::sslSocket($this->base, $smtp_connection->bev->fd, $this->sslctx, $ev_options);
    if (!$bev) 
    {
      $smtp_connection->server_responses=array();
      $smtp_connection->server_code=0;
      $sslerror=$bev->sslError();
      debug::printf(LOG_ERR,"Fail when creating socket bufferevent in ssl: %s\n",$sslerror);
      $smtp_connection->bev->close();
      $smtp_connection->bev->free();
      $bev->free();
      return false;
    }
    $smtp_connection->bev->free();
    $smtp_connection->bev=$bev;
    $smtp_connection->bev->setCallbacks(array($this,"ev_readcb"), /* writecb */ NULL, array($this,"ev_eventcb"), $smtp_connection);
    $smtp_connection->bev->enable(Event::READ | Event::WRITE);
    $smtp_connection->bev->setTimeouts($this->readtimeout,$this->writetimeout);
    $smtp_connection->tls_activated=true;
    $this->base->dispatch(); // to handle the connect event
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send Mail from
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_MAILFROM($smtp_connection,$mailfrom)
  {	
    $retcode=$this->ev_write($smtp_connection,"MAIL FROM:<".$mailfrom.">\r\n");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,250)!==true)
    {
       $this->ev_close($smtp_connection);
       debug::printf(LOG_ERR,"MAIL FROM <%s>, response error, code: %s arguments: %s \n",$mailfrom,$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       return false;
    }
    debug::printf(LOG_DEBUG,"MAIL FROM OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send Reciepient To
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_RCPT($smtp_connection,$recipient)
  {	
    $retcode=$this->ev_write($smtp_connection,"RCPT TO:<".$recipient.">\r\n");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,array(250,251))!==true)
    {
       $this->ev_close($smtp_connection);
       debug::printf(LOG_ERR,"RCPT TO <%s>, response error, code: %s arguments: %s \n",$recipient,$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       return false;
    }
    if ($smtp_connection->server_code===251)
      debug::printf(LOG_NOTICE,"RCPT TO OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    else
      debug::printf(LOG_DEBUG,"RCPT TO OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send DATA cmd
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_DATA($smtp_connection)
  {	
    $retcode=$this->ev_write($smtp_connection,"DATA\r\n");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,354)!==true)
    {
       $this->ev_close($smtp_connection);
       debug::printf(LOG_ERR,"DATA response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       return false;
    }
    debug::printf(LOG_DEBUG,"DATA OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send the data
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_DATASend($smtp_connection,&$data)
  {
    $retcode=$this->ev_write($smtp_connection,$data,"Data Sending");
    if ($retcode===true) $retcode=$this->ev_write($smtp_connection,"\r\n.\r\n","Data <CRLF>.<CRLF> sending");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,250)!==true)
    {
       $this->ev_close($smtp_connection);
       debug::printf(LOG_ERR,"DATA Sent response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       return false;
    }
    debug::printf(LOG_DEBUG,"DATA Sent OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send QUIT cmd and close the connection
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_QUIT($smtp_connection)
  {
    $retcode=$this->ev_write($smtp_connection,"QUIT\r\n");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,221)!==true)
    {
       debug::printf(LOG_ERR,"QUIT response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       $this->ev_close($smtp_connection);
       return false;
    }
    debug::printf(LOG_DEBUG,"QUIT OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    if (isset($smtp_connection->bev)) $this->ev_close($smtp_connection);
    return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send XCLIENT cmd 
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_XCLIENT($smtp_connection)
  {
    if (count($smtp_connection->server_xclient[0])>0)
    {
      $args="";
      foreach($this->xclient as $key =>$value)
      {
	debug::printf(LOG_DEBUG,"xclient value[%s]='%s'\n",$key,$value);
	if (strlen($args)+strlen($key)+strlen($value)+1<512)
	{
	   $args.=sprintf(" %s=%s",$key,$value);
	   debug::printf(LOG_DEBUG,"xclient args='%s'\n",$args);
	}
	else
	{
	   $smtp_connection->server_code=-1;
	   $smtp_connection->server_responses[]="xclient options line size to long!";
	   debug::printf(LOG_DEBUG,"xclient options line size to long!\n");
	   return false;
	}
      }
      if ($args!="")
      {
	 debug::printf(LOG_DEBUG,"xclient args='%s'\n",$args);
	 $retcode=$this->ev_write($smtp_connection,"XCLIENT".$args."\r\n");
	 $this->_SMTP_ReadResponses($smtp_connection);
	 if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,220)!==true)
	 {
	    debug::printf(LOG_ERR,"XCLIENT response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
	    return false;
	 }
	 debug::printf(LOG_DEBUG,"XCLIENT OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
	 $smtp_connection->xclient_sent=true;
	 return self::HELO;
      }
    }
    return true;
  }


  ////////////////////////////////////////////////////////////////////////////
  // Send XFORWARD cmd 
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_XFORWARD($smtp_connection)
  {
    if (count($smtp_connection->server_xforward[0])>0)
    {
      $args="";
      foreach($this->xforward as $key =>$value)
      {
	debug::printf(LOG_DEBUG,"xforward value[%s]='%s'\n",$key,$value);
	if (strlen($args)+strlen($key)+strlen($value)+1<512)
	{
	   $args.=sprintf(" %s=%s",$key,$value);
	   debug::printf(LOG_DEBUG,"xforward args='%s'\n",$args);
	}
	else
	{
          if ($this->__SMTP_XFORWARD($smtp_connection,$args)!=true) return false;
	  $args=sprintf(" %s=%s",$key,$value);
	}
      }
      if ($args!="")
        if ($this->__SMTP_XFORWARD($smtp_connection,$args)!=true) return false;
    }
    return true;
  }

  private function __SMTP_XFORWARD($smtp_connection,$args)
  {
     debug::printf(LOG_DEBUG,"xforward args='%s'\n",$args);
     $retcode=$this->ev_write($smtp_connection,"XFORWARD".$args."\r\n");
     $this->_SMTP_ReadResponses($smtp_connection);
     if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,250)!==true)
     {
	debug::printf(LOG_ERR,"XFORWARD response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
	return false;
     }
     debug::printf(LOG_DEBUG,"XFORWARD OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
     return true;
  }

  ////////////////////////////////////////////////////////////////////////////
  // Send RSET cmd 
  ////////////////////////////////////////////////////////////////////////////

  private function _SMTP_RSET($smtp_connection)
  {

    $retcode=$this->ev_write($smtp_connection,"RSET\r\n");
    $this->_SMTP_ReadResponses($smtp_connection);
    if ($retcode!==true||$this->_SMTP_IsValidResponse($smtp_connection,$smtp_connection->server_code,250)!==true)
    {
       debug::printf(LOG_ERR,"RSET response error, code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
       $this->ev_close($smtp_connection);
       return false;
    }
    debug::printf(LOG_DEBUG,"RSET OK code: %s arguments: %s \n",$smtp_connection->server_code,$smtp_connection->server_responses[0]);
    $this->ev_close($smtp_connection);
    return true;
  }
}


