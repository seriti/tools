<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Date;
use Seriti\Tools\DbInterface;
use Seriti\Tools\Cache;
use Seriti\Tools\Template;

use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_DOCS;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\MessagerHelpers;
use Seriti\Tools\TableStructures;
use Seriti\Tools\SecurityHelpers;

use Psr\Container\ContainerInterface;

class Wizard {

    use IconsClassesLinks;
    use ContainerHelpers; 
    use MessageHelpers;
    use TableStructures;
    use SecurityHelpers;

    //do NOT make private as wizards often use helpers that require container to be passed to them
    protected $container;
    protected $container_allow = ['s3','mail','user','system'];

    //store all form data here
    protected $form = array();
    //store any non-form data here
    protected $data = array();
    //store any required javascript
    protected $javascript = '';
    //must be true to allow normal page processing
    protected $process_page_no = true;
    //current wizard page no
    protected $page_no = 1;
    //next wizard page assuming no errors 
    protected $page_no_next = 0;
        
    protected $db;
    protected $cache;
    
    protected $debug = false;
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();
    protected $show_messages = true;
    protected $access_level = 'NONE';
    protected $form_input = false;
    protected $bread_crumbs = false;
    protected $pages = array();
    protected $upload_dir;

    //for defining form variables and types
    protected $variables = array();
    protected $types = array('INTEGER','DECIMAL','STRING','TEXT','EMAIL','URL','DATE','BOOLEAN','PASSWORD','DATETIME','TIME',
                             'IMAGE','FILE','CUSTOM');
    protected $files = []; //for IMAGE/FILE types

    //all form $variable must be defined
    protected $strict_var = true;
    protected $template;

    protected $user_csrf_token;
    protected $csrf_token = '';
     
    public function __construct(DbInterface $db, ContainerInterface $container, Cache $cache, Template $template) 
    {
        $this->db = $db;
        $this->container = $container;
        $this->cache = $cache;
        $this->template = $template;
        
        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;

        $this->upload_dir = BASE_UPLOAD.UPLOAD_DOCS;
    }
    
    public function setup($param = []) 
    {
        if(isset($param['strict_var'])) $this->strict_var = $param['strict_var'];
        
        if(isset($param['bread_crumbs'])) $this->bread_crumbs = $param['bread_crumbs'];
        
        if(isset($param['page_no'])) $this->page_no = $param['page_no'];

        if(isset($param['upload_dir'])) $this->upload_dir = $param['upload_dir'];
        //need to manually set user csrf token as wizard can used outside login env 
        if(isset($param['csrf_token'])) $this->user_csrf_token = $param['csrf_token']; 
        //turn message display on/off
        if(isset($param['show_messages'])) $this->show_messages = $param['show_messages'];      
    }

    //define all variable for validation/security purposes
    public function addVariable($var = array()) 
    {
        if(!in_array($var['type'],$this->types)) $var['type'] = 'STRING';
        if(!isset($var['title'])) $var['title'] = $var['id'];
        if(!isset($var['required'])) $var['required'] = true;
        if(!isset($var['new'])) $var['new'] = '';
         
        if($var['type'] === 'STRING' or $var['type'] === 'PASSWORD' or $var['type'] === 'EMAIL') {
            if(!isset($var['min'])) $var['min'] = 1;
            if(!isset($var['max'])) $var['max'] = 64;
            if(!isset($var['secure'])) $var['secure'] = true;
        }
         
        if($var['type'] === 'TEXT') {
            if(!isset($var['html'])) $var['html'] = false;
            if(!isset($var['secure'])) $var['secure'] = true;
            if(!isset($var['min'])) $var['min'] = 1;
            if(!isset($var['max'])) $var['max'] = 64000;
        }
         
        if($var['type'] === 'INTEGER' OR $var['type'] === 'DECIMAL') {
            if(!isset($var['min'])) $var['min'] = -100000000;
            if(!isset($var['max'])) $var['max'] = 100000000;
        }

        if($var['type'] === 'FILE' or $var['type'] === 'IMAGE') {
            if(!isset($var['min'])) $var['min'] = 0;
            if(!isset($var['max'])) $var['max'] = 10;
            $this->files[$var['id']] = $var;
        }    
                             
        $this->variables[$var['id']] = $var;
    }
    
    public function addPage($no,$title,$template,$param = []) 
    {
        $page = [];
        $page['no'] = $no;
        $page['title'] = $title;
        $page['template'] = $template; 

        if(!isset($param['go_back'])) $param['go_back'] = true;
        if(!isset($param['form_tag'])) $param['form_tag'] = 'DEFAULT';
        //specifies final page in wizard
        if(!isset($param['final'])) $param['final'] = false;
        //if last page and no form tag specified then do not wrap in default form tags
        if($param['final'] and $param['form_tag'] === 'DEFAULT') $param['form_tag'] = '';

        $page['param'] = $param;
                
        $this->pages[$no] = $page;   
    }  
    
    public function viewPage($no) 
    {
        $html = '';
        $error_page = false;

        $page = $this->pages[$no];
        $param = $page['param'];

        $this->setupPageData($no);
        
        if(!isset($page['template'])) {
            $error_page = true;
            $this->addError('No page template available!');  
        } else {
            //set template variables via magic __set() method
            $this->template->db = $this->db;
            $this->template->data = $this->data;
            $this->template->form = $this->form;
            $this->template->errors = $this->errors;
            $this->template->messages = $this->messages;
            $this->template->javascript = $this->javascript;
            
            if($this->bread_crumbs and !$param['final']) {
                $html .= '<ol class="'.$this->classes['breadcrumb'].'">';
                foreach($this->pages as $id => $data) {
                    if($id < $no) {
                        if($data['param']['go_back']) {
                            $html .= '<li><a href="?page='.$id.'">'.$data['title'].'</a></li>';
                        } else {
                            $html .= '<li>'.$data['title'].'</li>';
                        }    
                    } elseif($id == $no) {
                        $html .= '<li class="active">'.$data['title'].'</li>';  
                    }
                    
                }
                $html .= '</ol>'; 
            }  
            
            //render template form wraper
            if($param['form_tag'] === 'DEFAULT'){
                $html .= '<form action="?page='.$no.'" method="post" enctype="multipart/form-data" id="wizard_form" role="form">';
            } elseif($param['form_tag'] !== '') {
                $html .= $param['form_tag'];
            }  

            if(isset($this->user_csrf_token)) {
                $html .= '<input type="hidden" name="csrf_token" value="'.$this->user_csrf_token.'">';
            }

            //render template itself
            $html .= $this->template->render($page['template']);
             
            //close form 
            if($param['form_tag'] !== '') {
                $html .= '<input type="hidden" name="seriti_wizard" value="process"></form>';
            }  
        } 
        
        if($this->show_messages) {
            $html = $this->viewMessages().$html;    
        }
        
        return $html;
    }
    
    //NB: If you use saveData() then be careful that not overwritten on first page 
    public function process($param = array()) 
    {
        $this->form_input = false;
        $error = '';
        $message = '';
        $html = '';
       

        //placeholder for custom processing
        $this->beforeProcess();

        if($this->process_page_no) {
            //NB: all form and additional data reset on first(pageless) process
            if(isset($_GET['page']))  { 
                $this->page_no = Secure::clean('integer',$_GET['page']);
                if(isset($_POST['seriti_wizard']) and $_POST['seriti_wizard'] === 'process') $this->form_input = true;
            } else {
                $this->resetData('ALL');
                $this->page_no = 1;
                $this->initialConfig();
            }    
        }

        $page = $this->pages[$this->page_no];

        if(isset($this->user_csrf_token) and $this->form_input) {
            $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));
            $this->verifyCsrfToken($error);
            if($error !== '') $this->addError($error); 
        }

        $this->getData('form');
        $this->getData('data');

        //only process form variables if form has been submitted
        if($this->form_input) {
            //NB1: $this->form variables persist over pages and are only set when found in $_POST
            //NB2: checkbox inputs must be explicitly handled in custom processPage() function as unchecked setting results in NO $_POST value
            foreach($_POST as $key => $value) {
                if(isset($this->variables[$key])) {
                    $var = $this->variables[$key];

                    $value = trim($value);
                    //if empty check if value required before validating
                    if($value == '') {
                        if($var['required'] and $var['type'] != 'BOOLEAN') {
                            $this->addError('<b>'.$var['title'].'</b> is a required field! Please enter a value.',false); 
                        }  
                    } else {
                        $this->validate($var['id'],$value,$error);
                        if($error != '') $this->addError($error);
                    }  
                    
                    //value only saved to cache if no errors
                    $this->form[$key] = $value;
                } else {  
                    //this will force only defined wizard variables
                    if($this->strict_var) {
                        if($key !== 'seriti_wizard') $this->addError('Form value['.$key.'] is not a valid form variable!');
                    }  
                }  
            } 

            //Form::uploadFiles returns an array[0=>[],1=>[],...n=>[]] of single/multiple file data matching form id $key
            foreach($_FILES as $key => $value) {
                if(isset($this->files[$key])) {
                    $upload_options = [];
                    $upload_options['debug'] = $this->debug;
                    $upload_options['upload_dir'] = $this->upload_dir;
                    $this->form[$key] = Form::uploadFiles($key,$this->db,$upload_options,$error,$message);
                    if($error !== '') $this->addError($error);    
                    if($message !== '') $this->addMessage($message);
                } else {
                    //this will force only defined wizard file uploads
                    if($this->strict_var) {
                        $this->addError('Form file upload value['.$key.'] is not a valid form variable!');
                    } 
                }
            }    
            
            //placeholder function for custom validation etc
            $this->processPage();
            
            //save data state (regardless of errors or form submission as process_page() may modify any data)
            $this->saveData('form');
            $this->saveData('data');
            
            //set next page if no errors
            if(!$this->errors_found) {
                if($this->page_no_next === 0) $this->page_no_next = $this->page_no+1;
                $this->page_no = $this->page_no_next;
            } 
        }  

        $html .= $this->viewPage($this->page_no);

        return $html;
    }
    
    
    //placeholder functions
    public function setupPageData($no) {}
    public function beforeProcess() {}
    public function processPage() {}
    public function initialConfig() {}
    
    //helper functions
    public function getData($type = 'form') {
        //$this->cache defined in construct 
        $data = $this->cache->retrieve($type); 

        //merge with any existing template data
        if(is_array($data)) {
            //cache form values overwrite any matching keys in $this->form, $this->data...etc
            $this->$type = array_merge($this->$type,$data);
        } 
        //if form variables then use default/new value where not set 
        if($type === 'form') {   
            foreach($this->variables as $id => $var) {
                if(!isset($this->form[$id])) $this->form[$id] = $var['new'];
            }   
        }  
    }  
    
    public function saveData($type = 'form') {
        $this->cache->store($type,$this->$type);
    } 
    
    public function resetData($type = 'ALL') {
        if($type === 'ALL') {
            $this->cache->eraseAll();
        } else {
            $this->cache->erase($type);
        }    
    }   
    
    //NB: some validation functions also clean/convert form values....see validate_number/integer;
    public function validate($var_id,&$value,&$error)  {
        $error = '';
        $var = $this->variables[$var_id];
        
        switch($var['type']) {
            case 'CUSTOM'  : break; //custom validation must be handled by before_update() placeholder function
            case 'STRING'  : {
                Validate::string($var['title'],$var['min'],$var['max'],$value,$error,$var['secure']); 
                break;
            }  
            case 'PASSWORD': Validate::password($var['title'],$var['min'],$var['max'],$value,$error);  break;
            case 'TEXT'    : {
                if($var['html']) {
                    Validate::html($var['title'],$var['min'],$var['max'],$value,$error,$var['secure']);
                } else {
                    Validate::text($var['title'],$var['min'],$var['max'],$value,$error,$var['secure']);
                }   
                break;
            } 
            case 'INTEGER' : Validate::integer($var['title'],$var['min'],$var['max'],$value,$error);  break;
            case 'DECIMAL' : Validate::number($var['title'],$var['min'],$var['max'],$value,$error);  break;  
            case 'EMAIL'   : Validate::email($var['title'],$value,$error);  break;  
            case 'URL'     : Validate::url($var['title'],$value,$error);  break;  
            case 'DATE'    : Validate::date($var['title'],$value,'YYYY-MM-DD',$error);  break;  
            case 'DATETIME': Validate::dateTime($var['title'],$value,'YYYY-MM-DD HH:MM',$error);  break;  
            case 'TIME'    : Validate::time($var['title'],$value,'HH:MM:SS',$error);  break;  
            case 'BOOLEAN' : Validate::boolean($var['title'],$value,$error);  break;
             
            default: $error.='Unknown variable type['.$var['type'].']';
        }
    
    }  
}

