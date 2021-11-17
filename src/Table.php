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

class Table extends Model 
{
    
    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use SecurityHelpers;
    use TableStructures;

    protected $container;
    protected $container_allow = ['config','s3','mail','user','system'];

    protected $col_label='';
    protected $dates = array('from_days'=>30,'to_days'=>1,'zero'=>'1900-01-01');
    protected $mode = 'list_all';
    protected $row_name = 'row';
    protected $row_name_plural = 'rows';
    protected $row_tag = true;
    protected $show_info = false;
    protected $info = array();
    protected $page_no = 1;
    protected $row_no = 1;
    protected $row_count = 0;
    protected $max_rows = 100;
    protected $nav_show = 'TOP_BOTTOM'; //can be TOP or BOTTOM or TOP_BOTTOM or NONE
    protected $action_header = 'Action';
    protected $form = array();
    protected $pop_up = false;
    protected $update_calling_page = false; //update calling page if changes made in pop_up
    protected $add_repeat = false; //set to true to continue adding records after submit rather than show list view
    protected $add_href = '';//change add nav link to some external page or wizard
    protected $key_error = '';
                
    protected $table_action = false;//where multiple rows can be selected and modified as one
    protected $table_edit_all = false; //where all rows are editable together 
    protected $excel_csv = true;
    protected $actions = array();
    protected $location = '';
    protected $search = array();
    protected $search_xtra = array();
    protected $search_rows = 0;
    protected $show_search = false;
    protected $order_by = array(); //created in add_col() using all listed cols for use in search form
    protected $order_by_current = '';
    protected $action_col_left = false;
    protected $action_col_right = false;
    protected $images = array();
    protected $image_upload = false;
    protected $files = array();
    protected $file_upload = false;
    protected $data_prev = array(); //previous rows data 
    protected $data = array(); //use to store current edit/view data between public function calls 
    protected $data_xtra = array(); //use to store arbitrary xtra data between function calls 
    protected $calc_aggregate = false;
    protected $search_aggregate = array();
    
    protected $verify_csrf = true;
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
        if(isset($param['location'])) $this->location = $param['location']; //Could use URL_CLEAN_LAST
                     
        if(isset($param['row_name'])) $this->row_name = $param['row_name'];
        if(isset($param['row_name_plural'])) {
            $this->row_name_plural = $param['row_name_plural'];
        } else {
            $this->row_name_plural = $this->row_name.'s';
        }

        if(isset($param['max_rows'])) $this->max_rows = $param['max_rows'];    
        if(isset($param['table_edit_all'])) $this->table_edit_all = $param['table_edit_all'];
        if(isset($param['nav_show'])) $this->nav_show = $param['nav_show'];
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];
        if(isset($param['action_header'])) $this->action_header = $param['action_header'];
        if(isset($param['show_info'])) $this->show_info = $param['show_info'];
        if(isset($param['pop_up'])) {
            $this->pop_up = $param['pop_up'];
            if(isset($param['update_calling_page'])) $this->update_calling_page = $param['update_calling_page'];
        }  
        if(isset($param['excel_csv'])) $this->excel_csv = $param['excel_csv'];
        if(isset($param['add_repeat'])) $this->add_repeat = $param['add_repeat'];
        if(isset($param['add_href'])) $this->add_href = $param['add_href'];

        //use to turn off user csrf verification
        if(isset($param['verify_csrf'])) $this->verify_csrf = $param['verify_csrf']; 

        $this->user_access_level = $this->getContainer('user')->getAccessLevel();
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }
           
    public function processTable() 
    {
        $html = '';
        $id = 0;
        $param = array();
        $form = array();

        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));

        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        if(isset($_GET['page'])) $this->page_no = Secure::clean('integer',$_GET['page']);
        if(isset($_POST['page'])) $this->page_no = Secure::clean('integer',$_POST['page']);
        if($this->page_no < 1) $this->page_no = 1;
        
        if(isset($_GET['row'])) $this->row_no = Secure::clean('integer',$_GET['row']);
        if(isset($_POST['row'])) $this->row_no = Secure::clean('integer',$_POST['row']);
        if($this->row_no < 1) $this->row_no = 1;
        
        if(isset($_GET['id'])) $id = Secure::clean('basic',$_GET['id']); 
        if(isset($_POST['id'])) $id = Secure::clean('basic',$_POST['id']);
        $this->key['value'] = $id;
     
        if(isset($_GET['msg'])) $this->addMessage(Secure::clean('alpha',$_GET['msg']));
     
        if($this->mode === 'list_all') {
            $this->setCache('sql','');
            $this->mode = 'list';
            
            if($this->child and $id !== 0) $this->setCache('master_id',$id);
        } 
 
        if($this->child) {
            $this->master['key_val'] = $this->getCache('master_id');
            if($this->master['key_val'] === '') {
                throw new Exception('MASTER_TABLE_ERROR: Linked '.$this->row_name.' id unknown!');
            }    
        } else {
            $this->master['key_val'] = 0;
        } 
    
        $this->beforeProcess($id);    
        
        if($this->mode === 'sort') {
            $this->mode = 'list';
            $param['sort_col'] = Secure::clean('basic',$_GET['col']); 
            $param['sort_dir'] = Secure::clean('basic',$_GET['dir']); 
        }

        if($this->mode === 'excel') {
            if(isset($_GET['cols'])) $param['cols'] = Secure::clean('basic',$_GET['cols']); 
            if(!$this->dumpData('excel',$param)) $this->mode = 'list';
        }    
        
        if($this->mode === 'list')   $html .= $this->viewTable($param);
        if($this->mode === 'view')   $html .= $this->viewRecord($id);
        if($this->access['read_only'] === false) {
            if($this->mode === 'edit')   $html .= $this->viewEdit($id,$form,'UPDATE');
            if($this->mode === 'add')    $html .= $this->viewEdit($id,$form,'INSERT');
            if($this->mode === 'update') $html .= $this->updateRow($id,$_POST);
            if($this->mode === 'update_table') $html .= $this->updateTable(); //multiple selected row update 
            if($this->mode === 'delete') $html .= $this->deleteRow($id,'SINGLE');
        }  
        if($this->mode === 'search') $html .= $this->search();
        
        if($this->mode === 'custom') $html .= $this->processCustom($id);
                        
        return $html;
    } 
    
    public function getMode()
    {
        return $this->mode;
    } 

    public function addAllTableCols() 
    {
        $this->addAllCols();
        foreach($this->cols as $col) {
           $this->cols[$col['id']] = $this->setupCol($col); 
        }
    }

    protected function setupCol($col) 
    {
        //table display parameters
        if(!isset($col['edit_title'])) $col['edit_title']=$col['title'];
        if(!isset($col['class'])) $col['class']='';
        if(!isset($col['class_search'])) $col['class_search']=$col['class'];
        if(!isset($col['new'])) $col['new']='';
        if(!isset($col['hint'])) $col['hint']='';
        if(!isset($col['copylink'])) $col['copylink']=false;
         
         //assign type specific settings and defaults if not set
        if($col['type']=='DATE') {
            if(!isset($col['format'])) $col['format']='DD-MMM-YYYY';
        }
         
        if($col['type']=='DATETIME') {
            if(!isset($col['format'])) $col['format']='DD-MMM-YYYY HH:MM';
        }
         
        if($col['type']=='TIME') {
            if(!isset($col['format'])) $col['format']='HH:MM';
        }
         
        if($col['type']=='TEXT') {
            if(!isset($col['encode'])) $col['encode']=true;
            if(!isset($col['rows'])) $col['rows']=5;
            if(!isset($col['cols'])) $col['cols']=50;
        }
         
        if(!isset($col['sort'])) $col['sort']='';

         //assign list cols to order by list 
        if($col['list']==true and !isset($col['join'])) {
            $this->addSortOrder($col['id'],$col['edit_title']);
        }  
         
        //assign column value to use in row level warnings and messages if not already set
        if($this->col_label === '') $this->col_label=$col['id'];
        
        return $col;
    }

    public function addTableCol($col) 
    {
        $col = $this->addCol($col);
       
        $col = $this->setupCol($col); 

        $this->cols[$col['id']]=$col;
    }
    
       
    public function addSearchAggregate($aggregate) 
    {
        $this->calc_aggregate = true;
        //$aggregate['sql'],$aggregate['title'] as a minimum
        //ie:$aggregate=['sql'=>'SUM(amount)','title'=>'Total amount'];
        $this->search_aggregate[] = $aggregate; 
    } 
    
    
    public function addSearch($cols,$param=array()) 
    {
        if($this->access['search']) {
            $this->search=array_merge($this->search,$cols);
            if(isset($param['rows'])) {
                $this->search_rows=$param['rows'];
            } else {
                if($this->search_rows==0) {
                    $this->search_rows=ceil(count($this->search)/3);
                }  
            }  
            $this->show_search=true;
        }  
    }
    
    //NB: this public function can sometimes be replicated by using 'xtra'=>'X.col_id' in standard col definition
    public function addSearchXtra($col_id,$col_title,$param=array()) 
    {
        if($this->access['search']) { 
            //NB: col_id must have join table alias... ie TX.col_id 
            //*** "." is not allowed in variable names or array keys by PHP ***
            $xtra_id = str_replace('.','_',$col_id);
            $arr['id'] = $col_id;
            $arr['title'] = $col_title;
            if(isset($param['type'])) $arr['type'] = $param['type']; else $arr['type'] = 'STRING';
            if(isset($param['class'])) $arr['class'] = $param['class']; else $arr['class'] = '';
            
            $this->search_xtra[$xtra_id] = $arr;
           // if(isset($param['join'])) $this->add_sql('JOIN',$param['join']);
        } 
    }
        
    public function addSortOrder($order_by,$description,$type='') {
         if($type=='DEFAULT' or $this->order_by_current=='') $this->order_by_current=$order_by;
         $this->order_by[$order_by]=$description;
    } 
    
    
    //view generating public function
    public function viewTable($param=array()) 
    {
        $sql   = '';
        $where = '';
        $html  = '';
        $form  = array();
        
        //custom or search sql passed in or cached 
        $initialise = true;        
        if(isset($param['sql'])) {
            $initialise = false;        
            $this->row_count = $param['sql_count'];
            $form = $param['form'];
            
            $this->setCache('sql',$param['sql']);
            $this->setCache('sql_count',$this->row_count);
            $this->setCache('form',$form);
        } else {//check for cached search sql 
            $sql = $this->getCache('sql');
            if($sql != '') {
                $initialise = false; 
                $param['sql'] = $sql;
                $this->row_count = $this->getCache('sql_count');
                $form = $this->getCache('form');
                //after search with null result and then add a new record
                if($this->row_count == 0) {
                  $initialise = true;
                  unset($param['sql']);
                }  
            }  
        } 
        
        if($initialise) {
            $count = $this->count();
            $this->row_count = $count['row_count'];

            if($this->order_by_current!='') $this->addSql('ORDER',$this->order_by_current);   
        } 
        
        //redirect to add a new row if none exist
        if($this->row_count == 0 and $this->mode == 'list') {
            if($this->access['add']) {
                if($this->add_href != '') {
                    $location = Secure::clean('header',$this->add_href);
                    header('location: '.$location);
                    exit;
                } else {  
                    $this->addMessage('No '.$this->row_name.' data exists! Please add your first '.$this->row_name);
                    $html = $this->viewEdit('0',$form,'INSERT');
                    return $html;
                }  
                
            } else {
                $this->addMessage('No '.$this->row_name.' data exists! You are not authorised to add a '.$this->row_name);
            }   
        } 

        //pagination mods to final sql statement
        $sql_limit = (($this->page_no-1)*$this->max_rows).', '.$this->max_rows.' ';
        $this->addSql('LIMIT',$sql_limit);
        
        //get all matching data
        $table = $this->list($param);

        //FINALLY Generate HTML output
        $html = '';

        if($this->show_info) $info = $this->viewInfo('LIST'); else $info = '';
        
        $nav = $this->viewNavigation('TABLE'); 
                        
        $header = $this->viewHeader('TOP');

        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;//'<p align="center">'.$nav.'</p>';
        if($info != '') $html .= $info;
        if($this->search_rows != 0) $html .= $this->viewSearch($form);
       
        $html .= '<div id="list_div">'; 
        if($this->table_action) {
            $html .= '<form method="post" action="?mode=update_table" name="table_form" id="table_form" '.
                     'onsubmit="document.body.style.cursor=\'wait\'">'.$this->viewTableActions();
            //add any hidden variables        
            $html .= '<input type="hidden" name="page" value="'.$this->page_no.'">'.
                     $this->formState();        
        }

        $html .= '<table class="'.$this->classes['table'].'" id="'.$this->table.'_table">'.$header;
        
        if($this->row_count) {
            $row_no = 0;
            foreach($table as $row) {
                $row_no++;
                if($this->table_edit_all) {
                    $html .= $this->viewRowEdit($row,$row_no);
                } else {
                    $html .= $this->viewRow($row,$row_no);    
                }
                
                //store as previous row
                $this->data_prev = $row;
            }
        } 
                        
        $html .= '</table>';
        if($this->table_action) {
            if(strpos($this->nav_show,'BOTTOM')) {
                $html .= '<input type="submit" name="action_submit" value="'.$this->texts['btn_action'].'" class="'.$this->classes['button'].'">';
            }
            $html .= '</form>';
        }     
        $html .= '</div>';

        if(strpos($this->nav_show,'BOTTOM') !== false and $this->row_count > $this->max_rows) $html .= $nav;
        
        $html = $this->viewMessages().$html;

        return $html;
    }

    protected function viewTableActions() 
    {
        $html = '';
        $actions = array();
        $actions['SELECT'] = 'Action for selected '.$this->row_name_plural;
        $action_email = '';
        $action_id = '';

        if(!$this->access['read_only']) {
            if($this->table_edit_all and $this->access['edit']) {
                $actions['UPDATE']='Update selected '.$this->row_name_plural;
                $action_id = 'UPDATE';
            }   
            if($this->access['delete']) $actions['DELETE']='Delete selected '.$this->row_name_plural;
            if($this->access['email']) {
                $actions['EMAIL'] = 'Email selected '.$this->row_name_plural;
                if(isset($_POST['action_email'])) $action_email = Secure::clean('email',$_POST['action_email']);
            }
            if($this->child and $this->access['edit'] and $this->access['move']) {
                $actions['MOVE'] = 'Move selected '.$this->row_name_plural;
            }   
        }  
        
        if(count($actions) > 0) {
            //select action list
            $param = array();
            $param['class'] = $this->classes['action'];
            $param['onchange'] = 'javascript:change_table_action()';
            
            $html .= '<div id="action_div">';
          
            $html .= '<span style="padding:8px;"><input type="checkbox" id="checkbox_all"></span>'.
                     '<script type="text/javascript">'.
                     '$("#checkbox_all").click(function () {$(".checkbox_action").prop(\'checked\', $(this).prop(\'checked\'));});'.
                     '</script>';
          
            $html .= Form::arrayList($actions,'table_action',$action_id,true,$param);
            
            $js_xtra = '';
            $html_xtra = '';

            if($this->child) {
                $param = array();
                $param['class'] = $this->classes['action'];
                if($this->master['action_item'] === 'SELECT') {
                    if($this->master['action_sql'] != '') {
                        $sql  = str_replace('{KEY_VAL}',$this->db->escapeSql($this->master['key_val']),$this->master['action_sql']);
                    } else {
                        $sql = 'SELECT `'.$this->master['key'].'`,`'.$this->master['label'].'` FROM `'.$this->master['table'].'` '.
                               'WHERE `'.$this->master['key'].'` <> "'.$this->db->escapeSql($this->master['key_val']).'" '.
                               'ORDER BY `'.$this->master['label'].'` ';
                    }
                    $html_action_item = Form::sqlList($sql,$this->db,'master_action_id',$this->master['action_id'],$param);
                } else { 
                    $param['class'] = $this->classes['search'];
                    $html_action_item = str_replace('_',' ',$this->master['key']).' '.
                                        Form::textInput('master_action_id',$this->master['action_id'],$param);
                }

                $js_xtra .= 'var action_master_select = document.getElementById(\'action_master_select\');'.
                            'action_master_select.style.display = \'none\'; '.
                            'if(table_action.options[action_index].value==\'MOVE\') action_master_select.style.display = \'inline\'; ';

                $html_xtra .= '<span id="action_master_select" style="display:none"> to&raquo;'.
                              $html_action_item.
                              '</span>';
            }    
                 
            $html .= '<script type="text/javascript">'.
                     'function change_table_action() {'.
                     'var table_action = document.getElementById(\'table_action\');'.
                     'var action_index = table_action.selectedIndex; '.
                     'var action_email_select = document.getElementById(\'action_email_select\');'.
                     'action_email_select.style.display = \'none\'; '.
                     'if(table_action.options[action_index].value==\'EMAIL\') action_email_select.style.display = \'inline\'; '.
                     $js_xtra.
                     '}</script>';

            $html .= '<span id="action_email_select" style="display:none"> to&raquo;'.
                     Form::textInput('action_email',$action_email,$param).
                     '</span>'.$html_xtra.'&nbsp;'.
                     '<input type="submit" name="action_submit" value="'.$this->texts['btn_action'].'" class="'.$this->classes['button'].'">';

            $html .= '</div>';
        } 
         
        return $html; 
    }
    
    protected function viewHeader($position='TOP') 
    {
        $html='';
        if($position=='TOP') {
            $html='<tr class="thead">';
            if($this->action_col_left) $html.='<th>'.$this->action_header.'</th>';
            if($this->image_upload) $html.='<th>Images</th>';
            if($this->file_upload) $html.='<th>'.$this->files['title'].'</th>';
            foreach($this->cols as $col) {
                if($col['list']) {
                    if($col['sort']!='') {
                        $link_str=' <a href="?mode=sort&dir=up&col='.$col['id'].'">'.$this->icons['sort_up'].'</a>'.
                                  '<a href="?mode=sort&dir=dn&col='.$col['id'].'">'.$this->icons['sort_dn'].'</a>';
                    } else {
                        $link_str='';
                    }    
                    $html.='<th>'.$col['title'].$link_str.'</th>'; 
                }  
            }
            if($this->action_col_right) $html.='<th>Action</th>';
            $html.='</tr>';
        }  
        return $html; 
    }  
            
    protected function viewRow($data,$row_no)  
    {
        $html = '';
        if($this->row_tag) $html .= '<tr id="'.$row_no.'">'; else $html .= '<tr>';
                
        if($this->action_col_left) $html .= '<td valign="top">'.$this->viewActions($data,$row_no,'L').'</td>';
        if($this->image_upload) $html .= '<td valign="top">'.$this->viewImages($data).'</td>';
        if($this->file_upload) $html .= '<td valign="top">'.$this->viewFiles($data).'</td>';
        foreach($this->cols as $col) {
            if($col['list']) {
                $col_id = $col['id'];
                $value = $data[$col_id];

                $value = $this->viewColValue($col_id,$value);
                
                //placeholder to allow any xtra mods to display value
                $this->modifyRowValue($col_id,$data,$value);

                //add javascript to copy to clipboard
                if($col['copylink'] !== false) {
                    $span_id = $col_id.$row_no;
                    $value = Calc::viewTextCopyLink($col['copylink'],$span_id,$value);
                }
                
                if($col['type'] === 'DECIMAL') $style = 'style="text-align:right"'; else $style = '';
                $html .= '<td '.$style.'>'.$value.'</td>';
            } 
        }
        
        if($this->action_col_right) $html .= '<td valign="top">'.$this->viewActions($data,$row_no,'R').'</td>';
        
        $html .= '</tr>';
        return $html;
    } 

    protected function viewRowEdit($data,$row_no)  
    {
        $html = '';

        $record_id = $data[$this->key['id']];

        if($this->row_tag) $html .= '<tr id="'.$row_no.'">'; else $html .= '<tr>';
                
        if($this->action_col_left) $html .= '<td valign="top">'.$this->viewActions($data,$row_no,'L').'</td>';
        if($this->image_upload) $html .= '<td valign="top">'.$this->viewImages($data).'</td>';
        if($this->file_upload) $html .= '<td valign="top">'.$this->viewFiles($data).'</td>';
        foreach($this->cols as $col) {
            if($col['list']) {
                $col_id = $col['id'];
                $value = $data[$col_id];
                $param = [];

                if($col['edit']) {
                    //multiple rows need unique form names
                    $param['name'] = $record_id.':'.$col_id;
                    $value = $this->viewEditValue($col_id,$value,'UPDATE',$param);
                } else {
                    $value = $this->viewColValue($col_id,$value);
                }
                
                //placeholder to allow any xtra mods to display value
                $this->modifyRowValue($col['id'],$data,$value);

                //add javascript to copy to clipboard
                if($col['copylink'] !== false) {
                    $span_id = $col_id.$row_no;
                    $value = Calc::viewTextCopyLink($col['copylink'],$span_id,$value);
                }
                
                if($col['type'] === 'DECIMAL') $style = 'style="text-align:right"'; else $style = '';
                $html .= '<td '.$style.'>'.$value.'</td>';
            } 
        }
        
        if($this->action_col_right) $html .= '<td valign="top">'.$this->viewActions($data,$row_no,'R').'</td>';
        
        $html .= '</tr>';
        return $html;
    }
        
    protected function viewEdit($id,$form = [],$edit_type = 'UPDATE') 
    {
        $html = '';
        
        $this->checkAccess($edit_type,$id);
        
        $data = $this->edit($id);
        $this->data = $data;
        
        $nav = $this->viewNavigation('TABLE'); 
        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;
        
        if($this->show_info) $info = $this->viewInfo('EDIT'); else $info = '';
        $html .= $info;
        
        $class_edit  = 'class="'.$this->classes['table_edit'].'"';
        $class_label = 'class="'.$this->classes['col_label'].'"';
        $class_value = 'class="'.$this->classes['col_value'].'"';
        $class_submit= 'class="'.$this->classes['col_submit'].'"';
        
        $html .= '<div id="edit_div" '.$class_edit.'>';
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=update&id='.$id.'" name="update_form" id="update_form">';
        $html .= $this->formState();        
               
        $html .= '<input type="hidden" name="page" value="'.$this->page_no.'">'.
                 '<input type="hidden" name="row" value="'.$this->row_no.'">'.
                 '<input type="hidden" name="edit_type" value="'.$edit_type.'">'; 
                                
        if($edit_type === 'UPDATE') {   
            $html .= '<div class="row"><div '.$class_label.'>'.$this->key['title'].':</div>'.
                     '<div '.$class_value.'><input type="hidden" name="'.$this->key['id'].'" value="'.$id.'"><b>'.$id.'</b>&nbsp;';
            $html .= $this->viewEditActions($id,$this->data,$this->row_no);
            $html .= '</div></div>';       
        }

        if($edit_type === 'INSERT') {        
            $html .= '<div class="row"><div '.$class_label.'>'.$this->key['title'].':</div>'.
                     '<div '.$class_value.'>';
            if($this->key['key_auto']) {
                $html .= '<input type="hidden" name="'.$this->key['id'].'" value="0"><b>Automatically generated!</b>';        
            } else {
                $html .= '<input type="text" class="'.$this->classes['edit'].'" name="'.$this->key['id'].'" '.
                         'value="'.@$form[$this->key['id']].'">(Must be a UNIQUE identifier)';  
            } 
            $html .= '</div></div>';         
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
        
        $html .= $this->viewEditXtra($id,$form,$edit_type);

        if($edit_type === 'INSERT') $btn_text = $this->texts['btn_insert'];
        if($edit_type === 'UPDATE') $btn_text = $this->texts['btn_update'];

        $html .= '<div class="row"><div '.$class_submit.'>'.
                 '<input id="edit_submit" type="submit" name="submit" value="'.$btn_text.'" class="'.$this->classes['button'].'">'.
                 '</div></div>';
        $html .= '</form>';
        $html .= '</div>';
        
        $html = $this->viewMessages().$html;
        
        return $html;
    } 
    
    protected function viewRecord($id,$form=array()) 
    {
        $html = '';
        
        $data=$this->view($id);
                
        $nav = $this->viewNavigation('TABLE'); 
        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;
        
        if($this->show_info) $info = $this->viewInfo('VIEW'); else $info = '';
        $html .= $info;
        
        $class_label = 'class="'.$this->classes['col_label'].'"';
        $class_value = 'class="'.$this->classes['col_value'].'"';
        
        
        $html .= '<div id="edit_div">';
        $html .= '<div class="container">';

        if($data === 0) {
            $html .= '<p>'.$this->row_name.'['.$id.'] not recognised!</p>';
        } else {  
            if($this->access['edit']) {
                foreach($this->actions as $action) {
                    if($action['type'] === 'edit') {
                        if(isset($action['icon']) and $action['icon'] != false) {
                            $show = '<img class="action" src="'.$action['icon'].'" border="0" title="'.$action['text'].' '.$this->row_name.'" >';
                            if(isset($action['icon_text'])) $show .= $action['icon_text'];
                        } else {  
                            $show = $action['text'];
                        } 
                        $html .= '<a class="action" href="?mode=edit&page='.$this->page_no.'&id='.$id.'" >'.$show.'</a>';
                    }  
                }  
            } 
            
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
        $html.='</div>';
        
        $html=$this->viewMessages().$html;
        
        return $html;
    } 

    //form processing functions
    protected function search() 
    {
        $error = '';
        $where = '';
                
        $form['order_by'] = Secure::clean('string',$_POST['order_by']);
        if(isset($_POST['order_by_desc']) and substr($form['order_by'],-4) != 'DESC') {
            $form['order_by_desc'] = true; 
        } else {
            $form['order_by_desc'] = false;
        } 
        
        foreach($this->search as $col_id) {
            $col = $this->cols[$col_id];
            if(isset($col['xtra'])) $col_str = $col['xtra']; else $col_str = 'T.`'.$col_id.'`';
            
            if($col['type'] == 'DATE' or $col['type'] == 'DATETIME') {
                $value = array();
                
                if(isset($_POST[$col_id.'_from_use'])) $value['from_use'] = true; else $value['from_use'] = false;
                $value['from'] = $_POST[$col_id.'_from'];
                if($value['from_use']) {
                    $this->validate($col_id,$value['from'],'SEARCH');
                    $where .= $col_str.' >= "'.$this->db->escapeSql($value['from']).'" AND '; 
                }    
                                
                if(isset($_POST[$col_id.'_to_use'])) $value['to_use'] = true; else $value['to_use'] = false;
                $value['to'] = $_POST[$col_id.'_to'];
                if($value['to_use']) {
                    $this->validate($col_id,$value['to'],'SEARCH');
                    $where .= $col_str.' <= "'.$this->db->escapeSql($value['to']).'" AND ';
                }    
            } elseif($col['type'] === 'BOOLEAN') {
                $value = $_POST[$col_id];
                if($value != 'ALL') {
                    if($value === 'YES')  $where .= $col_str.' = "1" AND ';
                    if($value === 'NO') $where .= $col_str.' = "0" AND ';
                } 
            } else {  
                $value = $_POST["$col_id"];
                $value_mod = $value;
                
                //strip value of search specific terms like <>+* and set sql_parse operators
                $this->db->parseSearchTerm($value_mod,$sql_parse);
                
                //NB: validate routines also modify $value sometimes(like stripping out thousand separators)
                $this->validate($col_id,$value_mod,'SEARCH');
                                    
                if($value_mod != '') {
                    if(isset($this->select[$col_id])) {
                        if($value_mod != 'ALL') $where .= $col_str.' = "'.$this->db->escapeSql($value_mod).'" AND ';
                    } else {
                        if($value_mod == 'null') {
                            if($sql_parse['operator'] == '') $sql_parse['operator'] = '=';
                            $where .= $col_str.' '.$sql_parse['operator'].' "" AND ';
                        } else {  
                            $where .= $col_str.' '.$sql_parse['operator'].' "'.
                                      $sql_parse['prefix'].$this->db->escapeSql($value_mod).$sql_parse['suffix'].'" AND ';
                        }  
                    }   
                }
            
            
            }

            $form[$col_id] = $value;  
        }         

        $xtra_system = [];
        //add attached file fields for searching
        if(isset($this->files) and $this->files['search']) {
            $xtra_system['linked_file_count'] = '(SELECT COUNT(*) FROM '.$this->files['table'].' WHERE location_id = CONCAT("'.$this->files['location'].'",T.'.$this->key['id'].'))' ;
            $xtra_system['linked_file_name'] = '(SELECT GROUP_CONCAT(file_name_orig) FROM '.$this->files['table'].' WHERE location_id = CONCAT("'.$this->files['location'].'",T.'.$this->key['id'].'))' ;
        }

        //xtra search fields...NB: $col['id'] INCLUDES table join alias like T2.col_id
        //NB: *** $xtra_id is col['id'] with "." replaced by "_" ***
        foreach($this->search_xtra as $xtra_id => $col) {
            $value = $_POST[$xtra_id];
            $form[$xtra_id] = $value;
            if($col['type'] == 'CUSTOM') {
                $where .= $this->customSearchValue($col['id'],$value);
            } else {  
                if($value != '') {
                    $col_id = $col['id'];
                    //check for xtra system search fields 
                    if(isset($xtra_system[$col_id])) $col_id = $xtra_system[$col_id];
                    
                    if(isset($this->select[$xtra_id])) {
                        if($value != 'ALL') $where .= $col_id.' = "'.$this->db->escapeSql($value).'" AND ';
                    } else {
                        $value_mod = $value;
                        $this->db->parseSearchTerm($value_mod,$sql_parse);
                                                
                        $where .= $col_id.' '.$sql_parse['operator'].' "'.
                                  $sql_parse['prefix'].$this->db->escapeSql($value_mod).$sql_parse['suffix'].'" AND ';
                    }   
                } 
            } 
        }
                        
        if($where != '') {
            $where = substr($where,0,-4).' '; //strip off trailing AND/OR
            $this->addSql('SEARCH',$where);
        } 
 
        
        if($this->calc_aggregate) {
            $sql_agg = '';
            foreach($this->search_aggregate as $n => $agg) $sql_agg .= ', '.$agg['sql'].' AS agg_'.$n.' '; 
            $this->addSql('COUNT',$sql_agg);            
        }  
            
        if($form['order_by'] != '') {
            $sql_order = $this->db->escapeSql($form['order_by']).' ';
            if($form['order_by_desc']) $sql_order .= 'DESC ';
            $this->order_by_current = $form['order_by'];
            $this->addSql('ORDER',$sql_order); 
        }  
       
        $count = $this->count();

        $sql = $this->sqlConstruct('SELECT_LIST');

        $param = [];
        $param['sql'] = $sql;
        $param['sql_count'] = $count['row_count'];
        $param['form'] = $form;
                
        if($count['row_count'] == 0) {
            $this->addMessage('No '.$this->row_name_plural.' found that match your search criteria! Please modify search terms.');
        } else {
            $str = 'Found <b>'.$count['row_count'].'</b> '.$this->row_name_plural.' that match your search criteria!';
            if($this->mode == 'search' and $this->excel_csv) {
                $state_param = $this->linkState();
                $str .= '&nbsp;&nbsp;<a href="?mode=excel&cols=list'.$state_param.'">'.$this->icons['excel'].'Excel/csv</a>'.
                        '&nbsp;<a href="?mode=excel&cols=all'.$state_param.'">All</a>';
            }  
            $this->addMessage($str);
            
            if($this->calc_aggregate) {
                foreach($this->search_aggregate as $n => $agg) {
                    $str = $agg['title'].' : '.number_format($count['agg_'.$n],2);
                    $this->addMessage($str);
                }
            }  
        }    
        
        //search param will be cached until mode=list_all
        $html = $this->viewTable($param);

        return $html; 
    } 
    
    protected function updateRow($id,$form)  
    {
        $html = '';
        $error = '';
        
        if($this->verify_csrf and !$this->verifyCsrfToken($error)) $this->addError($error);
        
        $edit_type = $form['edit_type'];
        if($edit_type !== 'UPDATE' and $edit_type !== 'INSERT') {
           $this->addError('Cannot determine if UPDATE or INSERT!'); 
        }

        if($this->access['copy'] and $edit_type === 'UPDATE' and isset($form['copy_record'])) {
          if($form['copy_record'] === 'YES' and $this->key['key_auto']) {
            $edit_type = 'INSERT';
          } else {
            $this->addError('Cannot copy a record if primary key is not automatic!');
          }    
        }  

        if(!$this->errors_found) {
            if($edit_type === 'INSERT') {
                $output = $this->create($form);
                if($output['status'] === 'OK') $id = $output['id'];
            }
            if($edit_type === 'UPDATE') {
                $output = $this->update($id,$form);
            }
        }    
        
        if($this->errors_found) {
            $html .= $this->viewEdit($id,$form,$edit_type);
        } else {
            if($this->pop_up) $this->setCache('popup_updated',true);
            
            if($this->add_repeat and $edit_type === 'INSERT') {
                $form = array();
                $msg = 'Successfully added '.$this->row_name.'! Please continue capturing '.$this->row_name_plural.
                       ' or <a href="?mode=list">List all</a>';
                $this->addMessage($msg);
                $html .= $this->viewEdit('0',$form,'INSERT');
            } else {  
                $location = $this->location.'?mode=list'.$this->linkState().
                            '&msg='.urlencode('successful '.$edit_type.' of '.$this->row_name).
                            '&page='.$this->page_no.'&row='.$this->row_no.'#'.$this->row_no;
                $location = Secure::clean('header',$location);
                header('location: '.$location);
                exit;
            }  
        }   
        
        return $html;
    }
    
    protected function deleteRow($id,$type = 'SINGLE') 
    {
        $html = '';
        $data = '';
        $error = '';
        
        if($this->verify_csrf and !$this->verifyCsrfToken($error)) $this->addError($error);

        if(!$this->errors_found) $this->delete($id);
                
        //delete any images/files or other data associated with record. 
        //rather leave for manual deletion using afterDelete() event
                    
        if($this->errors_found) {
            if($type === 'SINGLE') {
                $this->mode = 'list';
                $html  = $this->viewTable();
            } elseif($type ==='MULTIPLE') {
                $html = 'ERROR: ['.$this->viewMessages().']';
            }
        } else {
            if($this->pop_up) $this->setCache('popup_updated',true);
            
            if($type === 'SINGLE') {
                $location = $this->location.'?mode=list'.$this->linkState().
                            '&msg='.urlencode('successfully deleted '.$this->row_name).
                            '&page='.$this->page_no.'&row='.$this->row_no.'#'.$this->row_no;
                $location = Secure::clean('header',$location);
                header('location: '.$location);
                exit;
            } elseif($type === 'MULTIPLE') {
                $html = $this->row_name.'['.$id.']';
            }
        }   
        
        return $html;
    } 
    
    protected function updateTable() {
        $error_tmp = '';
        $html = '';
        $action_count = 0;
        $audit_str = '';
                
        $action = Secure::clean('basic',$_POST['table_action']);
        if($action === 'SELECT') {
           $this->addError('You have not selected any action to perform on '.$this->row_name_plural.'!');
        } else {
            if($this->child and $action === 'MOVE') {
                if(!$this->access['edit'] or !$this->access['move']) {
                    $this->addError('You do not have move access');
                } else {
                    $action_id = Secure::clean('basic',$_POST['master_action_id']);
                    //check that action_id is valid
                    $sql = 'SELECT `'.$this->master['key'].'` FROM `'.$this->master['table'].'` WHERE `'.$this->master['key'].'` = "'.$action_id.'" ';
                    $move_id = $this->db->readSqlValue($sql,0);
                    if($move_id !== $action_id) $this->addError('Move action '.str_replace('_',' ',$this->master['key']).'['.$action_id.'] does not exist!');
                    $audit_str .= 'Move '.$this->row_name_plural.' from '.$this->master['table'].' id['.$this->master['key_val'].'] to id['.$action_id.'] :';    
                }    
            }
            if($action === 'EMAIL') {
                if(!$this->access['email']) {
                    $this->addError('You do not have email access');
                } else {
                    $action_email = $_POST['action_email'];
                    Validate::email('Action email',$action_email,$error_tmp);
                    if($error_tmp != '') $this->addError('Invalid action email!');
                    $audit_str .= 'Email '.$this->table.' '.$this->row_name_plural.' to '.$action_email.' :';
                }    
            }
            if($action === 'DELETE') {
                if(!$this->access['delete']){
                    $this->addError('You do not have delete access');
                } else {    
                    $audit_str .= 'Delete '.$this->table.' '.$this->row_name_plural.' :';
                }
            } 

            if($this->table_edit_all and $action === 'UPDATE') {
                if(!$this->access['edit']){
                    $this->addError('You do not have edit access');
                } else {
                    $audit_str .= 'Update '.$this->table.' multiple '.$this->row_name_plural.' :';
                }    
            }  
        }
        
            
        if(!$this->errors_found) {
            $email_table = [];
            foreach($_POST as $key => $value) {
                if(substr($key,0,8) === 'checked_') {
                    $action_count++;
                    $key_id = Secure::clean('basic',substr($key,8));
                    $record = $this->view($key_id);
                    if($record == 0) {
                        $this->addError($this->row_name.' ID['.$key_id.'] no longer exists!');
                    } else {
                        $label = '<strong>'.$record[$this->col_label].'</strong>';
                        $audit_str .= $this->row_name.' ID['.$key_id.'] ';
                        
                        if($action === 'DELETE') {
                            $response = $this->delete($key_id);
                            if($response['status'] === 'OK') {
                                $this->addMessage('Successfully deleted '.$this->row_name.' ID['.$key_id.'] '.$label);
                            }  
                        } 

                        if($this->child and $action === 'MOVE') {
                            $sql = 'UPDATE `'.$this->table.'` SET `'.$this->master['child_col'].'` = "'.$move_id.'" '.
                                   'WHERE `'.$this->key['id'].'` = "'.$this->db->escapeSql($key_id).'" ';
                            $this->db->executeSql($sql,$error_tmp);
                            if($error_tmp == '') {
                                $this->addMessage('Successfully moved '.$this->row_name.' ID['.$key_id.'] '.$label);
                            } else {
                                $this->addError('Could not MOVE '.$this->row_name.' ID['.$key_id.'] '.$label);
                            }  
                        }  

                        if($this->table_edit_all and $action === 'UPDATE') {
                            $input = [];
                            foreach($this->cols as $col) {
                                if($col['list'] and $col['edit']) {
                                    $col_id = $col['id'];
                                    $input_id = $key_id.':'.$col_id;
                                    $input[$col_id] = $_POST[$input_id];
                                }
                            }
                            
                            $this->update($key_id,$input); 
                            if($this->errors_found) {
                                $this->addMessage($this->row_name.': '.$label.' NOT updated and reset to original value.<br/>'.implode('<br/>',$this->errors));
                                $this->clearErrors();
                            } else {
                                $this->addMessage($this->row_name.': '.$label.' updated successfully.');
                            }
                        }   

                        if($action === 'STATUS_CHANGE') {
                            $sql = 'UPDATE `'.$this->table.'` SET `status` = "'.$this->db->escapeSql($status_change).'" '.
                                   'WHERE `'.$this->key['id'].'` = "'.$this->db->escapeSql($key_id).'" ';
                            $this->db->executeSql($sql,$error_tmp);
                            if($error_tmp === '') {
                                $audit_str .= ' success!';
                                $audit_count++;
                        
                                $this->addMessage('Status set['.$status_change.'] for '.$this->row_name.' ID['.$key_id.'] '.$label);                
                            } else {
                                $this->addError('Could not update status for '.$this->row_name.' ID['.$key_id.'] '.$label.': '.$error_tmp);                
                            }  
                        }
                    
                        if($action === 'EMAIL') $email_table[] = $this->view($key_id);
                    }
                }   
            }
        } 
        
        if($action_count == 0) $this->addError('NO '.$this->row_name_plural.' selected for action!');
                        
        if(!$this->errors_found and $action === 'EMAIL') {
            $param = ['format'=>'html'];
            $from = ''; //default will be used
            $to = $action_email;
            $subject = SITE_NAME.' '.$this->row_name_plural;
            $body = '<h1>Please see '.$this->row_name.' data below:</h1>'.
                    Html::arrayDumpHtml($email_table);

            $mailer = $this->getContainer('mail');
            if($mailer->sendEmail($from,$to,$subject,$body,$error_tmp,$param)) {
                $this->addMessage('SUCCESS sending data to['.$to.']'); 
            } else {
                $this->addError('FAILURE emailing data to['.$to.']:'.$error_tmp); 
            }
        }  
        
        if(!$this->errors_found) {
            $this->afterUpdateTable($action); 

            $audit_action = $action.'_'.strtoupper($this->table);   
            Audit::action($this->db,$this->user_id,$audit_action,$audit_str);
        }  
        
        if(!$this->errors_found and $this->pop_up) $this->setCache('popup_updated',true);
        
        $this->mode = 'list';
        $html .= $this->viewTable();
            
        return $html;
    }

    /*** PLACEHOLDERS ***/
   
    protected function beforeProcess($id = 0) {}
    protected function processCustom($id) {}
    protected function afterUpdateTable($action) {}
    
}
