<?php
namespace phpSMTPd\SMTP\Queues;

require_once("RFC822.php");
require_once("Debug.php");


class QueueIterator implements \Iterator {
    private $position = 0;
    private $msgs = array();  

    public function __construct($queuemessage) {
        $this->position = 0;
        if (is_array($queuemessage)) $this->msgs=$queuemessage;
    }

    function rewind() {
        $this->position = 0;
    }

    function count() {
        return count($this->msgs);
    }

    function get($key) {
        return $this->msgs[$key];
    }

    function current() {
        return $this->msgs[$this->position];
    }

    function key() {
        return $this->position;
    }

    function next() {
        ++$this->position;
    }

    function valid() {
        return isset($this->msgs[$this->position]);
    }
}

class MailQueue
{
  public $options = [];

  public function __construct($options=null) 
  {
    $this->options=$options;
  }

  public function QueueCount($queue)
  {
     $base_dir=$this->options["queue_dir"]."/".$queue;
     $count=0;
     if (is_dir($base_dir)) 
     {
       // for each queue subdir 0-F
       for($i=0;$i<15;$i++)
       {
	 $subdir=strtoupper(dechex($i));
	 if (is_dir($base_dir."/".$subdir))
	 {
	   // list files in it
	   if ($dh = opendir($base_dir."/".$subdir)) 
	   {
	     while (($file = readdir($dh)) !== false) 
	     {
	       $file_abs=$base_dir."/".$subdir."/".$file;
	       // for each file that match /^[0-9A-Fa-f]{11}/ increment count
	       if (filetype($file_abs) == "file"&&preg_match("/^[0-9A-Fa-f]{11}/",$file)==1)
	       {
		 //echo "fichier : $file_abs : type : " . filetype($file_abs) . "\n";
		 $count++;
	       }
	     }
	     closedir($dh);
	   }
	 }
       }
       //debug::printf(LOG_INFO,"QueueCount queue %s = %s\n",$queue,$count);
       return $count;
     }
     debug::printf(LOG_ERR,"QueueCount queue <%s> not found !\n",$base_dir);
     return -1;
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

  public function browsequeue($queue)
  {
     $base_dir=$this->options["queue_dir"]."/".$queue;
     $count=0;
     $msg_list=array();

     if (is_dir($base_dir)) 
     {
       // for each queue subdir 0-F
       for($i=0;$i<15;$i++)
       {
	 $subdir=strtoupper(dechex($i));
	 if (is_dir($base_dir."/".$subdir))
	 {
	   // list files in it
	   if ($dh = opendir($base_dir."/".$subdir)) 
	   {
	     while (($file = readdir($dh)) !== false) 
	     {
	       $file_abs=$base_dir."/".$subdir."/".$file;
	       // for each file that match /^[0-9A-Fa-f]{11}/ increment count
	       if (filetype($file_abs) == "file"&&preg_match("/^[0-9A-Fa-f]{11}/",$file)==1)
	       {
		 try 
		 {
		   $fd=fopen($file_abs,"r");
		   if ($fd===false)
			 throw new SMTPException(LOG_ERR,"dequeue file %s unable to open!\n",$file_abs);

		   // ne compte que les fichier marquer executable
		   $stat=fstat($fd);
		   if (($stat['mode']&0100)==64)
		   {
		     $message_info=$this->getEnveloppe($fd,$file_abs);
		     if ($message_info==false) throw new SMTPException(LOG_ERR,"browsequeue getEnveloppe error on file %s !\n",$file_abs);
		     $msg_list[$count++]=$message_info;
		   }
		 } catch(SMTPException $e) {
		   $e->log();
		   fclose($fd);
		 }
	       }
	     }
	     closedir($dh);
	   }
	 }
       }
       debug::printf(LOG_INFO,"browsequeue queue %s = %s\n",$queue,$count);
       return array('size'=>$count,'msg_list'=>new QueueIterator($msg_list));
     }
     debug::printf(LOG_ERR,"browsequeue queue <%s> not found !\n",$base_dir);
     return false;
  }

  public function getEnveloppe($fd,$file_abs)
  {
     try 
     {
       $file=basename($file_abs);

       // read version
       $version=fread($fd,16);
       if ($version==false) 
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s unable to read version!\n",$file_abs);
       if (strncmp($version,"MSG_VERSION:01\r\n",16)!=0) 
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s unknow version!\n",$file_abs);

       // read enveloppe header to determine enveloppe size
       $header_enveloppe=fread($fd,20);
       if ($header_enveloppe==false) 
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s unable to read header enveloppe!\n",$file_abs);
       if (strncmp($header_enveloppe,"ENVELOPPE:",10)!=0)
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s header enveloppe not found !\n",$file_abs);
       $enveloppe_lenght=intval(substr($header_enveloppe,10,8));
       if ($enveloppe_lenght<=0)
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s header enveloppe size error (%s) !\n",$file_abs,$enveloppe_lenght);

       // read enveloppe
       $enveloppe=fread($fd,$enveloppe_lenght);
       if ($enveloppe==false) 
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s unable to read enveloppe!\n",$file_abs);
       $enveloppe=unserialize($enveloppe);
       if ($enveloppe==false) 
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s unable unserialize enveloppe!\n",$file_abs);

       // read data header to determine data size
       $header_data=fread($fd,17);
       if ($header_data==false) 
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s unable to read header data!\n",$file_abs);
       if (strncmp($header_data,"DATA:",5)!=0)
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s header data not found !\n",$file_abs);
       $data_lenght=intval(substr($header_data,5,10));
       if ($data_lenght<=0)
	     throw new SMTPException(LOG_ERR,"getEnveloppe file %s header enveloppe size error (%s) !\n",$file_abs,$enveloppe_lenght);

       $message["message_id"]=$file;
       $message["file"]=$file_abs;
       $message["enveloppe"]=$enveloppe;
       $message["enveloppe_size"]=$enveloppe_lenght;
       $message["data_size"]=$data_lenght;
       return $message;
     } catch(SMTPException $e) {
       $e->log();
       return false;
     }
  }

  public function dequeue($queue,$msgid,$remove=false)
  {
     $base_dir=$this->options["queue_dir"]."/".$queue;
     if (is_dir($base_dir)) 
     {
       try 
       {
	 $subdir=$base_dir."/".substr($msgid,0,1);
	 if (!is_dir($base_dir))
	     throw new SMTPException(LOG_ERR,"dequeue directory %s not found!\n",$subdir);

	 $file_abs=$subdir."/".$msgid;
	 if (!file_exists($file_abs)) 
	     throw new SMTPException(LOG_ERR,"dequeue file %s not found!\n",$file_abs);

	 $fd=fopen($file_abs,"r+");
	 if ($fd===false)
	     throw new SMTPException(LOG_ERR,"dequeue file %s unable to open!\n",$file_abs);

	 if (!flock($fd, LOCK_EX)) 
	     throw new SMTPException(LOG_ERR,"dequeue file %s unable lock!\n",$file_abs);
	 if (!chmod($file_abs,0600))
	     throw new SMTPException(LOG_ERR,"dequeue chmod u-x of %s error!\n",$file_abs);

	 $message_enveloppe=$this->getEnveloppe($fd,$file_abs);
	 if ($message_enveloppe==false) 
	     throw new SMTPException(LOG_ERR,"dequeue getEnveloppe error on file %s!\n",$file_abs);

	 $data=fread($fd,$message_enveloppe['data_size']);
	 if ($data==false)
	     throw new SMTPException(LOG_ERR,"dequeue file %s unable to read header data!\n",$file_abs);
	 if ($remove==true)
	 {
	   if (!unlink($file_abs)) 
	     throw new SMTPException(LOG_ERR,"dequeue unable to remove file %s!\n",$file_abs);
	 }

       } catch(SMTPException $e) {
	 $e->log();
	 @flock($fd, LOCK_UN);
	 @fclose($fd);
	 return false;
       }
       flock($fd, LOCK_UN);
       fclose($fd);
       return array('message_enveloppe'=>$message_enveloppe,
		    'message_data'=>$data);
     }
     debug::printf(LOG_ERR,"browsequeue queue <%s> not found !\n",$base_dir);
     return false;
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

    } catch(SMTPException $e) {
      $e->log();
      umask($oldumask);
      return false;
    }

    debug::printf(LOG_NOTICE,"enqueue message enqueing to tempory file %s\n",$file);
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
      debug::printf(LOG_NOTICE,"enqueue message completly writen to tempory file %s...\n",$file);
      debug::printf(LOG_NOTICE,"enqueue ... renamed to %s\n",$newfilename);

    } catch(SMTPException $e) {
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
