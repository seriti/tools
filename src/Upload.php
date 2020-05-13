<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Mysql;
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

use Seriti\Tools\STORAGE;
use Seriti\Tools\BASE_URL;
use Seriti\Tools\URL_CLEAN;
use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_DOCS;

use Psr\Container\ContainerInterface;

class Upload extends Model 
{
    
    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use SecurityHelpers;
    use TableStructures;

    protected $container;
    protected $container_allow = ['s3','mail','user','system','logger'];

    protected $col_label = '';
    protected $dates = array('from_days'=>30,'to_days'=>1,'zero'=>'1900-01-01');
    protected $mode = 'list_all';
    protected $row_name = 'file';
    protected $row_name_plural = 'files';
    protected $row_tag = true;
    
    protected $allow_ext = array('Documents'=>array('doc','xls','ppt','pdf','rtf','docx','xlsx','pptx','ods','odt','txt','csv','zip','gz','msg','eml'),
                                 'Images'=>array('jpg','jpeg','bmp','gif','tif','tiff','png','pnt','pict','pct','pcd','pbm'),
                                 'Audiovisual'=>array('mp3','m3u','mp4','m4v','m4a','mpg','mpeg','mpeg4','wav','swf','wmv','mov','ogg','ogv','webm','avi','3gp','3g2')); 
    protected $encrypt_ext = array('doc','xls','ppt','pdf','rtf','docx','xlsx','pptx','ods','odt','txt','csv','zip','gz','msg','eml'); 
    protected $image_resize_ext = array('jpg','jpeg','png','gif');
    protected $inline_ext = array('pdf'); //default to inline donwload option rather than force as file download 
  
    protected $image_resize = array('original'=>false,'thumb_nail'=>true,'crop'=>true,
                                    'width'=>600,'height'=>400, //original resize settings
                                    'width_thumb'=>60,'height_thumb'=>40);//thumbnail resize settings 

    protected $image_thumbnail = array('list_view'=>true,'edit_view'=>true,
                                       'list_width'=>60,'list_height'=>0,'edit_width'=>0,'edit_height'=>0); //NB:0 value is not set                            
  
    protected $upload = array('interface'=>'plupload','interface_change'=>true,'jquery_inline'=>false,'url_ajax'=>BASE_URL.URL_CLEAN,
                              'path_base'=>BASE_UPLOAD,'path'=>UPLOAD_DOCS,'max_size'=>200000000,'prefix'=>'','location'=>'ALL',
                              'encrypt'=>false,'max_size_encrypt'=>10000000,'text_extract'=>false,'rank_interval'=>10);
  
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
    protected $add_href = ''; //change add nav link to some external page or wizard
        
    protected $table_action = false;
    protected $upload_location_only=true;//can only download/delete files from defined $upload['location'] string

    protected $storage = STORAGE;   //set to 'amazon' to use AWS S3
    protected $storage_backup = ''; //set to 'local' if want keep local copy after uploading to non-local storage
    protected $storage_download_local = true; //for non-local storage, set to false to delete local file after download ,or true if want to keep local copy
    
    protected $excel_csv = true;
    protected $actions = array();
    protected $location = '';
    protected $search = array();
    protected $search_xtra = array();
    protected $search_rows = 0;
    protected $show_search = false;
    protected $order_by = array(); 
    protected $order_by_current = '';
    protected $action_col_left = false;
    protected $action_col_right = false;
    protected $data = array(); //use to store current edit/view data between function calls 
    protected $data_xtra = array(); //use to store arbitrary xtra data between function calls 
    
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
        if(isset($param['encrypt_key'])) $this->encrypt_key = $param['encrypt_key'];
        
        //*** standard Table class parameters ***
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
        if(isset($param['excel_csv'])) $this->excel_csv = $param['excel_csv'];
        
        //*** specific Upload class parameters ***

        if(isset($param['url_ajax'])) $this->upload['url_ajax'] = $param['url_ajax'];
        if(isset($param['interface'])) $this->upload['interface'] = $param['interface'];
        if(isset($param['encrypt'])) $this->upload['encrypt'] = $param['encrypt'];
        if(isset($param['prefix'])) $this->upload['prefix'] = $param['prefix'];
        if(isset($param['upload_location'])) $this->upload['location'] = $param['upload_location'];
        if(isset($param['upload_path_base'])) $this->upload['path_base'] = $param['upload_path_base'];
        if(isset($param['upload_path'])) $this->upload['path'] = $param['upload_path'];
        if(isset($param['upload_rank_interval'])) $this->upload['rank_interval'] = $param['upload_rank_interval'];

        if(isset($param['storage'])) $this->storage = $param['storage'];
        if(isset($param['storage_backup'])) $this->storage_backup = $param['storage_backup'];
        if(isset($param['storage_download_local'])) $this->storage_download_local = $param['storage_download_local'];

        if($this->col_label === '') $this->col_label = $this->file_cols['file_name_orig'];

        $list_encrypt = $this->upload['encrypt'];

        //add all standard file cols which MUST exist, 
        $this->addFileCol(['id'=>$this->file_cols['file_id'],'title'=>'File','type'=>'INTEGER','key'=>true,'key_auto'=>false,'list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['file_name'],'title'=>'System file name','type'=>'STRING','list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['file_name_tn'],'title'=>'System thumbnail name','type'=>'STRING','required'=>false,'list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['file_name_orig'],'title'=>'File name','type'=>'STRING','update'=>true,'link'=>true]);
        $this->addFileCol(['id'=>$this->file_cols['file_text'],'title'=>'File text','type'=>'STRING','required'=>false,'list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['file_date'],'title'=>'File date','type'=>'DATE','update'=>true]);
        $this->addFileCol(['id'=>$this->file_cols['file_size'],'title'=>'File size','type'=>'INTEGER','update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['encrypted'],'title'=>'Encrypted','type'=>'BOOLEAN','list'=>$list_encrypt,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['file_ext'],'title'=>'File extension','type'=>'STRING','list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['file_type'],'title'=>'File type','type'=>'STRING','list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['location_id'],'title'=>'Location','type'=>'STRING','list'=>false,'update'=>false]);
        $this->addFileCol(['id'=>$this->file_cols['location_rank'],'title'=>'Location rank','type'=>'INTEGER','list'=>false,'update'=>false]);

        $this->user_access_level = $this->getContainer('user')->getAccessLevel();
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }
   
    public function setupImages($param = array()) 
    {
        //set width or height with other value 0 to autoscale images
        if(isset($param['thumbnail'])) {
            $set = $param['thumbnail'];
            if(isset($set['list_view'])) $this->image_thumbnail['list_view'] = $set['list_view'];
            if(isset($set['edit_view'])) $this->image_thumbnail['edit_view'] = $set['edit_view'];
            if(isset($set['list_width'])) $this->image_thumbnail['list_width'] = $set['list_width'];
            if(isset($set['list_height'])) $this->image_thumbnail['list_height'] = $set['list_height'];
            if(isset($set['edit_width'])) $this->image_thumbnail['edit_width'] = $set['edit_width'];
            if(isset($set['edit_height'])) $this->image_thumbnail['edit_height'] = $set['edit_height'];
        }
        
        if(isset($param['resize'])) {
            $set = $param['resize'];
            if(isset($set['original'])) $this->image_resize['original'] = $set['original'];
            if(isset($set['crop'])) $this->image_resize['crop'] = $set['crop'];
            if(isset($set['width'])) $this->image_resize['width'] = $set['width'];
            if(isset($set['height'])) $this->image_resize['height'] = $set['height'];

            if(isset($set['thumb_nail'])) $this->image_resize['thumb_nail'] = $set['thumb_nail'];
            if(isset($set['width_thumb'])) $this->image_resize['width_thumb'] = $set['width_thumb'];
            if(isset($set['height_thumb'])) $this->image_resize['height_thumb'] = $set['height_thumb'];
        }    
    }

    public function processUpload() 
    {
        $html = '';
        $id = 0;
        $param = array();
        $form = array();

        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));
 
        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        if(isset($_GET['page']))  $this->page_no = Secure::clean('integer',$_GET['page']);
        if(isset($_POST['page'])) $this->page_no = Secure::clean('integer',$_POST['page']);
        if($this->page_no < 1)    $this->page_no = 1;
        
        if(isset($_GET['row']))  $this->row_no = Secure::clean('integer',$_GET['row']);
        if(isset($_POST['row'])) $this->row_no = Secure::clean('integer',$_POST['row']);
        if($this->row_no < 1)    $this->row_no = 1;
        
        if(isset($_GET['id'])) $id = Secure::clean('basic',$_GET['id']); 
        if(isset($_POST['id'])) $id = Secure::clean('basic',$_POST['id']);
        $this->key['value'] = $id;
     
        if(isset($_GET['msg'])) $this->addMessage(Secure::clean('alpha',$_GET['msg']));
     
        //checks if user switched from default upload interface
        if($this->mode === 'add' and isset($_GET['interface'])) {
            $interface = Secure::clean('alpha',$_GET['interface']);
            if($interface === 'simple') $this->setCache('interface','simple');
            if($interface === 'multiple') $this->setCache('interface','plupload');
        } 
        $interface = $this->getCache('interface');
        if($interface !== '') $this->upload['interface'] = $interface;

        if($this->mode === 'list_all') {
            $this->setCache('sql','');
            $this->mode = 'list';
            
            if($this->child and $id !== 0) $this->setCache('master_id',$id);

            //NB: very different from Table class
            //if($this->child and $id !== 0) {
            //    $location_id = $this->upload['location'].$id;
            //    $this->setCache('master_id',$location_id );
            //}    
        } 
 
        if($this->child) {
            $this->master['key_val'] = $this->getCache('master_id');
            if($this->master['key_val'] === '' and $this->mode !== 'download') {
                throw new Exception('MASTER_TABLE_ERROR: Linked '.$this->row_name.' id unknown!');
            } 
        } else {
            $this->master['key_val'] = 0;
        } 
    

        //if($this->upload['location'] !== 'NONE' and $this->master['key_val'] != 0) {
        //    $location_id = $this->upload['location'].$this->master['key_val'];
        //    $this->addSql('RESTRICT','T.'.$this->file_cols['location_id'].' = "'.$location_id.' ');
        //}  

        $this->beforeProcess($id);    
        
        if($this->mode == 'sort') {
            $this->mode = 'list';
            $param['sort_col'] = Secure::clean('basic',$_GET['col']); 
            $param['sort_dir'] = Secure::clean('basic',$_GET['dir']); 
        }

        if($this->mode === 'excel') {
            if(isset($_GET['cols'])) $param['cols'] = Secure::clean('basic',$_GET['cols']); 
            if(!$this->dumpData('excel',$param)) $this->mode = 'list';
        }    
        
        if($this->mode == 'list')        $html .= $this->viewTable($param);
        if($this->access['read_only'] === false) {
            if($this->mode === 'edit')         $html .= $this->viewEdit($id,$form);
            if($this->mode === 'add')          $html .= $this->viewUpload($id,$form); 
            if($this->mode === 'update')       $html .= $this->updateFile($id,$_POST); 
            if($this->mode === 'update_table') $html .= $this->updateTable(); //multiple selected file update 
            if($this->mode === 'upload')       $html .= $this->uploadFile($id,$_POST,$_FILES);
            if($this->mode === 'upload_ajax')  $html .= $this->uploadAjax($id);  //single file upload via ajax
            if($this->mode === 'delete')       $html .= $this->deleteFile($id,'SINGLE');
          
        }  

        if($this->mode === 'search')     $html .= $this->search();
        if($this->mode === 'custom')     $html .= $this->processCustom($id);
        if($this->mode === 'view_image') $html .= $this->viewImage($id);
        
        if($this->mode === 'download')   {
            $this->upload['interface'] = 'download';
            $html .= $this->fileDownload($id);
        }

        return $html;
    } 
    
    public function addFileCol($col) 
    {
        $col = $this->addCol($col);
        $col = $this->setupCol($col); 

        $this->cols[$col['id']]=$col;
    }

    protected function setupCol($col) 
    {
        //table display parameters
        if(!isset($col['edit_title'])) $col['edit_title']=$col['title'];
        if(!isset($col['class'])) $col['class']='';
        if(!isset($col['class_search'])) $col['class_search']=$col['class'];
        if(!isset($col['new'])) $col['new']='';
        if(!isset($col['hint'])) $col['hint']='';
        if(!isset($col['link'])) $col['link']=false;

        //*** specific upload parameters ***
        //NB: $col['edit'] settings ignored except when calling Model->functions()
        if(!isset($col['upload'])) $col['upload']=false; //enables capture when uploading files
        if(!isset($col['update'])) $col['update']=true; //enables update of existing file details 
         
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
        
        return $col;
    }

    /*
    public function addTableCol($col) 
    {
        $col = $this->addCol($col);
       
        $col = $this->setupCol($col); 

        $this->cols[$col['id']]=$col;
    }
    */

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
        
    public function addSortOrder($order_by,$description,$type='') {
         if($type=='DEFAULT' or $this->order_by_current=='') $this->order_by_current=$order_by;
         $this->order_by[$order_by]=$description;
    } 
    
    public function viewTable($param=array()) 
    {
        $sql   = '';
        $where = '';
        $html  = '';
        $form  = array();
        $error = '';
        
        if($this->image_thumbnail['list_view']) {
            if($this->storage === 'amazon')  $s3 = $this->getContainer('s3');
            $image_attr_str = 'border="0" align="left" ';
            if($this->image_thumbnail['list_width'] != 0) $image_attr_str .= 'width="'.$this->image_thumbnail['list_width'].'" ';
            if($this->image_thumbnail['list_height'] != 0) $image_attr_str .= 'height="'.$this->image_thumbnail['list_height'].'" ';
        } 


        //*** standard Table class code ***
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

            if($this->order_by_current != '') $this->addSql('ORDER',$this->order_by_current);   
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
                    $html = $this->viewUpload('0',$form);
                    return $html;
                }  
                
            } else {
                $this->addMessage('No '.$this->row_name.' data exists! You are not authorised to add a '.$this->row_name);
            }   
        } 

        //pagination mods to final sql statement
        $sql_limit=(($this->page_no-1)*$this->max_rows).', '.$this->max_rows.' ';
        $this->addSql('LIMIT',$sql_limit);
         
        //get all matching data
        $table = $this->list($param);

        //FINALLY Generate HTML output
        $html = '';

        if($this->show_info) $info = $this->viewInfo('LIST'); else $info = '';
       
        $nav = $this->viewNavigation('TABLE'); 
                         
        $header = $this->viewHeader('TOP');

        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;
        if($info != '') $html .= $info;
        if($this->search_rows != 0) $html .= $this->viewSearch($form);
       
        $html .= '<div id="list_div">'; 
        if($this->table_action) {
            $html .= '<form method="post" action="?mode=update_table" name="table_form" id="table_form" '.
                   'onsubmit="document.body.style.cursor=\'wait\'">'.$this->viewTableActions();
            //add any hidden state variables        
            $html .= $this->formState();        
        }

        $html .= '<table class="'.$this->classes['table'].'" id="'.$this->table.'_table">'.$header;
        
        if($this->row_count) {
            $row_no = 0;
            foreach($table as $row) {
                $row_no++;

                $image_str = '';
                if($this->image_thumbnail['list_view'] and $row[$this->file_cols['file_name_tn']] != '') {
                    if($this->storage === 'amazon') {
                        $url = $s3->getS3Url($row[$this->file_cols['file_name_tn']]);
                    }  
                    if($this->storage === 'local') {
                        $path=$this->getPath('UPLOAD',$row[$this->file_cols['file_name_tn']]);
                        $url = Image::getImage('SRC',$path,$error);
                        if($error != '') $this->addError('Thumbnail error: '.$error);
                    }
                    
                    $image_str='<a href="?mode=view_image&id='.$row[$this->key['id']].'">'.
                               '<img src="'.$url.'" '.$image_attr_str.'></a>';
                }

                $html .= $this->viewRow($row,$row_no,['image'=>$image_str]);
            }
        } 
                        
        $html .= '</table>';
        if($this->table_action) $html .= '</form>'; 
        $html .= '</div>';
        
        if(strpos($this->nav_show,'BOTTOM') !== false and $this->row_count > 50) $html .= $nav;
                
        $html = $this->viewMessages().$html;

        return $html;
    }

    protected function viewTableActions() 
    {
        $html = '';
        $actions = array();
        $actions['SELECT'] = 'Action for selected '.$this->row_name_plural;
        $action_email = '';

        if(!$this->access['read_only']) {
            if($this->access['delete']) $actions['DELETE']='Delete selected '.$this->row_name_plural;
            if($this->access['email']) {
                $actions['EMAIL'] = 'Email selected '.$this->row_name_plural;
                if(isset($_POST['action_email'])) $action_email = Secure::clean('email',$_POST['action_email']);
            }
            if($this->child and $this->access['edit']) {
                $actions['MOVE'] = 'Move selected '.$this->row_name_plural;
            }   
        }  
        
        if(count($actions) > 0) {
            //select action list
            $param = array();
            $param['class'] = $this->classes['action'];
            $param['onchange'] = 'javascript:change_table_action()';
            $action_id = '';
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
                        $sql = 'SELECT '.$this->master['key'].','.$this->master['label'].' FROM '.$this->master['table'].' '.
                               'WHERE '.$this->master['key'].' <> "'.$this->db->escapeSql($this->master['key_val']).'" '.
                               'ORDER BY '.$this->master['label'].' ';
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
                     '<input type="submit" name="action_submit" value="Proceed" class="'.$this->classes['button'].'">';

            $html .= '</div>';
        } 
         
        return $html; 
    }  
    
    protected function viewHeader($position='TOP') 
    {
        $html = '';
        if($position === 'TOP') {
            $html='<tr class="thead">';
            if($this->action_col_left) $html .= '<th>'.$this->action_header.'</th>';
            foreach($this->cols as $col) {
                if($col['list']) {
                    if($col['sort'] != '') {
                        $link_str = ' <a href="?mode=sort&dir=up&col='.$col['id'].'">'.$this->icons['sort_up'].'</a>'.
                                     '<a href="?mode=sort&dir=dn&col='.$col['id'].'">'.$this->icons['sort_dn'].'</a>';
                    } else {
                        $link_str = '';
                    }    
                    $html .= '<th>'.$col['title'].$link_str.'</th>'; 
                }  
            }
            if($this->action_col_right) $html .= '<th>Action</th>';
            $html .= '</tr>';
        }  
        return $html; 
    }  
            
    protected function viewRow($data,$row_no,$param = array())  
    {
        $html = '';
        if($this->row_tag) $html .= '<tr id="'.$row_no.'">'; else $html .= '<tr>';
                
        if($this->action_col_left) $html .= '<td valign="top">'.$this->viewActions($data,$row_no,'L').'</td>';
        foreach($this->cols as $col) {
            if($col['list']) {
                $col_id = $col['id'];
                $value = $data[$col_id];

                $value = $this->viewColValue($col_id,$value);

                if($col['link']) {
                    $xtra = '';
                    $info = Doc::fileNameParts($value);
                    if(in_array($info['extension'],$this->inline_ext)) $xtra .= 'target="_blank" ';
                    
                    $value = '<a id="file'.$data[$this->key['id']].'" href="?mode=download&id='.$data[$this->key['id']].'" '.
                             'onclick="link_download(\'file'.$data[$this->key['id']].'\')" '.$xtra.'>'.
                             $this->icons['download'].$value.'</a>';
                } 
                
                if($col_id == $this->file_cols['file_name_orig'] and $param['image'] != '') {
                    $value = '<a href="?mode=view_image&id='.$data[$this->key['id']].'">'.$param['image'].'</a>'.$value;
                } 

                //placeholder to allow any xtra mods to display value
                $this->modifyRowValue($col_id,$data,$value);
                
                if($col['type'] === 'DECIMAL') $style = 'style="text-align:right"'; else $style = '';
                $html .= '<td '.$style.'>'.$value.'</td>';
            } 
        }
        
        if($this->action_col_right) $html .= '<td valign="top">'.$this->viewActions($data,$row_no,'R').'</td>';
        
        $html .= '</tr>';
        return $html;
    } 
    
    protected function viewUpload($id,$form = array()) {
        $html='';
            
        //configure for sexy uploads
        if($this->upload['interface'] === 'plupload') {
          $html .= '<style type="text/css">@import url('.$this->getUrl('INCLUDE','plupload/js/jquery.plupload.queue/css/jquery.plupload.queue.css').');</style>';
          if($this->upload['jquery_inline']) {
            $html .= '<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.5.1/jquery.min.js"></script>';
          }  
          $html .= '<script type="text/javascript" src="https://bp.yahooapis.com/2.4.21/browserplus-min.js"></script>';
          $html .= '<script type="text/javascript" src="'.$this->getUrl('INCLUDE','plupload/js/plupload.full.js').'"></script>';
          $html .= '<script type="text/javascript" src="'.$this->getUrl('INCLUDE','plupload/js/jquery.plupload.queue/jquery.plupload.queue.js').'"></script>';
             
          $html .= '<script type="text/javascript">'.
                   '$(function() {$("#uploader").pluploadQueue({'.
                   'runtimes : \'html5,flash,gears,silverlight,browserplus\','.
                   'url : \''.$this->upload['url_ajax'].'?mode=upload_ajax\','.
                   'max_file_size : \''.($this->upload['max_size']/1000000).'mb\','.
                   'chunk_size : \'1mb\', unique_names : true,'.
                   'filters : [';
                    foreach($this->allow_ext as $name => $extensions) {
                        $html.='{title : "'.$name.'('.implode(',',$extensions).')", extensions : "'.implode(',',$extensions).'"},';
                    }  
                 
          $html .= '],'.
                   'flash_swf_url : \'include/plupload/js/plupload.flash.swf\','.
                   'silverlight_xap_url : \'include/plupload/js/plupload.silverlight.xap\''.
                   '});';
           
          $html .= '$(\'form\').submit(function(e) {'.
                   'var uploader = $(\'#uploader\').pluploadQueue();'.
                   'if (uploader.files.length > 0) {'.
                   'uploader.bind(\'StateChanged\', function() {'.
                   'if (uploader.files.length === (uploader.total.uploaded + uploader.total.failed)) {document.forms["upload_form"].submit();}'.//document.forms["upload_form"].submit();
                   '});'.
                   'uploader.start();'.
                   '} else { alert(\'You must queue at least one file.\');}'.
                   'return false;'.
                   '});';
          $html .= '});'.
                   '</script>';
        }         
                  
        $nav = $this->viewNavigation('TABLE'); 
        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;
        
        $ext_info ='';
        foreach($this->allow_ext as $name => $extensions) {
          $ext_info .= '<li>'.$name.' : extensions permitted('.implode(' , ',$extensions).')</li>';
        }
        $ext_info = '<div id="allow_ext" style="display: none;"><ul>'.$ext_info.'</ul></div>';
        $html .= $ext_info;
        
        //get any help info for view
        if($this->show_info) $info = $this->viewInfo('ADD'); else $info='';
        $html .= $info;
         
        $class_edit  = 'class="'.$this->classes['table_edit'].'"';
        $class_label = 'class="'.$this->classes['col_label'].'"';
        $class_value = 'class="'.$this->classes['col_value'].'"';
        $class_submit= 'class="'.$this->classes['col_submit'].'"';

        $html .= '<div id="edit_div" '.$class_edit.'>';
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=upload&id='.$id.'" name="upload_form" id="upload_form" onsubmit="document.body.style.cursor=\'wait\'">';
        $html .= $this->formState();
        $html .= '<table width="100%" cellspacing="0" cellpadding="4">';
        
        //simple single file upload interface
        if($this->upload['interface'] === 'simple') {
          if($this->upload['interface_change']) {
            $html .= '<tr><td colspan="2" align="center">Click here for <a href="?mode=add&interface=multiple">multiple file uploads</a> facility.<br/></td></tr>';
          }
          $html .= '<tr><td '.$class_label.' align="right">Select file:</td>';
          $html .= '<td align="left"><input type="file" name="file1" size="40" class="'.$this->classes['file_browse'].'"></td>';
          $html .= '</tr>';
        }  
        
        //plupload sexy multiple file upload interface
        if($this->upload['interface'] === 'plupload') {
          $html .= '<tr><td colspan="2" align="center">';
          if($this->upload['interface_change']) {
            $html .= 'Click here to use <a href="?mode=add&interface=simple">basic file upload</a> facility.<br/>';
          }  
          $html .= '<div id="uploader">'.
                   '<p>Your browser does not have Flash, Silverlight, Gears, BrowserPlus or HTML5 support.</p>'.
                   '</div></td></tr>';
        }  
        
        //ONLY shows cols where upload=>true
        foreach($this->cols as $col) {
            if($col['upload']) {
                $param = [];

                $form_id = $col['id'];
                if($col['required']) $title = $this->icons['required'].$col['title']; else $title = $col['title'];
                $html .= '<tr><td align="right" class="edit_label" valign="top">'.$title.':</td><td>';
                if(isset($form[$form_id])) $value = $form[$form_id]; else $value = '';
                $html .= $this->viewEditValue($col['id'],$value,'INSERT',$param);
                $html .= '</td></tr>';
                
                if($col['repeat']) {
                    $form_id = $col['id'].'_repeat';
                    $html .= '<tr><td align="right" class="edit_label" valign="top">'.$col['title'].' repeat:</td><td>';
                    $param['repeat'] = true;
                    if(isset($form[$form_id])) $value = $form[$form_id]; else $value = '';
                    $html .= $this->viewEditValue($col['id'],$value,'INSERT',$param);
                    $html .= '</td></tr>';
                } 
            } 
        }
        
        //add encryption check box if valid encryption setup
        if($this->upload['encrypt']) {
            $html .= '<tr><td align="right" '.$class_label.' valign="top">Encrypt files:</td><td>';
            if(isset($_POST['encrypt_uploaded_files'])) $checked = true; else $checked = false;
            $html .= '<span class="edit_hint">(Check to encrypt uploaded files)</span>';
            $html .= Form::checkBox('encrypt_uploaded_files',true,$checked,['class'=>'form-control edit_input']);
            $html .= '</td></tr>';
        }  
        
        $html .= $this->viewUploadXtra($form);
        
        $html .= '<tr><td>&nbsp;</td><td>'.
                 '<input id="upload_submit" type="submit" name="upload_submit" value="Upload selected '.$this->row_name_plural.'" class="'.$this->classes['button'].'">'.
                 '</td></tr>';
        $html .= '</table></form>';
        $html .= '</div>';
        
        $html = $this->viewMessages().$html;
        
        return $html;
    }

    protected function viewUploadSuccess($file_data) {
        $html = '';
        
        foreach($file_data as $file) {
           $this->addMessage('Successfully uploaded: <b>'.$file['orig_name'].'</b> size: '.Calc::displayBytes($file['size'],0));
        }
        
        $html .= '<p align="center">'.$this->viewNavigation().'</p>';
        $html .= $this->viewMessages();
        
        $success_url = $this->getCache('success_url');
        if($success_url !== '') {
            $html .= '<p><a href="'.$success_url.'">Return to page where upload was initiated from.</a></p>';
            $this->setCache('success_url','');
        }  
        
        return $html;
    } 

    //for UPDATE ONLY! for new files see view_upload() above ************************************************
    protected function viewEdit($id,$form = array()) 
    {
        $html = '';
        $error='';
        
        $edit_type = 'UPDATE';

        $this->checkAccess($edit_type,$id);
        
        $data = $this->edit($id);
        $this->data = $data;
        
        $nav = $this->viewNavigation('TABLE'); 
        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;
        
        if($this->show_info) $info = $this->viewInfo('EDIT'); else $info = '';
        $html .= $info;
        
        $class_edit   = 'class="'.$this->classes['table_edit'].'"';
        $class_label  = 'class="'.$this->classes['col_label'].'"';
        $class_value  = 'class="'.$this->classes['col_value'].'"';
        $class_submit = 'class="'.$this->classes['col_submit'].'"';
        
        $html .= '<div id="edit_div" '.$class_edit.'>';
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=update&id='.$id.'" name="update_form" id="update_form">';
        $html .= $this->formState();        
               
        $html .= '<input type="hidden" name="page" value="'.$this->page_no.'">'.
                 '<input type="hidden" name="row" value="'.$this->row_no.'">'.
                 '<input type="hidden" name="edit_type" value="'.$edit_type.'">'; 
                                
        $delete_link = '';
        if($this->access['delete']) {
            $onclick = 'onclick="javascript:return confirm(\'Are you sure you want to DELETE '.$this->row_name.'?\')" '; 
            $href = '?mode=delete&page='.$this->page_no.'&row='.$this->row_no.'&id='.$id;
            $delete_link .= '<a class="action" href="'.$href.'" '.$onclick.'>(Delete)</a>&nbsp;&nbsp;'; 
        }  
                             
        $html .= '<div class="row"><div '.$class_label.'>'.$delete_link.$this->key['title'].':</div>'.
                 '<div '.$class_value.'><input type="hidden" name="'.$this->key['id'].'" value="'.$id.'"><b>'.$id.'</b>';
        if($this->access['copy']) {
            $html .= '&nbsp;&nbsp;(&nbsp;'.Form::checkbox('copy_record','YES',0).
                     '&nbsp;Create a new '.$this->row_name.' using displayed data? )';
        }    
        $html .= '</div></div>';       
        
        //display image thumbnail
        if($this->image_thumbnail['edit_view'] and in_array($data[$this->file_cols['file_ext']],$this->allow_ext['Images'])) {
          if($data[$this->file_cols['file_name_tn']] == '') {
                $image_str = 'Image thumbnail not available.'; 
            } else {
                $attr_str = '';
                if($this->image_thumbnail['edit_width'] != 0) $attr_str .= 'width="'.$this->image_thumbnail['edit_width'].'" ';
                if($this->image_thumbnail['edit_height'] != 0) $attr_str .= 'height="'.$this->image_thumbnail['edit_height'].'" ';
                if($this->storage === 'amazon') {
                    $s3 = $this->getContainer('s3');
                    $url = $s3->getS3Url($data[$this->file_cols['file_name_tn']]);
                } 
                if($this->storage === 'local') {
                    $path=$this->getPath('UPLOAD',$data[$this->file_cols['file_name_tn']]);
                    $url = Image::getImage('SRC',$path,$error);
                    if($error != '') $this->addError('Thumbnail error: '.$error);
                }
                $image_str = '<img src="'.$url.'" '.$attr_str.'>';   
            }  

            $html .= '<div class="row"><div '.$class_label.'>Thumbnail:</div><div '.$class_value.'>'.$image_str.'</div></div>';
        }

        foreach($this->cols as $col) {
            if($col['update']) {
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
        
        $html .= '<div class="row"><div '.$class_submit.'>'.
                 '<input id="edit_submit" type="submit" name="submit" value="Submit" class="'.$this->classes['button'].'">'.
                 '</div></div>';
        $html .= '</form>';
        $html .= '</div>';
        
        $html = $this->viewMessages().$html;
        
        return $html;
    } 

    protected function viewImage($id = '') 
    {
        $error = '';
        $image_str = '';
        $html = '';
            
        if($id === '') {
          $id = Secure::clean('INTEGER',$_GET['id']);
          if(!is_numeric($id)) $this->addError('INVALID Image identification!');
        }  
        
        $nav = $this->viewNavigation(); 
        if(strpos($this->nav_show,'TOP') !== false) $html .= '<p align="center">'.$nav.'</p>';
        
        if($this->show_info) $info = $this->viewInfo('IMAGE'); else $info = '';
        $html .= $info;
        
        if(!$this->errors_found) {
            $image = $this->get($id);
            if($image == 0) {
                $this->addError('Image['.$id.'] no longer exists in database!');
            } else {  
                if($this->storage === 'amazon') {
                    $s3 = $this->getContainer('s3');
                    $url = $s3->getS3Url($image[$this->file_cols['file_name']]);
                }  
                if($this->storage === 'local') {
                    $path=$this->getPath('UPLOAD',$image[$this->file_cols['file_name']]);
                    $url = Image::getImage('SRC',$path,$error);
                    if($error != '') $this->addError('Thumbnail error: '.$error);
                } 
                $image_str = '<img src="'.$url.'" class="image">'; 
            
                $image_header = '<p>'.$image[$this->file_cols['file_name_orig']].'&nbsp;'.
                                '<a id="img'.$id.'" href="?mode=download&id='.$id.'" onclick="link_download(\'img'.$id.'\')">'.
                                $this->icons['download'].'download image</a></p>';
            
                $html .= '<div id="image_view_div">'.$image_header.$image_str.'</div>';
            } 
        }  
        
        $html = $this->viewMessages().$html;
        
        return $html;     
    }

    //form processing functions
    protected function search() 
    {
        $error = '';
        $where = '';
        
        $form['order_by'] = Secure::clean('basic',$_POST['order_by']);
        if(isset($_POST['order_by_desc']) and substr($form['order_by'],-4) != 'DESC') {
            $form['order_by_desc'] = true; 
        } else {
            $form['order_by_desc'] = false;
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
            $sql_order .= $this->db->escapeSql($form['order_by']).' ';
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
        $html = $this->viewTable($param);

        return $html; 
    } 
    
    //called from Model class
    protected function beforeUpdate($id,$edit_type,&$form,&$error) 
    {
        if($edit_type === 'UPDATE' ) {
            $sql = 'SELECT * FROM '.$this->table.' WHERE '.$this->key['id'].' = "'.$this->db->escapeSql($id).'" ';
            $original = $this->db->readSqlRecord($sql); 

            $info_orig = Doc::fileNameParts($original[$this->file_cols['file_name']]);
            $info_form = Doc::fileNameParts($form[$this->file_cols['file_name_orig']]);
            if(strcasecmp($info_orig['extension'],$info_form['extension']) !== 0) {
                $error .= 'Original extension['.$info_orig['extension'].'] cannot change!';
            }    
        }
    }

    //NB: only updates existing file data
    protected function updateFile($id,$form) {
        $html = '';
        $error = '';

        if(!$this->verifyCsrfToken($error)) $this->addError($error); 
       
        if(!$this->errors_found) $output = $this->update($id,$form);
        
        if($this->errors_found) {
            $html .= $this->viewEdit($id,$form);
        } else {
            if($this->pop_up) $this->setCache('popup_updated',true);
            
            $location = $this->location.'?mode=list'.$this->linkState().
                        '&msg='.urlencode('successful UPDATE of '.$this->row_name).
                        '&page='.$this->page_no.'&row='.$this->row_no.'#'.$this->row_no;
            $location = Secure::clean('header',$location);
            header('location: '.$location);
            exit;
        }   
        
        return $html;
    }

    //NB: can be one or many files
    protected function uploadFile($id,$form = array(),$files = array())
    {
        $error = '';
        $html = '';
        
        if(!$this->verifyCsrfToken($error)) $this->addError($error); 

        $encrypt_files = false;
        if($this->upload['encrypt'] and isset($form['encrypt_uploaded_files'])) $encrypt_files = true;  
            
        $error = '';
        $this->beforeUpload($id,$form,$error);
        if($error != '') $this->addError($error);  
        
        if($this->upload['interface'] === 'plupload') {
            if(!isset($_POST['uploader_count'])) {
                $this->addError('File upload count NOT set, cannot proceed!');
            } else {
                $file_no = Secure::clean('INTEGER',$_POST['uploader_count']);
                if($file_no == 0) $this->addError('You have not selected any files to upload!');
            }  
                
            if(!$this->errors_found) {
                for($f = 0; $f < $file_no; $f++) {
                    $base_id = 'uploader_'.$f.'_';
                    $status = $_POST[$base_id.'status'];
                    if($status === 'done') {
                        $file = array();
                        $file['text'] = '';
                        $file['encrypted'] = 0;
                        $file['orig_name'] = $_POST[$base_id.'name'];
                        if(isset($_POST[$base_id.'tmpname'])){ //only set if .js unique_names : true
                            $file['temp_name'] = $_POST[$base_id.'tmpname'];
                        } else {
                            $file['temp_name'] = $file['orig_name'];
                        }
                        
                        $info = Doc::fileNameParts($file['temp_name']);
                        $file['ext'] = strtolower($info['extension']);

                        if(!$this->checkFileExtension($file['ext'],$file['type'])) {
                            $this->addError('Uploaded File "<b>'.$file['orig_name'].'</b>" does not have an allowed extension!<br/>'.
                                            'Please view allowed file extensions for supported file types.');
                        }    
        
                       
                        if(!$this->errors_found) {
                            //get sequential file number/id and update counter
                            $file['id'] = Calc::getFileId($this->db);
                            if(!$file['id']) {
                                $this->addError('Could not generate valid file ID!');
                            } else {  
                                //create actual storage sequential numeric file name and rename to this name
                                $file['name'] = $this->upload['prefix'].$file['id'].'.'.$file['ext'];
                                $from_path = $this->getPath('UPLOAD',$file['temp_name']);
                                $to_path = $this->getPath('UPLOAD',$file['name']); //NB: do not use realpath() as will return blank for non existent file
                                if(!rename($from_path,$to_path)) {
                                    $this->addError('Could not rename['.$file['temp_name'].']');
                                    if($this->debug) {
                                        if(!file_exists($from_path)) $this->addError('File['.$from_path.'] does not exist');
                                    }    
                                }    
                                
                                //set variables for later processing
                                $file['size'] = filesize($to_path);
                                $file['path'] = $to_path;

                                if($file['size'] > $this->upload['max_size']) {
                                    $this->addError('File['.$file['orig_name'].'] size['.Calc::displayBytes($file['size']).'] '.
                                                    'greater than maximum allowed['.Calc::displayBytes($this->upload['max_size']).']');
                                }  
                            }  
                        }
                        
                        $file_data[] = $file;
                    }
                }  
            } 
              
            //clean up any temporary file mess if errors
            if($this->errors_found) {
                foreach($file_data as $file) {
                    $file_path = $this->getPath('UPLOAD',$file['temp_name']);          
                    if(file_exists($file_path)) unlink($file_path);
                    $file_path = $this->getPath('UPLOAD',$file['name']);          
                    if(file_exists($file_path)) unlink($file_path);
                    $file_path = $this->getPath('UPLOAD',$file['name_tn']);          
                    if(file_exists($file_path)) unlink($file_path);
                }  
            }  
        }
        
        if($this->upload['interface'] === 'simple') {
            //see if a file has been selected and validate details
            $form_id = 'file1';
            if($files[$form_id]['name'] == '') {
                $valid = false;
                $this->addError('You have not selected a file to upload! Click [Browse] button to select.');
            } else {
                $valid = true;
                switch ($files[$form_id]['error']) {
                    case 1 : $error = 'File size exceeded limit : '.ini_get('upload_max_filesize'); break;
                    case 2 : $error = 'File size exceeded limit specified in HTML '; break;
                    case 3 : $error = 'File was only partially uploaded'; break;
                    case 4 : $error = 'NO File was uploaded'; break;
                    case 6 : $error = 'File upload failed, missing temporary folder.'; break;
                    case 7 : $error = 'File upload failed, could not write to disk.'; break;
                    case 8 : $error = 'File upload stopped by extension!'; break;
                }
                if($error != '') $this->addError($error);
                //file_size=0 indicates that php.ini POST_MAX_SIZE or UPLOAD_MAX_SIZE exceeded
                if(!$this->errors_found and $files[$form_id]['size']==0) {
                    $this->addError('Uploaded file size exceeded either POST limit : '.ini_get('post_max_size').' or UPLOAD limit : '.ini_get('upload_max_filesize'));
                }
            } 
         
          
            if($valid and !$this->errors_found) {
                $file = array();
                $file['text'] = '';
                $file['encrypted'] = 0;
                $file['orig_name'] = basename($files[$form_id]['name']);
                $file['size'] = $files[$form_id]['size'];

                $info = Doc::fileNameParts($file['orig_name']);
                $file['ext'] = strtolower($info['extension']);

                if(!$this->checkFileExtension($file['ext'],$file['type'])) {
                    $this->addError('Uploaded File "<b>'.$file['orig_name'].'</b>" does not have an allowed extension!<br/>'.
                                    'Please view allowed file extensions for supported file types.');
                } 

                if($file['size'] > $this->upload['max_size']) {
                    $this->addError('File['.$file['orig_name'].'] size['.Calc::displayBytes($file['size']).'] '.
                                    'greater than maximum allowed['.Calc::displayBytes($this->upload['max_size']).']');
                }    

                if(!$this->errors_found)  {
                    //get sequential file number/id and update counter
                    $file['id'] = Calc::getFileId($this->db);
                    if(!$file['id']) {
                       $this->addError('Could not generate valid file ID!');
                    } else {  
                        $file['name'] = $this->upload['prefix'].$file['id'].'.'.$file['ext'];
                        //$upload_file_name=$file_base_name; use this if you want to keep original filename
                        $file['path'] = $this->getPath('UPLOAD',$file['name']);
                        if(file_exists($file['path'])) {
                            $this->addError('File already exists! Please contact support!');
                        } else {    
                            if(!move_uploaded_file($files[$form_id]['tmp_name'],$file['path']))  {
                                $this->addError('Error moving uploading file['.$file['orig_name'].']');
                            }
                        }  
                    }  
                } 
                
                if(!$this->errors_found)  $file_data[] = $file;
            }
        }    


        //image resizing
        if(!$this->errors_found) {
            foreach($file_data as $key => $file) {
                if(in_array($file['ext'],$this->image_resize_ext)) {
                    if($this->image_resize['original']) {
                        Image::resizeImage($file['ext'],$file['path'],$file['path'],$this->image_resize['crop'],
                                            $this->image_resize['width'],$this->image_resize['height'],$error_tmp);
                        if($error_tmp != '') $this->addError('Could not resize original image['.$file['orig_name'].']!'); 
                    }  
                    if($this->image_resize['thumb_nail']) {
                        $name_tn = $this->upload['prefix'].$file['id'].'_tn.'.$file['ext'];
                        $path_tn = $this->getPath('UPLOAD',$name_tn);
                        Image::resizeImage($file['ext'],$file['path'],$path_tn,$this->image_resize['crop'],
                                           $this->image_resize['width_thumb'],$this->image_resize['height_thumb'],$error_tmp);
                        if($error_tmp != '') {
                            $this->addError('Could not create thumbnail image['.$file['orig_name'].']!'); 
                        } else {
                            $file_data[$key]['name_tn'] = $name_tn;     
                            $file_data[$key]['path_tn'] = $path_tn;
                            $file_data[$key]['size_tn'] = filesize($path_tn);
                        }
                    } 
                } 
            }
        }
         
        //additional file processing after upload 
        if(!$this->errors_found and $this->upload['text_extract']) {
            foreach($file_data as $key => $file) {
                $file['path'] = realpath($file['path']);
                $file['text'] = Doc::stripDocText($file['path']);
             
                $file_data[$key]['text'] = $file['text'];
            }  
        }
        
        //NB: this must come after any text extraction
        if(!$this->errors_found and $encrypt_files) {
            foreach($file_data as $id => $file) {
                $valid = true;

                if(!in_array($file['ext'],$this->encrypt_ext)) {
                    $this->addError('file['.$file['orig_name'].'] invalid extension/type for encryption!');
                    $valid = false;
                } 

                if($file['size'] > $this->upload['max_size_encrypt']) {
                    $this->addError('file['.$file['orig_name'].'] Exceeds maximum size for encryption!');
                    $valid = false;
                }  
                
                if($valid) {
                    $file_path = $this->getPath('UPLOAD',$file['name']);
                    $file_path_encrypt = $this->getPath('UPLOAD','encrypt'.$file['name']);
                  
                    if(Crypt::encryptFile($file_path,$file_path_encrypt,$this->encrypt_key) !== true) {
                        $this->addError('Could not encrypt file['.$file['orig_name'].']'); 
                    } else {
                        unlink($file_path);
                        if(!rename($file_path_encrypt,$file_path)) {
                            $this->addError('Could not rename encrypted file['.$file_path_encrypt.']');
                        }  
                    }  
                  
                    $file_data[$id]['encrypted'] = 1;
                } 
            }  
        }  
        
        if(!$this->errors_found and $this->storage === 'amazon') {
            $s3 = $this->getContainer('s3');
                
            $s3_files = [];
            foreach($file_data as $file) {
                $s3_files[] = $file;
                if(isset($file['path_tn'])) $s3_files[] = ['name'=>$file['name_tn'],'path'=>$file['path_tn']];
            }

            $s3->putFiles($s3_files,$error);
            if($error != '' ) $this->addError('Amazon S3 upload error:'.$error);

            if($this->storage_backup !== 'local') {
                foreach($file_data as $file) {
                    if(!unlink($file['path'])) $this->addError('Could NOT remove file['.$file['name'].'] from temporary local directory!');
                    if(isset($file['path_tn'])) {
                        if(!unlink($file['path_tn'])) $this->addError('Could NOT remove image thumbnail['.$file['name_tn'].'] from temporary local directory!');
                    }
                }
             } 
        } 
            
        //Finally update files table with all file details
        if(!$this->errors_found) {
            //$form includes all non-file $_POST data
            $create = $form;

            $location_id = $this->upload['location'];
            if($this->child) $location_id = $this->master['key_val']; //NB: master key val already includes upload location

            //if addFileCol(['id'=>'location_rank',upload'=>true])
            if(isset($form[$this->file_cols['location_rank']])) {
                $location_rank = intval($form[$this->file_cols['location_rank']]);
            } else {
                $sql = 'SELECT MAX('.$this->file_cols['location_rank'].') FROM '.$this->table.' '.
                       'WHERE '.$this->file_cols['location_id'].' = "'.$location_id.'" '; 
                $location_rank = intval($this->db->readSqlValue($sql,0)) + $this->upload['rank_interval'];
    
            }
            
            foreach($file_data as $file) {
                //allows editing of one file rank without modifying others.
                $location_rank = $location_rank + $this->upload['rank_interval'];
                $create[$this->file_cols['file_id']]        = $file['id'];
                $create[$this->file_cols['file_name']]      = $file['name'];
                $create[$this->file_cols['file_name_tn']]   = $file['name_tn'];
                $create[$this->file_cols['file_name_orig']] = $file['orig_name'];
                $create[$this->file_cols['file_text']]      = $file['text'];
                $create[$this->file_cols['file_size']]      = $file['size'];
                $create[$this->file_cols['file_date']]      = date('Y-m-d');
                $create[$this->file_cols['encrypted']]      = $file['encrypted'];
                $create[$this->file_cols['file_ext']]       = $file['ext'];
                $create[$this->file_cols['file_type']]      = $file['type'];
                $create[$this->file_cols['location_id']]    = $location_id;
                $create[$this->file_cols['location_rank']]  = $location_rank;
                //die('WTF ID:'.var_dump($file).var_dump($create)); exit;
                $this->create($create);
            }
        } 
        
        if($this->errors_found) {
            $html .= $this->viewUpload($id,$form);
        } else {
            if($this->pop_up) $this->setCache('popup_updated',true);
                    
            $this->afterUpload($id,$form,$file_data);
          
            $html .= $this->viewUploadSuccess($file_data);
        } 

        return $html;  
    }

    protected function checkFileExtension($ext,&$type)
    {
        $valid = false;
        foreach($this->allow_ext as $name => $extensions) {
            if(in_array($ext,$extensions)) {
                $valid = true;
                $type = $name;
            }  
        }

        return $valid;
    }


    protected function uploadAjax($id) {
        //$log = $this->getContainer('logger');

        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        $targetDir = $this->getPath('UPLOAD','');
        if(substr($targetDir,-1) == '/') $targetDir = substr($targetDir,0,-1);

        set_time_limit(5 * 60);
        
        // Get parameters
        $chunk = isset($_REQUEST["chunk"]) ? $_REQUEST["chunk"] : 0;
        $chunks = isset($_REQUEST["chunks"]) ? $_REQUEST["chunks"] : 0;
        $fileName = isset($_REQUEST["name"]) ? $_REQUEST["name"] : '';

        // Clean the fileName for security reasons
        $fileName = preg_replace('/[^\w\._]+/', '', $fileName);

        //$log->info('target_dir:'.$targetDir. ' File:'.$fileName);

        // Look for the content type header
        if(isset($_SERVER["HTTP_CONTENT_TYPE"])) $contentType = $_SERVER["HTTP_CONTENT_TYPE"];

        if(isset($_SERVER["CONTENT_TYPE"])) $contentType = $_SERVER["CONTENT_TYPE"];

        // Handle non multipart uploads older WebKit versions didn't support multipart in HTML5
        if(strpos($contentType, "multipart") !== false) {
            if(isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                // Open temp file
                $out = fopen($targetDir . DIRECTORY_SEPARATOR . $fileName, $chunk == 0 ? "wb" : "ab");
                if($out) {
                    // Read binary input stream and append it to temp file
                    $in = fopen($_FILES['file']['tmp_name'], "rb");

                    if ($in) {
                        while ($buff = fread($in, 4096)) fwrite($out, $buff);
                    } else {
                        die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
                    }
                    fclose($in);
                    fclose($out);
                    @unlink($_FILES['file']['tmp_name']);
                } else {
                  die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
                }
            } else {
                die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
            }  
        } else {
            // Open temp file
            $out = fopen($targetDir . DIRECTORY_SEPARATOR . $fileName, $chunk == 0 ? "wb" : "ab");
            if ($out) {
                // Read binary input stream and append it to temp file
                $in = fopen("php://input", "rb");

                if ($in) {
                  while ($buff = fread($in, 4096)) fwrite($out, $buff);
                } else
                  die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');

                fclose($in);
                fclose($out);
            } else {
                die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
            }  
        }

        // Return JSON-RPC response
        die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');
    }


    protected function deleteFile($id,$type = 'SINGLE') 
    {
        $html = '';
        $data = '';
        $error = '';
        
        $file = $this->get($id);
        if($file == 0) $this->addError('Could not get file details to delete!');

        if($this->upload_location_only and $this->upload['location'] !== 'ALL') {
            if(strpos($file[$this->file_cols['location_id']],$this->upload['location'])===false) {
                $error_str .= 'file location is invalid!<br/>';
                if($this->debug) $error_str .= 'Expecting['.$this->upload['location'].'] but set as ['.$this->file_cols['location_id'].'] ';
             }   
        }  

        //delete physical file/s
        if(!$this->errors_found) {
            if($this->storage === 'amazon') {
                $s3 = $this->getContainer('s3');

                $s3->deleteFile($file[$this->file_cols['file_name']],$error);
                if($error != '') $this->addError('Could NOT remove file from Amazon S3 storage!');

                if($file[$this->file_cols['file_name_tn']] != '')  {
                    $s3->deleteFile($file[$this->file_cols['file_name_tn']],$error);
                    if($error != '') $this->addError('Could NOT remove image thumbnail from Amazon S3 storage!');
                }
            }
                
            if($this->storage === 'local' or $this->storage_backup === 'local' or $this->storage_download_local) { 
                $file_path = $this->getPath('UPLOAD',$file[$this->file_cols['file_name']]);
                if(file_exists($file_path)){
                    if(!unlink($file_path)) {
                        $error = $this->row_name.'['.$file[$this->file_cols['file_name_orig']].'] could not be deleted!';
                        if($this->debug) $error .= ' Path['.$file_path.']';
                        $this->addError($error);
                    }    
                } 
                
                //delete thumbnail
                if($file[$this->file_cols['file_name_tn']] != '')  {
                    $file_path = $this->getPath('UPLOAD',$file[$this->file_cols['file_name_tn']]);
                    if(file_exists($file_path)){
                        if (!unlink($file_path)) {
                            $error = $this->row_name.'['.$file[$this->file_cols['file_name_orig']].'] thumbnail could not be deleted!';
                            if($this->debug) $error .= ' Path['.$file_path.']';
                            $this->addError($error);
                        }    
                    } 
                } 
            } 
        } 

        //Finally delete file record
        if(!$this->errors_found) $this->delete($id);
            
        if($this->errors_found) {
            if($type === 'SINGLE') {
                $this->mode = 'list';
                $html  = $this->viewTable();
            } elseif($type === 'MULTIPLE') {
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
    

    public function fileDownload($id,$output = 'BROWSER') 
    {
        //NB: normally called from a link so only addError() at end of function
        $error = '';
        
        $file = $this->get($id);
        if($file == 0) $this->addError('Could not find file['.$id.'] in database');
        
        if(!$this->errors_found) {
            $this->beforeDownload($id,$error);
            if($error != '') $this->addError($error);
        } 

        if(!$this->errors_found) {
            $file_path = $this->getPath('UPLOAD',$file[$this->file_cols['file_name']]);
            $file_name = $file[$this->file_cols['file_name_orig']];
            
            $info = Doc::fileNameParts($file[$this->file_cols['file_name']]);
            $info['extension'] = strtolower($info['extension']);
            if(!$this->checkFileExtension($info['extension'],$type)) {
                $this->addError('download file extension['.$info['extension'].'] is invalid!');
            } 
            
            //check whether option to view inline applicable
            $content_type = 'application/octet-stream';
            if(in_array($info['extension'],$this->inline_ext)) {
                $disposition = 'inline'; 
              
                switch($info['extension']) {
                    case 'pdf'  : $content_type='application/pdf'; break;
                    case 'jpg'  : $content_type='image/jpeg'; break;
                    case 'jpeg' : $content_type='image/jpeg'; break;
                    case 'gif'  : $content_type='image/gif'; break;
                    case 'png'  : $content_type='image/png'; break;
                    case 'tiff' : $content_type='image/tiff'; break;
                    case 'mp3'  : $content_type='audio/mpeg'; break;
                    case 'wav'  : $content_type='audio/x-wav'; break;
                    case 'mp4'  : $content_type='video/mp4'; break;
                    case 'wmv'  : $content_type='video/x-ms-wmv'; break;
                }   
            } else {
                $disposition = 'attachment';
            }  
            
            if($this->upload_location_only and $this->upload['location'] !== 'ALL') {
                //NB cannot check master['key_val'] as not known for downloads!
                if(strpos($file[$this->file_cols['location_id']],$this->upload['location']) === false) {
                    $error = 'File location is invalid!';
                    if($this->debug) $error .= ' Expecting['.$this->upload['location'].'] but set as ['.$file[$this->file_cols['location_id']].'] '; 
                    $this->addError($error);
                    
                }    
            }  
                    
            //designate download path default if no encryption
            $file_path_download = $file_path;
            
            //download file from non-local storage, after checking for local version if downloaded before
            if(!$this->errors_found and $this->storage !== 'local') {
                $fetch_file = true;
                if($this->storage_download_local and file_exists($file_path)) $fetch_file=false;
                        
                if($fetch_file) {
                    if($this->storage === 'amazon') {
                        $s3 = $this->getContainer('s3');
                        $s3->getFile($file[$this->file_cols['file_name']],$file_path,$error);
                        if($error != '') $this->addError($error);
                    }
                }  
            }  
                    
            if(!$this->errors_found and $file['encrypted']) {
                $file_path_download = $this->getPath('UPLOAD','decrypt'.$file[$this->file_cols['file_name']]);
                
                if(Crypt::decryptFile($file_path,$file_path_download,$this->encrypt_key) !== true) {
                    $this->addError('Could not decrypt file contents!'); 
                }
            }
            
            //send file to browser for viewing or saving depending on disposition
            if(!$this->errors_found) {
                if(file_exists($file_path_download)) {
                    if($output === 'BROWSER') {
                        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                        header('Content-Description: File Transfer');
                        header('Content-Type: '.$content_type);
                        header('Content-Length: '.@filesize($file_path_download));
                        header('Content-Disposition: '.$disposition.'; filename="'.$file_name.'"');
                        if(@readfile($file_path_download) === false) $this->addError('Error reading downloading file!');
                    }  
                } else {
                    $this->addError('File['.$file_name.'] no longer exists!');
                }
            } 
            
            if($output === 'BROWSER') {
                //delete temp file from server if stored non-locally ie: amazon
                if($this->storage !== 'local' and $this->storage_download_local === false) unlink($file_path);
                //remove decrypted temp file
                if($file['encrypted']) unlink($file_path_download);
            }
          
        } 
        
        if($this->errors_found) {
            if($this->upload['interface'] === 'download') {
                return 'Error downloading file: '.implode(', ',$this->errors);
            } else {
                return $this->viewTable();
            }    
        } else { 
          if($output === 'BROWSER') exit; else return $file_path_download;
        }  
      
    }

    protected function updateTable() {
        $error_tmp = '';
        $html = '';
        $action_count = 0;
        $audit_str = '';
        //use for emailing files
        $action_files = array();
        
        $action = Secure::clean('basic',$_POST['table_action']);
        if($action === 'SELECT') {
           $this->addError('You have not selected any action to perform on '.$this->row_name_plural.'!');
        } else {
            if($this->child and $action === 'MOVE') {
                $action_id = Secure::clean('basic',$_POST['master_action_id']);
                $new_location_id = $this->db->escapeSql($this->upload['location'].$action_id);
                //check that action_id is valid
                $sql = 'SELECT '.$this->master['key'].' FROM '.$this->master['table'].' WHERE '.$this->master['key'].' = "'.$action_id.'" ';
                $move_id = $this->db->readSqlValue($sql,0);
                if($move_id !== $action_id) $this->addError('Move action '.str_replace('_',' ',$this->master['key']).'['.$action_id.'] does not exist!');
                $audit_str .= 'Move '.$this->row_name_plural.' from '.$this->master['table'].' id['.$this->master['key_val'].'] to id['.$action_id.'] :';
            }
            if($action === 'EMAIL') {
                $action_email = $_POST['action_email'];
                Validate::email('Action email',$action_email,$error_tmp);
                if($error_tmp != '') $this->addError('Invalid action email!');
                if($this->child) {
                    $audit_str .= 'Email '.$this->master['table'].' id['.$this->master['key_val'].'] '.$this->row_name_plural.' to '.$action_email.' :';
                } else {
                    $audit_str .= 'Email '.$this->table.' '.$this->row_name_plural.' to '.$action_email.' :';
                }  
            }
            if($action === 'DELETE') {
                if($this->child) {
                    $audit_str .= 'Delete '.$this->master['table'].' id['.$this->master['key_val'].'] '.$this->row_name_plural.' :';
                } else {
                    $audit_str .= 'Delete '.$this->table.' '.$this->row_name_plural.' :';
                }    
            }  
        }
        
            
        if(!$this->errors_found) {
            foreach($_POST as $key => $value) {
                if(substr($key,0,8) === 'checked_') {
                    $action_count++; 
                    $file_id = Secure::clean('basic',substr($key,8));
                    $file = $this->get($file_id);

                    if($file == 0) {
                        $this->addError($this->row_name.' ID['.$file_id.'] no longer exists!');
                    } else {
                        $file_name_orig = $file[$this->file_cols['file_name_orig']];

                        $audit_str .= $file_id.',';
                        
                        if($action === 'DELETE') {
                            $response = $this->deleteFile($file_id,'MULTIPLE');
                            if($response == 'OK') {
                                $this->addMessage('Successfully deleted '.$file_name_orig);
                            } else {  
                                $this->addError($response);
                            }  
                        } 

                        if($action === 'EMAIL') {
                            $attach = array();
                            $attach['name'] = $file_name_orig;
                            $attach['path'] = $this->fileDownload($file_id,'FILE'); 
                            if(!$this->errors_found) $action_files[]=$attach;
                        }  

                        if($this->child and $action === 'MOVE') {
                            $sql = 'UPDATE '.$this->table.' SET '.$this->file_cols['location_id'].' = "'.$new_location_id.'" '.
                                   'WHERE '.$this->file_cols['file_id'].' = "'.$file_id.'" ';
                            $this->db->executeSql($sql,$error_tmp);
                            if($error_tmp == '') {
                                $this->addMessage('Successfully moved '.$file_name_orig);
                            } else {
                                $this->addError('Could not MOVE '.$file_name_orig.'!');
                            }  
                        } 
                    }     
                }    
            }
        } 
        
        if($action_count == 0) $this->addError('NO '.$this->row_name_plural.' selected for action!');
                        
        if(!$this->errors_found and $action === 'EMAIL') {
            $param = array();
            $param['attach'] = $action_files;
            $from = ''; //default will be used
            $to = $action_email;
            if($this->child) {
                $subject = SITE_NAME.' '.$this->master['table'].' id['.$this->master['key_val'].'] : '.$this->row_name_plural;
            } else {    
                $subject = SITE_NAME.' '.$this->table.': '.$this->row_name_plural;
            }    
            $body = 'Please see attached documents from '.SITE_NAME;

            $mailer = $this->getContainer('mail');
            if($mailer->sendEmail($from,$to,$subject,$body,$error_tmp,$param)) {
                $this->addMessage('SUCCESS sending files to['.$to.']'); 
            } else {
                $this->addError('FAILURE emailing files to['.$to.']:'.$error_tmp); 
            }
        }  
        
        if(!$this->errors_found) {
            $this->afterUpdateTable($action); 

            $audit_action = $action.'_FILE';   
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
    protected function beforeUpload($id,&$form,&$error_str) {}
    protected function afterUpload($id,$form,$file_data) {}  
    protected function beforeDownload($id,&$error_str) {}
    protected function viewUploadXtra($form) {}
    protected function afterUpdateTable($action) {}
    
}
