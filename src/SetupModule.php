<?php
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\Date;
use Seriti\Tools\Validate;
use Seriti\Tools\DbInterface;
use Seriti\Tools\System;
use Seriti\Tools\Image;
use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\SecurityHelpers;

use Seriti\Tools\BASE_PATH;
use Seriti\Tools\BASE_UPLOAD_WWW;
use Seriti\Tools\BASE_URL;

use Psr\Container\ContainerInterface;

class SetupModule 
{
    use IconsClassesLinks;
    use MessageHelpers;
    use ContainerHelpers;
    use SecurityHelpers;

    protected $container;
    protected $container_allow = ['config','system','user'];

    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    protected $db;
    protected $system;
    protected $debug = false;

    protected $upload_dir;
    protected $base_url;
    protected $upload_url;
    
    protected $module = '';
    protected $change_count = 0;

    protected $default_type = ['TEXT','TEXTAREA','HTML','SELECT','IMAGE'];
    protected $default = [];

    protected $user_access_level;
    protected $user_id;
    protected $user_csrf_token;
    protected $csrf_token = '';

    protected $mode = 'list_all';
    protected $access = array('edit'=>true,'view'=>true,'delete'=>true,'add'=>true,'link'=>true,
                              'read_only'=>false,'audit'=>true,'search'=>true,'copy'=>false,'import'=>false);
    
    public function __construct(DbInterface $db, ContainerInterface $container, $module = []) 
    {
        if(!isset($module['name'])) throw new Exception('No module name specified');
        if(!isset($module['table_prefix'])) throw new Exception('No module table prefix specified');

        $this->db = $db;
        $this->container = $container;

        $this->system = $this->getContainer('system');
        $this->module = strtoupper($module['name']);
        
        //NB: these assumes public access to uploaded files
        $this->upload_dir = BASE_PATH.BASE_UPLOAD_WWW;
        $this->base_url = BASE_URL; //only used for defaults
        $this->upload_url = BASE_URL.BASE_UPLOAD_WWW;
        
        if(defined('\Seriti\Tools\DEBUG')) $this->debug = \Seriti\Tools\DEBUG;

        $this->user_access_level = $this->getContainer('user')->getAccessLevel();
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }

    public function setUpload($upload_dir,$upload_url)
    {
        $this->upload_dir = $upload_dir;
        //NB if $upload_url === 'PRIVATE' will get image src data from upload_dir;
        $this->upload_url = $upload_url;
    }

    public function processSetup()
    {
        $html = '';

        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));

        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);

        if($this->access['read_only'] === false) {
            if($this->mode === 'update') $this->update($_POST);
            if($this->mode === 'reset') $this->resetAll();
        } else {
            $this->addError('You have insuffient access rights.');
        } 
        
        $html .= $this->viewAll();

        return $html;
    }

       
    public function viewAll()
    {
        $list_param = [];
        $list_param['class'] = 'form-control';

        $text_param=array();
        $text_param['class']='form-control';

        $html = '';

        $html .= '<form action="?mode=update" method="post" enctype="multipart/form-data">';
        $html .= '<input type="hidden" name="csrf_token" value="'.$this->user_csrf_token.'"><br/>';

        $html .= '<div class="row">'.
                 '<div class="col-sm-12">'.
                 '<h2>Change any settings below and then click update button to save: '.
                    '<input type="submit" class="btn btn-primary" value="Update '.$this->module.' settings"></h2>'.
                    $this->viewMessages().
                 '</div>'.
                 '</div>'.
                 '<hr/>';
        foreach($this->default as $default) {
            $value = $this->system->getDefault($default['id'],$default['value']);
            
            $html .= '<div class="row">'.
                     '<div class="col-sm-3"><strong>'.$default['title'].'</strong></div>';

            if($default['type'] === 'TEXT') {
                $html .= '<div class="col-sm-6">'.
                         Form::textInput($default['id'],$value,$text_param).
                         '</div>';
            }

            if($default['type'] === 'TEXTAREA' or $default['type'] === 'HTML') {
                $html .= '<div class="col-sm-6">'.
                         Form::textAreaInput($default['id'],$value,'',$default['rows'],$text_param).
                         '</div>';
            }

            if($default['type'] === 'SELECT') {
                if(isset($default['options'][0])) $assoc = false; else $assoc = true;
                $html .= '<div class="col-sm-6">'.
                         Form::arrayList($default['options'],$default['id'],$value,$assoc,$list_param).
                         '</div>';
            }

            if($default['type'] === 'IMAGE') {
                if($value === $default['value']) {
                    $image = $this->base_url.$value;
                } else {
                    if($this->upload_url !== 'PRIVATE' ) {
                        $image = $this->upload_url.$value;
                    } else {
                        $error = '';
                        $path = $this->upload_dir.$value;
                        $image = Image::getImage('SRC',$path,$error);
                    }    
                }    
                $html .= '<div class="col-sm-6">'.
                         Form::fileInput($default['id'],'',$list_param).
                         '<br/><img src="'.$image.'" height="50" align="left">'.
                         '</div>';
            }

            $html.='<div class="col-sm-3">'.$default['info'].'</div>';
            $html.='</div><hr/>';
        } 
      
        $html.='</form>';
      
        $html.='<a href="?mode=reset&csrf_token='.$this->user_csrf_token.'">Reset all settings to default values.</a><br/><br/><br/>';

        return $html;
    } 

    protected function addDefault($type,$id,$title,$param = [])
    {
        if($id == '') $this->addError('DEfault ID cannot be blank.');
        if($title == '') $this->addError('DEfault Title cannot be blank.');

        if(!in_array($type,$this->default_type)) {
            $this->addError($type.' is Not valid default type.');
        }

        if($type === 'SELECT'){
            if(!isset($param['options']) or !is_array($param['options'])) $this->addError('SELECT type requires array of options.');   
            if(!isset($param['max_size'])) $param['max_size'] = 64;
        }
        
        if($type === 'IMAGE') {
            if(!isset($param['max_size'])) $param['max_size'] = 100000;
        }

        if($type === 'TEXT') {
            if(!isset($param['max_size'])) $param['max_size'] = 255;
        }

        if($type === 'TEXTAREA' or $type === 'HTML') {
            if(!isset($param['max_size'])) $param['max_size'] = 64000;
            if(!isset($param['rows'])) $param['rows'] = 5;
        } 

        if(!isset($param['value'])) $param['value'] = '';
        if(!isset($param['max'])) $param['max'] = '';
        if(!isset($param['rows'])) $param['rows'] = 0;
        if(!isset($param['options'])) $param['options'] = [];

        if(!$this->errors_found) {
            $default = [];
            $default['type'] = $type;
            $default['id'] = $id; 
            $default['title'] = $title;
            $default['info'] = $param['info'];
            $default['options'] = $param['options'];
            $default['value'] = $param['value'];
            $default['max_size'] = $param['max_size'];
            $default['rows'] = $param['rows'];

            $this->default[] = $default;    
        }    
    }


    protected function resetAll()
    {
        $this->verifyCsrfToken();

        foreach($this->default as $default) {
            $reset = $this->system->removeDefault($default['id']);
            if($reset) {
                $this->addMessage('Successfully reset: '.$default['title']);
            } else {
                $this->addError('Could NOT reset default: '.$default['title']);
            }    
        }  
    }


    protected function update($form) 
    {
        $updated = array();
        $error = '';

        $this->verifyCsrfToken();
          
        foreach($this->default as $default) {
            if($default['type'] === 'TEXT') {
                if(isset($form[$default['id']])) {
                    $value_exist = $this->system->getDefault($default['id'],$default['value']);
                    $value = Secure::clean('string',$form[$default['id']]);
                    if($value_exist !== $value) {
                        $this->system->setDefault($default['id'],$value);
                        $this->addMessage('Successfully updated '.$default['title'].' setting.');
                        $updated[$default['id']] = true;
                        $this->change_count++;
                    } else {
                        $updated[$default['id']] = false; 
                    }   
                }   
            }  
            
            if($default['type'] === 'TEXTAREA') {
               if(isset($form[$default['id']])) {
                    $value_exist = $this->system->getDefault($default['id'],$default['value']);
                    $value = Secure::clean('text',$form[$default['id']]);
                    if($value_exist !== $value) {
                        $this->system->setDefault($default['id'],$value);
                        $this->addMessage('Successfully updated '.$default['title'].' setting.');
                        $updated[$default['id']] = true;
                        $this->change_count++;
                    } else {
                        $updated[$default['id']] = false; 
                    }   
                }   
            } 

            if($default['type'] === 'HTML') {
               if(isset($form[$default['id']])) {
                    $value_exist = $this->system->getDefault($default['id'],$default['value']);
                    //NB: NO cleaning of input as anything goes
                    $value = $form[$default['id']];
                    if($value_exist !== $value) {
                        $this->system->setDefault($default['id'],$value);
                        $this->addMessage('Successfully updated '.$default['title'].' setting.');
                        $updated[$default['id']] = true;
                        $this->change_count++;
                    } else {
                        $updated[$default['id']] = false; 
                    }   
                }   
            }  

            if($default['type'] === 'SELECT') {
                if(isset($form[$default['id']])) {
                    $value_exist = $this->system->getDefault($default['id'],$default['value']);
                    $value = Secure::clean('alpha',$form[$default['id']]);
                    if($value_exist !== $value) {
                        $this->system->setDefault($default['id'],$value);
                        $this->addMessage('Successfully updated '.$default['title'].' setting.');
                        $updated[$default['id']] = true;
                        $this->change_count++;
                    } else {
                        $updated[$default['id']] = false; 
                    }   
                }   
            }  

            if($default['type'] === 'IMAGE') {
                $file_options = array();
                $file_options['upload_dir'] = $this->upload_dir;
                $file_options['allow_ext'] = array('jpg','jpeg','png','gif');
                $file_options['max_size'] = $default['max_size'];
                $save_name = $default['id'];
                $image_name = Form::uploadFile($default['id'],$save_name,$file_options,$error);
                if($error !== '') {
                    if($error !== 'NO_FILE') $this->addError($default['title'].': '.$error);
                    $updated[$default['id']] = false;
                } else {
                    $this->system->setDefault($default['id'],$image_name);
                    $this->addMessage('Successfully updated '.$default['title'].' image.');
                    $updated[$default['id']] = true;
                    $this->change_count++;
                }     
            }  
        }  
      

        if($this->change_count === 0) {
            $this->addMessage('No changes processed.');
        } else {    
            $this->addMessage('You may need to refresh pages to show changes.');
        }
    }



}


  
?>
