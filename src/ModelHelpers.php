<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Secure;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Crypt;
use Seriti\Tools\Csv;
use Seriti\Tools\Doc;
use Seriti\Tools\STORAGE;
use Seriti\Tools\BASE_URL;
use Seriti\Tools\BASE_INCLUDE;
use Seriti\Tools\BASE_TEMPLATE;
use Seriti\Tools\BASE_UPLOAD_WWW;
use Seriti\Tools\URL_CLEAN_LAST;
use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_DOCS;


trait  ModelHelpers 
{
   
    protected function dumpData($format,$param=array()) 
    {
        if(!isset($param['cols'])) $param['cols'] = 'list';
        
        if(isset($param['sql'])) {
            $sql = $param['sql'];
            $this->row_count = $param['sql_count'];
        } else {
            //check for cached search sql 
            $sql = $this->getCache('sql');
            if($sql != '') $this->row_count = $this->getCache('sql_count');
        } 
        
        //redirect to list view if errors or no data
        if($sql == '' or $this->row_count == 0) {
            $this->addError('NO data found to download!');
            return false;
        } 
        
        if($format == 'excel') {
            $file_name = str_replace(' ','_',$this->row_name_plural).'_'.date('Y-m-d').'_';
            if($param['cols'] == 'list') $file_name .= 'listview'; else $file_name.='alldata';
            $file_name .= '.csv';
            $data = '';
            $line = array();
            $line_str = '';
            $csv_options = array();
            
            //put col titles in first row
            foreach($this->cols as $col) {
                if($param['cols'] == 'list') {
                    if($col['list']) $line[] = Csv::csvPrep($col['title']); 
                } else {
                    $line[] = Csv::csvPrep($col['title']);  
                }    
            }
            $line_str = implode(',',$line);
            Csv::csvAddRow($line_str,$data);
            
            //now process all row data
            $result = $this->db->readSql($sql);
            if($result->num_rows > 0) {
                while($row = $result->fetch_array(MYSQLI_ASSOC)) {
                    $line = array();
                    foreach($this->cols as $col) {
                        $value = $row[$col['id']];
                        
                        if($col['encrypt'] and $value != '') $value = Crypt::decryptText($value,$this->encrypt_key);
                                                
                        $this->modifyRowValue($col['id'],$row,$value);
                        
                        if(($col['type'] == 'INTEGER' or $col['type'] == 'DECIMAL') and !isset($col['join'])) {
                            $csv_options['type'] = 'NUMBER';
                        } else {
                            $csv_options['type'] = 'STRING';
                        }  
                            
                        if($param['cols'] == 'list') {
                            if($col['list']) $line[] = Csv::csvPrep($value,$csv_options); 
                        } else {
                            $line[] = Csv::csvPrep($value,$csv_options);  
                        }    
                    }
                    $line_str = implode(',',$line);
                    Csv::csvAddRow($line_str,$data);
                }
                $result->free();
            } 
            
            //$data=seriti_csv::mysql_dump_csv($data_set);
            Doc::outputDoc($data,$file_name,'DOWNLOAD');
            exit();
        }   
        
    }  
    
    protected function setCache($id,$value) 
    {
        $cache_id = $this->table.'_'.$id;
        $_SESSION[$cache_id] = $value;
    }
    
    protected function getCache($id) 
    {
        $value = '';
        $cache_id = $this->table.'_'.$id;
        if(isset($_SESSION[$cache_id])) $value = $_SESSION[$cache_id];
        return $value;
    }

    public function getErrors() 
    {
        return $this->errors;
    }

    public function getMessages() 
    {
        return $this->messages;
    }

    public function addState($key,$value) 
    {
        $this->state[$key] = $value;
    } 

    protected function linkState() 
    {
        $param = '';
        if(count($this->state) != 0) {
            foreach($this->state as $key => $value) $param .= $key.'='.$value.'&';
            $param = '&'.substr($param,0,-1);
        } 

        if(isset($this->user_csrf_token)) {
            $param .= '&csrf_token='.$this->user_csrf_token;
        }

        return $param;
    }

    protected function formState() 
    {
        $html = '';
        if(count($this->state) != 0) {
            foreach($this->state as $key => $value) {
                $html .= '<input type="hidden" name="'.$key.'" value="'.$value.'">';
            }  
        } 

        if(isset($this->user_csrf_token)) {
            $html .= '<input type="hidden" name="csrf_token" value="'.$this->user_csrf_token.'">';
        }

        return $html;
    } 

    public function addAction($action) 
    {
        if(!is_array($action)) {
            if(in_array($action,['edit','delete','view'])) $text = $action; else $text = '';
            $action = ['type'=>$action,'text'=>$text];
        }

        $action_valid = true;
        if(!isset($action['icon'])) $action['icon'] = false;
        
        if($action['type'] == 'edit') {
            if($action['icon'] === true) $action['icon'] = $this->icons['edit'];
            $action['mode'] = 'edit';
            if(!$this->access['edit']) $action_valid = false;
        }  
        if($action['type'] == 'view') {
            if($action['icon'] === true) $action['icon'] = $this->icons['view'];
            $action['mode'] = 'view';
        }
        if($action['type'] == 'delete') {
            if($action['icon'] === true) $action['icon'] = $this->icons['delete'];
            $action['mode'] = 'delete';
            if(!$this->access['delete']) $action_valid = false;
        } 
        if($action['type'] == 'popup') {
            if(!isset($action['width'])) $action['width'] = 600;
            if(!isset($action['height'])) $action['height'] = 400;
        } 
        //this action allows multiple rows in table to be selected and an action performed on all checked records
        if($action['type'] == 'check_box') {
            if($this->access['read_only'] or !$this->access['edit']) {
                $action_valid = false;
            } else {  
                $this->table_action = true; 
                if(!isset($action['checked'])) $action['checked'] = false;
            }  
        } 
                
        if(!isset($action['class'])) $action['class'] = '';    
        if(!isset($action['mode'])) $action['mode'] = 'list';
        if(!isset($action['verify'])) $action['verify'] = false;
        if(!isset($action['spacer'])) $action['spacer'] = '&nbsp;';
                        
        if($action_valid) {
            if(!isset($action['pos'])) $action['pos'] = 'L';
            if($action['pos'] == 'L') $this->action_col_left = true;
            if($action['pos'] == 'R') $this->action_col_right = true;
            
            $this->actions[] = $action;
        } 
    } 

    //used in Table class to show related images
    public function setupImages($param = array()) 
    {
        if(!isset($param['table'])) $param['table'] = 'files';
        if(!isset($param['table_cols'])) $param['table_cols'] = 'DEFAULT';
        if(!isset($param['location'])) $param['location'] = $this->table;
        if(!isset($param['link_url'])) $param['link_url'] = URL_CLEAN_LAST.'_image';
        if(!isset($param['max_no'])) $param['max_no'] = 10;
        if(!isset($param['manage'])) $param['manage'] = true;
        if(!isset($param['list'])) $param['list'] = true;
        if(!isset($param['list_thumbnail'])) $param['list_thumbnail'] = true;
        if(!isset($param['list_no'])) $param['list_no'] = 1;
        if(!isset($param['title'])) $param['title'] = 'Images';
        if(!isset($param['height'])) $param['height'] = '500';
        if(!isset($param['width'])) $param['width'] = '700';
        if(!isset($param['icon'])) $param['icon'] = '';
        if(!isset($param['name'])) $param['name'] = '';
        if(!isset($param['storage'])) $param['storage'] = STORAGE;
        if(!isset($param['path'])) $param['path'] = BASE_UPLOAD.UPLOAD_DOCS;
        if(!isset($param['path_public'])) $param['path_public'] = false;
        if(!isset($param['https'])) $param['https'] = true;
         
        $this->image_upload = true;
        $this->images = $param;

        if($param['storage'] === 'amazon' and $this->images['list'] and $this->images['list_thumbnail']) {
            $this->images['s3'] = $this->getContainer('s3');
        }     
    }

    //used in Table class to show related files
    public function setupFiles($param = array()) 
    {
        if(!isset($param['table'])) $param['table'] = 'files';
        if(!isset($param['table_cols'])) $param['table_cols'] = 'DEFAULT';
        if(!isset($param['location'])) $param['location'] = $this->table;
        if(!isset($param['link_url'])) $param['link_url'] = URL_CLEAN_LAST.'_file';
        if(!isset($param['max_no'])) $param['max_no'] = 10;
        if(!isset($param['manage'])) $param['manage'] = true;
        if(!isset($param['list'])) $param['list'] = true;
        if(!isset($param['list_no'])) $param['list_no'] = 10;
        if(!isset($param['title'])) $param['title'] = 'Documents';
        if(!isset($param['height'])) $param['height'] = '500';
        if(!isset($param['width'])) $param['width'] = '700';
        if(!isset($param['icon'])) $param['icon'] = '';
        if(!isset($param['name'])) $param['name'] = '';
        if(!isset($param['storage'])) $param['storage'] = STORAGE;
        if(!isset($param['path'])) $param['path'] = BASE_UPLOAD.UPLOAD_DOCS;
        if(!isset($param['path_public'])) $param['path_public'] = false;
        
        $this->file_upload = true;
        $this->files = $param;
    }

    protected function getUrl($type,$file_name) 
    {
        $url = '';
        //BASE_UPLOAD_WWW = relative path from public_html folder to storage folder for url accessible files 
        if($type === 'FILE') $url = BASE_URL.BASE_UPLOAD_WWW.$this->upload['path'].$file_name;
        //BASE_URL = http address to public_html folder
        if($type === 'INCLUDE') $url = BASE_URL.BASE_INCLUDE.$file_name;
        return $url; 
    }
    
    protected function getPath($type,$file_name,$param = []) {
        $path = '';
        //all paths assumed to have trailing "/"
        if($type === 'UPLOAD') $path = $this->upload['path_base'].$this->upload['path'].$file_name;
        if($type === 'TEMPLATE') $path = BASE_TEMPLATE.$file_name;
        return $path; 
    }  


        
}