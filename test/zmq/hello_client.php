<?php
/*
*  mail alias forwarder to zmq queue
*/

$EX_TEMPFAIL=75;
$EX_UNAVAILABLE=69;
$max_size=15*1024*1024;

if ($argc==2) $socket_name=$argv[1];
else $socket_name="ipc:///tmp/helloworld_in";

$context = new ZMQContext();

//  Socket to talk to server
$requester = new ZMQSocket($context, ZMQ::SOCKET_REQ);
$requester->connect($socket_name);
fprintf(STDERR,"Connected to queue %s...\n",$socket_name);

fprintf(STDERR,"Reading message from input...\n");
$contents = '';
$size=0;
while (!feof(STDIN)) {
   $readed = fread(STDIN, 1024);
   $size+=strlen($readed);
   fprintf(STDERR,"Message Readed Size:%s\n",$size);
   $contents .= $readed;
}

fprintf(STDERR,"Message Size:%s\n",$size);

if ($size>=$max_size) 
{
  printf("Error: la taille du message (%s) est plus grand que (%s)\n",$size,$max_size);
  exit($EX_UNAVAILABLE);
}

$requester->send($contents);
fprintf(STDERR,"Message Sended...\n");
exit(0);
