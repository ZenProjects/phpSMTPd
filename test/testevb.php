<?php

    $cfg = new EventConfig();
    if ($cfg->avoidMethod("epoll")) {
         echo "Méthode 'epoll' ignorée\n";
    }
    $base = new EventBase($cfg);
    $fdin=fopen("filein.txt","r");
    umask(0177);
    unlink("fileout.txt");
    $fdout=fopen("fileout.txt","w");
    $evin=new Event($base,$fdin,Event::READ| Event::PERSIST, 'readcb',$fdout);
    if (!$evin)
    {
       fprintf(STDERR,"error d'initialisation de event\n");
    }
    $evin->add();

    function readcb($fd,$what, $ctx)
    {
        global $base,$evin;
	$lastread=fread($fd,256);
	$len=strlen($lastread);
	if ($len>0)
	{
	  printf("read %d octets\n",$len);
	  fwrite($ctx, $lastread);
	}
	else
	 $evin->del();
    }

    $base->dispatch();
