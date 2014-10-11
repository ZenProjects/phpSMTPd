<?php

   mt_srand(time());
   for($i=0;$i<1000000;$i++)
   {
   printf("%s%06s\n",substr(dechex(microtime(true)*1000000),-5),substr(dechex(mt_rand()),-6));
   //printf("%s\n",dechex(mt_rand()));
   //printf("%s\n",md5(time()."-".mt_rand()));
   }

