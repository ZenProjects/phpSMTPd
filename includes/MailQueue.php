<?php
namespace SuperListd;

require_once("RFC822.php");
require_once("Debug.php");


class MailQueue
{
  public $options = [];

  public function __construct($options=null) 
  {
    $this->options=$options;
  }

  public function makequeue($queue)
  {
    $base_dir=$this->options["queue_dir"];
    if (!is_dir($base_dir)&&!mkdir($base_dir,0766))
      debug::exit_with_error(5,"Couldn't create queue base dir: %s\n",$base_dir);

    $queue_dir=$base_dir."/".$queue;
    if (!is_dir($queue_dir)&&!mkdir($queue_dir,0766))
      debug::exit_with_error(5,"Couldn't create queue dir %s: %s\n",$queue,$queue_dir);
    
    for($i=0;$i<=15;$i++)
    {
      $subdir=$queue_dir."/".strtoupper(dechex($i));
      if (!is_dir($subdir)&&!mkdir($subdir,0766))
        debug::exit_with_error(5,"Couldn't create subdir %s of queue %s\n",$subdir,$queue);
    }

  }

  public function enqueue($queue,$enveloppe,$data)
  {
    $oldumask=umask();
    umask(0177);

    $base_dir=$this->options["queue_dir"];
    $queue_dir=$base_dir."/".$queue;

    try 
    {
      $microsecondes=strtoupper(substr(dechex(microtime(true)*1000000),-5));
      $key=sprintf("%s%06s",$microsecondes,strtoupper(substr(dechex(mt_rand()),-6)));
      $subdir=$queue_dir."/".substr($key,0,1);
      if (!is_dir($subdir)) throw new SMTPException(LOG_ERR,"enqueue Subdir not exit %s\n",$subdir);

      $file=$subdir."/N".$key;

      if (file_exists($file))
	 debug::printf(LOG_ERR,"enqueue Message file key %s alrady exist!!! truncate it !\n",$file);

      if (!isset($enveloppe['clientDataLen']))
	 $enveloppe['clientDataLen']=strlen($data);

      if ($enveloppe['clientDataLen']>9999999999) 
	 new SMTPException(LOG_ERR,"enqueue Message data size to long %s\n",$enveloppe_len);

    } catch(Exception $e) {
      $e->log();
      umask($oldumask);
      return false;
    }

    debug::printf(LOG_NOTICE,"enqueue message enquing to file %s\n",$file);
    try 
    {
      $fp = fopen($file,"w+");
      $stats=fstat($fp);
      if ($fp===FALSE) throw new SMTPException(LOG_ERR,"enqueue Impossible de crée le fichier %s\n",$file);

      if (!isset($stats['ino'])) throw new SMTPException(LOG_ERR,"enqueue inod not found\n");
      $key=sprintf("%s%06s",$microsecondes,strtoupper(substr(dechex($stats['ino']),-6)));
      $enveloppe["message_log_id"]=$key;

      $enveloppe_serialized=serialize($enveloppe)."\n";
      $enveloppe_len=strlen($enveloppe_serialized);
      if ($enveloppe_len>99999999) throw new SMTPException(LOG_ERR,"enqueue Message enveloppe size to long %s\n",$enveloppe_len);


      $nb=fprintf($fp,"MSG_VERSION:01\r\n");
      if ($nb==0) throw new SMTPException(LOG_ERR,"enqueue fprintf error\n");

      $nb=fprintf($fp,"ENVELOPPE:%08d\r\n",$enveloppe_len);
      if ($nb==0) throw new SMTPException(LOG_ERR,"enqueue fprintf error\n");

      $nb=fwrite($fp,$enveloppe_serialized);
      if ($nb==0) throw new SMTPException(LOG_ERR,"enqueue fwrite error\n");

      $nb=fprintf($fp,"DATA:%010d\r\n",$enveloppe['clientDataLen']);
      if ($nb==0) throw new SMTPException(LOG_ERR,"enqueue fprintf error\n");

      $nb=fwrite($fp,$data);
      if ($nb==0) throw new SMTPException(LOG_ERR,"enqueue fwrite error\n");

      fclose($fp);

      $newfilename=$subdir."/".sprintf("%s%06s",$microsecondes,strtoupper(substr(dechex($stats['ino']),-6)));
      if (!rename($file,$newfilename))
	throw new SMTPException(LOG_ERR,"enqueue rename error\n");
      if (!chmod($newfilename,0700))
      {
	unlink($newfilename);
	throw new SMTPException(LOG_ERR,"enqueue chmod error\n");
      }
      debug::printf(LOG_NOTICE,"enqueue message file enqueued to %s\n",$newfilename);

    } catch(Exception $e) {
      $e->log();
      fclose($fp);
      unlink($file);
      umask($oldumask);
      return false;
    }
    umask($oldumask);
    return true;
  }
}
