<?php
namespace Seriti\Tools;

use Exception;


//class intended as a pseudo namespace for a group of functions to be referenced as "Debug::function_name()"
class Debug 
{
    
    //check all defined paths exist
    public static function checkPaths($param = array()) 
    {
        if(!isset($param['exception'])) $param['exception'] = true;
        if(!isset($param['paths'])) $param['paths'] = array();

        //assume current directory if no base path specified
        if(!isset($param['paths']['base'])) $param['paths']['base'] = __DIR__;

        $error = '';
        
        foreach($param['paths'] as $key => $path) {
            if($key !== 'base') {
                $test_path = $param['paths']['base'].$path; 
                //path must either be a valid file or directory
                if(is_dir($test_path) === false and is_file($test_path) === false) {
                    $error .= 'Invalid path:'.$test_path.'<br/>'; 
                }  
            }   
        }
        
        if($error != '' and $param['exception']) {
            throw new Exception('PATH_INVALID:'.$error);
        }    
        
        return $error_str;
    }

    //display contents of a variable 
    public static function show_var($var,$format = 'html')
    {
        $output = '';

        if($format === 'html') $output .= '<pre>'.print_r($var,true).'</pre>';
        
        if($format === 'text') $output .= print_r($var,true);
       
        if($format === 'json') $output .= json_encode($var);
        
        return $output;
    }
    
}
