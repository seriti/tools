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

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;
use Seriti\Tools\TableStructures;
use Seriti\Tools\DbInterface;

use Psr\Container\ContainerInterface;


class ImportCsv {

    use IconsClassesLinks;
    use MessageHelpers;
    use TableStructures;

    protected $db;
    protected $table;
    protected $debug = false;
    protected $audit = false;
    protected $update_existing = false;
    
    protected $form;
    protected $cols;

    protected $delimiter = ',';
    protected $enclosure = '"';
    protected $escape = "\\";

    protected $unique_field = '';

    protected $import_flag = false;
    protected $import_flag_field = 'import_flag';
    protected $import_flag_value;
        
    protected $max_rows = 100000;
    protected $col_label = '';
    protected $max_cols = 0;

    protected $header = [];
    protected $line_prev = [];
    protected $line_prev_raw = [];
    protected $trim_values = true;
            
    protected $file_path = '';
    protected $dates = array('zero'=>'1900-01-01');
    protected $start_row = 1;
    protected $ignore_if_first_col_blank = true;

    //to select which table col matches which csv col
    protected $test = false;
    protected $col_select = [];
    protected $col_convert = [];
    //'MERGE-LN' will append string value to previous value
    protected $col_types = ['AUTO','STRING','TEXT','INTEGER','DECIMAL','DATE','DATETIME','TIME','BOOLEAN','CURRENCY'];
     
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    public function __construct(DbInterface $db,$table,$format = 'COMMA')
    {
        $this->db = $db;
        $this->table = $table;

        if($format === 'COMMA') {
            $this->delimiter = ','; 
            $this->enclosure = '"';
        }

        if($format === 'SEMICOLON') {
            $this->delimiter = ';'; 
            $this->enclosure = '"';
        }

        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;
    } 

    //superflous as wizard sits above this 
    public function process($param = []) 
    {
        $html = '';

        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);

        if($this->mode === 'link_form') $html .= $this->getLinkForm();

        if($this->mode === 'import') $html .= $this->importCsvData();
    }    

    public function setup($param = []) 
    {
        $this->file_path = $param['file_path'];

        if(!file_exists($this->file_path)) {
           $this->addError('Import file does not exist!'); 
        }

        //get all table cols and info from database
        $this->setupTableCols($param);
        $this->modifyTableCols($param);

        //assume total table fields matchable to csv cols is base max va;ue   
        $this->max_cols = count($this->col_select);
                
        //allow for extra text column concatenation from csv file
        $this->max_cols = $this->max_cols + 5;

        if(isset($param['delimiter'])) $this->delimiter = $param['delimiter']; 
        if(isset($param['enclosure'])) $this->enclosure = $param['enclosure'];
        if(isset($param['escape'])) $this->escape = $param['escape'];       

        if(isset($param['test'])) $this->test = $param['test'];
        if(isset($param['max_rows'])) $this->max_rows = $param['max_rows'];
        if(isset($param['audit'])) $this->audit = $param['audit'];  
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];  
        if(isset($param['trim_values'])) $this->trim_values = $param['trim_values'];

        //NB: must be same as $this->cols settings in processLinkForm()
        if(isset($param['setup_cols'])) $this->cols = $param['setup_cols']; 
        //will not import if this field value exists
        if(isset($param['unique_field'])) $this->unique_field = $param['unique_field']; 

        if(isset($param['import_flag'])) $this->import_flag = $param['import_flag'];  
        if(isset($param['import_flag_field'])) $this->import_flag_field = $param['import_flag_field'];  
        if(isset($param['import_flag_value'])) $this->import_flag_value = $param['import_flag_value']; 
        if($this->import_flag) {
            if(!isset($this->col_select[$this->import_flag_field])) $this->addError('Import flag field['.$this->import_flag_field.'] does not exist in table['.$this->table.']');
        }

        if($this->errors_found) return false; else return true;  
    }

    public function getErrors() 
    {
        return $this->errors;
    }

    public function getForm() 
    {
        return $this->form;
    }

    //Placeholder function to modify $this->col_select
    public function modifyTableCols($param) {}

    private function setupTableCols($param) 
    {
        $this->col_select = [];
        $this->col_convert = [];

        $this->col_select['IGNORE'] = 'IGNORE column';
        $this->col_select['MERGE-LN'] = 'MERGE text with previous column';

        $sql = 'SHOW COLUMNS FROM '.$this->table;
        $cols = $this->db->readSqlArray($sql); 
        foreach($cols as $col_id => $col) {
            $this->col_select[$col_id] = $col_id;
            //$col['Type'].$col['Key']
            $this->col_convert[$col_id] = $this->getConvertType($col['Type']);
        }
    }

    //get conversion/validation type
    private function getConvertType($mysql_type)
    {
        $convert_type = 'STRING';
        $mysql_type = strtolower($mysql_type);
        
        if(stripos($mysql_type,'int') !== false) $convert_type = 'INTEGER';
        //maybe best to leave as integer
        //if(stripos($mysql_type,'tinyint') !== false) $convert_type = 'BOOLEAN';
        if(stripos($mysql_type,'char') !== false) $convert_type = 'STRING';
        if(stripos($mysql_type,'date') !== false) $convert_type = 'DATE';
        if(stripos($mysql_type,'datetime') !== false) $convert_type = 'DATETINE';
        if(stripos($mysql_type,'decimal') !== false) $convert_type = 'DECIMAL';
        if(stripos($mysql_type,'text') !== false) $convert_type = 'TEXT';

        return $convert_type;
    }
    
    public function getLinkForm($form = [],$param = []) 
    {
        $html = '';

        //get csv header line
        $handle = fopen($this->file_path ,'r');
        $csv_header = fgetcsv($handle,0,$this->delimiter,$this->enclosure,$this->escape);
        fclose($handle);  

        if(!isset($param['form'])) $param['form'] = ''; //'<form method="post" action="?mode=process" autocomplete="off">';
        if(!isset($param['button'])) $param['button'] = ''; //'<input type="submit" name="submit" value="PROCESS">';
        
        if($param['form'] !== '') $html .= $param['form'];
        if($param['button'] !== '') $html .= $param['button'];
        $html .= '<table class="'.$this->classes['table'].'"">';
        $html .= '<tr><th>Column</th><th>Header</th><th>Link to database field</th><th>Conversion</th></tr>';
        
        $c = 0;
        foreach($csv_header as $col_no => $name) { 
            $c++;

            $name_lcase = strtolower($name);
            
            if($c <= $this->max_cols) {

                $form_type_id = 'type_'.$col_no;
                $form_col_id = 'col_'.$col_no;

                //check for previously set form values
                if(isset($form[$form_type_id])) {  
                    $use_col_type = $form[$form_type_id];
                    $use_col_id = $form[$form_col_id];
                } else {
                    //try and guess best match of table col to csv header name
                    $max_score = 60;
                    $use_col_id = '';
                    $percent = 0;
                    foreach($this->col_select as $col_id => $value) {
                        if($col_id !== 'IGNORE' and $col_id !== 'MERGE-LN') {
                            similar_text($col_id,$name_lcase,$percent);
                            if($percent >= $max_score){
                                $max_score = $percent;
                                $use_col_id = $col_id;
                            }    
                        }
                    } 
                    if($use_col_id === '') $use_col_id = 'IGNORE';

                    //automatic unless selected otherwise
                    $use_col_type = 'AUTO';
                }
                
                    
                
                $html .= '<tr>';
                $html .= '<td>'.Calc::excelColConvert('N2L',$col_no).'</td>';
                $html .= '<td>'.$name.'</td>';
                $html .= '<td>'.Form::arrayList($this->col_select,$form_col_id,$use_col_id,true).'</td>';
                $html .= '<td>'.Form::arrayList($this->col_types,$form_type_id,$use_col_type,false).'</td>';
                
                $html .= '</tr>';
            }  
        }  
        
        $html .= '</table>';
        if($param['form'] !== '') $html .= '</form>';
        
        return $html;
    }

    

    public function processLinkForm($param = []) 
    {
        $error = '';
        $error_tmp = '';
        $output = [];

        //get csv header line
        $handle = fopen($this->file_path ,'r');
        $csv_header = fgetcsv($handle,0,$this->delimiter,$this->enclosure,$this->escape);
        fclose($handle);  
        
        $c = 0;
        foreach($csv_header as $col_no => $name) { 
            $c++;

            $col = [];
            $form_type_id = 'type_'.$col_no;
            $form_col_id = 'col_'.$col_no;

            if($c <= $this->max_cols) {
                if(isset($_POST[$form_col_id])) {
                    $this->form[$form_col_id] = $_POST[$form_col_id];
                } else {
                    $this->addError('No valid database COL setting for CSV column['.$name.']');
                }   

                if(isset($_POST[$form_type_id])) {
                    $this->form[$form_type_id] = $_POST[$form_type_id];
                } else {
                    $this->addError('No valid database TYPE setting for CSV column['.$name.']');
                }  
                

                //database field name
                $col['id'] = $this->form[$form_col_id];
                //conversion/validation data type
                if($this->form[$form_type_id] !== 'AUTO') {
                    $col['type'] = $this->form[$form_type_id];    
                } else {
                    $col['type'] = $this->col_convert[$col['id']];
                }
                $col['name'] = $name;
                
                //check that col_id not used before
                foreach($this->cols as $no=>$data) {
                    if($data['id'] !== 'IGNORE' and $col['id'] !== 'IGNORE' and $data['id'] === $col['id']) {
                        //$this->addError('WTF');
                        $this->addError('You have assigned CSV headers ['.$data['name'].'] & ['.$name.'] to Single database field['.$col['id'].']. Set Link database field to IGNORE or link to different field.');
                    }
                }

                $this->cols[$col_no] = $col;
               
            }  
        }


        $output['cols'] = $this->cols;
        $output['errors_found'] = $this->errors_found;
        
        return $output;
    }    
     
    //NB: any errors are output in $html and $this->addError() should not used unless in test mode.
    public function importCsvData($param = []) 
    {
        $html = '';
        $error = '';
        $error_tmp = '';

        if(!isset($param['header_rows'])) $param['header_rows'] = 1;
        if(!isset($param['min_cols'])) $param['min_cols'] = 1;
        if(!isset($param['test_rows'])) $param['test_rows'] = 10;


        $i = 0;
        $f = 0;
        $s = 0;
        $insert_i = 0;
        $update_i = 0;
        $exist_i = 0;
        $error_i = 0;
        $invalid_i = 0;

        if($this->test) {
            $html .= '<table class="'.$this->classes['table'].'"><tr>';
            foreach($this->cols as $col_no => $col) {
                if($col['id'] !== 'IGNORE' and $col['id'] !== 'MERGE-LN') {
                    $html .= '<th>'.$col['name'].'<br/>field:'.$col['id'].'</th>';
                }    
            }    
            $html .= '</tr>';  
        }    


        $line = array();
        $handle = fopen($this->file_path,'r');

        while(($line = fgetcsv($handle,0,$this->delimiter,$this->enclosure,$this->escape))!== FALSE) {
            $i++;
            $value_num = count($line);
            $error_line = '';
            
            //keep process alive 
            if(fmod($i,1000) == 0) {
                set_time_limit(60);
                $html .= 'Processed '.$i.' lines of csv file.<br/>';
            } 
            
            //$html.='WTF:'.print_r($line).'</br>';

                        
            if($i > $param['header_rows'] and $value_num >= $param['min_cols']) {
                $data = array();
                $line_valid = true;
                $update = false;
                
                if($this->ignore_if_first_col_blank and $line[0] === '') $line_valid = false;
                if($this->test and $i > $param['test_rows']) $line_valid = false;
                
                //placeholder function to modify line values based on any criteria and/or error checking
                $this->modifyCsvLine($line,$error_line,$line_valid);

                if($line_valid) {
                    //get data values
                    $data = [];
                    $col_id_merge = 0;
                    foreach($this->cols as $col_no => $col) {
                        if($col['id'] !== 'IGNORE' and $col['id'] !== 'MERGE-LN' ) {
                            $data[$col['id']] = $this->processValue($line[$col_no],$col['name'],$col['type'],$error_tmp);
                            if($error_tmp !== '') $error_line .= $error_tmp;
                            //set merge col for any subsequent MERGE-LN cols
                            $col_id_merge = $col['id'];
                        } else {
                            if($col['id']==='MERGE-LN') {
                                $merge_str = $this->processValue($line[$col_no],$col['name'],'STRING',$error_tmp);
                                if($error_tmp !== '') $error_line .= $error_tmp;
                                if($merge_str !== '') $data[$col_id_merge] .= "\r\n".$merge_str;
                            }
                        }    
                    }

                    //check if record exists with unique field
                    if($this->unique_field !== '' and isset($data[$this->unique_field])) {
                        $where[$this->unique_field] = $data[$this->unique_field];
                        $rec = $this->db->getRecord($this->table,$where);
                        if($rec !== 0) {
                            $line_valid = false;
                            $exist_i++;
                        }
                    } 
                }  
                                
                if(!$line_valid) {
                    $invalid_i++;
                } else {    
                    if($this->test) {
                        $html .= '<tr><td>'.implode('</td><td>',$data).'</td></tr>';  
                    } else {
                        if($this->import_flag) {
                            $data[$this->import_flag_field] = $this->import_flag_value;
                        }  
                    }
                      
                    //**** UPDATE STILL TO BE DONE, WHAT TO UPDATE ETC  
                    if($update) {
                        $where[$key_id] = $data[$key_id];
                        unset($data[$key_id]);
                        if($this->test === false) $this->db->updateRecord($this->table,$data,$where,$error_tmp);
                        if($error_tmp !== '') {
                            $error_line .= 'Could not UPDATE database record['.$where[$key_id].']:'.$error_tmp.'<br/>';
                        } else {
                            $update_i++; 
                        }  
                    } else {  
                        if($this->test === false) $this->db->insertRecord($this->table,$data,$error_tmp);    
                        if($error_tmp !== '') {
                            $error_line .= 'Could not CREATE database record:'.$error_tmp.'<br/>';
                        } else {
                            $insert_i++; 
                        }  
                    }  
                }  
            }

            //NB: only addError() in test mode otherwise errors are output to html. 
            if($error_line !== '') {
                $error_i++;
                $error_line = 'Error in line['.$i.'] : '.$error_line;
                $error .= $error_line.'<br/>'; 
                if($this->test) $this->addError($error_line);
            }    
            
          
        }
        fclose($handle);
        
        if($this->test) {
            $html .= '</table>';
            $html = $error.$html;
        } else {
            $html = "INSERTED: $insert_i records, EXISTING: $exist_i records, UPDATED: $update_i records. INVALID: $invalid_i lines, ERRORS: $error_i lines.<br/>";
            $html .= $error;    
        }    

        return $html;
    }


    //NB: some validation functions also clean/convert form values....see validate_number/integer;
    protected function processValue($value,$name,$type,&$error)  {
        $error = '';
        $secure = true;
                
        switch($type) {
            case 'CUSTOM'  : break; //custom validation must be handled by before_update() placeholder function
            case 'STRING'  : {
                Validate::string($name,0,255,$value,$error,$secure); 
                break;
            }  
            case 'PASSWORD': Validate::password($name,1,255,$value,$error);  break;
            case 'TEXT'    : Validate::text($name,0,64000,$value,$error,$secure); break;
            case 'HTML'    : Validate::html($name,0,64000,$value,$error,$secure); break;
            case 'INTEGER' : Validate::integer($name,0,1000000000,$value,$error);  break;
            case 'DECIMAL' : Validate::number($name,0,1000000000,$value,$error);  break;  
            case 'EMAIL'   : Validate::email($name,$value,$error);  break;  
            case 'URL'     : Validate::url($name,$value,$error);  break;  
            case 'DATE'    : Validate::date($name,$value,'YYYY-MM-DD',$error);  break;  
            case 'DATETIME': Validate::dateTime($name,$value,'YYYY-MM-DD HH:MM',$error);  break;  
            case 'TIME'    : Validate::time($name,$value,'HH:MM:SS',$error);  break;  
            case 'BOOLEAN' : {
                //test for true/false or yes/no
                if(strcasecmp($value[1],'y') === 0 or strcasecmp($value[1],'t') === 0 ) {
                    $value = '1';
                } elseif(strcasecmp($value[1],'n') === 0 or strcasecmp($value[1],'f') === 0 ) {
                     $value = '0';
                }    

                Validate::boolean($name,$value,$error);
                break;
            }    
             
            default: $error.='Unknown variable type['.$type.']';
        }

        return $value;
    } 

    //placeholder functions for custom modifications
    protected function modifyCsvLine(&$line,&$error,&$line_valid) {} 
    
}
