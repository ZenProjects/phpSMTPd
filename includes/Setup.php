<?php
namespace SuperListd;


static $basedir="";

// set class autoloader to load class named <class name>.php in $basedir/includes/ directory
spl_autoload_register(function ($class) {
     global $basedir;
     if ($basedir!="")
     {
       $class=preg_replace("/^SuperListd\\\/","",$class);
       $file=$basedir."/includes/".$class.".php";
       if (file_exists($file))
           include_once($file);
     }
});

// search base installation dir
$basedir=preg_replace("/[\/]*includes$/","",__DIR__);
if (!is_dir($basedir))
{
  fprintf(STDERR,"The include path determined <%s> are not a directory!!!!\n",$basedir);
  fprintf(STDERR,"Error cannot determine where the daemon is installed, cannot start daemon!!!!\n");
  exit(-1);
}

// set include path based on basedir
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

// set the default debug log level et debug file base
Debug::$basedir=$basedir."/";

