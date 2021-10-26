<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Crypt;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Audit;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\TableStructures;

use Psr\Container\ContainerInterface;

class Queue extends Model 
{
    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use TableStructures;

    protected $container;
    protected $container_allow = [];
   
    public function __construct(DbInterface $db, ContainerInterface $container, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;

        //$this->setup($param);
    }
                                                     
    protected $items_added = 0; //new queue items added
    protected $items_exist = 0; //items that already existed s0 addding not necessary 
    protected $items_processed = 0; 
    protected $items_completed = false;

    public function setup($param = array()) 
    {
        //add all standard queue_cols which MUST exist, NB: required=>false as many partial field updates
        $this->addCol(['id'=>$this->queue_cols['id'],'title'=>'Queue ID','type'=>'INTEGER','key'=>true,'key_auto'=>true,'list'=>true]);
        $this->addCol(['id'=>$this->queue_cols['process_id'],'title'=>'Process ID','type'=>'STRING']);
        $this->addCol(['id'=>$this->queue_cols['process_key'],'title'=>'Process Key','type'=>'STRING']);
        $this->addCol(['id'=>$this->queue_cols['process_data'],'title'=>'Process Data','type'=>'TEXT']);
        $this->addCol(['id'=>$this->queue_cols['date_create'],'title'=>'Date created','type'=>'DATETIME']);
        $this->addCol(['id'=>$this->queue_cols['date_process'],'title'=>'Date created','type'=>'DATETIME','required'=>false]);
        $this->addCol(['id'=>$this->queue_cols['process_status'],'title'=>'Process Status','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->queue_cols['process_result'],'title'=>'Process Result','type'=>'TEXT','required'=>false]);
        $this->addCol(['id'=>$this->queue_cols['process_complete'],'title'=>'Process Complete','type'=>'BOOLEAN']);
    }  
    
    public function getQueueInfo($info) 
    {
        if($info === 'COMPLETE') return $this->items_completed;
        if($info === 'ADDED') return $this->items_added;
        if($info === 'EXIST') return $this->items_exist;
        if($info === 'PROCESSED') return $this->items_processed;
        if($info === 'MESSAGES') return $this->viewMessages();
    }

    //process items in queue depending on param
    public function processQueue($process_id,$param=array()) 
    {
        $error = '';
        $queue = [];
        
        $sql = $this->processQueueSql($process_id,$param);
        $queue = $this->db->readSqlArray($sql);
        if($queue === 0) {
            $this->addMessage('Process['.$process_id.'] No queue items found to process!');
            $this->items_completed = true;
        } else {

            foreach($queue as $id => $item) {
                $item[$this->queue_cols['process_data']] = json_decode($item[$this->queue_cols['process_data']],true);
                
                $update = $this->processItem($id,$item);
                //queue may be updated in processItem(), in which case dont return anything or return false
                if(is_array($update)) {
                    if(!isset($update[$this->queue_cols['date_process']])) {
                        $update[$this->queue_cols['date_process']] = date('Y-m-d H:i:s');
                    }  
                    
                    //remove queue id if for some reason placed in update array
                    if(isset($update[$this->queue_cols['id']])) unset($update[$this->queue_cols['id']]);
                    
                    $result = $this->update($id,$update);

                    if($result['status'] === 'OK') {
                       $this->items_processed++;
                    } else {
                       $error = 'SYSTEM_QUEUE_ERROR: could not update queue item';
                       if($this->debug) $error .= 'Process['.$process_id.'] Item['.$id.']: '.implode(',',$result['errors']);
                       throw new Exception($error);
                    }
                }   
            }  
        }    
    }
    
    public function processQueueSql($process_id,$param) 
    {
        if(!isset($param['max_items'])) $param['max_items'] = 100;
        if(!isset($param['process_id_match'])) $param['process_id_match'] = 'EXACT';
        
        $process_id = $this->db->escapeSql($process_id);
                
        //NB: first field in query must be a unique identifier! 
        $sql='SELECT T.`'.$this->queue_cols['id'].'` AS `id` , T.* '.
             'FROM `'.$this->table.'` AS T '.
             'WHERE T.`'.$this->queue_cols['process_complete'].'` = 0 AND '.
                   'T.`'.$this->queue_cols['process_status'].'` <> "ERROR" ';

        if($param['process_id_match'] === 'EXACT') {
            $sql.=' AND `'.$this->queue_cols['process_id'].'` = "'.$process_id.'" ';      
        }
        if($param['process_id_match'] === 'BEGIN') {
            $sql .= ' AND `'.$this->queue_cols['process_id'].'` LIKE "'.$process_id.'%" ';      
        }
        if($param['process_id_match'] === 'END') {
            $sql .= ' AND `'.$this->queue_cols['process_id'].'` LIKE "%'.$process_id.'" ';      
        } 

        $sql .= 'ORDER BY `'.$this->queue_cols['id'].'` LIMIT '.$param['max_items'];
        return $sql;
    }
    
    public function addItem($process_id,$process_key,$process_data,$process_status) 
    {
        $item = [];
        $error = '';
        
        //first test if process_key exists already before adding
        $sql='SELECT COUNT(*) FROM `'.$this->table.'` '.
             'WHERE `'.$this->queue_cols['process_id'].'` = "'.$this->db->escapeSql($process_id).'" AND '.
                    '`'.$this->queue_cols['process_key'].'` = "'.$this->db->escapeSql($process_key).'" ';
        $count = $this->db->readSqlValue($sql,0);
        if($count == 0) {     
            $item[$this->queue_cols['process_id']] = $process_id;
            $item[$this->queue_cols['process_key']] = $process_key;
            $item[$this->queue_cols['process_data']] = json_encode($process_data);
            $item[$this->queue_cols['process_status']] = $process_status;
            $item[$this->queue_cols['date_create']] = date('Y-m-d H:i:s');

            $result = $this->create($item);
            if($result['status'] === 'OK') {
               $this->items_added++; 
            } else {
               $error = 'SYSTEM_QUEUE_ERROR: could not add queue item ';
               if($this->debug) $error .= 'Process['.$process_id.'] Key['.$process_key.']: '.implode(',',$result['errors']);
               throw new Exception($error);
            }
 
        } else {
            $this->items_exist++;
            $this->addMessage('Queue['.$process_id.'] item['.$process_key.'] already in queue.');  
        }   
    }
    
    public function getItem($queue_id) 
    {
        $item = $this->get($queue_id);

        if($item === 0) {
            $this->addError('Could not get queue item['.$queue_id.']');
        } else {
            //convert queue_data from json to php array
            $item[$this->queue_cols['process_data']] = json_decode($item[$this->queue_cols['process_data']],true);
        }
    } 
   
    
    /*** EVENT PLACEHOLDER FUNCTIONS ***/
    public function processItem($id,$item = array()) { return false;}
    
}
