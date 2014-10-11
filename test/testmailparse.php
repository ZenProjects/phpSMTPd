<?php

require_once("Mail-1.2.0/Mail/RFC822.php");

   $rfc="prvs=3359b7afe=mathieu.carbonneaux@sfr.com";
   if (preg_match("/^prvs=[0-9]{4}[0-9A-Fa-f]{5,6}=(.*\..*)$/",$rfc,$arr)==1) 
   printf("%s\n",$arr[1]);
