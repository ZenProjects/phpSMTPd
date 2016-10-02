<?php
namespace phpSMTPd\Exceptions;

 /*
 * Author: Mathieu CARBONNEAUX 
 */

class SMTPException extends \Exception 
{
  protected $LogLevel;

  public function __construct() 
  {
     $num_args = func_num_args();
     if ($num_args<=1) return;
     $args=func_get_args();
     unset($args[0]);
     unset($args[1]);
     $this->LogLevel=func_get_arg(0);
     if ($this->LogLevel!=LOG_INFO&&$this->LogLevel!=LOG_NOTICE)
     { 
       $backtrace=debug_backtrace();
       $trace=$backtrace[0];
       $file=strtr($trace['file'],array(Debug::$basedir=>""));
       $vargs=array_merge($args,array($file,$trace['line']));
       $format=func_get_arg(1);
       $format=preg_replace("/\n$/","",$format);
       $this->message=vsprintf($format." at %s:%s\n",$vargs);
     }
     else
     {
       $this->message=vsprintf(func_get_arg(1),$args);
     }
  }

  public function log()
  {
   if ($this->LogLevel>Debug::$loglevel) return;
    syslog($this->LogLevel,$this->message);
  }

  public function getLogLevel()
  {
    return $this->LogLevel;
  }
}
