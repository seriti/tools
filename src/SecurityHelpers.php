<?php
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\Secure;
use Seriti\Tools\Form;

trait  SecurityHelpers 
{

    protected function verifyCsrfToken() 
    {   
        $error = '';
        if(!isset($this->user_csrf_token)) {
            $error .= 'Invalid request! ';
            if($this->debug) $error .= 'user CSRF token NOT set.';
        } elseif($this->csrf_token === '') {
            $error .= 'Invalid request! ';
            if($this->debug) $error .= 'Form or Link CSRF token NOT set.';
        } else {    
            if($this->user_csrf_token !== $this->csrf_token) {
                $error .= 'Invalid request! ';
                if($this->debug) $error .= 'CSRF request token['.$this->csrf_token.'] does not match user Token['.$this->user_csrf_token.']';
            }
        }

        if($error !== '') throw new Exception($error);
    }

    protected function setupAccess($user_access) 
    {
        switch ($user_access) {
            case 'VIEW' : {
                $this->access['view'] = true;
                $this->access['search'] = true;
                $this->access['read_only'] = true;
                $this->access['edit'] = false;
                $this->access['add'] = false;
                $this->access['delete'] = false;
                $this->access['email'] = false;
                $this->access['link'] = false;
                break;
            }
            case 'USER' : {
                $this->access['view'] = true;
                $this->access['search'] = true;
                $this->access['read_only'] = false;
                $this->access['edit'] = true;
                $this->access['add'] = true;
                $this->access['delete'] = false;
                $this->access['email'] = true;
                $this->access['link'] = false;
                break;
            }
            case 'ADMIN' : {
                $this->access['view'] = true;
                $this->access['search'] = true;
                $this->access['read_only'] = false;
                $this->access['edit'] = true;
                $this->access['add'] = true;
                $this->access['delete'] = true;
                $this->access['email'] = true;
                $this->access['link'] = true;
                break;
            }
            
        }    
    } 

    protected function modifyAccess($param = array()) 
    {
        //overrides default access array keys if $param has them
        $this->access = array_merge($this->access,$param);
        if($this->access['read_only']) {
            $this->access['edit'] = false;
            $this->access['add'] = false;
            $this->access['delete'] = false;
        }  
    } 

}
?>