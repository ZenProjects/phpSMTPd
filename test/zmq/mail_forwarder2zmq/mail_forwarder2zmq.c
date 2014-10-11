//  Hello World client
#include <zmq.h>
#include <string.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <sysexits.h>

#define DEFAULT_CONNECTION "ipc:///tmp/helloworld_in";
int main (int argc,char *argv[])
{
    char *buffer;
    char c;
    long i=0;
    long len=0;
    long max_message_size=15*1024*1024;
    char default_connection[512];

    if (argc<1) 
    {
       fprintf(stderr,"Syntax Error: %s <socket>\n",argv[0]);
       return EX_TEMPFAIL;
    }

    len=strlen(argv[1]);
    if (len>512) len=511;
    if (len>0) strncpy(default_connection,argv[1],len);
    default_connection[len]=0;
    fprintf(stderr,"Socket connection:%s\n",default_connection);

    buffer=malloc(max_message_size);
    if (buffer==0) 
    {
       fprintf(stdout,"Error: impossible d'allouer 15Mo de ram!\n");
       return EX_TEMPFAIL;
    }

    while(1) 
    {
       c=fgetc(stdin);
       if (feof(stdin)) break;
       buffer[i++]=c;
       if (i>=max_message_size) 
       {
	 fprintf(stdout,"Error: message larger than 15MO reject!\n");
	 return EX_UNAVAILABLE;
       }
    }
    buffer[i]=0;

    fprintf(stderr,"Connecting to hello world server...\n");
    void *context = zmq_ctx_new ();
    void *requester = zmq_socket (context, ZMQ_REQ);
    zmq_connect (requester, argv[1]);

    fprintf(stderr,"Sending message ...\n");
    zmq_send (requester, buffer, i, 0);

    zmq_close (requester);
    zmq_ctx_destroy (context);
    return 0;
}
