#!/bin/sh

$(dirname $0)/smtpd --listen --tls --stderr 2>&1| egrep --color ": (R|S)[\(][^\)]*[\)].*|$"
