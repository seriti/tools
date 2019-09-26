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
use Seriti\Tools\BASE_URL;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\SecurityHelpers;
use Seriti\Tools\TableStructures;

use Psr\Container\ContainerInterface;

class Listing extends Model 
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
    
    protected $list_action = false;//where multiple rows can be acted on
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
    
    
    protected $col_count = 0;
    //additional classes from standard admin IconsClassesLinks;
    protected $list_classes = ['row'=>'row list_items_row',
                               'image'=>'list_items_image',
                               'title'=>'list_title',
                               'width'=>'list_width',
                               'select'=>'form-control input-small input-inline'];
    protected $image_pos = 'LEFT';
    protected $show_header = false;
    protected $div_id = 'list_items_div';
    protected $format = 'STANDARD';
    protected $no_image_src = BASE_URL.'images/no_image.png';
    protected $action_mode = 'AJAX';
    protected $action_route = '';
    protected $action_button_text = 'Add to cart';
    protected $col_options = '';

    //select lists for action col
    protected  $action_select = [];

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
        //turns off any data modification functionality in Model Class
        $this->access['read_only'] = true;

        //Implemented in Model Class
        if(isset($param['distinct'])) $this->distinct = $param['distinct'];
        if(isset($param['encrypt_key'])) $this->encrypt_key = $param['encrypt_key'];
        
        $this->dates['new'] = date('Y-m-d');
        if(isset($param['location'])) $this->location = $param['location']; //Could use URL_CLEAN_LAST
                     
        if(isset($param['row_name'])) $this->row_name = $param['row_name'];
        if(isset($param['row_name_plural'])) {
            $this->row_name_plural = $param['row_name_plural'];
        } else {
            $this->row_name_plural = $this->row_name.'s';
        }    
        if(isset($param['max_rows'])) $this->max_rows = $param['max_rows'];
        if(isset($param['nav_show'])) $this->nav_show = $param['nav_show'];
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];
        if(isset($param['action_header'])) $this->action_header = $param['action_header'];
        if(isset($param['show_info'])) $this->show_info = $param['show_info'];
        if(isset($param['pop_up'])) {
            $this->pop_up = $param['pop_up'];
            if(isset($param['update_calling_page'])) $this->update_calling_page = $param['update_calling_page'];
        }  

        //******************* new stuff

        //need to manually set user csrf token as wizard can used outside login env 
        if(isset($param['csrf_token'])) $this->user_csrf_token = $param['csrf_token'];

        if(isset($param['order_by'])) $this->order_by_current = $param['order_by'];
        if(isset($param['image_pos'])) $this->image_pos = $param['image_pos'];
        if(isset($param['show_header'])) $this->show_header = $param['show_header'];
        if(isset($param['format'])) $this->format = $param['format'];
        if(isset($param['no_image_src'])) $this->no_image_src = $param['no_image_src'];
        if(isset($param['col_options'])) $this->col_options = $param['col_options'];

        if(isset($param['no_image_src'])) $this->no_image_src = $param['no_image_src'];
        if(isset($param['action_mode'])) $this->action_mode = $param['action_mode'];
        if(isset($param['action_route'])) $this->action_route = $param['action_route'];
        if(isset($param['action_button_text'])) $this->action_button_text = $param['action_button_text'];
    }
           
    public function processList() 
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
            $this->master['key_val']=0;
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
        
        if($this->mode === 'list')   $html .= $this->viewList($param);
        if($this->mode === 'view')   $html .= $this->viewListItem($id);
        
        if($this->mode === 'search' or $this->mode === 'index') $html .= $this->search();
        
        if($this->mode === 'custom') $html .= $this->processCustom($id);
        
                        
        return $html;
    } 
    
    public function addAllListCols() 
    {
        $this->addAllCols();
        foreach($this->cols as $col) {
           $this->cols[$col['id']] = $this->setupListCol($col); 
        }
    }

    protected function setupListCol($col) 
    {
        //table display parameters
        if(!isset($col['edit_title'])) $col['edit_title'] = $col['title'];
        if(!isset($col['class'])) $col['class'] = '';
        if(!isset($col['class_search'])) $col['class_search'] = $col['class'];
        if(!isset($col['new'])) $col['new'] = '';
        if(!isset($col['hint'])) $col['hint'] = '';
        if(!isset($col['prefix'])) $col['prefix'] = '';
        if(!isset($col['suffix'])) $col['suffix'] = '';
        //used when searching on a tree hierarchy, must contain SQL alias for JOINed table
        if(!isset($col['tree'])) $col['tree'] = '';

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
         
        if(!isset($col['sort'])) $col['sort'] = '';

         //assign list cols to order by list 
        if($col['list'] === true and !isset($col['join'])) {
            $this->addSortOrder($col['id'],$col['edit_title']);
        }  
         
        //assign column value to use in row level warnings and messages if not already set
        if($this->col_label === '') $this->col_label = $col['id'];
        
        return $col;
    }

    protected function setupListImages($param = []) 
    {
        $this->col_count++;

        $this->setupImages($param);
    }

    protected function setupListFiles($param = []) 
    {
        $this->col_count++;
        
        $this->setupFiles($param);
    }

    public function addListCol($col) 
    {
        $col = $this->addCol($col);
       
        $col = $this->setupListCol($col); 

        if($col['list']) $this->col_count++;

        $this->cols[$col['id']]=$col;
    }
    
    
    public function addListAction($action_id,$action = []) 
    {
        $action_valid = true;

        if(!isset($action['pos'])) $action['pos'] = 'L';
        if(!isset($action['icon'])) $action['icon'] = false;
        if(!isset($action['class'])) $action['class'] = '';    
        if(!isset($action['mode'])) $action['mode'] = 'ajax';
        if(!isset($action['verify'])) $action['verify'] = false;
        if(!isset($action['spacer'])) $action['spacer'] = '</br>';
        if(!isset($action['text'])) $action['text'] = '';
        if(!isset($action['value'])) $action['value'] = ''; 
        if(!isset($action['param'])) $action['param'] = []; 
                 
        if($action['type'] == 'popup') {
            if(!isset($action['width'])) $action['width'] = 600;
            if(!isset($action['height'])) $action['height'] = 400;
        } 
        //this action allows multiple rows in table to be selected and an action performed on all
        if($action['type'] == 'check_box') {
            if($this->access['read_only'] or !$this->access['edit']) {
                $action_valid = false;
            } else {  
                $this->list_action = true; 
            }  
        } 
        
        if($action['type'] == 'select') {
            //can have 'sql' statement or 'list' array
            if(isset($action['list'])) {
                if(!isset($action['list_assoc'])) $action['list_assoc'] = false;
            } 
        }    
                        
        if($action_valid) {
            if($action['pos'] == 'L' and !$this->action_col_left) {
                $this->action_col_left = true;
                $this->col_count++;
            }    
            if($action['pos'] == 'R' and !$this->action_col_right) {
                $this->action_col_right = true;
                $this->col_count++;
            }    
            
            //NB: "." is not allowed in variable names or array keys by PHP
            $action_id = str_replace('.','_',$action_id);
            $this->actions[$action_id] = $action;
        } 
    }

    
    
    public function addSearch($cols,$param = []) 
    {
        if($this->access['search']) {
            $this->search = array_merge($this->search,$cols);
            if(isset($param['rows'])) {
                $this->search_rows = $param['rows'];
            } else {
                if($this->search_rows == 0) {
                    $this->search_rows = ceil(count($this->search)/3);
                }  
            }  
            $this->show_search = true;
        }  
    }
    
    //NB: this public function can sometimes be replicated by using 'xtra'=>'X.col_id' in standard col definition
    public function addSearchXtra($col_id,$col_title,$param = []) 
    {
        if($this->access['search']) { 
            //NB: col_id must have join table alias... ie TX.col_id 
            //*** "." is not allowed in variable names or array keys by PHP ***
            $xtra_id=str_replace('.','_',$col_id);
            $arr['id']=$col_id;
            $arr['title']=$col_title;
            if(isset($param['type'])) $arr['type']=$param['type']; else $arr['type']='STRING';
            
            if(isset($param['class'])) $arr['class']=$param['class']; else $arr['class']='';
            //if(isset($param['class'])) $arr['class_search']=$param['class']; else $arr['class_search']='';
            
            $this->search_xtra[$xtra_id]=$arr;
           // if(isset($param['join'])) $this->add_sql('JOIN',$param['join']);
        } 
    }
        
    public function addSortOrder($order_by,$description,$type = '') {
         if($type=='DEFAULT' or $this->order_by_current=='') $this->order_by_current=$order_by;
         $this->order_by[$order_by]=$description;
    } 
    
    //processed by search()
    public function viewSearchIndex($type,$col_id,$form = [],$param = [])
    {
        $html = '';

        if(!isset($param['select_submit'])) $param['select_submit'] = true;
        if(!isset($param['select_all'])) $param['select_all'] = true;
        if(!isset($param['class'])) $param['class'] = 'input-inline';

        if(isset($form[$col_id])) $value = $form[$col_id]; else $value = '';

        $html .= '<span id="index_div">';
        
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=index" name="index_form" id="index_form" class="'.$param['class'].'">';
        $html .= $this->formState();        

        //NB: $col_id needs to have been setup using addSelect()
        if($type === 'SELECT' and isset($this->select[$col_id])) {
            $select_param = [];
            if($param['select_all']) $select_param['xtra'] = 'ALL';
            if($param['select_submit']) $select_param['onchange'] = 'this.form.submit()';

            if(isset($this->select[$col_id]['sql'])) {
                $html .= Form::sqlList($this->select[$col_id]['sql'],$this->db,$col_id,$value,$select_param);
            } elseif(isset($this->select[$col_id]['list'])) { 
                $html .= Form::arrayList($this->select[$col_id]['list'],$col_id,$value,$this->select[$col_id]['list_assoc'],$select_param);
            }  
        
        }

        $html .= '</form></span>';

        return $html;
    }
    
    public function viewList($param=array()) 
    {
        $sql   = '';
        $where = '';
        $html  = '';
        $header = '';
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
                /* legacy from Table class
                if($this->row_count == 0) {
                  $initialise = true;
                  unset($param['sql']);
                } 
                */ 
            }  
        } 
        
        if($initialise) {
            $count = $this->count();
            $this->row_count = $count['row_count'];

            if($this->order_by_current!='') $this->addSql('ORDER',$this->order_by_current);   
        } 
        
        //redirect to add a new row if none exist
        if($this->row_count == 0 and $this->mode == 'list') {
            $this->addMessage('No '.$this->row_name.' data exists to list!'); 
        } 

        //pagination mods to final sql statement
        $sql_limit = (($this->page_no-1)*$this->max_rows).', '.$this->max_rows.' ';
        $this->addSql('LIMIT',$sql_limit);
        
        //get all matching data
        $list = $this->list($param);

        //FINALLY Generate HTML output
        $html = '';

        if($this->show_info) $info = $this->viewInfo('LIST'); else $info = '';
        
        $nav = $this->viewNavigation('TABLE'); 
                       

        if($this->show_header) $header = $this->viewHeader('','TOP');

        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;//'<p align="center">'.$nav.'</p>';
        
        if($this->search_rows != 0) $html .= $this->viewSearch($form);
       
        $html .= '<div id="'.$this->div_id.'">'; 
        $html .= $header;
        
        if($this->row_count) {
            $row_no = 0;
            foreach($list as $row) {
                $row_no++;
                $html .= $this->viewListRow($row,$row_no);
                //store as previous row
                $this->data_prev = $row;
            }
        } 
                        
        $html .= '</div>';
        
        if(strpos($this->nav_show,'BOTTOM' !== false and $this->row_count > 10)) $html .= $nav;
        
        $html = $this->viewMessages().$html;

        $html .= $this->getJavascript('list_action');

        return $html;
    }

    protected function getJavascript($type)
    {
        //var elem = document.getElementById(form_id).elements;
        $js = "\r\n<script type='text/javascript'>\r\n";
        
        if(isset($this->user_csrf_token)) {
            $param_init = "csrf_token='+encodeURIComponent('".$this->user_csrf_token."')+'&'";
        } else {
            $param_init = '';
        }    

        if($type === 'list_action') {
            

            $js .= "function process_list_action(form,response_id) {
                        var param = '".$param_init."';
                        var elem = form.elements;
                        for(i = 0; i < elem.length; i++) {
                            param += elem[i].name+'='+encodeURIComponent(elem[i].value);
                            if(i < (elem.length-1)) param += '&';
                        } 

                        //alert('WTF:'+param+' route:'+'".$this->action_route."');
                        //return false;

                        xhr('".$this->action_route."',param,list_action_callback,response_id);

                        //to prevent form submission
                        return false; 
                    }\r\n";

            $js .= "function list_action_callback(response,response_id) {
                        alert(response);
                    }";        
        }        

        
        $js .= "</script>";

        return $js;

    }

    //convert options text into array list assuming line format "key:opt1,opt2,opt3..."
    protected function parseOptions($text) 
    {
        $options = [];

        if($text !== '') {
            $lines = explode("\r",$text); 
            foreach($lines as $line) {
                $param = explode(':',trim($line)); 
                if(count($param)>1) {
                    $key = trim($param[0]);
                    $values = explode(',',$param[1]);
                    $options[$key] = $values; 
                }
            }
        }

        return $options;
    }

    protected function viewListActions($data,$row_no,$pos = 'L') 
    {
        $html = '';
        $state_param = $this->linkState();
        $hidden = [];

        if(count($this->actions) != 0) {
            $form_id = 'action_'.$data[$this->key['id']];
            $hidden[$this->key['id']] = $data[$this->key['id']];

            //check for item specific options
            $item_options = false;
            if($this->col_options !== '' and isset($data[$this->col_options])) {
                $options = $this->parseOptions($data[$this->col_options]);
                if(count($options)) $item_options = true;
            }


            $html .= '<form method="post" action="?mode=update_table" id="'.$form_id.'" '.
                       'onSubmit="return process_list_action(this,\''.$form_id.'\')">';

            $html .= Form::hiddenInput($hidden);

            foreach($this->actions as $action_id => $action) {
                $valid = true;
                if($action['verify']) $valid = $this->verifyRowAction($action,$data);
                
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
                    } elseif($action['type'] === 'select'){
                        $select_param = $action['param'];

                        if(!isset($select_param['class'])) $select_param['class'] = $this->list_classes['select'];
                        
                        if(isset($action['sql'])) {
                            $html .= $show.Form::sqlList($action['sql'],$this->db,$action_id,$action['value'],$select_param);
                        } elseif(isset($action['list'])) { 
                            //check for custom item options
                            if($item_options and isset($options[$action_id])) {
                                $action['list'] = $options[$action_id];
                                $action['list_assoc'] = false;
                            }

                            if(count($action['list'])) {
                                $html .= $show.Form::arrayList($action['list'],$action_id,$action['value'],$action['list_assoc'],$select_param);
                            }    
                        } 
                    } else {    
                        $onclick = '';
                        if($action['type'] == 'delete') {
                            $item = $this->row_name.'['.$data[$this->col_label].']';
                            $onclick = 'onclick="javascript:return confirm(\'Are you sure you want to DELETE '.$item.'?\')" '; 
                        }
                        $href = '?mode='.$action['mode'].'&page='.$this->page_no.'&row='.$row_no.'&id='.$data[$this->key['id']].$state_param;  
                        $html .= '<a class="action" href="'.$href.'" '.$onclick.'>'.$show.'</a>';  
                    } 
                    
                    if($action['class'] != '') $html .= '</span>';
                    //space between actions, if &nbsp; then no auto breaks
                    $html .= $action['spacer'];       
                } 
            }

            $html .= '<input type="submit" name="submit" value="'.$this->action_button_text.'" class="'.$this->classes['button'].'">';
            $html .= '</form>';
        } 
        
        return $html;
    }
    
    protected function viewHeader($tag,$position='TOP') 
    {
        $html = '';
        
        if($this->format === 'STANDARD') {
            //bootstrap responsive cols
            $size = floor(12 / $this->col_count);
            $col_class = 'class="col-sm-'.$size.'"';


            $html .= '<div class="'.$this->list_classes['row'].'" '.$tag.'>';

            if($this->action_col_left) $html .= '<div '.$col_class.'>'.$this->action_header.'</div>';

            if($this->image_upload) $html .= '<div '.$col_class.'>'.$this->images['title'].'</div>';

            if($this->file_upload) $html .= '<div '.$col_class.'>'.$this->files['title'].'</div>';

            foreach($this->cols as $col) {
                if($col['list']) $html .= '<div '.$col_class.'>'.$col['title'].'</div>'; 
            }    

            if($this->action_col_right) $html .= '<div '.$col_class.'>'.$this->action_header.'</div>';              
                             
            $html .= '</div>';

        }

        if($this->format === 'MERGE_COLS') {
            $col_count = 1; 
            if($this->action_col_left) $col_count++;
            if($this->image_upload) $col_count++;
            if($this->file_upload) $col_count++;
            if($this->action_col_right) $col_count++;

            $size = floor(12 / $col_count);
            $col_class = 'class="col-sm-'.$size.'"';

            $html .= '<div class="'.$this->list_classes['row'].'" '.$tag.'>';

            if($this->action_col_left) $html .= '<div '.$col_class.'>'.$this->action_header.'</div>';

            if($this->image_upload) $html .= '<div '.$col_class.'>'.$this->images['title'].'</div>';

            if($this->file_upload) $html .= '<div '.$col_class.'>'.$this->files['title'].'</div>';

            $html .= '<div '.$col_class.'>'.$this->row_name.' description</div>'; 
            
            if($this->action_col_right) $html .= '<div '.$col_class.'>'.$this->action_header.'</div>';              
                             
            $html .= '</div>';
        }
          
        return $html; 
    }  
     
    protected function formatItemValue($value,$col)  
    {
        switch($col['type']) {
            case 'DATE' : {
                $value = Date::formatDate($value,'MYSQL',$col['format']);
                break;
            } 
            case 'DATETIME' : {
                $value = Date::formatDateTime($value,'MYSQL',$col['format']);
                break;
            }
            case 'TIME' : {
                $value = Date::formatTime($value,'MYSQL',$col['format']);
                break;
            } 
            case 'EMAIL' : {
                $value = Secure::clean('email',$value);
                $value = '<a href="mailto:'.$value.'">'.$value.'</a>'; 
                break;
            }
            case 'URL' : {
                $value = Secure::clean('url',$value);
                if(strpos($value,'//') === false) $http = 'http://'; else $http = '';
                $value = '<a href="'.$http.$value.'" target="_blank">'.$value.'</a>';
                break;
            } 
            case 'BOOLEAN' : {
                if($value == 1) $value = $this->icons['true']; else $value = $this->icons['false'];
                break;
            } 
            case 'PASSWORD' : {
                $value = '****';
                break;
            } 
            case 'STRING' : {
                if($col['secure']) $value=Secure::clean('string',$value);
                break;
            } 
            case 'TEXT' : {
                if($col['secure']) {
                    if($col['html']) {
                        if($col['encode']) $value = Secure::clean('html',$value);
                    } else {
                        $value = Secure::clean('text',$value);
                        $value = nl2br($value);
                    }
                } else {
                    if(!$col['html']) $value = nl2br($value);
                }   
                break;
            }  
                                  
            default : $value = Secure::clean('string',$value);
        }

        if($col['prefix'] !== '') $value = $col['prefix'].$value;
        if($col['suffix'] !== '') $value = $value.$col['suffix'];

        if($col['class'] !== '') {
           $value = '<span class="'.$col['class'].'">'.$value.'</span>';
        }


        
        return $value;        
    }

    protected function viewListRow($data,$row_no)  
    {
        $html = '';
        $tag = '';
        $actions_left = '';
        $actions_right = '';
        $images = '';
        $files = '';
        $items = [];

        //get standard formatted row content
        if($this->row_tag) $tag = 'id="'.$row_no.'"';
        if($this->action_col_left) $actions_left = $this->viewListActions($data,$row_no,'L');
        if($this->action_col_right) $actions_right = $this->viewListActions($data,$row_no,'R');
        if($this->image_upload) $images = $this->viewListImages($data);
        if($this->file_upload) $files = $this->viewFiles($data);

        foreach($this->cols as $key => $col) {
            $item = [];
            $item['id'] = $col['id'];
            $item['value'] = $data[$col['id']];
            $item['formatted'] = $this->formatItemValue($item['value'],$col);
            $item['list'] = $col['list'];

            $items[] = $item;
        }


        $html .= $this->viewRowFormatted($row_no,$tag,$actions_left,$actions_right,$images,$files,$items);

        return $html;
    } 
    


    protected function viewRowFormatted($row_no,$tag,$actions_left,$actions_right,$images,$files,$items = [])
    {
        $html = '';

        if($this->format === 'STANDARD') {
            $image_left = false;
            $image_right = false;
            if($this->image_upload) {
                if($this->image_pos === 'ALTERNATE') {
                    if(fmod($row_no,2) == 1) $image_left = true; else $image_right = true;
                } elseif($this->image_pos === 'RIGHT') {
                    $image_right = true;
                } else {
                    $image_left = true;
                }
            }

            //bootstrap responsive cols
            $size = floor(12 / $this->col_count);
            $col_class = 'class="col-sm-'.$size.'"';


            $html .= '<div class="'.$this->list_classes['row'].'" '.$tag.'>';

            if($this->action_col_left) $html .= '<div '.$col_class.'>'.$actions_left.'</div>';


            if($image_left) $html .= '<div '.$col_class.'>'.$images.'</div>';

            if($this->file_upload) $html .= '<div '.$col_class.'>'.$files.'</div>';

            foreach($items as $item) {
                if($item['list']) $html .= '<div '.$col_class.'>'.$item['formatted'].'</div>'; 
            }    

            if($image_right) $html .= '<div '.$col_class.'>'.$images.'</div>';

            if($this->action_col_right) $html .= '<div '.$col_class.'>'.$actions_right.'</div>';              
                             
            $html .= '</div>';

        }  

        if($this->format === 'MERGE_COLS') {
            $merged = '';
            $merge_count = 0;

            if($this->image_upload) {
                $merge_count++;
                $merged .= $images; 
            }

            foreach($items as $item) {
                if($item['list']) {
                    $merge_count++;
                    $merged .= $item['formatted'].'<br/>';
                }    
            } 

            if($this->file_upload) {
                $merge_count++;
                $merged .= $files; 
            }  

            $col_count = $this->col_count - $merge_count + 1;

            $size = floor(12 / $col_count);
            $col_class = 'class="col-sm-'.$size.'"';

            $html .= '<div class="'.$this->list_classes['row'].'" '.$tag.'>';

            if($this->action_col_left) $html .= '<div '.$col_class.'>'.$actions_left.'</div>';

            $html .= '<div '.$col_class.'>'.$merged.'</div>'; 
            
            if($this->action_col_right) $html .= '<div '.$col_class.'>'.$actions_right.'</div>';              
                             
            $html .= '</div>';
        }      

        return $html;
    }

    protected function viewListImages($data) 
    {
        $html = '';
        $html = '';
        $error_tmp = '';

        $location_id = $this->images['location'].$this->db->escapeSql($data[$this->key['id']]);
        if(isset($this->images['icon'])) $show = $this->images['icon']; else $show = $this->icons['images'];
        
        $image_count = 0;
        if($this->images['list']) {
            $sql = 'SELECT '.$this->file_cols['file_id'].' AS file_id,'.$this->file_cols['file_name_orig'].' AS name, '.
                             $this->file_cols['file_name'].' AS file_name , '.$this->file_cols['file_name_tn'].' AS file_name_tn  '.
                   'FROM '.$this->images['table'].' '.
                   'WHERE '.$this->file_cols['location_id'].' = "'.$location_id.'" '.
                   'ORDER BY '.$this->file_cols['location_rank'].','.$this->file_cols['file_date'].' DESC LIMIT '.$this->images['list_no'];
            $images = $this->db->readSqlArray($sql);
            if($images !== 0) {
                
                foreach($images as $file_id => $image) {
                    $image_count++;
                    
                    if($this->images['list_thumbnail']) $file_name = $image['file_name_tn']; else $file_name = $image['file_name'];

                    if($this->images['storage'] === 'amazon') {
                        $url = $this->images['s3']->getS3Url($file_name);
                        if($this->images['https'] and strpos($url,'https') === false) $url = str_replace('http','https',$url);
                    } else {
                        if($this->images['path_public']) {
                            $url = BASE_URL.$this->images['path'].$file_name;
                        } else {
                            //this will return image as encoded string when stored outside public access
                            $path = $this->images['path'].$file_name;
                            $url = Image::getImage('SRC',$path,$error);
                            if($error != '') $this->addError('Thumbnail error: '.$error);
                        }    
                    } 

                    //$images[$file_id]['url'] = $url;  
                    
                    $html .= '<img class="'.$this->list_classes['image'].'" src="'.$url.'" title="'.$image['name'].'" align="left">';
                
                }
            } else {
                $html .= '<img class="'.$this->list_classes['image'].'" src="'.$this->no_image_src.'" title="No image available" align="left">';
            } 
        } 
        
        //wrap in a scroll box)
        //if($image_count > 10) $style = 'style="overflow: auto; height:200px;"'; else $style = '';
        //$html = '<div '.$style.'>'.$html.'</div>';
        
        return $html;
    }

    //form processing functions
    protected function search() 
    {
        $error = '';
        $where = '';
        
        if($this->mode === 'index') {
            $form['order_by'] = $this->order_by_current; 
            $form['order_by_desc'] =  false;
        } else  {
            $form['order_by'] = Secure::clean('basic',$_POST['order_by']);
            if(isset($_POST['order_by_desc']) and substr($form['order_by'],-4) != 'DESC') {
                $form['order_by_desc'] = true; 
            } else {
                $form['order_by_desc'] = false;
            }
        }     
        
        foreach($this->search as $col_id) {
            $col = $this->cols[$col_id];
            if(isset($col['xtra'])) $col_str = $col['xtra']; else $col_str = 'T.'.$col_id;
            
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
                
                //strip $value_mod of search specific terms like <>+* and set sql_parse operators
                $this->db->parseSearchTerm($value_mod,$sql_parse);
                
                //NB: validate routines also modify $value_mod sometimes(like stripping out thousand separators)
                $this->validate($col_id,$value_mod,'SEARCH');
                                    
                if($value_mod != '') {
                    $value_mod = $this->db->escapeSql($value_mod);

                    if(isset($this->select[$col_id])) {
                        if($value_mod != 'ALL') {
                            if($col['tree'] != '') {
                                //$col['tree'] must be SQL alias for JOINed tree table
                                $csv_col = $col['tree'].'.'.$this->tree_cols['lineage'];
                                $where .= '('.$col_str.' = "'.$value_mod.'" OR FIND_IN_SET("'.$value_mod.'",'.$csv_col.') > 0) AND ';
                            } else {
                                $where .= $col_str.' = "'.$value_mod.'" AND ';
                            }    
                        }     
                    } else {
                        if($value_mod == 'null') {
                            if($sql_parse['operator'] == '') $sql_parse['operator'] = '=';
                            $where .= $col_str.' '.$sql_parse['operator'].' "" AND ';
                        } else {  
                            $where .= $col_str.' '.$sql_parse['operator'].' "'.
                                      $sql_parse['prefix'].$value_mod.$sql_parse['suffix'].'" AND ';
                        }  
                    }   
                }
            
            
            }

            $form[$col_id] = $value;  
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
                    if(isset($this->select[$xtra_id])) {
                        if($value != 'ALL') $where .= $col['id'].' = "'.$this->db->escapeSql($value).'" AND ';
                    } else {
                        $value_mod = $value;
                        $this->db->parseSearchTerm($value_mod,$sql_parse);
                        
                        $where .= $col['id'].' '.$sql_parse['operator'].' "'.
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
                    $str = $agg['title'].' : '.$count['agg_'.$n];
                    $this->addMessage($str);
                }
            }  
        }    
        
        //search param will be cached until mode=list_all
        $html = $this->viewList($param);

        return $html; 
    } 
         
       

    /*** PLACEHOLDERS ***/
   
    protected function beforeProcess($id = 0) {}
    protected function processCustom($id) {}
    protected function afterUpdateTable($action) {}

    
}
?>
