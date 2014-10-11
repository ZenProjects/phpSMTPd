<?php
namespace PHP_SMTPd;

require_once("../includes/Debug.php");

openlog("PHP-SMTPd",LOG_PID|LOG_PERROR,LOG_MAIL);

try
{
   throw new SMTPException(LOG_ERR,"test %s\n",456);
} catch (SMTPException $e) {
   echo $e->getLogLevel()."\n";
   echo $e->getMessage()."\n";
   $e->log();
}

