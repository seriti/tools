<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Csv;
use Seriti\Tools\Doc;
use Seriti\Tools\Image;
use Seriti\Tools\Audit;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\SecurityHelpers;
use Seriti\Tools\TableStructures;

use Psr\Container\ContainerInterface;

class Record extends Model 
{
    
    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use SecurityHelpers;
    use TableStructures;

    protected $container;
    protected $container_allow = ['config','s3','mail','user','system'];

    protected $record_id = 0;
    protected $action_text = 'Actions:';
    protected $action_show = 'TOP'; //TOP, BOTTOM, TOP_BOTTOM, 
    protected $col_label='';
    protected $mode = 'view';
    protected $record_name = 'record';
    protected $show_info = false;
    protected $info = [];
    protected $form = [];
    protected $dates = [];
    protected $pop_up = false;
    protected $update_calling_page = false; //update calling page if changes made in pop_up
                
    protected $images = [];
    protected $image_upload = false;
    protected $files = [];
    protected $file_upload = false;
    
    protected $data = []; //use to store current edit/view data between public function calls 
    protected $data_xtra = []; //use to store arbitrary xtra data between function calls 
    
    protected $user_access_level;
    protected $user_id;
    protected $user_csrf_token;
    protected $csrf_token = '';
    
    public function __construct(DbInterface $db, ContainerInterface $container, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;

        //$this->setup($param);
    }            
    
    public function setup($param = array()) 
    {
        //Implemented in Model Class
        if(isset($param['distinct'])) $this->distinct = $param['distinct'];
        if(isset($param['encrypt_key'])) $this->encrypt_key = $param['encrypt_key'];
        
        //implemented locally
        $this->dates['new'] = date('Y-m-d');

        if(isset($param['record_id'])) $this->record_id = $param['record_id'];
                     
        if(isset($param['record_name'])) $this->record_name = $param['record_name'];
        
        if(isset($param['action_show'])) $this->action_show = $param['action_show'];
        
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];
        
        if(isset($param['show_info'])) $this->show_info = $param['show_info'];
        
        if(isset($param['pop_up'])) {
            $this->pop_up = $param['pop_up'];
            if(isset($param['update_calling_page'])) $this->update_calling_page = $param['update_calling_page'];
        }  

        $this->user_access_level = $this->getContainer('user')->getAccessLevel();
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }
           
    public function processRecord() 
    {
        $html = '';
        $param = [];
        $form = [];

        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));

        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);

        //normally assigned in setup()
        if($this->record_id == 0) {
            if(isset($_GET['id'])) $this->record_id = Secure::clean('basic',$_GET['id']); 
            if(isset($_POST['id'])) $this->record_id = Secure::clean('basic',$_POST['id']);
        }    
        $this->key['value'] = $this->record_id;
        if($this->record_id == 0) $this->mode = 'new';
     
        if(isset($_GET['msg'])) $this->addMessage(Secure::clean('alpha',$_GET['msg']));
     
        if($this->child) {
            $this->master['key_val'] = $this->getCache('master_id');
            if($this->master['key_val'] === '') {
                throw new Exception('MASTER_TABLE_ERROR: Linked '.$this->record_name.' id unknown!');
            }    
        } else {
            $this->master['key_val'] = 0;
        } 
    
        $this->beforeProcess(); 
            
        if($this->mode === 'view')   $html .= $this->viewRecord();
        if($this->access['read_only'] === false) {
            if($this->mode === 'edit')   $html .= $this->viewEdit($form,'UPDATE');
            if($this->mode === 'new')    $html .= $this->viewEdit($form,'INSERT');
            if($this->mode === 'update') $html .= $this->updateRecord($_POST);
        }  
        
        if($this->mode === 'custom') $html .= $this->processCustom();

        return $html;
    } 
    
    public function getMode()
    {
        return $this->mode;
    } 

    public function addAllRecordCols() 
    {
        $this->addAllCols();
        foreach($this->cols as $col) {
           $this->cols[$col['id']] = $this->setupCol($col); 
        }
    }

    protected function setupCol($col) 
    {
        //table display parameters
        if(!isset($col['edit_title'])) $col['edit_title'] = $col['title'];
        if(!isset($col['class'])) $col['class'] = '';
        if(!isset($col['new'])) $col['new'] = '';
        if(!isset($col['hint'])) $col['hint'] = '';
         
        //assign type specific settings and defaults if not set
        if($col['type'] === 'DATE') {
            if(!isset($col['format'])) $col['format'] = 'DD-MMM-YYYY';
        }
         
        if($col['type'] === 'DATETIME') {
            if(!isset($col['format'])) $col['format'] = 'DD-MMM-YYYY HH:MM';
        }
         
        if($col['type'] === 'TIME') {
            if(!isset($col['format'])) $col['format'] = 'HH:MM';
        }
         
        if($col['type'] === 'TEXT') {
            if(!isset($col['encode'])) $col['encode'] = true;
            if(!isset($col['rows'])) $col['rows'] = 5;
            if(!isset($col['cols'])) $col['cols'] = 50;
        }
         
        //assign column value to use in row level warnings and messages if not already set
        if($this->col_label === '') $this->col_label = $col['id'];
        
        return $col;
    }

    public function addRecordCol($col) 
    {
        $col = $this->addCol($col);
       
        $col = $this->setupCol($col); 

        $this->cols[$col['id']] = $col;
    }
    
     protected function viewRecordActions($context,$data,$row_no,$pos = 'L') 
    {
        $html = '';
        $state_param = $this->linkState();
         
        if(count($this->actions) != 0) {
            foreach($this->actions as $action) {
                $valid = true;
                if($action['verify']) $valid = $this->verifyRowAction($action,$data);
                
                if($context === 'INSERT' or $context === 'UPDATE') {
                    if($action['type'] === 'edit' or $action['type'] === 'delete') $valid = false;
                }

                if($valid and ($action['pos'] === $pos or $pos === 'ALL')) {
            
                    if($action['class'] != '') $html .= '<span class="'.$action['class'].'">';
                    
                    $show = '';
                    if($action['icon'] !== false) $show .= $action['icon'];
                    if(isset($action['col_id'])) $show .= $data[$action['col_id']];
                    if($action['text'] != '') $show .= $action['text']; 
                        
                    if($action['type'] === 'popup') {
                        if(!strpos($action['url'],'?')) $url_mod = '?'; else $url_mod = '&';
                        $url = $action['url'].$url_mod.'id='.$data[$this->key['id']].$state_param;
                        $html .= '<a class="action" href="Javascript:open_popup(\''.$url.'\','.
                                     $action['width'].','.$action['height'].')">'.$show.'</a>';     
                    } elseif($action['type'] === 'link') {
                        if(isset($action['target'])) $target = 'target="'.$action['target'].'"'; else $target='';
                        if(!strpos($action['url'],'?')) $url_mod = '?'; else $url_mod='&';
                        $href = $action['url'].$url_mod.'id='.$data[$this->key['id']].$state_param;
                        if($action['mode'] != '') $href .= '&mode='.$action['mode'];
                        $html .= '<a class="action" '.$target.' href="'.$href.'" >'.$show.'</a>'; 
                    } elseif($action['type'] === 'check_box'){
                        $param['class'] = 'checkbox_action';
                        $html .= Form::checkbox('checked_'.$data[$this->key['id']],'YES',0,$param).$show;
                    } else {    
                        $onclick = '';
                        if($action['type'] === 'delete') {
                            $item = $this->record_name.'['.$data[$this->col_label].']';
                            $onclick = 'onclick="javascript:return confirm(\'Are you sure you want to DELETE '.$item.'?\')" '; 
                        }
                        $href = '?mode='.$action['mode'].'&id='.$data[$this->key['id']].$state_param;  
                        $html .= '<a class="action" href="'.$href.'" '.$onclick.'>'.$show.'</a>';  
                    } 
                    
                    if($action['class'] != '') $html .= '</span>';
                    //space between actions, if &nbsp; then no auto breaks
                    $html .= $action['spacer'];       
                } 
            }
        } 
        
        return $html;
    }

    protected function viewEdit($form = [],$edit_type = 'UPDATE') 
    {
        $html = '';
        $action_html = '';
        
        $this->checkAccess($edit_type,$this->record_id);
        
        $data = $this->edit($this->record_id);
        $this->data = $data;
        
        
        if($this->show_info) $info = $this->viewInfo('EDIT'); else $info = '';
        $html .= $info;
        
        $class_edit  = 'class="'.$this->classes['table_edit'].'"';
        $class_label = 'class="'.$this->classes['col_label'].'"';
        $class_value = 'class="'.$this->classes['col_value'].'"';
        $class_submit= 'class="'.$this->classes['col_submit'].'"';

        if($this->action_show !== '') {
            $actions = $this->viewRecordActions($edit_type,$data,0,'ALL');
            $action_html .= '<div class="row">'.
                            '<div '.$class_label.'>'.$this->action_text.'</div>'.
                            '<div '.$class_label.'><input id="edit_submit" type="submit" name="submit" value="Update" class="'.$this->classes['button'].'">'.$actions.'</div>'.
                            '</div>';    
        }
        
        $html .= '<div id="edit_div" '.$class_edit.'>';
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=update&id='.$this->record_id.'" name="update_form" id="update_form">';
        $html .= $this->formState();        
               
        $html .= '<input type="hidden" name="edit_type" value="'.$edit_type.'">'; 

        if(strpos($this->action_show,'TOP') !== false) $html .= $action_html;
                                        
        if($edit_type === 'UPDATE') {   
            $html .= '<input type="hidden" name="'.$this->key['id'].'" value="'.$this->record_id.'">';

            if($this->key['view']) {
                $html .= '<div class="row">'.
                         '<div '.$class_label.'>'.$this->key['title'].':</div>'.
                         '<div '.$class_value.'><b>'.$this->record_id.'</b></div>'.
                         '</div>';
            }             
        }

        if($edit_type === 'INSERT') {   
            if($this->key['key_auto']) {
                $html .= '<input type="hidden" name="'.$this->key['id'].'" value="0">';        
            } else {     
                $html .= '<div class="row">'.
                         '<div '.$class_label.'>'.$this->key['title'].':</div>'.
                         '<div '.$class_value.'>'.
                         '<input type="text" class="'.$this->classes['edit'].'" name="'.$this->key['id'].'" value="'.@$form[$this->key['id']].'">(Must be a UNIQUE identifier)'.
                         '</div></div>';
            }               
        }          
        
        foreach($this->cols as $col) {
            if($col['edit']) {
                $param = [];

                if($col['required']) $title = $this->icons['required'].$col['edit_title']; else $title = $col['edit_title'];
                $html .= '<div id="tr_'.$col['id'].'" class="row"><div '.$class_label.'>'.$title.':</div>'.
                         '<div '.$class_value.'>';
                if(isset($form[$col['id']])) {
                    $value = $form[$col['id']];
                    $param['redisplay'] = true;
                } else {
                    $value = $data[$col['id']];
                    $param['redisplay'] = false;
                } 
                $repeat = false;        
                   
                if($col['hint'] == '' and $col['encrypt']) $col['hint'] = '(All content is encrypted and not searchable.)';

                if($col['hint'] != '' and $col['type'] !== 'BOOLEAN') $html .= '<span class="edit_hint">'.$col['hint'].'<br/></span>';
                
                if($col['type'] === 'CUSTOM') {
                    $html .= $this->customEditValue($col['id'],$value,$edit_type,$form);
                } else {
                    $html .= $this->viewEditValue($col['id'],$value,$edit_type,$param);
                }  
                
                if($col['hint'] != '' and $col['type']==='BOOLEAN') {
                    $html.='<span class="edit_hint" style="display: inline">'.$col['hint'].'<br/></span>';
                }    
                
                $html.='</div></div>';
                
                if($col['repeat']) {
                    $form_id = $col['id'].'_repeat';
                    $html .= '<div class="row"><div '.$class_label.'">'.$col['edit_title'].' repeat:</div>'.
                             '<div '.$class_value.'>';
                    $param['repeat'] = true;
                    if(isset($form[$form_id])) $value = $form[$form_id]; else $value = $data[$col['id']];
                    $html .= $this->viewEditValue($col['id'],$value,$edit_type,$param);
                    $html .= '</div></div>';
                } 
            } 
        }
        
        $html .= $this->viewEditXtra($this->record_id,$form,$edit_type);
        
        if(strpos($this->action_show,'BOTTOM') !== false) $html .= $action_html;
        
        $html .= '</form>';
        $html .= '</div>';
        
        $html = $this->viewMessages().$html;
        
        return $html;
    } 
    
    protected function viewRecord($form=array()) 
    {
        $html = '';
        
        $data=$this->view($this->record_id);
                
                
        if($this->show_info) $info = $this->viewInfo('VIEW'); else $info = '';
        $html .= $info;
        
        $class_label = 'class="'.$this->classes['col_label'].'"';
        $class_value = 'class="'.$this->classes['col_value'].'"';
        
        if($this->action_show !== '') {
            $actions = $this->viewRecordActions($edit_type,$data,0,'ALL');
            if($actions !== '') {
                $action_html .= '<div class="row">'.
                                '<div '.$class_label.'>'.$this->action_text.'</div>'.
                                '<div '.$class_label.'>'.$actions.'</div>'.
                                '</div>';    
            }
        }
        
        $html .= '<div id="edit_div">';
        $html .= '<div class="container">';

        if($data === 0) {
            $html .= '<p>'.$this->record_name.'['.$this->record_id.'] not recognised!</p>';
        } else { 

            if(strpos($this->action_show,'TOP') !== false) $html .= $action_html;

            foreach($this->cols as $col) {
                if($col['view']) {
                    $value=$data[$col['id']];
                    switch($col['type']) {
                        case 'DATE' : {
                            $value=Date::formatDate($value,'MYSQL',$col['format']);
                            break;
                        } 
                        case 'DATETIME' : {
                            $value=Date::formatDateTime($value,'MYSQL',$col['format']);  
                            break;
                        }
                        case 'TIME' : {
                            $value=Date::formatTime($value,'MYSQL',$col['format']);
                            break;
                        }   
                        case 'EMAIL' : {
                            $value=Secure::clean('email',$value);
                            $value='<a href="mailto:'.$value.'">'.$value.'</a>'; 
                            break;
                        } 
                        case 'URL' : {
                            $value=Secure::clean('url',$value);
                            if(strpos($value,'//')===false) $http='http://'; else $http='';
                            $value='<a href="'.$http.$value.'" target="_blank">'.$value.'</a>';
                            break;
                        }
                        case 'BOOLEAN' : {
                            if($value==1) $value=$this->icons['true']; else $value=$this->icons['false'];
                            break;
                        } 
                        case 'PASSWORD' : {
                            $value='****';
                            break;
                        } 
                        case 'STRING' : {
                            $value=Secure::clean('string',$value);
                            break;
                        } 
                        case 'TEXT' : {
                            if($col['html']) {
                                if($col['encode']) $value=Secure::clean('html',$value);
                            } else {
                                $value=Secure::clean('text',$value);
                                $value=nl2br($value);
                            }
                            break;
                        }  
                        
                        default : $value=Secure::clean('string',$value);
                    }

                    $this->modifyRecordValue($col['id'],$data,$value);
                    
                    //add javascript to copy to clipboard
                    if($col['copylink']) {
                        $span_id = 'copy'.$col['id'];
                        $value = Calc::viewTextCopyLink($col['copylink'],$span_id,$value);
                    }

                    $html .= '<div class="row">'.
                             '<div '.$class_label.'>'.$col['title'].':</div>'.
                             '<div '.$class_value.'>'.$value.'</div>'.
                             '</div>';
                } 
            }
        }   
        
        if(strpos($this->action_show,'BOTTOM') !== false) $html .= $action_html;

        $html.='</div>';
        
        $html=$this->viewMessages().$html;
        
        return $html;
    } 

    //form processing functions
    protected function updateRecord($form)  
    {
        $html = '';
        $error = '';
        
        if(!$this->verifyCsrfToken($error)) $this->addError($error);
        
        $edit_type = $form['edit_type'];
        if($edit_type !== 'UPDATE' and $edit_type !== 'INSERT') {
           $this->addError('Cannot determine if UPDATE or INSERT!'); 
        }

        if(!$this->errors_found) {
            if($edit_type === 'INSERT') {
                $output = $this->create($form);
                if($output['status'] === 'OK') $this->record_id = $output['id'];
            }
            if($edit_type === 'UPDATE') {
                $output = $this->update($this->record_id,$form);
            }
        }    
        
        if($this->errors_found) {
            $html .= $this->viewEdit($form,$edit_type);
        } else {
            $this->addMessage('Successfuly updated '.$this->record_name); 
            $html .= $this->viewRecord($form);

        }   
        
        return $html;
    }
    
    /*** PLACEHOLDERS ***/
   
    protected function beforeProcess() {}
    protected function processCustom() {}
    
    
}
?>
