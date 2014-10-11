<?php

   function toto()
   {
      titi();
   }

   function titi()
   {
      tutu();
   }

   function tutu()
   {
      var_dump(debug_backtrace()[1]);
   }

   toto();

