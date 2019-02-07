<?php
namespace Seriti\Tools;

//standard helper classes/functions/variables that can be injected into multiple classes, like Email, AmazonS3, User
//simpel version of laravel fascade BS. Can expand later for testing/faking etc
trait Helpers 
{
        
    protected  $helpers = [];

    public function setHelper($key,$value)
    {
        $this->helpers[$key]=$value;
    }
}
