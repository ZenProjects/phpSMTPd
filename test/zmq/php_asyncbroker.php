<?php
/*
 * Asynchronous client-to-server (DEALER to ROUTER)
 *
 * While this example runs in a single process, that is just to make
 * it easier to start and stop the example. Each task has its own
 * context and conceptually acts as a separate process.
 * @author Ian Barber <ian(dot)barber(at)gmail(dot)com>
 */

/* ---------------------------------------------------------------------
 * This is our server task
 * It uses the multithreaded server model to deal requests out to a pool
 * of workers and route replies back to clients. One worker can handle
 * one request at a time but one client can talk to multiple workers at
 * once.
 */
function server_task()
{
    printf("starting proxy...\n");
    cli_set_process_title("ZMQ Broker");
    $context = new ZMQContext();

    //  Socket facing clients
    $frontend = $context->getSocket(ZMQ::SOCKET_ROUTER);
    $frontend->bind("ipc:///tmp/helloworld_in");

    //  Socket facing services
    $backend = $context->getSocket(ZMQ::SOCKET_DEALER);
    $backend->bind("ipc:///tmp/helloworld_out");

    //  Start built-in device
    $device = new ZMQDevice($frontend, $backend);

    $device->run();

}

function server_task_poll()
{
    printf("starting proxy poll...\n");
    cli_set_process_title("ZMQ Zpool Broker");
    //  Prepare our context and sockets
    $context = new ZMQContext();
    $frontend = new ZMQSocket($context, ZMQ::SOCKET_ROUTER);
    $backend = new ZMQSocket($context, ZMQ::SOCKET_DEALER);
    $frontend->bind("ipc:///tmp/helloworld_in");
    $backend->bind("ipc:///tmp/helloworld_out");

    //  Initialize poll set
    $poll = new ZMQPoll();
    $poll->add($frontend, ZMQ::POLL_IN);
    $poll->add($backend, ZMQ::POLL_IN);
    $readable = $writeable = array();

    //  Switch messages between sockets
    while (true) {
	$events = $poll->poll($readable, $writeable);

	foreach ($readable as $socket) {
	    if ($socket === $frontend) {
		//  Process all parts of the message
		while (true) {
		    $message = $socket->recv();
		    //  Multipart detection
		    $more = $socket->getSockOpt(ZMQ::SOCKOPT_RCVMORE);
		    $backend->send($message, $more ? ZMQ::MODE_SNDMORE : null);
		    if (!$more) {
			break; //  Last message part
		    }
		}
	    } elseif ($socket === $backend) {
		$message = $socket->recv();
		//  Multipart detection
		$more = $socket->getSockOpt(ZMQ::SOCKOPT_RCVMORE);
		$frontend->send($message, $more ? ZMQ::MODE_SNDMORE : null);
		if (!$more) {
		    break; //  Last message part
		}
	    }
	}
    }
}

function server_worker($thread_nbr)
{
    printf("starting worker $thread_nbr...\n");
    cli_set_process_title("ZMQ Worker - ".$thread_nbr);
    $context = new ZMQContext();

    //  Socket to talk to clients
    $responder = new ZMQSocket($context, ZMQ::SOCKET_DEALER);
    $responder->connect("ipc:///tmp/helloworld_out");

    while (true) {
	$_parts = array();
        while (true) {
            $_parts[] = $responder->recv();
            if (!$responder->getSockOpt(ZMQ::SOCKOPT_RCVMORE)) {
                break;
            }
        }
	printf ("Received request[%d]: [%s]%s", $thread_nbr, $_parts[2], PHP_EOL);

	// Do some 'work'
	//sleep(1);

    }
}

/* This main thread simply starts several clients, and a server, and then
 * waits for the server to finish.
 */
function main()
{
    //  Launch pool of worker threads, precise number is not critical
    for ($thread_nbr = 0; $thread_nbr < 5; $thread_nbr++) {
        $pid = pcntl_fork();
        if ($pid == 0) {
            server_worker($thread_nbr);
            exit();
        }
    }

        server_task_poll();
    /*
    $pid = pcntl_fork();
    if ($pid == 0) {
        server_task();
        exit();
    }
    */



}

main();
