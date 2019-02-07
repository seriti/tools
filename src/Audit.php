<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\TableStructures;

class Audit 
{
    use TableStructures;

    protected $table = TABLE_AUDIT;
    protected $table_task = TABLE_AUDIT;
    protected $debug = false;

    protected function __construct($param = [])
    {
        if(isset($param['debug'])) {
            $this->debug = $param['debug'];
        } elseif(defined(__NAMESPACE__.'\DEBUG')) {
            $this->debug = DEBUG;
        } 
    }

    public static function action($db,$user_id = 0,$action,$description) 
    {
        $obj = new static();
        $error = '';
        $error_tmp = '';

        $rec = array();
        $c = $obj->audit_cols;
        $rec[$c['user_id']] = $user_id;
        $rec[$c['date']] = date("Y-m-d H:i:s");
        $rec[$c['action']] = $action;
        $rec[$c['text']] = $description;

        $db->insertRecord($obj->table,$rec,$error_tmp);
        if($error_tmp !== '') {
            $error = 'AUDIT_ERROR: Could not create audit record. ';
            if($obj->debug) $error .= $error_tmp;
            throw new Exception($error);
        }    
    }
    
    public static function task($db,$user_id = 0,$task_id,$action,$description,$data=array(),$link_table='',$link_id='') 
    {
        $obj = new static();
        $error = '';
        $error_tmp = '';

        $rec = array();
        $c = $obj->audit_cols;
        $rec[$c['user_id']] = $user_id;
        $rec[$c['date']] = date("Y-m-d H:i:s");
        $rec[$c['action']] = $action;
        $rec[$c['text']] = $description;
        $rec[$c['data']] = json_encode($data);
        $rec[$c['link']] = $link_table;
        $rec[$c['link_id']] = $link_id;

        $db->insertRecord($obj->table_task,$rec,$error_tmp); 
        if($error_tmp !== '') {
            $error = 'AUDIT_ERROR: Could not create task audit record. ';
            if($obj->debug) $error .= $error_tmp;
            throw new Exception($error);
        }  
    }
}
