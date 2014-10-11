<?php
namespace SuperListd;

require_once("Debug.php");

 /*
 * Author: Mathieu CARBONNEAUX 
 */

class NetTool 
{
  static function getFQDNHostname() 
  {
     $fd=popen("hostname -f","r");
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
     $fd=popen("nproc","r");
     if ($fd===FALSE)
     {
      debug::printf(LOG_ERR, "nproc command not found forcing max_workers to 1\n");
      return 1;
     }

     $nproc=fgets($fd,4096);
     fclose($fd);
     $nproc=str_replace("\r\n", "", $nproc);
     $nproc=str_replace("\n", "", $nproc);
     if (!is_int($nproc)||$nproc<=0)
     {
       debug::printf(LOG_ERR, "nproc command not respond correctly forcing max_workers to 1\n");
       return 1;
     }
     return $nproc;
  }

}

