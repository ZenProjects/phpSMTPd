<?php

/*
*  Simple message queuing broker
*  Same as request-reply broker but using QUEUE device
* @author Ian Barber <ian(dot)barber(at)gmail(dot)com>
*/

$context = new ZMQContext();

//  Socket facing clients
$frontend = $context->getSocket(ZMQ::SOCKET_XPUB);
$frontend->bind("ipc:///tmp/helloworld_in");
//$frontend->setsockopt(ZMQ::SUBSCRIBE, "");

//  Socket facing services
$backend = $context->getSocket(ZMQ::SOCKET_XSUB);
$backend->bind("ipc:///tmp/helloworld_out");

//  Start built-in device
$device = new ZMQDevice($frontend, $backend);

$device->run();

//  We never get here…
