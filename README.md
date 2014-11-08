# SuperList Daemon

SuperListd are Mailing List SMTP daemon coded 100% in php based on PECL-Event extension.

Acting as SMTP Proxy to be used as proxy filter behind postfix to manage Mailing List.

In this project they have implemented in 100% PHP a complete [PECL-Event](http://php.net/manual/fr/book.event.php) ([libevent](http://libevent.org/)) based SMTP Client and Server stack.

Server part are Multiprocess pre-forked daemon with watchdog.

They can start as root listen on port <1025 and impersonate to other user.

The skelete of the server part are based on SMTP event based example on php site by Andrew Rose :
http://php.net/manual/fr/event.examples.php

The STARTTLS server part are from this example also.

The PHP daemon also inspired me.
http://daemon.io/

The SMTP Implementation are largely based on D.J. Bernstein (QMAIL) implementation notes: http://cr.yp.to/smtp.html

| Links                              | SMTP verbs                  |
| ---------------------------------- | --------------------------- |
| http://cr.yp.to/smtp/helo.html     | HELO, RSET, and NOOP verbs  |
| http://cr.yp.to/smtp/ehlo.html     | EHLO verb                   |
| http://cr.yp.to/smtp/mail.html     | MAIL, RCPT, and DATA verbs  |
| http://cr.yp.to/smtp/quit.html     | QUIT verb                   |
| http://cr.yp.to/smtp/vrfy.html     | VRFY/EXPN verbs             |
| http://cr.yp.to/smtp/8bitmime.html | 8BITMIME extension          |
| http://cr.yp.to/smtp/size.html     | SIZE extension              |

# _**For the moment store only message on inbound queue.**_

## Prerequisit

PHP V5.5 minimum

PHP Extension:
- PECL-Event >=1.11.0 (get last version from pecl-event git : https://bitbucket.org/osmanov/pecl-event/overview)
- openssl
- PCNTL
- sysvsem
- sysvshm
- PCRE
- POSIX

-----------------------------------

## SMTP Implementation Notes

## SMTP client

  - Implement client part of SMTP, and implement this SMTP verbs: EHLO/HELO, STARTTLS, XFORWARD, XCLIENT, MAIL FROM, RCPT TO, QUIT
  - no need HELP, SEND, SAML, SOML, TURN, ETRN verbs in this client
  - for the moment no support for VRFY/EXPN, but possible addition to VRFY/EXPN support in future to check address
  - based largely on D.J. Bernstein (author of QMAIL) implementation notes: http://cr.yp.to/smtp/client.html
  - Conform with ESMTP standard RFC 1869 and implement this extension : 8BITMIME, STARTTLS, SIZE, XCLIENT, XFORWARD

### Implementation detail:

#### HELO/EHLO + XFORWARD/XCLIENT + STARTLS verbs

  - EHLO are sent systematicly (as say djb), and HELO only if $this->forceehlo is set to false and the SERVER not responded ESMTP in gretting
  - NOTE: possible amelioration is to send HELO in case of error with EHLO for server that not support ESMTP.
  - abort if SIZE receved form the server are larger than data message
  - send xclient/xforward information if the server support it
  - Transmit all XCLIENT attributs ($this->xclients array, it not set send [UNAVAILABLE]) found in XCLIENT EHLO response...
  - Transmit all XFORWARD attributs ($this->xforwards array, it not set send [UNAVAILABLE]) found in XFORWARD EHLO response...
  - STARTLS if the server claim to support it and the $this->tls attribut is set

#### MAIL, RCPT and DATA verbs

  - all are implemented
  - not SIZE option on MAIL cmd for the moment...
  - no Q-P conversion (for the moment, but in regard of djb note are not needed anymore...), send 8bit for all server even if the server not specify 8BITMIME in EHLO

#### QUIT verb

  - send quit, wait response and close connection
  - NOTE: possible amelioration is to not wait response... like qmail client


## SMTP Server

  - Implement this SMTP verbs: EHLO/HELO, STARTTLS, XFORWARD, XCLIENT, MAIL, RCPT, RSET, VRFY, NOOP, QUIT
  - no HELP, SEND, SAML, SOML, TURN, ETRN verbs
  - conforming to ESMTP standard RFC 1869 and implement this extension : 8BITMIME, STARTTLS, SIZE, XCLIENT, XFORWARD
  - based largely on D.J. Bernstein (author of QMAIL) implementation notes: http://cr.yp.to/smtp.html

### Implementation detail:

#### HELO/EHLO + XFORWARD/XCLIENT + STARTLS verbs

  - support EHLO and HELO
  - HELO not send extension like EHLO does
  - send extension SIZE (send server configured maximum message size), 8BITMIME, VRFY as default
  - send XCLIENT/XFORWARD extension if configuration enabeled
  - send STARTTLS extension if configuration enabeled
  - check resolvabilty of the hostname sended

#### MAIL, RCPT and DATA verbs

  - all are implemented
  - MAIL and RCPT controle the address format
  - RCPT controle if the destination are configured Mailling List
  - controle the sequancing the cmds
  - SIZE option on MAIL cmd are supported, and abort if the size is larger the server configured maximum.
  - no Q-P conversion, full 8BITMIME server. 

#### VRFY, NOOP, RSET

  - VRFY controle if the addresse are configured as mailing list
  - NOOP do noop...
  - RSET reset the enveloppe and restart sequencing to after EHLO/HELO

#### QUIT verb

  - QUIT close the connection after sending bye message

-----------------------------------

### To use/test STARTLS:

  1. Prepare cert.pem certificate and privkey.pem private key files.

     http://rene.bz/setting-smtp-authentication-over-tls-postfix/

     http://www.postfix.org/TLS_README.html

  2. Launch the server script

  3. to test TLS support:

       $ openssl s_client  -connect localhost:25 -starttls smtp -crlf -quiet -state -msg
     
     or
     
       $ gnutls-cli --crlf --starttls -p 25 --debug 256 --insecure 127.0.0.1
       
       - send EHLO <hostname> and STARTTLS
     
       - and after the response of STARTTLS 
       
       - send CTRL-D and gnutls-cli go in TLS handcheck


  you can test also with swaks: http://www.jetmore.org/john/code/swaks/
   
   $ ./swaks --to mylist@listes.mydomain.tld --from myaddresse@mydomain.tld --server 127.0.0.1:25 -tls


-----------------------------------

## The SMTP principal rfcs:

  http://en.wikipedia.org/wiki/Simple_Mail_Transfer_Protocol

  http://en.wikipedia.org/wiki/Extended_SMTP

  SMTP 			: RFC 5321 – Simple Mail Transfer Protocol

  http://tools.ietf.org/html/rfc5321

  ESMTP 		: RFC 1869 – SMTP Service Extensions

  http://tools.ietf.org/html/rfc1869

  Mail Message Format	: RFC 5322 - Internet Message Format

  http://tools.ietf.org/html/rfc5322

  SMTP Extension | RFC | Description
  --- | --- | ---
  SIZE 			| 1870 | SMTP Service Extension for Message Size Declaration
  8BITMIME 	| 6152 | SMTP Service Extension for 8-bit MIME Transport
  STARTTLS	| 3207 | SMTP Service Extension for Secure SMTP over Transport Layer Security
  AUTH	 		| 4954 | SMTP Service Extension for Authentication
  PIPLINING | 2920 | SMTP Service Extension for Command Pipelining
  CHUNKING 	| 3030 | SMTP Service Extensions for Transmission of Large and Binary MIME Messages
  DSN			  | 3461 | Simple Mail Transfer Protocol (SMTP) Service Extension for Delivery Status Notifications (DSNs)
  ENHANCEDSTATUSCODES	| 3463 | Enhanced Mail System Status Codes
  SMTPUTF8 		| 6531 | SMTP Extension for Internationalized Email

-----------------------------------

## ESMTP Extension implementation status:

- 8BITMIME, **client/server done partial**

  http://cr.yp.to/smtp/8bitmime.html

  https://tools.ietf.org/html/rfc6152

- STARTLS, **client/server done**

  http://en.wikipedia.org/wiki/STARTTLS

  https://tools.ietf.org/html/rfc3207

- SIZE, **client/server done partial**

  http://cr.yp.to/smtp/size.html

  http://tools.ietf.org/html/rfc1870

- XCLIENT, **client/server done**

  http://www.postfix.org/XCLIENT_README.html

- XFORWARD, **client/server done**

  http://www.postfix.org/XFORWARD_README.html

- PIPELINING, **not done but planned**

  http://tools.ietf.org/html/rfc2920

  http://cr.yp.to/smtp/pipelining.html

- SMTP-AUTH, **not done, planned CRAM-MD5, PLAIN only**
  
  http://tools.ietf.org/html/rfc4954 ==> SMTP AUTH are based on SASL

  http://www.fehcom.de/qmail/smtpauth.html ==> extension for qmail, good starting point

  http://www.iana.org/assignments/sasl-mechanisms/sasl-mechanisms.xhtml ==> list of SASL mecanismes

  http://tools.ietf.org/html/rfc4422 ==> SASL standard

  http://tools.ietf.org/html/rfc2195 ==> SASL CRAM-MD5

  http://tools.ietf.org/html/rfc4616 ==> SASL PLAIN

  http://tools.ietf.org/html/rfc6331 ==> SASL DIGEST-MD5 (obsoleted)

  http://tools.ietf.org/html/rfc5802 ==> SCRAM-*

  http://tools.ietf.org/html/rfc4505 ==> ANONYMOUS

  https://qmail.jms1.net/test-auth.shtml

  - for use with authenticated relay (not for public mx relay)

- TLS DANE, **not done, planned**

  https://tools.ietf.org/html/draft-ietf-dane-smtp-with-dane-13

  https://datatracker.ietf.org/doc/draft-ietf-dane-smtp-with-dane/

  http://www.postfix.org/TLS_README.html#client_tls_dane

- ENHANCEDSTATUSCODES, **not done possibly planned**

  http://tools.ietf.org/html/rfc2034 ==> the extension

  http://tools.ietf.org/html/rfc3463 ==> List of Enhanced Mail System Status Codes

  http://tools.ietf.org/html/rfc1894 ==> An Extensible Message Format for Delivery Status Notifications, defines a mechanism to send such coded material to users

- SMTPUTF8, **not done, not planned**

  http://www.postfix.org/SMTPUTF8_README.html

  http://tools.ietf.org/html/rfc6531 ==> the SMTPUTF8 extension

  http://tools.ietf.org/html/rfc6532 ==> Internationalized Email Headers
  
  http://tools.ietf.org/html/rfc6533 ==> Internationalized Delivery Status and Disposition Notifications

- DSN, **not done, not planned**

  http://tools.ietf.org/html/rfc3461

- CHUNKING, **not done, not planned**

  http://tools.ietf.org/html/rfc3030

