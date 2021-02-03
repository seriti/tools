<?php
namespace Seriti\Tools;

trait  MessageHelpers 
{

    public function viewMessages() 
    {
        $html = '';
                                        
        if($this->errors_found and count($this->errors) != 0) {
            $html .= '<div id="error_div" class="'.$this->classes['error'].'">'.
                     '<button type="button" class="close" data-dismiss="alert">x</button><ul>';
            foreach($this->errors as $error) {
                $html .= '<li>'.$error.'</li>';  
            }  
            $html .= '</ul></div>';
        }  
        if(count($this->messages) != 0) {
            $html .= '<div id="message_div" class="'.$this->classes['message'].'">'.
                     '<button type="button" class="close" data-dismiss="alert">x</button><ul>';
            foreach($this->messages as $message) {
                $html .= '<li>'.$message.'</li>';  
            }  
            $html .= '</ul></div>';
        }
        
        return $html;
    }

    //NB: htmlspecialchars() used as errors may include user input  
    protected function addError($error,$clean=true) 
    {
        if($error !== '') {
            if($clean) $error = htmlspecialchars($error);
            $this->errors[] = $error;  
            $this->errors_found = true;
        }  
    } 
      
    protected function addMessage($str) 
    {
        if($str !== '') $this->messages[] = $str;
    }
     
}