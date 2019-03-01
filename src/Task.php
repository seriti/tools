<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Date;
use Seriti\Tools\DbInterface;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;
use Seriti\Tools\ContainerHelpers;

use Psr\Container\ContainerInterface;

class Task {

    use IconsClassesLinks;
    use MessageHelpers;
    use ContainerHelpers;

    private $container;
    protected $container_allow = ['s3','mail','user','system','cache'];

    protected $db;
    protected $debug = false;
    protected $mode = 'list';
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();
    protected $tasks = array();
    protected $list_header = '';
  
     
    public function __construct(DbInterface $db, ContainerInterface $container, $param = array()) 
    {
        $this->db = $db;
        $this->container = $container;
                
        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;
    }
    
    //default function to handle all processing and views
    public function processTasks() 
    {
        $html = '';
        $param = array();
        $id = 0;
            
        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        if(isset($_GET['id'])) $id = Secure::clean('basic',$_GET['id']); 
        if(isset($_POST['id'])) $id = Secure::clean('basic',$_POST['id']);
       
        if($this->mode === 'task') {
            if(!isset($this->tasks[$id])) {
                $this->addError('INVALID Task ID['.$id.'] specified!'); 
            } else {
                foreach($_POST as $key => $value) {
                    $param[$key] = Secure::clean('string',$value); 
                }  
                //get task parameters and process
                $task = $this->tasks[$id];
                $this->processTask($id,$param);
                //insert any required javascript if task is ajax related
                if(!$this->errors_found and $task['param']['ajax']) {
                    $html .= $this->insertAjax($id,$task['param']);
                } else {
                    //$html.=var_dump($task); 
                }    
            }
        } 
        
        if($this->mode === 'list') {
          $html .= $this->viewTasks();
        } else {
          $html .= '<h1>'.$task['title'].': (<a href="?mode=list">return</a> to task list)</h1>';
        }  
        
        $html .= $this->viewMessages();
                
        return $html;
    }

    public function addTask($id,$title,$param = array()) 
    {
        if(!isset($param['ajax'])) $param['ajax'] = false;
        if(!isset($param['wizard'])) $param['wizard'] = false;
        if(!isset($param['separator'])) $param['separator'] = false;
        
        $this->tasks[$id] = array('id'=>$id,'title'=>$title,'param'=>$param);
    } 
    
    public function viewTasks() 
    {
        $html = '';
        
        if(count($this->tasks) != 0) {
            $html .= '<div id="task_div">'.$this->list_header.
                     '<ul class="'.$this->classes['list'].'">';
            foreach($this->tasks as $id => $task) {
                $task_html = $this->viewTask($id,$task);
                if($task_html == '') {
                    $task_html = '<a href="?mode=task&id='.$task['id'].'">'.$task['title'].'</a>';
                    if($task['param']['separator']) $task_html .= '<hr/>';
                }  
                
                $html .= '<li class="'.$this->classes['list_item'].'">'.$task_html.'</li>';  
            }  
          $html .= '</ul></div>';
        }  
            
        return $html;
    }

    public function insertAjax($id,$param=array()) 
    {
        $js = '';
        
        if(isset($param['ajax_custom'])) {
            $js .= $param['ajax_custom'];
        } else {
            $js .= '<script type="text/javascript">
                    server_task=new Object(); 
                    server_task.url="'.$param['url'].'";
                    server_task.flag_complete="'.$param['flag_complete'].'";
                    server_task.progress_div="'.$param['div_progress'].'";
                    server_task.run_counter=0;
                    server_task.run_limit='.$param['run_limit'].';
                    server_task.param="";
       
                    function server_task_setup() {
                      server_task.param="task_id='.$id.'";
                        
                      var div=document.getElementById(server_task.progress_div);
                      div.innerHTML="Processing task '.$id.'....";
                      document.body.style.cursor = "progress";
                      
                      server_task_run();
                    }
       
                    function server_task_run() {
                      var param=server_task.param+"&counter="+server_task.run_counter;
                      xhr(server_task.url,param,server_task_complete,server_task.progress_div);
                    }  
       
                    function server_task_complete(response_text,div_id) {
                      var error_txt="";
                      var show_txt="";
                      var finished=false;
                      
                      if(response_text.indexOf("ERROR")==0) error_txt=response_text;
                      if(response_text.indexOf(server_task.flag_complete)==0) finished=true;
                        
                      server_task.run_counter+=1;
                      if(server_task.run_counter>=server_task.run_limit) {
                        error_txt="** RUN LIMIT["+server_task.run_limit+"-batches] exceeded! ***<br/>"+
                                  "Simply process task again to process items not completed in this run";
                        response_text+="<br/>"+error_txt;
                      } 
                      var div=document.getElementById(div_id);
                      div.innerHTML=div.innerHTML+"<br/>Task batch["+server_task.run_counter+"]<br/>"+response_text;
                            
                      if(!finished && error_txt=="") {
                        server_task_run();
                      } else {
                        server_task.run_counter=0;
                        document.body.style.cursor = "default";
                      } 
                    }  
                    </script>'; 
        }  
              
        return $js;      
    }

    /*** EVENT PLACEHOLDER FUNCTIONS ***/
    public function viewTask($id,$param = []){}
    public function processTask($id,$param = []) {} 
}

