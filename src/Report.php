<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Form;
use Seriti\Tools\Secure;
use Seriti\Tools\Date;
use Seriti\Tools\DbInterface;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;
use Seriti\Tools\ContainerHelpers;

use Psr\Container\ContainerInterface;

class Report {

    use IconsClassesLinks;
    use MessageHelpers;
    use ContainerHelpers;

    //do NOT make private as Reports often require container to be passed to them
    protected $container;
    protected $container_allow = ['s3','mail','user','system'];

    protected $report_header = '';
    protected $report_select_title = 'Select report';
    protected $submit_title = 'View report';
    protected $submit_location = 'BOTTOM';
    protected $report = array();
    protected $report_list = array();
    protected $input = array();
    protected $form = array();

    protected $db;
    protected $debug = false;
    protected $mode = 'list';
    protected $always_list_reports = false; //make this true if you want to see report options with results.
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();
    protected $tasks = array();
  
     
    public function __construct(DbInterface $db, ContainerInterface $container, $param = array()) 
    {
        $this->db = $db;
        $this->container = $container;
                
        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;
    }
    
    //default function to handle all processing and views
    public function process() 
    {
        $html = '';
        $param = array();
        $id = 0;
        $this->mode = 'list';
            
        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        if(isset($_GET['report_id'])) $id = Secure::clean('basic',$_GET['report_id']); 
        if(isset($_POST['report_id'])) $id = Secure::clean('basic',$_POST['report_id']);
       
        if($this->mode === 'create') {
            if(!isset($this->report[$id])) {
                $this->addError('INVALID Report ID['.$id.'] specified!');
                $this->mode = 'list';
            } else {
                $report = $this->report[$id];
                foreach($_GET as $key=>$value) {
                    $this->form[$key] = Secure::clean('string',$value); 
                }  
                
                $report_html = $this->processReport($id,$this->form);
                if($this->errors_found) $this->mode = 'list';
            }
        } 
        
        if($this->mode === 'list' or $this->always_list_reports) {
            $html .= $this->viewReports();
        } 

        if($this->mode === 'create') {
            $html .= '<h1>'.$report['title'].': (<a href="?mode=list">'.
                     '<span class="glyphicon glyphicon-backward small"></span>back</a> to report list)</h1>'.
                     $report_html;
        }  
        
        $html = $this->viewMessages().$html;
                
        return $html;
    }

    protected function addReport($id,$title,$param = array()) 
    {
        //simple diplay separator
        if(!isset($param['separator'])) $param['separator'] = false;
        //array of input div id's to display for that report
        if(!isset($param['input'])) $param['input'] = [];
                
        $this->report[$id] = array('id'=>$id,'title'=>$title,'param'=>$param);
        $this->report_list[$id] = $title;
    } 
    
    protected function addInput($id,$title) 
    {
        $this->input[$id] = $title;
    }

    protected function viewReports() 
    {
        $html =  '<div id="report_div">';
        if($this->report_header !=='') $html .= '<h1>'.$this->report_header.'</h1>';

        $html .= '<form action="?mode=create" method="get" enctype="multipart/form-data" id="report_form">'.
                 '<input type="hidden" name="mode" value="create">';

        if($this->submit_location === 'TOP' or $this->submit_location === 'BOTH') {
            $this->report_select_title .= '&nbsp;<input class="'.$this->classes['button'].'" type="submit" name="submit_button_top" '.
                                           'id="submit_button_top" value="'.$this->submit_title.'" onclick="link_download(\'submit_button_top\');">';
        }       
        
        if(count($this->report) != 0) {
            $param = [];
            //$param['xtra'] = array('NONE'=>'Select report');
            $param['onchange'] = 'display_options();';
            $param['class'] = 'form-control input-medium';
            $list_assoc = true;
            if(isset($this->form['report_id'])) $report_id = $this->form['report_id']; else $report_id = '';
            $html .= '<h1>'.$this->report_select_title.'</h1>'.Form::arrayList($this->report_list,'report_id',$report_id,$list_assoc,$param);
        }  

        if(count($this->input) != 0) {
            //$html .= '<ul class="'.$this->classes['input_list'].'">';
            foreach($this->input as $id => $title) {
                $input_html = $this->viewInput($id,$this->form);
                if($input_html !== '') {
                    //$html .= '<div id="'.$id.'"><li>'.$input_html.'</li></div>';
                    $html .= '<div id="'.$id.'"><h2>'.$title.'</h2>'.$input_html.'</div>';
                }  
            }  
            //$html .= '</ul>';
        }  
        
        if($this->submit_location === 'BOTTOM' or $this->submit_location === 'BOTH') {
            $html .= '<input class="'.$this->classes['button'].'" type="submit" name="submit_button" '.
                      'id="submit_button" value="'.$this->submit_title.'" onclick="link_download(\'submit_button\');">';
        }              

        $html .= '</form></div>';

        return $html;
    }

    public function getJavascript() 
    {
        $js = '';
        
        $js .= '<script type="text/javascript">
                $(document).ready(function(){
                  display_options(); 
                });';

        $js .= 'function display_options() {
                    var form = document.getElementById("report_form");
                    var report_id = form.report_id.value;'."\r\n";

        if(count($this->input) != 0) {
            foreach($this->input as $id => $input) {
                $js .= 'var select_'.$id.' = document.getElementById("'.$id.'");'."\r\n";
            }
            foreach($this->input as $id => $input) {
                $js .= 'select_'.$id.'.style.display = "none";'."\r\n";
            }  
        }             
                         
        $js .= 'if(report_id != "") { ';
        
        foreach($this->report as $id => $report) {
            $input = $report['param']['input'];
            if(count($input) != 0) {
                $js .= 'if(report_id == "'.$id.'") {'."\r\n";
                foreach($input as $input_id) {
                    $js .= 'select_'.$input_id.'.style.display = "block";'."\r\n";
                }
                $js .= '}'."\r\n";  
            }
        }
                                 
        $js .= '    }  
                } 
                </script>'; 
         
              
        return $js;      
    }

    /*** PLACEHOLDER FUNCTIONS ***/
    protected function viewInput($id,$form = []) {}
    protected function processReport($id,$form = []) {} 
}

