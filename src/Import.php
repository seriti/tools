<?php
namespace Seriti\Tools;

use Exception;
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
use Seriti\Tools\DbInterface;

use Psr\Container\ContainerInterface;

class Import extends Model {

    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use TableStructures;
    use SecurityHelpers;
    
    private $container;
    protected $container_allow = ['mail','user'];

    protected $data_type = 'CSV';
    protected $audit = false;
    protected $update_existing = false;
        
    protected $max_rows = 100000;
    protected $col_label = '';
    protected $col_count = 0;
    protected $header = array();
    protected $line_prev = array();
    protected $line_prev_raw = array();
    protected $trim_values = true;
            
    protected $file_path = '';
    protected $dates = array('zero'=>'1900-01-01');
    protected $start_row = 1;

    protected $user_access_level;
    protected $user_id;
    protected $user_csrf_token;
    protected $csrf_token = '';
                                                
    public function __construct(DbInterface $db, ContainerInterface $container, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;
    } 

    public function setup($param) {
        $this->file_path = $param['file_path'];

        if(!file_exists($this->file_path)) {
           $this->addError('Import file does not exist!'); 
        }
                        
        if(isset($param['data_type'])) $this->data_type = $param['data_type'];
        if(isset($param['max_rows'])) $this->max_rows = $param['max_rows'];
        if(isset($param['audit'])) $this->audit = $param['audit'];  
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];  
        if(isset($param['trim_values'])) $this->trim_values = $param['trim_values'];  
                                                
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }
    
    public function addImportCol($col) 
    {
        $col = $this->addCol($col);

        if(!isset($col['class'])) $col['class'] = '';
        if(!isset($col['update'])) $col['update'] = false;
        if(!isset($col['confirm'])) $col['confirm'] = true;

        //allow update if record id exists
        if($col['update'] === true) $this->update_existing = true;  

        $this->cols[$col['id']] = $col;
        $this->col_count++;
    }

    //create data array from import file or confirm form depending on type
    public function createDataArray($data_type,&$data_array) 
    {
        $error = '';
        //echo 'WTF:'.$this->file_path.'<br/>';
       
        $data_array = [];
                
        if($data_type === 'CSV') {    
            if(file_exists($this->file_path) === false) {
                $error = 'Import file['.$this->file_path.'] does not exist!';
                return false;
            }
        }
        
        if($data_type === 'CSV') {
            $i = 0;
            $line = array();
            $handle = fopen($this->file_path,'r');
            while(($line = fgetcsv($handle,0, ",")) !== FALSE) {
                $i++;
                if($i >= $this->start_row) {
                    $value_num = count($line);
                    
                    if($this->trim_values) {
                        $line = array_map('trim',$line);
                    }    
                                                                    
                    if($i == $this->start_row) {//analyse header line for valid column titles
                        $c = 0;
                        foreach($this->cols as $id => $col) {
                            //strip BOM from first header row column if put there as UTF-8 marker by MS Excel
                            if($c === 0) {
                                $line[$c] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '',$line[$c]); 
                            }  
                            
                            if(strcasecmp($col['title'],trim($line[$c])) !== 0) {
                                $error .= 'First row column name['.$line[$c].'] is not valid! expecting['.$col['title'].']<br/>';
                            }  
                            $c++;
                        } 
                        
                        if($error !== '') {
                            $this->addError($error);
                            fclose($handle);
                            return false;
                        } 
                    } else {
                        if($value_num > 1) {//blank lines are returned as an array with single null field
                            $this->line_prev_raw = $line;  
                            
                            if($value_num < $this->col_count) { //allow for excel generated spurious additional columns
                                $this->addError('Line['.$i.'] number of values['.$value_num.'] invalid, '.$this->col_count.' values expected!');
                            } else {
                                $valid_line = true;
                                $error_line = false;
                                                        
                                //placeholder function to modify line values based on any criteria and/or error checking
                                $this->modifyCsvLine($line,$error_line,$valid_line);
                                if($valid_line) {
                                    $data = [];

                                    $c = 0;
                                    foreach($this->cols as $id => $col) {
                                        if($col['type'] !== 'IGNORE') {
                                            $value = Csv::csvStrip($line[$c]);
                                            
                                            if($value == '') {
                                                if($col['required'] and $col['type'] != 'BOOLEAN') {
                                                    $error_line = true;
                                                    $this->addError('['.$col['title'].'] is a required field!');
                                                }
                                            } else {  
                                                //validate() sometimes modifies value, ie: stripping out thousand separators
                                                if(!$this->validate($col['id'],$value)) $error_line = true;
                                            }  
                                            
                                            $data[$id] = $value;
                                        }
                                        $c++;  
                                    } 
                                    
                                    if($error_line) {
                                        $this->addError('Previous Errors found in Line['.$i.']');
                                    } else {
                                        $data_array[] = $data;                                  
                                    }
                                }
                            }
                        } 
                        //keep previous line for use by modifyCsvLine() if required
                        $this->line_prev = $line;  
                    } 
                } 
            }
            fclose($handle);
        }
        
        if($data_type === 'CONFIRM_FORM') {
            $row_count = $_POST['row_count'];
            if(!is_numeric($row_count)) $this->addError('Invalid row count!');
            
            for($i = 1; $i <= $row_count; $i++) {
                $row = array();
                $valid_row = true;
                $error_row = false;
                                
                $name = 'ignore_'.$i;
                if(isset($_POST[$name])) $valid_row = false;
                
                //get row values from form
                foreach($this->cols as $id => $col) {
                    if($col['type'] !== 'IGNORE') {
                        $name = $id.'_'.$i;
                        //NB: checkbox value is not set if not checked
                        if(isset($_POST[$name])) $row[$id] = $_POST[$name]; else $row[$id]='';
                    }    
                }  
                
                //placeholder function to modify row values based on any criteria and/or error checking
                $this->modifyConfirmRow($row,$error_row,$valid_row);
                
                if($valid_row) {
                    $data = [];
                    
                    foreach($this->cols as $id=>$col) {
                        if($col['type'] !== 'IGNORE') {
                            $value = $row[$id];
                                                    
                            if($value == '') {
                                if($col['required'] and $col['type'] != 'BOOLEAN') {
                                    $error_row = true;
                                    $this->addError('['.$col['title'].'] is a required field!');
                                }
                            } else {  
                                //validate() sometimes modifies value, ie: stripping out thousand separators
                                if(!$this->validate($col['id'],$value)) $error_row = true;
                            }  
                            
                            $data[$id] = $value;        
                        }    
                    } 
                  
                    if($error_row) {
                        $this->addError('Previous Errors found in Row['.$i.']');
                    } else {
                        $data_array[] = $data; 
                        $this->afterRowConfirmed($row);                                 
                    }
                }  
            }  
        }
                
        if(!$this->errors_found) return true; else return false;
    }

    public function importDataArray(&$data_array) {
        $i = 0;
        $e = 0;
        $error = '';
        $message_str = '';
        
        
        foreach($data_array as $key => $data) {
            $valid = true;
            $this->beforeImportData($data,$valid);

            if($valid) {
                $exists = false;

                if($this->update_existing and $data[$this->key['id']])  {
                    $update_id = $data[$this->key['id']];
                    $record = $this->get($update_id);
                    if($record !== 0) {
                        $exists = true;
                        $update = [];
                        foreach($this->cols as $id => $col) {
                            if($col['update']) $update[$id] = $data[$id];
                        }    
                    }    
                }  

                if(!$exists) {
                    $output = $this->create($data);
                } else {
                    $output = $this->update($update_id,$update);
                }
                
                if($output['status'] !== 'OK') {
                    $e++;
                    $this->addError('Could not import data from array['.$key.']');
                } else {
                    $i++;
                }
            }    
        }
        
        if($e !== 0) $message_str .= 'Import errors for ['.$e.'] records. ';
        if($i !== 0) $message_str .= 'Successfuly imported ['.$i.'] records.';

        $this->addMessage($message_str); 
            
        if($this->audit) Audit::action($this->db,$this->user_id,$this->table.'_IMPORT',$message_str);

        if($e === 0) return true; else return false;
    }
    
    //for import review/confirm 
    protected function viewConfirmValue($row_no,$col_id,$value)  {
        $html = '';
        $param = [];
        
        $name = $col_id.'_'.$row_no;
        
        $col=$this->cols[$col_id];
        if($col['class']  === '') $param['class'] = $this->classes['edit']; else $param['class'] = $col['class'];
        
        if($col['confirm'] === false) {
            $html .= '<input type="hidden" name="'.$name.'" value="'.$value.'">'.$value;
            return $html;
        }  

        //assign any event code specified
        if(isset($col['onchange'])) $param['onchange']=$col['onchange'];
        if(isset($col['onkeyup'])) $param['onkeyup']=$col['onkeyup'];
        if(isset($col['onblur'])) $param['onblur']=$col['onblur'];
                    
        if(isset($this->select[$col_id]) and $this->select[$col_id]['edit'] == true) {
            if(isset($this->select[$col_id]['onchange'])) $param['onchange'] = $this->select[$col_id]['onchange'];
            if(isset($this->select[$col_id]['xtra'])) $param['xtra'] = $this->select[$col_id]['xtra'];
            
            if(isset($this->select[$col_id]['sql'])) {
                $html .= Form::sqlList($this->select[$col_id]['sql'],$this->db,$name,$value,$param);
            } elseif(isset($this->select[$col_id]['list'])) { 
                $html .= Form::arrayList($this->select[$col_id]['list'],$name,$value,$this->select[$col_id]['list_assoc'],$param);
            }
        } else {
            switch($col['type']) {
                case 'STRING' : {
                    if($col['secure']) $value = Secure::clean('string',$value);
                    $html .= Form::textInput($name,$value,$param);
                    break;
                }
                case 'PASSWORD' : {
                    $value = Secure::clean('string',$value);
                    $html .= Form::textInput($name,$value,$param);
                    break;  
                }
                case 'INTEGER' : {
                    $value = Secure::clean('integer',$value);
                    $html .= Form::textInput($name,$value,$param);
                    break;
                }
                case 'DECIMAL' : {
                    $value = Secure::clean('float',$value);
                    $html .= Form::textInput($name,$value,$param);
                    break;
                }
                case 'TEXT' : {
                    if($col['secure']) {
                        if($col['html']) {
                            $value = Secure::clean('html',$value);
                        } else {
                            $value = Secure::clean('text',$value);
                        }
                    } 
                    if($param['class'] === 'HTMLeditor') $col['rows'] += 3; 
                    $html .= Form::textAreaInput($name,$value,$col['cols'],$col['rows'],$param);
                    break;
                }
                case 'DATE' : {
                    if($value == '') $this->dates['new'];
                    if($value == '0000-00-00') $value = $this->dates['zero'];
                    $value=Secure::clean('date',$value);
                    if($this->classes['date'] != '') $param['class'] .= ' '.$this->classes['date'];
                    $html .= Form::textInput($name,$value,$param);
                    break;
                }
                case 'DATETIME' : {
                    if($value == '') $value = date('Y-m-d H:i:s');
                    if($value == '0000-00-00 00:00:00') $value = $this->dates['zero'].' 00:00:00';
                    $value=Secure::clean('date',$value);
                    if($this->classes['date'] != '') $param['class'] .= ' '.$this->classes['date'];
                    $html .= '<table cellpadding="0" cellspacing="0"><tr>'.
                             '<td>'.Form::textInput($name,substr($value,0,10),$param).'</td>'.
                             '<td>@ <input style="display:inline;" type="text" name="'.$name.'_time" value="'.substr($value,11,8).'" class="'.$this->classes['time'].'"></td>'.
                             '</tr></table>';
                    break;
                }
                case 'TIME' : {
                    if($value == '') $value = date('H:i:s');
                    $value = Secure::clean('string',$value);
                    if($col['format'] == 'HH:MM') $value = substr($value,0,5);
                    $html .= Form::textInput($name,$value,$param);
                    break;
                } 
                case 'EMAIL' : {
                    $value = Secure::clean('email',$value);
                    $html .= Form::textInput($name,$value,$param);
                    break;
                }
                case 'URL' : {
                    $value = Secure::clean('url',$value);
                    $html .= Form::textInput($name,$value,$param);
                    break;
                } 
                case 'BOOLEAN' : {
                    $html .= Form::checkBox($name,'1',$value,$param);
                    break;   
                }
                
                default : {
                    $value = Secure::clean('string',$value);
                    $html .= Form::textInput($name,$value,$param);
                }   
            } 
        }
                
        return $html;
    }
    
    public function viewConfirm($data_type,$param = [],&$error) {
        $error = '';

        $html = '<table class="'.$this->classes['table'].'">';

        if($data_type === 'CSV') {
            $i = 0;
            $row_no = 0;
            $line = [];
            $handle = fopen($this->file_path,'r');
            while(($line = fgetcsv($handle,0, ",")) !== FALSE) {
                $i++;
                $value_num = count($line);
                
                //print_r($line);
                if($this->trim_values) {
                    $line = array_map('trim',$line);
                }

                if($i === 1) {//analyse header line for valid column titles
                    $html .= '<tr class="thead"><th>Ignore</th>';
                    $c = 0;
                    foreach($this->cols as $id => $col) {
                        if($col['type'] !== 'IGNORE') {
                            if(strcasecmp($col['title'],trim($line[$c])) != 0) {
                                $error .= 'First row column name['.$line[$c].'] is not valid! expecting['.$col['title'].']<br/>';
                            } else {
                                $html .= '<th>'.$col['title'].'</th>'; 
                            }
                        }       
                        $c++;
                    } 
                    $html .= '</tr>';
                    
                    if($error !== '') {
                        $this->addError($error);
                        fclose($handle);
                        return false;
                    } 
                } else {
                    if($value_num > 1) {//blank lines are returned as an array with single null field
                                             
                        if($value_num < $this->col_count) { //allow for excel generated spurious additional columns
                            $this->addError('Line['.$i.'] number of values['.$value_num.'] invalid, '.$this->col_count.' values expected!');
                        } else {
                            $row_no++;
                            $checked = false;
                            $html .= '<tr><td>'.Form::checkBox('ignore_'.$row_no,'YES',$checked).'</td>';
                            $c=0;
                            foreach($this->cols as $id => $col) {
                                if($col['type'] !== 'IGNORE') {
                                    $value = Csv::csvStrip($line[$c]);
                                    if($col['type'] == 'DECIMAL') $align = 'align="right"'; else $align = '';
                                    $html .= '<td '.$align.'>'.$this->viewConfirmValue($row_no,$id,$value).'</td>';                  
                                }
                                $c++;  
                            } 
                            $html .= '</tr>';
                        }
                    } 
                }  
            }
            fclose($handle); 
        }     
        
        $html .= '</table>';
        $html .= '<input type="hidden" name="row_count" value="'.$row_no.'">';
        return $html;
    }  

    //placeholder functions for custom modifications
    protected function modifyCsvLine(&$line,&$error_line,&$valid_line) {}
    protected function modifyConfirmRow(&$row,&$error_row,&$valid_row) {}
    protected function afterRowConfirmed($row) {}
    protected function beforeImportData(&$data,&$valid) {}
    
}
