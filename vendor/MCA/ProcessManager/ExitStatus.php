<?php
namespace MCA\ProcessManager;
 
class ExitStatus
{
   const WorkerBase		= 1; //Couldn't open worker event base
   const WorkerClass		= 2; //$worker args must be a Worker class
   const EventBase		= 3; //Couldn't open event base
   const EventBaseReInit	= 4; //Error EventBase reInit
   const HookCallable		= 5; //The hook function are not callable!
}
