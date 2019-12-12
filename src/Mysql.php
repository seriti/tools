<?php
namespace Seriti\Tools;

use Exception;
//use Seriti\Tools\Config;
use Seriti\Tools\DbInterface;
use Seriti\Tools\TableStructures;

class Mysql implements DbInterface
{
    use TableStructures;

    protected $db;
    protected $audit = false;
    protected $audit_user_id = '';
    protected $audit_table = '';
    protected $audit_table_exclude = [];
    protected $debug = false;


    public function __construct($param=array()) 
    {
        $this->db = $this->connect($param);

        if(isset($param['audit'])) $this->setupAudit($param['audit']);
    }

    public function connect($param=array()) 
    {
        $error = '';
        if(!isset($param['host']))     $error .= 'NO MySQL host specified: ';
        if(!isset($param['user']))     $error .= 'NO MySQL user specified: ';
        if(!isset($param['password'])) $error .= 'NO MySQL password specified: ';
        if(!isset($param['name']))     $error .= 'NO MySQL database specified: ';
        if($error !== '') throw new Exception('MYSQL_CONNECT: Err['.$error.']');
        
        if(!isset($param['charset'])) $param['charset'] = 'utf8';

        if(isset($param['debug'])) {
            $this->debug = $param['debug'];
        } elseif(defined(__NAMESPACE__.'\DEBUG')) {
            $this->debug = DEBUG;
        } 
    
        $db = new \mysqli($param['host'],$param['user'],$param['password'],$param['name']);
        if($db->connect_error) {
            throw new Exception('MYSQL_CONNECT: No['.$db->connect_errno.'] Err['.$db->connect_error.']');
        }  
         
        $db->set_charset($param['charset']);
    
        return $db;
    }

    public function disableAudit()
    {
        $this->audit = false;
    }

    public function enableAudit()
    {
        if($this->audit_table !== '') $this->audit = true;
    }

    public function setupAudit($param = [])
    {
        if($param['enabled'] !== true) {
            $this->audit = false;
        } else {    
            $this->audit = true;
            $this->audit_table = $param['table'];
            $this->audit_user_id = 0;
            if(isset($param['user_id'])) $this->audit_user_id = $param['user_id'];
            if(isset($param['table_exclude']) and is_array($param['table_exclude'])) {
                $this->audit_table_exclude = $param['table_exclude'];
            } 
        }
    }

    public function setAuditUserId($user_id)
    {
        if($this->audit !== false) $this->audit_user_id = $user_id;
    }

    public function parseSearchTerm(&$value,&$sql = array()) 
    {
        $sql['prefix'] = '';
        $sql['suffix'] = '';
        $sql['operator'] = '';
        
        $value = trim($value);
        $prefix = substr($value,0,1);
        $suffix = substr($value,-1);
        
        //NB sequence is vital <> must come before < and >    
        if(strpos($value,'<>') === 0) {
            $value = substr($value,2);
            $sql['operator'] = '<>';
        } elseif(strpos($value,'>=') === 0) {
            $value = substr($value,2);
            $sql['operator'] = '>=';
        } elseif(strpos($value,'<=') === 0) {
            $value = substr($value,2);
            $sql['operator'] = '<=';
        } elseif($prefix === '>') {
            $value = substr($value,1);
            $sql['operator'] = '>';  
        } elseif($prefix === '<') {
            $value = substr($value,1);
            $sql['operator'] = '<';
        } elseif($prefix === '=') {
            $value = substr($value,1);
            $sql['operator'] = '=';
        } elseif($prefix === '*' or $suffix === '*') {
            if($prefix === '*') { $sql['prefix'] = '%'; $value = substr($value,1); }
            if($suffix === '*') { $sql['suffix'] = '%'; $value = substr($value,0,-1); }
            $value = str_replace('*','',$value);
            $sql['operator'] = 'LIKE';
        } elseif($prefix === '"' and $suffix === '"') {
            $value = substr($value,1,-1);
            $sql['operator'] = '=';
        } else {
            $sql['operator'] = 'LIKE';
            $sql['prefix'] = '%';
            $sql['suffix'] = '%';
        }  
    }  
    
    public function getRecord($table,$where = array()) 
    {
        $sql = 'SELECT * FROM `'.$table.'` WHERE ';
        foreach($where as $key => $value) {
            $sql .= $key.' = "'.$this->escapeSql($value).'" AND ';
        }
        $sql = substr($sql,0,-4);

        $record = $this->readSqlRecord($sql);
        
        return $record; 
    } 

    public function deleteRecord($table,$where = array(),&$error)
    {
        if($this->audit) $rec = $this->getRecord($table,$where);
        
        $sql = 'DELETE FROM `'.$table.'` WHERE ';
        foreach($where as $key => $value) {
            $sql .= $key.' = "'.$this->escapeSql($value).'" AND ';
        }
        $sql = substr($sql,0,-4);

        $this->executeSql($sql,$error); 
        if($error === '') {
            if($this->audit) $this->auditAction('DELETE',$table,$rec,$where);

            return true;
        } else {
            return false;
        } 
    }  
    
    public function updateRecord($table,$rec = array(),$where = array(),&$error) 
    {
        $error = '';
                
        $sql = 'UPDATE `'.$table.'` SET ';
        foreach($rec as $key => $value) {
            $sql .= $key.' = "'.$this->escapeSql($value).'",';
        }
        
        $sql = substr($sql,0,-1).' WHERE ';
        foreach($where as $key => $value) {
            $sql .= $key.' = "'.$this->escapeSql($value).'" AND ';
        } 
        $sql = substr($sql,0,-4);
        
        $this->executeSql($sql,$error); 
        if($error == '') {
            if($this->audit) $this->auditAction('UPDATE',$table,$rec,$where);
            
            return true;
        } else {
            return false;
        }  
    }
    
    public function insertRecord($table,$rec = array(),&$error) 
    {
        $error = '';
        $fields = '';
        $values = '';
                
        foreach($rec as $key => $value) {
            $fields .= $key.',';
            $values .= '"'.$this->escapeSql($value).'",';
        }
        $fields = '('.substr($fields,0,-1).')';
        $values = '('.substr($values,0,-1).')';
        $sql = 'INSERT INTO `'.$table.'` '.$fields.' VALUES '.$values;      
        
        $this->executeSql($sql,$error); 
        if($error === '') {
            $insert_id = $this->db->insert_id;
            if($this->audit) $this->auditAction('INSERT',$table,$rec);
            
            return $insert_id;
        } else {
            return false;
        } 
    }
    
    
    //updates a record, and inserts if no record existed to update
    public function updateInsertRecord($table,$rec = array(),$where = array(),$options = array(),&$error) {
        $error = '';
        
        $this->updateRecord($table,$rec,$where,$error);
        if($error === '') {
            //check if any record updated/exists
            if($this->db->affected_rows == 0) {
                //add any insert specific vaues
                if(isset($options['insert_add'])) {
                    foreach($options['insert_add'] as $key => $value) {
                        $rec[$key] = $value; 
                    }  
                } 
                //assign where key identifiers to record
                foreach($where as $key => $value) {
                    $rec[$key] = $value; 
                }  
                $this->insertRecord($table,$rec,$error);
            }  
        }
        
        if($error === '') return true; else return false;  
    } 
 
    public function checkTableExists($table) {
        $sql = 'show tables like "'.$this->escapeSql($table).'" ';
        $result = $this->readSqlList($sql);
        if($result === 0) {
            $exists = false; 
        } else {
            $exists = true;
        }  
        
        return $exists;
    } 

    public function executeSql($sql,&$error) 
    {
        $error = '';
        
        if($this->db->query($sql) === false) {
            $error = 'MYSQL_EXECUTE_ERROR:';
            if($this->debug) $error .= 'Error['.$this->db->error.'] for SQL['.$sql.']';
            return false;
        } else {
            return $this->db->affected_rows;
        }
    }

    public function readSql($sql)
    {
        $this->checkSql($sql,'READ');

        $result = $this->db->query($sql);
        if($result === false) {
            $error = 'MYSQL_READ_ERROR:';
            if($this->debug) $error .= 'Error['.$this->db->error.'] for SQL['.$sql.']';

            throw new Exception($error); 
        }

        return $result;
    }


    public function readSqlResult($sql) 
    {
        $result = $this->readSql($sql);
        if($result->num_rows == 0) {
            $result->free();
            $result = 0;
        } 

        //NB: $result->free(); must be in calling code where $result!=0
        return $result;
    }


    public function readSqlValue($sql,$no_value = 0) 
    {
        $result = $this->readSql($sql);
        If($result->num_rows == 0) {
            $value = $no_value;
        } else {
            $row = $result->fetch_array(MYSQLI_NUM);
            $value = $row[0];
            //when SQL has SUM() or other group function and no matching records a record is returned with null value
            if($value === null) $value = $no_value;
        }
        $result->free();

        return $value;
    }

    public function readSqlList($sql) 
    {
        $result = $this->readSql($sql);
        If($result->num_rows == 0) {
            $list = 0;
        } else {
            $col_count = $result->field_count;
            $list = array();
            while($row = $result->fetch_array(MYSQLI_NUM)) {
                if($col_count > 1) $list[$row[0]] = $row[1]; else $list[] = $row[0];
            }
        }
        $result->free();

        return $list;
    }

    //creates an array of records with key = first col value...each record is an associative array  
    public function readSqlArray($sql,$first_col_key = true) 
    {
        $result = $this->readSql($sql);
        if($result->num_rows == 0) {
            $array = 0;
        } else {
            $col_count = $result->field_count;
            $array = array();
            while($row = $result->fetch_array(MYSQLI_ASSOC)) {
                if($col_count > 1) {
                    if($first_col_key) {
                        $key = array_shift($row);
                        $array[$key] = $row; 
                    } else {
                        $array[] = $row;
                    }   
                } else {
                    $array[] = $row[0];
                } 
            }
        }
        $result->free();

        return $array;
    }

    //NB: assumed that any values in $sql have been correctly escaped. If you are at all unsure then use get_record() 
    public function readSqlRecord($sql) 
    {
        $result = $this->readSql($sql);
        if($result->num_rows == 0) {
            $record = 0;
        } else {
            $record = $result->fetch_array(MYSQLI_ASSOC);
        }
        $result->free();

        return $record;
    }

    public function closeMysql() 
    {
        if($this->db) return $this->db->close();

        return false;
    }

    public function escapeSql($value) 
    {
        if(!is_numeric($value)) {
            $value = $this->db->real_escape_string($value);
        }
        return $value;
    }

    public function checkSql($sql,$sql_type = 'READ') 
    {
        $error = '';
        $prevent = ['UPDATE','DELETE','INSERT','REPLACE'];
        
        if($sql === '') {
            $error .= 'Empty SQL statement';
        } else {
            if($sql_type === 'READ') {
                $str = strtoupper($sql);
                foreach($prevent as $cmd) {
                    //first look for command folowed by space
                    $pos = strpos($str,$cmd.' ');
                    if($pos !== false)  {
                        //if command at beginning of statement or preceded by space or ;
                        if($pos === 0 or $str[$pos-1] === ' ' or $str[$pos-1] === ';') {
                            $error .= $cmd.' command called in a READ ONLY function ';
                        }    
                    }    
                }
            }
        }

        if($error !== '') {
            $error = 'MYSQL_READ_ERROR:'.$error;
            if($this->debug) $error .= ' for SQL['.$sql.']';
            throw new Exception($error);
        }
    }

    public function auditAction($action,$table,$rec = [],$where = [])
    {
        $audit = false;
        $error = '';

        if($this->audit !== false and $table !== $this->audit_table) {
            if(in_array($table,$this->audit_table_exclude)) {
                $audit = $false; 
            } else {
                $audit = true;
            }
        }

        if($audit) {
            $text = json_encode($rec);
            if(count($where)) $text .= ' WHERE '.json_encode($where);

            $data = array();
            $data[$this->audit_cols['user_id']] = $this->audit_user_id;
            $data[$this->audit_cols['date']] = date("Y-m-d H:i:s");
            $data[$this->audit_cols['action']] = strtoupper($action.'_'.$table);
            $data[$this->audit_cols['text']] = $text;
            
            $this->insertRecord($this->audit_table,$data,$error);
            if($error !== '') throw new Exception('MYSQL_AUDIT_ERROR: Err['.$error.']');
        }  
    }

    //copy all data from one db to another
    public function mysqlCopy($from_db = [],$to_db = [],&$error) {
        $error = '';
        
        $command = 'mysqldump --opt --skip-extended-insert --compress --host='.$from_db['host'].' --user='.$from_db['user'].' '.
                   '--password='.$from_db['password'].' '.$from_db['name'].' | '.
                   'mysql --one-database --compress --host='.$to_db['host'].' --user='.$to_db['user'].' '.
                   '--password='.$to_db['password'].' '.$to_db['name'].' ';
            
        system($command,$return_val);
        if($return_val === false) $error = 'Error executing command..."'.$command.'" ';
        
        if($error=='') return true; else return false;
    }
    
    //parse sql file into single command array
    public function parseSqlFile($file_path,&$sql_array,&$error) {
        $error = '';

        $sql = '';
        $sql_array = [];
        
        if(file_exists($file_path) === false) {
            $error = 'File does not exist!';
            return false;
        }
        
        $file_str = file_get_contents($file_path);
        $file_array = explode("\n",$file_str);
        unset($file_str);
                
        foreach($file_array as $line)  {    
            $line = trim($line);
            if($line != '' and substr($line, 0, 2) !== '--') {       
                $sql .= $line.' ';
                if(substr($line, -1, 1) === ';') { 
                    $sql_array[] = $sql;            
                    $sql = '';
                }  
            }
        }
        unset($file_array);

        if($error == '') return true; else return false;
    }

    public function importSqlArray(&$sql_array,&$message_str,&$error) {
        $error = '';
        $error_tmp = '';
        $message_str = '';
        $time_stamp = time();
        $time_max = ini_get('max_execution_time');
        if($time_max == '') $time_max = 30;
        
        
        foreach($db_array as $db) {
            if(Calc::checkTimeout($time_stamp,$time_max,5))  {
                //set_time_limit($time_max); this will restart the clock if necessary
                $error .= 'SCRIPT TIMEOUT EMINENT! execution suspended!';
                return false;
            }

            $e = 0;
            foreach($sql_array as $sql)  {
                $this->executeSql($sql,$error_tmp);
                if($error_tmp !== '') {
                    $e++;
                    $error .= 'SQL error['.$error_tmp.'] for statement['.$sql.']<br/>'; 
                } 
            }
        
            if($e == 0) $message_str .= 'SUCCESS! All commands imported!'; else $message_str.='FAILURE, see errors!<br/>';
        }
        
        if($error === '') return true; else return false;
    }

}