<?php
namespace Seriti\Tools;

use Exception;
//use Seriti\Tools\Mysql;
use Seriti\Tools\DbInterface;
use Seriti\Tools\Validate;
use Seriti\Tools\Crypt;

class Model 
{
    protected $db;
    protected $table;
    protected $debug = false;

    protected $key = array();
    protected $cols = array(); //specify all columns
    protected $cols_fixed = array(); //use to add fixed values when inserting a record
    protected $select = array();
    protected $col_types = array('INTEGER','DECIMAL','STRING','TEXT','EMAIL','URL','DATE','BOOLEAN','PASSWORD',
                                 'DATETIME','TIME','CUSTOM','IGNORE');
    protected $access = array('edit'=>true,'view'=>true,'delete'=>true,'add'=>true,'link'=>true,'email'=>true,
                              'read_only'=>false,'search'=>true,'copy'=>false,'move'=>false,'import'=>false,'restore'=>false); 
    protected $encrypt_key = false; 
    protected $sql_join = '';
    protected $sql_restrict = '';
    protected $sql_order = '';
    protected $sql_limit = '';
    protected $sql_search = '';
    protected $sql_count = '';
    protected $distinct = false;
    protected $foreign_keys = array();
    protected $master = array();
    protected $child = false;
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();
    protected $state = array();//use to add hidden fields to all form submissions and links.
    
    public function __construct(DbInterface $db,$table) 
    {
        $this->db = $db;
        $this->table = $table;

        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;
    }

    //Setup model cols based on mysql configuration. Rather use addCols() for fine control.
    protected function addAllCols()
    { 
        $sql = 'SHOW COLUMNS FROM `'.$this->table.'` ';
        $db_cols = $this->db->readSqlArray($sql);
        if($db_cols != 0) {
            foreach($db_cols as $name=>$db_col) {
                $col = [];
                $col['id'] = $name;
                $col['title'] = ucfirst(str_replace('_',' ',$name));

                $type=$db_col['Type'];
                if($type === 'date'){
                    $col['type'] = 'DATE';
                } elseif ($type === 'datetime' or $type === 'timestamp') {
                    $col['type'] = 'DATETIME';
                } elseif ($type === 'time') {
                    $col['type'] = 'TIME';
                } elseif(strpos($type,'text') !== false) {
                    $col['type'] = 'TEXT';
                } elseif (strpos($type,'char') !== false) {
                    $col['type'] = 'STRING';
                } elseif ($type === 'tinyint(1)') {
                    $col['type'] = 'BOOLEAN';
                } elseif (strpos($type,'int') !== false) {
                    $col['type'] = 'INTEGER';
                } elseif (strpos($type,'decimal') !== false) {
                    $col['type'] = 'DECIMAL';
                } else {
                    $col['type'] = 'STRING';
                }

                if($db_col['Key'] === 'PRI') {
                   $col['key'] = true;
                   if(strpos($db_col['Extra'],'auto_increment') !==false) $col['key_auto'] = true;
                }

                $this->addCol($col);
            }
        }       
    }

    //NB: only use this where need to configure key outside of addCol() like in Import class
    protected function setupKey($col = []) 
    {
        $col['key'] = true;
        if(!isset($col['title'])) $col['title'] = $col['id'];
        $col['edit'] = false; 
        if(!isset($col['key_auto'])) $col['key_auto'] = false;  
        if($col['key_auto']) $col['required'] = false;
        $this->key = $col;
    }

    //NB: if you used addAllCols() then this will overwite individual col settings
    protected function addCol($col = []) 
    {
         if(!in_array($col['type'],$this->col_types)) $col['type'] = 'STRING';
         if(!isset($col['title'])) $col['title'] = $col['id'];
         if(!isset($col['list'])) $col['list'] = true;
         if(!isset($col['required'])) $col['required'] = true;
         if(!isset($col['edit'])) $col['edit'] = true;
         if(!isset($col['view'])) $col['view'] = true;
         //force user to repeat an important input for verifiction using name=$col['id']."_repeat" 
         if(!isset($col['repeat'])) $col['repeat'] = false;
         //will encrypt TEXT and STRING fields only
         if(!isset($col['encrypt'])) $col['encrypt'] = false;
         
         if(!isset($col['key'])) $col['key'] = false;
         if($col['key']) {
           $col['edit'] = false; 
           if(!isset($col['key_auto'])) $col['key_auto'] = false;  
           if($col['key_auto']) $col['required'] = false;
           $this->key = $col;
           $this->sql_order = $col['id'].' DESC';
         }  
         
         //used for inserting placeholders values from joined tables or inserting {KEY_VALUE}
         if(isset($col['linked'])) $col['edit'] = false; 
                  
         if($col['type'] === 'STRING' or $col['type'] === 'PASSWORD' or $col['type'] === 'EMAIL') {
            if(!isset($col['min'])) $col['min'] = 1;
            if(!isset($col['max'])) $col['max'] = 64;
            if(!isset($col['secure'])) $col['secure'] = true;
         }
         
         if($col['type'] === 'TEXT') {
            if(!isset($col['html'])) $col['html'] = false;
            if(!isset($col['secure'])) $col['secure'] = true;
            if(!isset($col['min'])) $col['min'] = 1;
            if(!isset($col['max'])) $col['max'] = 64000;
         }
         
         if($col['type'] === 'INTEGER' OR $col['type'] === 'DECIMAL') {
            if(!isset($col['min'])) $col['min'] = -1000000000;
            if(!isset($col['max'])) $col['max'] = 1000000000;
         }
         
         $this->cols[$col['id']] = $col;

         return $col;
    }
  
    public function addColFixed($col = array()) 
    {
        if(!isset($col['title'])) $col['title'] = $col['id'];
        if(!isset($col['value'])) $col['value'] = '0';

        $this->cols_fixed[$col['id']] = $col; 
    }

    public function addSelect($col_id,$param) 
    {
        //check for simple sql statement
        if(!is_array($param)) $options['sql'] = $param; else $options = $param;

        //default is to show list for editing as well as search
        if(!isset($options['edit'])) $options['edit'] = true;
        if(!isset($options['invalid'])) $options['invalid'] = 'NONE';
        
        //setup defaults for array select lists rather than sql
        if(isset($options['list'])) {
            if(!isset($options['list_assoc'])) $options['list_assoc'] = true;
        } 
        
        //NB: "." is not allowed in variable names or array keys by PHP
        $col_id = str_replace('.','_',$col_id);
        $this->select[$col_id] = $options;
    }

    public function checkAccess($action,$id = '') 
    {
        $allow = false;
        $check_restrict = false;
        $error_add = '';
        $data = [];

        if($action === 'INSERT') {
            if(!$this->access['read_only'] and $this->access['add']) $allow = true;
        }
        if($action === 'UPDATE') {
            if(!$this->access['read_only'] and $this->access['edit']) $check_restrict = true;
        }  
        if($action === 'DELETE') {
            if(!$this->access['read_only'] and $this->access['delete']) $check_restrict = true;
        }

        //update and deletes rely on form $id which could be modified by a malicious actor to a valid id restricted from user 
        if($check_restrict) {
            $data = $this->get($id);
            if($data === 0) {
                $error_add .= 'Cannot '.$action.' a restricted record.';
            } else {
                $allow = true;
            }  
        }
 
        if(!$allow) {
            $error = 'MODEL_ACCESS_ERROR:You have insufficient access rights. ';
            if($this->debug) $error .= 'Action['.$action.'] Access['.var_export($this->access,true).'] '.$error_add;
            throw new Exception($error);
        }    
    } 

    public function setupMaster($param) 
    {
        $this->child = true;
        if(!isset($param['key'])) throw new Exception('MASTER_TABLE_ERROR: Master table must have "key" configured');
        if(!isset($param['table'])) throw new Exception('MASTER_TABLE_ERROR: Master table must have "table" configured');
       
        if(!isset($param['label'])) $param['label'] = $param['key'];
        if(!isset($param['child_col'])) $param['child_col'] = $param['key'];
        if(!isset($param['child_prefix'])) $param['child_prefix'] = '';

        if(!isset($param['show_sql'])) $param['show_sql'] = '';
        //applies to copy/move multiple record actions
        if(!isset($param['action_item'])) $param['action_item'] = 'SELECT';
        if(!isset($param['action_sql'])) $param['action_sql'] = '';
        if(!isset($param['action_id'])) $param['action_id'] = '';
                 
        $this->master = $param;
    }

    public function getMaster() 
    {
        return $this->master;
    }

    protected function validateData($record_id,$context,$input)
    {
        $data = [];

        foreach($this->cols as $col) {
            if($col['edit']) {
                $id = $col['id'];
                $value = '';

                if(isset($input[$id])) {
                    $set = true;
                    $value = $input[$id];
                    if($col['type'] === 'DATETIME' and isset($input[$id.'_time'])) $value .= ' '.$input[$id.'_time'];

                    if($value == '') {
                        if($col['required'] and $col['type'] != 'BOOLEAN') {
                            $this->addError('['.$col['title'].'] is a required field!');
                        }  
                    } else { 
                        //NB: validate also modifies $value sometimes(like stripping out thousand separators) 
                        $this->validate($id,$value,$context);
                    } 

                    if($col['repeat']) {
                        $id = $col['id'].'_repeat';
                        $repeat_value = (isset($input[$id])) ? $input[$id] : '';
                        if($repeat_value !== $value) {
                            $this->addError('['.$col['title'].'] repeat not identical! Please check!');
                        }    
                    }  

                    $data[$id]=$value;
                } else {
                    if($context === 'INSERT' and $col['required'] and $col['type'] !== 'BOOLEAN') {
                        $this->addError('['.$col['title'].'] is a required field!');
                    } 
                    //NB: $input is normally $_POST data and unchecked checkbox is absent from $_POST data
                    if($col['type'] === 'BOOLEAN') {
                        $value = '0';
                        $data[$id] = $value;
                    }       
                }   
            } 
        }
        
        return $data;
    }

    //expects request $_POST array or similar as $input
    public function create($input = [])  
    {
        $data = array();
        $error = '';
        $context = 'INSERT';

        $this->checkAccess($context);

        if(!isset($this->key['id'])) $this->addError('No primary key specified');
        
        if($this->key['key_auto']) {
            //mysql will auto increment 0 value correctly
            $create_id = '0';
        } else {  
            $create_id = $input[$this->key['id']];
            $this->validate($this->key['id'],$create_id,$context);
        } 
        $this->key['value'] = $create_id;
        
        $data = $this->validateData($create_id,$context,$input);

        //die('WTF Input:'.var_dump($input).' validated:'.var_dump($data)); exit;

        $data[$this->key['id']] = $create_id; 
        
        $this->beforeUpdate($create_id,$context,$data,$error);
        if($error!='') $this->addError($error);
         
        if($this->encrypt_key !== false and !$this->errors_found) {
            $data = $this->encrypt($data);
        }  

        if(!$this->errors_found) {
            if($this->child) {
                $data[$this->master['child_col']] = $this->master['child_prefix'].$this->master['key_val'];
            }    

            if(count($this->cols_fixed)) {
                foreach($this->cols_fixed as $fixed) {
                    $data[$fixed['id']] = $fixed['value'];
                }  
            }  
            
            $insert_id = $this->db->insertRecord($this->table,$data,$error);
            if($error !== '') {
                if(strpos($error,'1062')) {
                    $error_show = 'Value for ['.$this->key['title'].'] allready exists!';
                } else {
                    $error_show = 'Could not '.$context.' data! ';
                }    
                if($this->debug) $error_show .= $error;
                $this->addError($error_show);
            } elseif($this->key['key_auto'] and $this->key['type'] === 'INTEGER') {
                $create_id = $insert_id;
                $data[$this->key['id']] = $create_id; 
            }   
        }
        
             
        if(!$this->errors_found) {
            $this->afterUpdate($create_id,$context,$data);
            $output['status'] = 'OK';
            $output['id'] = $create_id;
        } else {
            $output['status'] = 'ERROR';
            $output['errors'] = $this->errors;
        }

        return $output; 
    }

    //expects $_POST array or similar as $input
    public function update($update_id,$input = [])  
    {
        $output = [];
        $data = [];
        $error = '';
        $context = 'UPDATE';
        
        $this->checkAccess($context,$update_id);
        
        if(!isset($this->key['id'])) $this->addError('No primary key specified');

        $this->key['value'] = $update_id;
         
        $data = $this->validateData($update_id,$context,$input);   
        
        $this->beforeUpdate($update_id,$context,$data,$error);
        if($error !== '') $this->addError($error);
         
        if($this->encrypt_key !== false and !$this->errors_found) {
            $data = $this->encrypt($data);
        } 

        //generate and execute SQL statements
        if(!$this->errors_found) {
            $where = array($this->key['id']=>$update_id);
            $this->db->updateRecord($this->table,$data,$where,$error);
            if($error !== '') {
                if(strpos($error,'1062')) {
                    $error_show = 'Value for ['.$this->key['title'].'] allready exists!';
                } else {
                    $error_show = 'Could not '.$context.' data! ';
                }    
                if($this->debug) $error_show .= $error;
                $this->addError($error_show);    
            }
        }
        
        if(!$this->errors_found) {
            $this->afterUpdate($update_id,$context,$data);
            $output['status'] = 'OK';
        } else {
            $output['status'] = 'ERROR';
            $output['errors'] = $this->errors;
        }

        return $output; 
    }
  
    public function delete($delete_id) {
        $error = '';
        $context = 'DELETE';
       
        $this->checkAccess($context,$delete_id);
         
        if(!isset($this->key['id'])) $this->addError('No primary key specified');

        $this->beforeDelete($delete_id,$error);
        if($error!='') $this->addError($error);
            
        if(count($this->foreign_keys) != 0) {
            foreach($this->foreign_keys as $key) {
                $sql = 'SELECT COUNT(*) FROM `'.$key['table'].'` WHERE `'.$key['col_id'].'` = "'.$this->db->escapeSql($delete_id).'" ';
                $key_count = $this->db->readSqlValue($sql,0);
                if($key_count > 0) $this->addError('Found['.$key_count.'] linked records in '.$key['message']);
            }
        } 
        
        if(!$this->errors_found) {
            $where = array($this->key['id'] => $delete_id);
            $this->db->deleteRecord($this->table,$where,$error); 
            if($error != '') $this->addError($error);    
        }    

        if(!$this->errors_found) {
            $this->afterDelete($delete_id);
            $output['status'] = 'OK';
        } else {
            $output['status'] = 'ERROR';
            $output['errors'] = $this->errors;
        }

        return $output; 
    }

    //get an array of all records matching model restrictions
    public function list($param = array())
    { 
        $list = [];

        if(isset($param['sql']) and $param['sql'] !== '' ) {
          $sql = $param['sql'];    
        } else {
          $sql = $this->sqlConstruct('SELECT_LIST');    
        }

        if($this->sql_limit !== '') $sql.='LIMIT '.$this->sql_limit.' ';
        
        $first_col_key = false;
        $list = $this->db->readSqlArray($sql,$first_col_key);
 
        if($list !== 0 and $this->encrypt_key !== false) {
            foreach($list as $i=>$data) {
                $list[$i] = $this->decrypt($data);
            }    
        }  

       return $list;
    }

    protected function encrypt($data)
    {
        foreach($data as $id=>$value) {
            $col = $this->cols[$id];
            if($col['encrypt'] and $value != '' and ($col['type'] === 'TEXT' or $col['type'] === 'STRING')){
                $data[$id] = Crypt::encryptText($value,$this->encrypt_key);
            }
        }

        return $data;  
    }

    protected function decrypt($data)
    {
        foreach($data as $id=>$value) {
            $col = $this->cols[$id];
            if($col['encrypt'] and $value != '' and ($col['type'] === 'TEXT' or $col['type'] === 'STRING')){
                $data[$id] = Crypt::decryptText($value,$this->encrypt_key);
            }
        }

        return $data;  
    }


    //count records that would be returned by list()
    public function count($param = array())
    { 
       $count = 0;
       $sql = $this->sqlConstruct('SELECT_COUNT');
       $count = $this->db->readSqlRecord($sql);
       
       return $count;
    }

    //view all columns as is of an individual record 
    public function get($id)
    { 
        $data = [];
        $sql = $this->sqlConstruct('SELECT_RAW',$id);
        $data = $this->db->readSqlRecord($sql);

        if($data !== 0 and $this->encrypt_key !== false) {
            $data = $this->decrypt($data);
        }  

        return $data;
    }

    //view all defined columns of an individual record with join fields used
    public function view($id)
    { 
        $data = [];
        $sql = $this->sqlConstruct('SELECT_VIEW',$id);
        $data = $this->db->readSqlRecord($sql);

        if($data !== 0 and $this->encrypt_key !== false) {
            $data = $this->decrypt($data);
        }  

        return $data;
    }

    //view all editable defined columns of an individual record without join fields
    public function edit($id)
    { 
        $data = [];
        $sql = $this->sqlConstruct('SELECT_EDIT',$id);
        $data = $this->db->readSqlRecord($sql);

        if($data !== 0 and $this->encrypt_key !== false) {
            $data = $this->decrypt($data);
        } 

        return $data;
    }

    public function sqlConstruct($type,$id=0) {
        $sql='';

        //restrictions which apply to all queries. 
        $restrict = [];
        //NB: sometimes master['key_val'] is not know when fetching a record, like with fileDownload() in Upload class
        if($this->child and $type !== 'SELECT_RAW') {
            $restrict[] = 'T.`'.$this->master['child_col'].'` = "'.$this->db->escapeSql($this->master['child_prefix'].$this->master['key_val']).'" ';
        }    
        if($this->sql_restrict != '') $restrict[] = $this->sql_restrict; 

        //optional conditions merged with restrictions
        $where = [];
        if($this->sql_search != '') $where[] = $this->sql_search; 
        $where = array_merge($restrict,$where);

        if($type === 'SELECT_COUNT') {
            $sql='SELECT COUNT(DISTINCT T.`'.$this->key['id'].'`) AS `row_count` '.$this->sql_count.' '.
                 'FROM `'.$this->table.'` AS T '.$this->sql_join;
            if(count($where)) $sql .= 'WHERE '.implode(' AND ',$where);
        }

        if($type === 'SELECT_LIST' or $type === 'SELECT_VIEW') {
            $sql = 'SELECT ';
            if($this->distinct) $sql .= 'DISTINCT ';

            foreach($this->cols as $col) {
                if(isset($col['join'])) { //for nested select statement to get related field from id
                    $sql .= '( SELECT '.$col['join'].' = T.`'.$col['id'].'` LIMIT 1 ) AS `'.$col['id'].'`,';
                } elseif(isset($col['linked'])) { 
                    if($col['linked'] === 'EMPTY' or $col['linked'] === 'KEY_VAL') { //placeholder filled with key field for later modification.
                        $sql .= 'T.`'.$this->key['id'].'` AS `'.$col['id'].'`,'; 
                    } else { //$col['linked'] must include any table identifier(ie: T2.xyz) as specified in addSql('JOIN',....).  
                        $str = str_replace('{KEY_VAL}','T.`'.$this->key['id'].'`',$col['linked']); 
                        $sql .= '('.$str.') AS `'.$col['id'].'`,'; 
                    } 
                } else {  
                    $sql .= 'T.`'.$col['id'].'`,';
                }  
            }
            
            $sql = substr($sql,0,-1).' FROM `'.$this->table.'` AS T '.$this->sql_join;
            if($type === 'SELECT_VIEW') {
                $sql .= 'WHERE T.`'.$this->key['id'].'` = "'.$this->db->escapeSql($id).'" ';
                if(count($restrict)) $sql .= 'AND '.implode(' AND ',$restrict).' ';
                $sql .= 'LIMIT 1 ';
            } else {
                if(count($where)) $sql .= 'WHERE '.implode(' AND ',$where);
                if($this->sql_order !== '') $sql.='ORDER BY '.$this->sql_order.' ';
            }    
        }  

        if($type === 'SELECT_EDIT') {
            $sql = 'SELECT ';
            foreach($this->cols as $col) {
                if($col['edit']) $sql .= 'T.`'.$col['id'].'`,'; 
            } 
            $sql = substr($sql,0,-1).' FROM `'.$this->table.'` AS T '.$this->sql_join.
                  'WHERE T.`'.$this->key['id'].'` = "'.$this->db->escapeSql($id).'" ';
            if(count($restrict)) $sql .= 'AND '.implode(' AND ',$restrict).' ';
            $sql .= 'LIMIT 1 ';
        }

        if($type === 'SELECT_RAW') {
            $sql = 'SELECT * FROM `'.$this->table.'` AS T '.$this->sql_join.
                  'WHERE T.`'.$this->key['id'].'` = "'.$this->db->escapeSql($id).'" ';
            if(count($restrict)) $sql .= 'AND '.implode(' AND ',$restrict).' ';
            $sql .= 'LIMIT 1 ';
        }

        //NOT USED ANYWHERE, INCLUDES ALL RESTRICTIONS
        if($type === 'DELETE') {
          $sql = 'DELETE FROM `'.$this->table.'` AS T '.$this->sql_join.
                 'WHERE T.`'.$this->key['id'].'` = "'.$this->db->escapeSql($id).'" ';
          if(count($restrict)) $sql .= 'AND '.implode(' AND ',$restrict);
        } 

        return $sql;
    } 

    //NB: some validation functions also clean/convert form values....see validate_number/integer/email;
    public function validate($col_id,&$value,$context = 'UPDATE')  
    {
        $error = '';
        $col = $this->cols[$col_id];
        
        //custom validation
        $this->beforeValidate($col_id,$value,$error,$context);
        if($error !== '') {
            $this->addError($error);
            return false;
        }

        if(isset($this->select[$col_id])) {
            if($context === 'UPDATE' or $context === 'INSERT' ) {
                if($value === $this->select[$col_id]['invalid'] and $col['required']) {
                    $this->addError('Selection for ['.$col['title'].'] is not valid!');
                    return false;
                }    
            }  
          if($context === 'SEARCH' and $value === 'ALL') return true;
        }   

        if($context === 'SEARCH') {
          if($col['type'] === 'EMAIL' or $col['type'] === 'URL') $col['type'] = 'STRING';
          if($value == '') return true;
        }
                
        switch($col['type']) {
            case 'CUSTOM'  : break; //custom validation must be handled by beforeUpdate() placeholder function
            case 'STRING'  : {
                Validate::string($col['title'],$col['min'],$col['max'],$value,$error,$col['secure']); 
                break;
            }  
            case 'PASSWORD': Validate::password($col['title'],$col['min'],$col['max'],$value,$error);  break;
            case 'TEXT'    : {
                if($col['html']) {
                    Validate::html($col['title'],$col['min'],$col['max'],$value,$error,$col['secure']);
                } else {
                    Validate::text($col['title'],$col['min'],$col['max'],$value,$error,$col['secure']);
                }       
                break;
            } 
            case 'INTEGER' : Validate::integer($col['title'],$col['min'],$col['max'],$value,$error);  break;
            case 'DECIMAL' : Validate::number($col['title'],$col['min'],$col['max'],$value,$error);  break;  
            case 'EMAIL'   : Validate::email($col['title'],$value,$error);  break;  
            case 'URL'     : Validate::url($col['title'],$value,$error);  break;  
            case 'DATE'    : Validate::date($col['title'],$value,'YYYY-MM-DD',$error);  break;  
            case 'DATETIME': Validate::dateTime($col['title'],$value,'YYYY-MM-DD HH:MM',$error);  break;  
            case 'TIME'    : Validate::time($col['title'],$value,'HH:MM:SS',$error);  break;  
            case 'BOOLEAN' : Validate::boolean($col['title'],$value,$error);  break;
            default: $error.='Unknown column type['.$col['type'].']';
        }

        if($error !== '') {
            $this->addError($error);
            return false;
        } else {
            return true;
        }    
    }

    //ONLY $this->sql_join is concatenated with .= , others must be a single statement
    public function addSql($type,$sql) 
    {
        if($type === 'WHERE') $type = 'RESTRICT';

        if($type === 'JOIN')     $this->sql_join    .= $sql.' ';
        if($type === 'RESTRICT') $this->sql_restrict = $sql.' ';
        if($type === 'ORDER')    $this->sql_order    = $sql.' ';
        if($type === 'LIMIT')    $this->sql_limit    = $sql.' ';
        if($type === 'SEARCH')   $this->sql_search   = $sql.' ';
        if($type === 'COUNT')    $this->sql_count    = $sql.' ';
    }

    public function resetSql() 
    {
        $this->sql_join = '';
        $this->sql_restrict = '';
        $this->sql_order = '';
        $this->sql_limit = '';
        $this->sql_search = '';
        $this->sql_count = '';
    }        
        
    public function addForeignKey($key) 
    {
        //$key=['table'=>'XXX','col_id'=>'XXX_ID','message'=>'XXX_NAME'];
        if(!isset($key['message'])) $key['message'] = $key['table']; 
        if(!isset($key['col_id'])) $key['col_id'] = $this->key['id'];
        
        $this->foreign_keys[] = $key;
    }   
    
    //NB: htmlspecialchars() used as errors may include user input  
    protected function addError($error,$clean=true) 
    {
        if($error !== '') {
            if($clean) $error = htmlspecialchars($error);
            $this->errors[] = $error; 
            $this->errors_found = true;
        }  
    }

    protected function clearErrors() 
    {
        $this->errors_found = false;
        $this->errors = [];
    }  
      
    protected function addMessage($str) 
    {
        if($str !== '') $this->messages[] = $str;
    }
    
    /*** EVENT PLACEHOLDER FUNCTIONS ***/
    protected function beforeUpdate($id,$context,&$data,&$error) {}
    protected function afterUpdate($id,$context,$data) {}  
    protected function beforeDelete($id,&$error) {}
    protected function afterDelete($id) {} 
    protected function beforeValidate($col_id,&$value,&$error,$context) {}

}  
