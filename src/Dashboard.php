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

class Dashboard {

    use IconsClassesLinks;
    use MessageHelpers;
    use ContainerHelpers;

    protected $container;
    protected $container_allow = ['s3','mail','user','system'];

    //store all form data here
    protected $form = array();
    //store any non-form data here
    protected $data = array();
    //current wizard page no
    protected $page_no = 1;
    //next wizard page assuming no errors 
    protected $page_no_next = 0;
        
    protected $db;
    //wizard template variable names used
    //protected $template = array('data'=>'data','form'=>'form','errors'=>'errors','messages'=>'messages');
    protected $mode = 'list';
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    protected $blocks = [];
    protected $items = [];

    protected $col_count = 3;
    protected $row_count = 1;

    //TODO, add access control per user. 
    public function __construct(DbInterface $db, ContainerInterface $container) 
    {
        $this->db = $db;
        $this->container = $container;
                
        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;
    }
    
    public function addBlock($id,$col,$row,$title,$param = array()) 
    {
        if($row > $this->row_count) $this->row_count = $row;
        $this->blocks[$col][$row] = ['id'=>$id,'title'=>$title];
    }

    public function addItem($block_id,$title,$param = array()) 
    {
        if(!isset($param['link'])) $param['link'] = '#';
        if(!isset($param['icon'])) $param['icon'] = false;
        if(!isset($param['separator'])) $param['separator'] = false;
        
        $this->Items[$block_id][] = array('title'=>$title,'param'=>$param);
    } 
    
    public function viewBlocks() 
    {
        $html = '<div class="container">';
        for($row = 1; $row<=$this->row_count; $row++) {
            $html .= '<div class="row">';

            $class_col = 'col-sm-'.floor(12/$this->col_count);
            for($col = 1; $col<=$this->col_count; $col++) {
                $html .= '<div class="'.$class_col.'">';
                if(isset($this->blocks[$col][$row])) {
                    $block = $this->blocks[$col][$row];
                    $block_id = $block['id'];
                    $html .= '<h2>'.$block['title'].'</h2>'; 
                    $html .= $this->viewBlockItems($block_id);
                } 
                
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    protected function viewBlockItems($block_id) 
    {
        $html = '';

        $items = $this->Items[$block_id];
        
        if(count($items) != 0) {
            $html .= '<ul class="'.$this->classes['task_list'].'">';
            foreach($items as $item) {
                $item_html = $this->viewItem($block_id,$item);
                if($item_html == '') {
                    if($item['param']['separator']) {
                        $item_html .= $item['title'].'<hr/>';
                    } else {
                        $item_html .= '<a href="'.$item['param']['link'].'">';
                        if($item['param']['icon']) $item_html .= $this->icons[$item['param']['icon']];
                        $item_html .= $item['title'].'</a>';
                    }    
                }  
                
                $html .= '<li>'.$item_html.'</li>';  
            }  
          $html .= '</ul>';
        }  
            
        return $html;
    }
   

    /*** PLACEHOLDER FUNCTIONS ***/
    public function getJavaScript() {}
    protected function viewItem($id,$param = []){}
    
}

