
LDFLAGS=-L /opt/srv/lib/libzmq/lib/ -Wl,-rpath,/opt/srv/lib/libzmq/lib/ -lzmq
CFLAGS=-I /opt/srv/lib/libzmq/include/ 


all: mail_forwarder2zmq 

%.o.%c: 
	gcc  -c $(CFLAGS) $< -o $@

mail_forwarder2zmq: mail_forwarder2zmq.o
	gcc  $(LDFLAGS) $< -o $@

clean:
	-rm -rf mail_forwarder2zmq.o mail_forwarder2zmq
