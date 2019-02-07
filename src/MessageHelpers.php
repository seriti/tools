<?php
namespace Seriti\Tools;

trait  MessageHelpers 
{

    protected function viewMessages() 
    {
        $html = '';
                                        
        if($this->errors_found) {
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

    protected function addError($error) 
    {
        if($error !== '') {
          $this->errors[] = $error;
          $this->errors_found = true;
        }  
    } 
      
    protected function addMessage($str) 
    {
        if($str !== '') $this->messages[] = $str;
    }
     
}