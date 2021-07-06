<?php
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\Secure;
use Seriti\Tools\Form;

trait  SecurityHelpers 
{

    protected function verifyCsrfToken(&$error) 
    {   
        $error = '';
        if(!isset($this->user_csrf_token)) {
            $error .= 'CSRF token error! ';
            if($this->debug) $error .= 'user CSRF token NOT set.';
        } elseif($this->csrf_token === '') {
            $error .= 'CSRF token error! ';
            if($this->debug) $error .= 'Form or Link CSRF token NOT set.';
        } else {    
            if($this->user_csrf_token !== $this->csrf_token) {
                $error .= 'CSRF token error! If page has been inactive for some time then resubmit and issue will resolve.';
                if($this->debug) $error .= 'CSRF request token['.$this->csrf_token.'] does not match user Token['.$this->user_csrf_token.']';
            }
        }

        if($error !== '') return false; else return true;
    }

    protected function setupAccess($access_level) 
    {
        switch ($access_level) {
            case 'GOD' : {
                $this->access['view'] = true;
                $this->access['search'] = true;
                $this->access['read_only'] = false;
                $this->access['edit'] = true;
                $this->access['move'] = true;
                $this->access['add'] = true;
                $this->access['delete'] = true;
                $this->access['email'] = true;
                $this->access['link'] = true;
                break;
            }
            case 'ADMIN' : {
                $this->access['view'] = true;
                $this->access['search'] = true;
                $this->access['read_only'] = false;
                $this->access['edit'] = true;
                $this->access['move'] = true;
                $this->access['add'] = true;
                $this->access['delete'] = true;
                $this->access['email'] = true;
                $this->access['link'] = true;
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
                        
            default : {
                $this->access['view'] = true;
                $this->access['search'] = true;
                $this->access['read_only'] = true;
                $this->access['edit'] = false;
                $this->access['move'] = false;
                $this->access['copy'] = false;
                $this->access['add'] = false;
                $this->access['delete'] = false;
                $this->access['email'] = false;
                $this->access['link'] = false;
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
