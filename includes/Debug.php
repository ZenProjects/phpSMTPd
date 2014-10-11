<?php
namespace SuperListd;

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
       $trace=debug_backtrace()[0];
       $vargs=array_merge($args,array($trace['file'],$trace['line']));
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
    syslog($this->LogLevel,$this->message);
  }

  public function getLogLevel()
  {
    return $this->LogLevel;
  }
}

class Debug
{
  public function open($logname="SuperListd",$syslog_flag=LOG_PID,$syslog_facility=LOG_MAIL) 
  {
      openlog($logname, LOG_PID, LOG_MAIL);
  }
  static function exit_with_error($arg)
  {
     /*
     print("\n");
     debug_print_backtrace();
     print("\n");
     */
     $num_args = func_num_args();
     if ($num_args<=1) return;
     $args=func_get_args();
     unset($args[1]);
     $trace=debug_backtrace()[0];
     $vargs=array_merge($args,array($trace['file'],$trace['line']));
     $format=func_get_arg(1);
     $format=preg_replace("/\n$/","",$format);
     syslog(LOG_ERR,vsprintf("Exit with %s code - ".$format." at %s:%s\n",$vargs));
     exit(func_get_arg(0));
  }

  static function print_r($log_level,$array)
  {
     syslog($log_level,print_r($array,true));
  }

  static function printf()
  {
     /*
     print("\n");
     debug_print_backtrace();
     print("\n");
     */
     $num_args = func_num_args();
     if ($num_args<=1) return;
     $args=func_get_args();
     unset($args[0]);
     unset($args[1]);
     if (func_get_arg(0)!=LOG_INFO&&func_get_arg(0)!=LOG_NOTICE)
     { 
       $trace=debug_backtrace()[0];
       $vargs=array_merge($args,array($trace['file'],$trace['line']));
       $format=func_get_arg(1);
       $format=preg_replace("/\n$/","",$format);
       syslog(func_get_arg(0),vsprintf($format." at %s:%s\n",$vargs));
     }
     else
     {
       syslog(func_get_arg(0),vsprintf(func_get_arg(1),$args));
     }
  }
}

