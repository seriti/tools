<?php
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\Date;
use Seriti\Tools\Validate;
use Seriti\Tools\DbInterface;
use Seriti\Tools\System;
use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;

class SetupModuleData 
{
    use IconsClassesLinks;
    use MessageHelpers;

    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    protected $db;
    protected $system;
    protected $system_id;
    protected $debug = false;

    protected $module = '';
    protected $tables = [];
    protected $tables_sql = [];
    protected $initialise = [];
    protected $updates = [];
    protected $table_prefix = '';
    protected $process_count = 0;

    
    public function __construct(DbInterface $db, System $system, $module = []) 
    {
        if(!isset($module['name'])) throw new Exception('No module name specified');
        if(!isset($module['table_prefix'])) throw new Exception('No module table prefix specified');

        $this->db = $db;
        $this->system = $system;
        $this->module = strtoupper($module['name']);
        $this->system_id = 'MOD_'.$this->module;
        $this->table_prefix = $module['table_prefix'];
        
        if(defined('\Seriti\Tools\DEBUG')) $this->debug = \Seriti\Tools\DEBUG;

        $this->addMessage($this->module.' module data configuration');

    }

    public function process()
    {
        $html= '';

        $this->checkTables();
        $this->updateData();

        if($this->process_count === 0) $this->addMessage('No changes processed.');

        $html .= $this->viewMessages();
        return $html;
    }

    public function destroy()
    {
        $html= '';

        $this->destroyAllTables();
        
        $html .= $this->viewMessages();
        return $html;
    }
    
    protected function checkTables()
    {
        $sql = 'SHOW TABLES LIKE "'.$this->table_prefix.'%" ';
        $table_exist = $this->db->readSqlList($sql);
        $exist = '';

        if($table_exist !== 0) {
            foreach($this->tables as $table) {
                $table_name = $this->table_prefix.$table;
                if(!in_array($table_name,$table_exist)) {
                    $this->addMessage('Table['.$table_name.'] not present.');
                    $this->createTable($table);
                } else {
                    $exist .= $table_name.', ';
                }
            }
            $this->addMessage('Existing tables['.$exist.']');
        } else {
            $this->addMessage('NO '.$this->module.' Tables present.');
            $this->createAllTables();
            $this->initialiseData();
            $this->updateTimeStamp();
        }
    }  

    protected function processSql($sql,$action)
    {
        $error = '';
        if($sql === '') {
            $this->addError('No SQL statement for process['.$action.']');
        } else {
            $this->db->executeSql($sql,$error); 
            if($error == '') {
                $this->addMessage('Succesfully processed ['.$action.']');
                $this->process_count++;
                if($this->debug) $this->addMessage('SQL['.$sql.']');
            } else {
                $this->addError('Could NOT ['.$action.']');
                if($this->debug) $this->addError('SQL['.$sql.'] error['.$error.']');
            } 
        }

        if($error === '') return true; else return false;   
    }

    protected function updateTimeStamp($time = 0)
    {
        if(!$this->errors_found) {
            if($time === 0) {
                $date = getdate();
                $time = $date[0];
            }     
            $this->system->setDefault($this->system_id,$time,'count');
        }    
    }

    protected function destroyAllTables() 
    {
        foreach($this->tables as $table) $this->destroyTable($table);
    }

    protected function destroyTable($table)
    {
        $table_name = $this->table_prefix.$table;

        $sql = 'DROP TABLE IF EXISTS `'.$table_name.'` ';
        $this->processSql($sql,'destroy '.$table_name);
    }    

    protected function createAllTables() 
    {
        foreach($this->tables as $table) $this->createTable($table);
    }

    protected function updateData()
    {
        $last_update_time = $this->system->getDefault($this->system_id,0,'count');

        foreach($this->updates as $timestamp => $update) {
            $update_date = Date::mysqlGetDate($timestamp);
            
            if(!$this->errors_found and $update_date[0] > $last_update_time) {
                $this->processSql($update['sql'],'update['.$timestamp.'] '.$update['text']);
                //will not update if any errors in execution
                $this->updateTimeStamp($update_date[0]);
            }
        }
    }

    protected function insertTableName($table,$sql)
    {
        $table_name = $this->table_prefix.$table;
        $sql= str_replace('TABLE_NAME',$table_name,$sql);
        
        return $sql;
    }

    protected function insertTablePrefix($sql)
    {
        $sql= str_replace('TABLE_PREFIX',$this->table_prefix,$sql);

        return $sql;
    }

    protected function addCreateSql($table,$sql) 
    {
        if(!in_array($table,$this->tables)) $this->addError('Cannot add table'.$table.' create SQL. Table not in list!');
        if(strpos($sql,'TABLE_NAME') == false) $this->addError('Table['.$table.'] SQL, no "TABLE_NAME" placeholder in SQL!');

        if(!$this->errors_found) {
            $sql = $this->insertTableName($table,$sql);
            $sql = $this->insertTablePrefix($table,$sql);
            $this->tables_sql[$table] = $sql;
        }    
    }

    protected function createTable($table)
    {
        if(!isset($this->tables_sql[$table])) {
            $this->addError('Cannot create table['.$table.'] as no SQL statement exists!');
        } else {
            $sql = $this->tables_sql[$table];
            $this->processSql($sql,'create table['.$this->table_prefix.$table.']');
        }  
    }

    protected function addInitialSql($sql,$description = '') 
    {
        if(strpos($sql,'TABLE_NAME') !== false) $this->addError('"TABLE_NAME" place holder not valid in initial SQL, use "TABLE_PREFIX" instead.');

        if(!$this->errors_found) {
            $sql = $this->insertTablePrefix($sql);
            $this->initialise[] = ['sql'=>$sql,'text'=>$description];
        }    
    }

    protected function initialiseData() 
    {
        foreach($this->initialise as $data) {
           $this->processSql($data['sql'],'Initialise data: '.$data['text']); 
        }
    }

    protected function addUpdateSql($timestamp,$sql,$description = '') 
    {
        $error = '';        
        Validate::dateTime('Update timestamp',$timestamp,'YYYY-MM-DD HH:MM',$error);
        if($error != '') $this->addError($error);
        if(strpos($sql,'TABLE_NAME') !== false) $this->addError('"TABLE_NAME" place holder not valid in update SQL, use "TABLE_PREFIX" instead.');

        if(isset($this->updates[$timestamp])) $this->addError('Update SQL timestamp['.$timestamp.'] allready used modify hours or minutes accordingly.');

        //check consecutive time sequence!
        if(!$this->errors_found) {
            end($this->updates);
            $timestamp_prev = key($this->updates);
            $date_prev = Date::mysqlGetDate($timestamp_prev);
            $date = Date::mysqlGetDate($timestamp);

            if($date[0] <= $date_prev[0]) $this->addError('Update timestamp['.$timestamp.'] is not after previous timestamp['.$timestamp_prev.']');
        }    
     
        if(!$this->errors_found) {
            $sql = $this->insertTablePrefix($sql);
            $this->updates[$timestamp] = ['sql'=>$sql,'text'=>$description];
        }
    }


    

}


  
?>
