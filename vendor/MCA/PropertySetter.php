<?php
namespace phpSMTPd;

trait PropertySetter {
    public function __set($name, $value)
    {
       debug::printf(LOG_DEBUG,"try to set '$name' with value '$value'\n");

       $method="set".$name;
       if (method_exists($this,$method))
       {
	 debug::printf(LOG_DEBUG,"call method ".$method."\n");
	 $this->$method($value);
	 return;
       }
       $rec= new \ReflectionClass($this);
       if ($rec->hasProperty($name))
       {
         $re= new \ReflectionProperty($this,$name);
	 if ($re->isProtected())
	      $this->$name = $value;
	 else
	      debug::printf(LOG_ERR,"ERROR Impossible to set '$name' property of '".get_class($this)."' class, the property are private!\n");
       }
       else
       {
	 debug::printf(LOG_ERR,"ERROR Impossible to set '$name' property of '".get_class($this)."' class, does not exist in this class!\n");
	 throw new \Exception("ERROR Impossible to set '$name' property of '".get_class($this)."' class, does not exist in this class!\n");
       }
    }
    public function __isset($name)
    {
       debug::printf(LOG_DEBUG,"check if isset '$name'\n");
       return isset($this->$name);
    }

    public function __get($name)
    {
       debug::printf(LOG_DEBUG,"try to get '$name'\n");
       $method="get".$name;
       if (method_exists($this,$method))
       {
	 debug::printf(LOG_DEBUG,"call method ".$method."\n");
	 return $this->$method();
       }

       $rec= new \ReflectionClass($this);
       if ($rec->hasProperty($name))
       {
         $re= new \ReflectionProperty($this,$name);
	 if ($re->isProtected())
	    return $this->$name;
	 else
	    debug::printf(LOG_ERR,"ERROR Impossible to set '$name' property of '".get_class($this)."' class, the property are private!\n");
       }
       else
	 debug::printf(LOG_ERR,"ERROR Impossible to get '$name' propery of '".get_class($this)."' class, does not exist in this class!\n");
       return null;
    }
}

