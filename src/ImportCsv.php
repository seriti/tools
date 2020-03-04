<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Cache;
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
    
    protected $container;
    protected $container_allow = ['mail','user','cache'];

    protected $audit = false;
    protected $update_existing = false;
    protected $cache;
    protected $form;
        
    protected $max_rows = 100000;
    protected $col_label = '';
    protected $max_cols = 0;

    protected $header = array();
    protected $line_prev = array();
    protected $line_prev_raw = array();
    protected $trim_values = true;
            
    protected $file_path = '';
    protected $dates = array('zero'=>'1900-01-01');
    protected $start_row = 1;
    protected $ignore_if_first_col_blank = true;

    //to select which table col matches which csv col
    protected $test = false;
    protected $col_select;
    protected $col_id;
    //'MERGE-LN' will append string value to previous value
    protected $col_types = ['IGNORE','DATE-YYYY-MM-DD','DATE-YYYYMMDD','BOOLEAN','STRING','TEXT','INTEGER','DECIMAL','CURRENCY','MERGE-LN'];

    protected $user_access_level;
    protected $user_id;
    protected $user_csrf_token;
    protected $csrf_token = '';
                                                
    public function __construct(DbInterface $db, ContainerInterface $container, Cache $cache, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;

        $this->cache = $cache;
        
        //setup cache to store import choices for table
        $user_specific = true;
        $cache_name = 'import_csv_'.$table;
        $this->cache->setCache($cache_name,$user_specific);
    } 

    public function process($param) {
        $html = '';

        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));

        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);

        if($this->mode === 'link_form') $html .= $this->getLinkForm();

        if($this->mode === 'import') $html .= $this->importCsvData();
    }    

    public function setup($param) {
        $this->file_path = $param['file_path'];

        if(!file_exists($this->file_path)) {
           $this->addError('Import file does not exist!'); 
        }

        //construct select column array for table
        $sql = 'SHOW COLUMNS FROM '.$this->table;
        $cols = $this->db->readSqlArray($sql); 
        $this->col_select = [];
        $this->col_id = [];
        foreach($cols as $col_id => $col) {
            $this->col_select[$col_id] = $col_id.':'.$col['Type'].$col['Key'];
            $this->col_id[] = $col_id;
            $this->max_cols++;
        }  

        
        //allow for extra text column concatenation from csv file
        $this->max_cols = $this->max_cols + 5;

        if(isset($param['test'])) $this->test = $param['test'];
        if(isset($param['max_rows'])) $this->max_rows = $param['max_rows'];
        if(isset($param['audit'])) $this->audit = $param['audit'];  
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];  
        if(isset($param['trim_values'])) $this->trim_values = $param['trim_values'];  
                                                
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }
    
    protected function getLinkForm() 
    {
        $html = '';

        //get any form values saved before
        $this->form = $this->cache->retrieve('form');

        //get csv header line
        $handle = fopen($this->file_path ,'r');
        $csv_header = fgetcsv($handle,0, ",");
        fclose($handle);    
        
        
        $html .= '<form method="post" action="?mode=process" autocomplete="off">';
        $html .= '<input type="submit" name="submit" value="PROCESS">';
        $html .= '<table>';
        $html .= '<tr><th>Col No.</th><th>CSV header</th><th>CSV convert type</th><th>DB field/col</th></tr>';
        
        $c = 0;
        foreach($csv_header as $col_no => $name) { 
            $c++;

            $name_lcase = strtolower($name);
            
            if($c <= $this->max_cols) {

                $form_type_id = 'type_'.$col_no;
                $form_col_id = 'col_'.$col_no;

                //check for previously set form values
                if(isset($this->form[$form_type_id])) {  
                    $use_col_type = $this->form[$form_type_id];
                    $use_col_id = $this->form[$form_col_id];
                } else {
                    //try and guess best match of table col to csv header name
                    $max_score = 0;
                    $use_col_id = '';
                    $percent = 0;
                    foreach($this->col_id as $col_id) {
                        similar_text($col_id,$name_lcase,$percent);
                        if($percent >= $max_score){
                            $max_score = $percent;
                            $use_col_id = $col_id;
                        }  
                    } 

                    //now guess type
                    $use_col_type = 'IGNORE';
                    if($use_col_id !== ''){
                        $col_type = $this->col_select[$use_col_id];
                        if(stripos($col_type,'int') !== false) $use_col_type = 'INTEGER';
                        if(stripos($col_type,'char') !== false) $use_col_type = 'STRING';
                        if(stripos($col_type,'date') !== false) $use_col_type = 'DATE-YYYY-MM-DD';
                        if(stripos($col_type,'decimal') !== false) $use_col_type = 'DECIMAL';
                        if(stripos($col_type,'tinyint') !== false) $use_col_type = 'BOOLEAN';
                        if(stripos($col_type,'text') !== false) $use_col_type = 'TEXT';
                    }

                }
                
                    
                
                $html .= '<tr>';
                $html .= '<td>'.$col_no.'</td>';
                $html .= '<td>'.$name.'</td>';
                $html .= '<td>'.Form::arrayList($this->col_types,$form_type_id,$use_col_type,false).'</td>';
                $html .= '<td>'.Form::arrayList($this->col_select,$form_col_id,$use_col_id,true).'</td>';
                $html .= '</tr>';
            }  
        }  
        
        $html .= '</table>';
        $html .= '</form>';

        return $html;
    }


    protected function importCsvData() 
    {
        $html = '';
        $error = '';
        $error_tmp = '';

        $i = 0;
        $f = 0;
        $s = 0;
        $insert_i = 0;
        $update_i = 0;
        $exist_i = 0;
        $invalid_i = 0;
        $line = array();
        $handle = fopen($this->file_path,'r');

        while(($line = fgetcsv($handle,0, ","))!== FALSE) {
            $i++;
            $value_num = count($line);
            
            //keep process alive 
            if(fmod($i,1000) == 0) {
                set_time_limit(60);
                $html .= 'Processed '.$i.' lines of csv file.<br/>';
                if($error!='') {
                    $html .= 'ERRORS:'.$error;
                    $error = '';
                }  
            } 
            
            //get header line and setup form values 
            if($i === 1) {
                $csv_header = $line;
                
                //get link form setup
                $cols = [];
                $c = 0;
                $test = [];
                foreach($csv_header as $col_no => $name) { 
                    $c++;

                    $form_type_id = 'type_'.$col_no;
                    $form_col_id = 'col_'.$col_no;

                    if($c <= $this->max_cols) {
                        if(isset($_POST[$form_type_id])) {
                            $this->form[$form_type_id] = $_POST[$form_type_id];
                        } else {
                            $this->addError('No valid database TYPE setting for CSV column['.$name.']');
                        }  

                        if(isset($_POST[$form_col_id])) {
                            $this->form[$form_col_id] = $_POST[$form_col_id];
                        } else {
                            $this->addError('No valid database COL setting for CSV column['.$name.']');
                        }    


                        $col['type'] = $this->form[$form_type_id];
                        $col['id'] = $this->form[$form_col_id];
                        
                        $cols[$col_no] = $col;
                        if($col['type'] !== 'IGNORE') $test[] = $name;
                    }  
                }
                
                //save 

                if($this->test) $html .= '<strong>'.implode(':',$test).'</strong><br/>';
            }  
            
            if($i > 1 and $value_num > 3) {
                $data = array();
                $line_valid = true;
                $update = false;
                
                if($this->ignore_if_first_col_blank and $line[0] === '') $line_valid = false;
                
                if($line_valid) {
                    //get data values
                    $data = [];
                    $col_id_merge = 0;
                    foreach($cols as $col_no => $col) {
                      if($col['type'] !== 'IGNORE' and $col['type'] !== 'MERGE-LN' ) {
                            $data[$col['id']] = $this->processValue($line[$col_no],$col['type']);
                            //set merge col for any subsequent MERGE-LN cols
                            $col_id_merge = $col['id'];
                      } else {
                            if($col['type']==='MERGE-LN') {
                                $merge_str = $this->processValue($line[$col_no],'STRING');
                                if($merge_str !== '') $data[$col_id_merge] .= "\r\n".$merge_str;
                                if($this->test) $html .= 'MERGE('.$col_id_merge.'):'.$data[$col_id_merge].'<br/>';
                            }
                      }    
                    } 
                }  
                
                //print_r($data);
                //echo '<br/>';
                if($line_valid) {
                    if($this->test) {
                        $html .= implode(':',$data).'<br/>';  
                    }
                      
                    //**** UPDATE STILL TO BE DONE, WHAT TO UPDATE ETC  
                    if($update) {
                        $where[$key_id] = $data[$key_id];
                        unset($data[$key_id]);
                        if($this->test === false) $this->db->updateRecord($this->table,$data,$where,$error_tmp);
                        if($error_tmp !== '') {
                            $error .= 'Could not UPDATE '.$this->table.' Record['.$where[$key_id].'] in line '.$i.' ERROR:'.$error_tmp.'<br/>';
                        } else {
                            $update_i++; 
                        }  
                    } else {  
                        if($this->test === false) $this->db->insertRecord($this->table,$data,$error_tmp);    
                        if($error_tmp !== '') {
                            $error .= 'Could not CREATE '.$this->table.'  record in line '.$i.' ERROR:'.$error_tmp.'<br/>';
                        } else {
                            $insert_i++; 
                        }  
                    }  
                }  
                
              
            }   
          
        }
        fclose($handle);
        
        $html .= "<br/><br/>INSERTED $insert_i records, INVALID $invalid_i records, already EXISTS $exist_i records, updated $update_i for table $table<br/>";
    }


    //***************************************



    protected function setupCol($col) 
    {
        if(!isset($col['class'])) $col['class'] = '';
        if(!isset($col['update'])) $col['update'] = false;
        if(!isset($col['confirm'])) $col['confirm'] = true;

        //allow update if record id exists
        if($col['update'] === true) $this->update_existing = true; 

        return $col;
    }

    public function addImportCol($col) 
    {
        $col = $this->addCol($col);

        $col = $this->setupCol($col); 
        
        $this->cols[$col['id']] = $col;
        $this->col_count++;
    }

    public function addAllImportCols() 
    {
        $this->addAllCols();
        foreach($this->cols as $col) {
           $this->cols[$col['id']] = $this->setupCol($col); 
           $this->col_count++;
        }
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
