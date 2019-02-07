<?php
namespace Seriti\Tools;

use Exception;

trait  ContainerHelpers 
{

    public function getContainer($item)
    {
        if(in_array($item,$this->container_allow)) {
            return $this->container->get($item);
        } else {
            $error = 'CONTAINER_ACCESS_ERROR: Requested item is not allowed';
            if($this->debug) $error .= ' requested['.$item.'] allowed['.implode(',',$this->container_allow).'] for class['.get_class($this).']';
            throw new Exception($error);
        }    
    }
     
}