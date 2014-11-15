#!/bin/sh

$(dirname $0)/smtpd --listen --tls --xclient --xforward --stderr 2>&1| egrep --color ": (R|S)[\(][^\)]*[\)].*|$"
