<?php
namespace Seriti\Tools;

use Exception;

class Template 
{
    protected $template_dir;
    protected $debug = false;
    protected $variables = array();
     
    public function __construct($template_dir) 
    {
        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;

        if(is_dir($template_dir)) {
            if(substr($template_dir,-1) !== '/' ) $template_dir .= '/';
            $this->template_dir = $template_dir;
        } else {
            $error = 'Template directory does not exist.';
            if($this->debug) $error .= '['.$template_dir.']'; 
            throw new Exception('TEMPLATE_ERROR: '.$error);
        }
    }
    
    public function __get($key) 
    {
        return $this->variables[$key];
    }
     
    public function __set($key, $value) 
    {
        $this->variables[$key] = $value;
    }
    
    public function __toString() 
    {
        return $this->render();
    }

    public function render($template) 
    {
        $template_path = $this->template_dir.$template;

        if (!is_file($template_path)) {
            $error = 'Template does not exist.';
            if($this->debug) $error .= '['.$template_path.']'; 
            throw new Exception('TEMPLATE_ERROR: '.$error);
        }

        extract($this->variables);
        ob_start();
        
        include($template_path);
         
        return ob_get_clean();
    }
}

