<?php
namespace SuperListd;

require_once("Debug.php");

 /*
 * Author: Mathieu CARBONNEAUX 
 */

class NetTool 
{
   // RFC 1891 xtext encoding
   // http://tools.ietf.org/html/rfc1891
   // based on perl cpan module Convert-XText
   // http://search.cpan.org/~chrwin/Convert-XText-0.01/lib/Convert/XText.pm
   static function encode_xtext($string)
   {
      return preg_replace_callback('/([^!-*,-<>-~])/',
	  function ($matches) {
	     return "+".strtoupper(unpack('H*', $matches[0])[1]);
	  },$string);
   }
   static function decode_xtext($string)
   {
      return preg_replace_callback('/\+([0-9A-F]{2})/',
	  function ($matches) {
	     return chr(hexdec($matches[0]));
	  },$string);
   }

  static function is_ip($address)
  {
    // check if is ip or host
    if (($ip=@inet_pton($address))!==false)
    {
      $iplen=strlen($ip);
      if ($iplen==16) return AF_INET6;
      else if ($iplen==4) return AF_INET;
    }
    return false;
  }

  static function getmx($domain)
  {
    if (getmxrr($domain,$mxrr,$mxweight))
    {
       $mxs=array_combine($mxrr,$mxweight);
       asort($mxs);
       return $mxs;
    }
    return false;
  }

  static function gethostbyname($hostname,$ipv6=true)
  {
     $ip=false;
     $dnsrr=array();
     $ipv6flag=false;

     if ($ipv6===true) $dnsrr=dns_get_record($hostname,DNS_AAAA);
     if ($dnsrr===FALSE||count($dnsrr)==0)
     {
       $dnsrr=dns_get_record($hostname,DNS_A);
       if ($dnsrr===FALSE)
	  return false;
     }
     else
     {
       $ipv6flag=true;
     }

     //print_r($dnsrr);
     foreach($dnsrr as $key => $value)
     {
	if (isset($value['ip'])) $ip[]=$value['ip'];
	else $ip[]=$value['ipv6'];
     }

     if ($ip!==false)
     {
       shuffle($ip);
       if ($ipv6flag===true) $ip['type']=AF_INET6;
       else $ip['type']=AF_INET;
     }
     return $ip;
  }

  static function getFQDNHostname() 
  {
     $fd=popen("/bin/hostname -f","r");
     if ($fd===FALSE)
     {
      debug::printf(LOG_ERR, "hostname command not found fallback to gethostname()...\n");
      return gethostname();
     }
     $fdqnhostname=fgets($fd,4096);
     fclose($fd);
     $fdqnhostname=str_replace("\r\n", "", $fdqnhostname);
     $fdqnhostname=str_replace("\n", "", $fdqnhostname);
     if ($fdqnhostname=="") 
     {
      debug::printf(LOG_ERR, "hostname command not respond correctly fallback to gethostname()...\n");
      $fdqnhostname=gethostname();
     }
     return $fdqnhostname;
  }

  static function getNproc() 
  {
     $fd=popen("/usr/bin/nproc","r");
     if ($fd===FALSE)
     {
      debug::printf(LOG_ERR, "nproc command not found forcing max_workers to 1\n");
      return 1;
     }

     $nproc=fgets($fd,4096);
     fclose($fd);
     $nproc=str_replace("\r\n", "", $nproc);
     $nproc=intval(str_replace("\n", "", $nproc));
     if (!is_int($nproc)||$nproc<=0)
     {
       debug::printf(LOG_ERR, "nproc command (%s) not respond correctly forcing max_workers to 1\n", $nproc);
       return 1;
     }
     return $nproc;
  }

  static function toprintable($str)
  {
    return preg_replace_callback('/[\x00-\x1F\x80-\xFF]/u', 
      function($matches) {
	 return sprintf("%%%02X",ord($matches[0]));
    }, $str);
  }


}

