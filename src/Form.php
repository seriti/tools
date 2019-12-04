<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Date;
use Seriti\Tools\Secure;
use Seriti\Tools\DbInterface;

//class intended as a pseudo namespace for a group of functions to be referenced as "Form::function_name()"
class Form 
{
        
    public static function viewMessages($errors=array(),$messages=array(),$options=array()) 
    {
        $html = '';
        
        if(!isset($options['class_error'])) $options['class_error'] = 'alert alert-danger';
        if(!isset($options['class_message'])) $options['class_message'] = 'alert alert-success';
                
        if(count($errors) != 0) {
            $html .= '<div id="error_div" class="'.$options['class_error'].'">'.
                         '<button type="button" class="close" data-dismiss="alert">x</button><ul>';
            foreach($errors as $error) {
                $html .= '<li>'.$error.'</li>';  
            }  
            $html .= '</ul></div>';
        }  
        if(count($messages) != 0) {
            $html .= '<div id="message_div" class="'.$options['class_message'].'">'.
                     '<button type="button" class="close" data-dismiss="alert">x</button><ul>';
            foreach($messages as $message) {
                $html .= '<li>'.$message.'</li>';  
            }  
            $html .= '</ul></div>';
        }
        
        return $html;
    }  
    
    //uploads SINGLE OR MULTIPLE files with sequential file name
    public static function uploadFiles($form_id,DbInterface $db,$options = [],&$error,&$message) 
    {
        $error = '';
        
        if(!isset($options['debug'])) $options['debug'] = false; 
        if(!isset($options['rename'])) $options['rename'] = true; //rename using system file counter
        if(!isset($options['overwrite'])) $options['overwrite'] = true;
        if(!isset($options['upload_dir'])) $options['upload_dir'] = 'upload/';
        if(!isset($options['max_size'])) $options['max_size'] = '5000000';
        if(!isset($options['allow_ext'])) $options['allow_ext'] = array('doc','xls','ppt','pdf','rtf','docx','xlsx','pptx','ods','odt','txt','csv','zip','msg','jpg','jpeg','gif','png');

        //create a flattened file array [0=>[attributes],1=>[attributes],2=>[attributes]....]
        $files = [];

        $form = $_FILES[$form_id];
        //check if multiple upload
        if (is_array($form['name'])) {
            //$attribute = name,type,tmp_name,error,size
            foreach ($form as $attribute => $list) {
                //$key = 0,1,2,3...N file count
                foreach ($list as $key => $value) {
                    $files[$key][$attribute]=$value;
                }
            }
        } else {
            $files[0] = $form;
        }
        
        //now process all files
        foreach($files as $f=>$file) {
            $count = 0;
            $error_file = '';

            if($file['name'] !== '') {
                $count++;

                switch ($file['error']) {
                    case 1 : $error_file .= 'size exceeded limit : '.ini_get('upload_max_filesize'); break;
                    case 2 : $error_file .= 'size exceeded limit specified in HTML '; break;
                    case 3 : $error_file .= 'was only partially uploaded'; break;
                    case 4 : $error_file .= 'no file was uploaded'; break;
                    case 6 : $error_file .= 'missing temporary folder.'; break;
                    case 7 : $error_file .= 'could not write to disk.'; break;
                    case 8 : $error_file .= 'upload stopped by extension!'; break;
                }

                if($error_file === '' and $file['size'] == 0) {
                    $error_file .= 'file size exceeded either POST limit : '.ini_get('post_max_size').' or UPLOAD limit : '.ini_get('upload_max_filesize');
                }

                if($error_file === '') {
                    $parts = Doc::fileNameParts($file['name']);
                    $file_ext = strtolower($parts['extension']);
                    $base_name = $parts['basename'];
                    $file_size = $file['size'];

                    if(!in_array($file_ext,$options['allow_ext'])) {
                        $error_file .= 'Not a supported filetype! '.
                                       'Please upload only '.strtoupper(implode(' : ',$options['allow_ext'])).' files. Thanks. ';
                    }
                    
                    if($file_size > $options['max_size']) {
                        $error_file .= 'Size['.Calc::displayBytes($file_size).'] greater than '.
                                       'maximum allowed['.Calc::displayBytes($options['max_size']).'] ';
                    }    
                }

                if($error_file === '') {
                    if($options['rename']) {
                        $save_name = Calc::getFileId($db);
                        $save_name = $save_name.'.'.$file_ext;
                    } else {
                        $save_name = $base_name.'.'.$file_ext;
                    }
                                        
                    $file_path = $options['upload_dir'].$save_name;
                    if(file_exists($file_path) and !$options['overwrite']) {
                        $error_file .= 'File already exists! Please contact support!<br/>';
                    } else {    
                        if(!move_uploaded_file($file['tmp_name'],$file_path))  {
                            if($options['debug']) {
                                $error_file .= 'Error moving uploading file['.$file['tmp_name'].'] to ['.$file_path.']' ;
                            } else {
                                $error_file .= 'Error moving uploaded from temporary folder.' ;
                            }    
                        }
                    } 

                    $file['extension'] = $file_ext;
                    $file['save_name'] = $save_name;
                    $file['save_path'] = $file_path;
                }
            } 

            if($error_file !== '') {
                $error .= 'Error uploading file['.$file['name'].'] :'.$error_file.'<br/>';
                unset($files[$f]);
            } else {
                $message .= 'Success uploading file['.$file['name'].']<br/>';
                $files[$f] = $file;
            } 
        }
        
        //NB: Successfully uploaded files only!
        return $files; 
    }

    //uploads a SINGLE file selection, NOT multiple file selection!
    public static function uploadFile($form_id,$save_name,$options=array(),&$error_str) 
    {
        $error_str = '';
        if(!isset($options['overwrite'])) $options['overwrite'] = true;
        if(!isset($options['upload_dir'])) $options['upload_dir'] = 'upload/';
        if(!isset($options['max_size'])) $options['max_size'] = '5000000';
        if(!isset($options['allow_ext'])) $options['allow_ext'] = array('doc','xls','ppt','pdf','rtf','docx','xlsx','pptx','ods','odt','txt','csv','zip','msg','jpg','jpeg','gif','png');
            
        if($_FILES["$form_id"]['name'] != '') {
            switch ($_FILES["$form_id"]['error']) {
                case 1 : $error_str .= 'File size exceeded limit : '.ini_get('upload_max_filesize'); break;
                case 2 : $error_str .= 'File size exceeded limit specified in HTML '; break;
                case 3 : $error_str .= 'File was only partially uploaded'; break;
                case 4 : $error_str .= 'NO File was uploaded'; break;
                case 6 : $error_str .= 'File upload failed, missing temporary folder.'; break;
                case 7 : $error_str .= 'File upload failed, could not write to disk.'; break;
                case 8 : $error_str .= 'File upload stopped by extension!'; break;
            }
            
            //file_size=0 indicates that php.ini POST_MAX_SIZE or UPLOAD_MAX_SIZE exceeded
            if($error_str === '' and $_FILES["$form_id"]['size'] == 0) {
                $error_str .= 'Uploaded file size exceeded either POST limit : '.ini_get('post_max_size').' or UPLOAD limit : '.ini_get('upload_max_filesize');
            }
        } else {
            $error_str = 'NO_FILE';
        } 
        
        if($error_str === '') {
            $file_base_name = basename($_FILES["$form_id"]['name']);
            $file_size = $_FILES["$form_id"]['size'];

            $temp = explode(".",$file_base_name);
            $file_ext = strtolower($temp[count($temp)-1]);

            if(!in_array($file_ext,$options['allow_ext'])) {
                $error_str .= '<br/>Uploaded File "<b>'.$file_base_name.'</b>" is not a supported filetype!<br/>'.
                              'Please upload only '.strtoupper(implode(' : ',$options['allow_ext'])).' files. Thanks. ';
            }
            
            if($file_size > $options['max_size']) {
                $error_str .= '<br> File['.$file_base_name.'] size['.round($file_size/1000,1).'Kb] greater than maximum allowed['.($options['max_size']/1000).'Kb] ';
            }    
        }
            
        //get outa here at this point if any errors 
        if($error_str != '') return false;
        
        //get filename to save uploaded file with
        if($save_name != '') $upload_file_name = $save_name.'.'.$file_ext; else $upload_file_name = $file_base_name;
        
        $file_path = $options['upload_dir'].$upload_file_name;
        if(file_exists($file_path) and !$options['overwrite']) {
            $error_str .= 'File already exists! Please contact support!<br/>';
        } else {    
            if(!move_uploaded_file($_FILES["$form_id"]['tmp_name'],$file_path))  {
                $error_str .= 'Error moving uploading file['.$file_base_name.']'.$file_path ;
            }
        } 
        
        if($error_str === '') {
            return $upload_file_name;
        } else {
            return false; 
        }   
    } 
    
    


    public static function hiddenInput($data = array(),$param = array()) 
    {
        $html =  '';
        $xtra = '';
        
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
                 
        foreach($data as $key => $value) {
            $html .= '<input type="hidden" name="'.$key.'" value="'.$value.'" '.$xtra.'>';
        }  
        return $html;
    }
    
    public static function fileInput($name,$value,$param =  array()) 
    {
        $html = '';
        $xtra = '';
        
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['onkeyup'])) $xtra .= 'onkeyup="'.$param['onkeyup'].'" ';
        if(isset($param['onblur'])) $xtra .= 'onblur="'.$param['onblur'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
                        
        $html .= '<input type="file" name="'.$name.'" value="'.$value.'" '.$xtra.'>';
        return $html;
    }
    
    public static function textInput($name,$value,$param = array()) 
    {
        $html = '';
        $xtra = '';
        
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['onkeyup'])) $xtra .= 'onkeyup="'.$param['onkeyup'].'" ';
        if(isset($param['onblur'])) $xtra .= 'onblur="'.$param['onblur'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
        if(isset($param['max'])) $xtra .= 'maxlength="'.$param['max'].'" ';
        if(isset($param['size'])) $xtra .= 'size="'.$param['size'].'" ';
        if(isset($param['placeholder'])) $xtra .= 'placeholder="'.$param['placeholder'].'" ';
        
        $value = str_replace('"','&quot;',$value);
                        
        $html .= '<input type="text" id="'.$name.'" name="'.$name.'" value="'.$value.'" '.$xtra.'>';
        return $html;
    }
    
    public static function textAreaInput($name,$value,$cols = '',$rows = '',$param = array()) 
    {
        $html = '';
        $xtra = '';
        
        if($cols != '') $xtra .= 'cols="'.$cols.'" ';
        if($rows != '') $xtra .= 'rows="'.$rows.'" ';
        
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['onkeyup'])) $xtra .= 'onkeyup="'.$param['onkeyup'].'" ';
        if(isset($param['onblur'])) $xtra .= 'onblur="'.$param['onblur'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
        if(isset($param['placeholder'])) $xtra .= 'placeholder="'.$param['placeholder'].'" ';
    
        $html .= '<textarea id="'.$name.'" name="'.$name.'" '.$xtra.'>'.$value.'</textarea>';    
        return $html;
    } 

    public static function checkBox($name,$value,$checked = false,$param = array()) 
    {
        $html = '';
        $xtra = '';
        
        //NB if $checked contains any non-empty string then it is seen as true
        if($checked) $xtra .= 'CHECKED ';
        
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['onkeyup'])) $xtra .= 'onkeyup="'.$param['onkeyup'].'" ';
        if(isset($param['onblur'])) $xtra .= 'onblur="'.$param['onblur'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
                
        $html .= '<input type="checkbox" id="'.$name.'" name="'.$name.'" value="'.$value.'" '.$xtra.'>';
        return $html;
    } 
        
    public static function radioButton($group_name,$button_value,$value,$param = array()) 
    {
        $html = '';
        $xtra = '';
        
        if($value === $button_value) $xtra .= 'CHECKED ';
        
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['onkeyup'])) $xtra .= 'onkeyup="'.$param['onkeyup'].'" ';
        if(isset($param['onblur'])) $xtra .= 'onblur="'.$param['onblur'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
                
        $html .= '<input type="radio" name="'.$group_name.'" value="'.$button_value.'" '.$xtra.'>';
        return $html;
    }

    public static function submitButton($name,$button_text,$param = array()) 
    {
        $html = '';
        $xtra = '';
        
        if(!isset($param['class'])) $param['class'] = 'btn btn-primary';
        $xtra .= 'class="'.$param['class'].'" ';

        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['onkeyup'])) $xtra .= 'onkeyup="'.$param['onkeyup'].'" ';
        if(isset($param['onblur'])) $xtra .= 'onblur="'.$param['onblur'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
                        
        $html .= '<input type="button" name="'.$name.'" value="'.$button_text.'" '.$xtra.'>';
        return $html;
    } 

    public static function sqlList($sql,DbInterface $db,$name,$value,$param = array()) 
    {
        $html = '';
        $xtra = '';
        $value_found = false;
        
        if(!isset($param['mode'])) $param['mode'] = 'STD';
         
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';   
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
        
        if(isset($param['select'])) {
            $html .= $param['select'];
        } else {
            $html .= '<select name="'.$name.'" id="'.$name.'" '.$xtra.'>';
        }   
        
        if(isset($param['xtra'])) {
            if(is_array($param['xtra'])) { //NB assumes an associative array
                foreach($param['xtra'] as $key => $name) {
                    if($key == $value) {
                        $select = 'selected';
                        $value_found = true;
                    } else {
                        $select = '';
                    }  
                    $option = '<option value="'.$key.'" '.$select.' >'.$name.'</option>'."\n";
                    $html .= $option;
                } 
            } else {  
                if($param['xtra'] == $value) {
                    $value_found = true;
                    $html .= '<option value="'.$param['xtra'].'" selected>'.$param['xtra'].'</option>';
                } else {
                    $html .= '<option value="'.$param['xtra'].'">'.$param['xtra'].'</option>';
                }
            } 
        } 
        
        if($sql != '') {
            $result = $db->readSql($sql);
            if($result !== 0) {
                $col_count = $result->field_count;
                while($select = $result->fetch_array(MYSQLI_NUM)) {
                    if($col_count > 1) {
                        if($param['mode'] == 'ID') $show_value = $select[0].' : '.$select[1]; else $show_value = $select[1];
            
                        if($select[0] == $value) {
                            $value_found = true;
                            $html .= '<option value="'.$select[0].'" selected>'.$show_value.'</option>';
                        } else {
                            $html .= '<option value="'.$select[0].'">'.$show_value.'</option>';
                        }
                    } else {
                        if($select[0] == $value) {
                            $value_found = true;
                            $html .= '<option value="'.$select[0].'" selected>'.$select[0].'</option>';
                        } else {
                            $html .= '<option value="'.$select[0].'">'.$select[0].'</option>';
                        }
                    }
                }
            }
        }  
        
        //in the event that the value is no longer in select list create option with value selected so form value is unchanged unless another value selected
        if(!$value_found and $value != '') {
            $html .= '<option value="'.$value.'" selected>'.$value.'</option>';
        }  
            
        $html .= '</select>';
        return $html;
    }

    public static function arrayList($array,$name,$key_value,$key_assoc = true,$param = array()) 
    {
        $html = '';
        $xtra = '';
        $value_found = false;
        
        if(isset($param['onclick'])) $xtra .= 'onclick="'.$param['onclick'].'" ';
        if(isset($param['class'])) $xtra .= 'class="'.$param['class'].'" ';
        if(isset($param['onchange'])) $xtra .= 'onchange="'.$param['onchange'].'" ';
        if(isset($param['form'])) $xtra .= 'form="'.$param['form'].'" ';
                
        if(isset($param['select'])) {
            $html .= $param['select'];
        } else {
            $html .= '<select name="'.$name.'" id="'.$name.'" '.$xtra.'>';
        }   
        
        if(isset($param['xtra'])) {
            if(is_array($param['xtra'])) { //NB assumes an associative array
                foreach($param['xtra'] as $key => $name) {
                    if ($key == $key_value) {
                        $value_found = true;
                        $select = 'selected';
                    } else {
                        $select = '';
                    }  
                    $option = '<option value="'.$key.'" '.$select.' >'.$name.'</option>'."\n";
                    $html .= $option;
                } 
            } else {  
                if($param['xtra'] == $key_value) {
                    $value_found = true;
                    $html .= '<option value="'.$param['xtra'].'" selected>'.$param['xtra'].'</option>';
                } else {
                    $html .= '<option value="'.$param['xtra'].'">'.$param['xtra'].'</option>';
                }
            }  
        } 
        
        $count = count($array);
        if($key_assoc) {
            $keys = array_keys($array);
            for ($i = 0; $i < $count; $i++) {
                $key = $keys[$i];
                if($key == $key_value) {
                    $value_found = true;
                    $select = 'selected';
                } else {
                    $select = '';
                }  
                $option = '<option value="'.$keys[$i].'" '.$select.' >'.$array[$key].'</option>'."\n";
                $html .= $option;
            } 
        } else {
            for ($i = 0; $i < $count; $i++) {
                if($array[$i] == $key_value) {
                    $value_found = true;
                    $select = 'selected';
                } else {
                    $select = '';
                }  
                $option = '<option value="'.$array[$i].'" '.$select.' >'.$array[$i].'</option>'."\n";
                $html .= $option;
            }
        }
        
        //in the event that the value is no longer in select list create option with value selected so form value is unchanged unless another value selected
        if(!$value_found and $key_value != '') {
            $html .= '<option value="'.$key_value.'" selected>'.$key_value.'</option>';
        }  
                
        $html.='</select>';
        return $html;
    }

    public static function daysList($day) 
    {
        $html = '';
        for($i = 1; $i <= 31; $i++) {
            if($day == $i){
                $option = '<option selected  value="';
            } else {
                $option = '<option  value="';
            }
            
            $option .= $i.'">'.$i.'</option>'."\n";
            $html .= $option;
        }
        return $html;
    }

    //NB requires $time in 00:00 f0rmat
    public static function timeList($time) 
    {
        $html = '';
        for($i = 0; $i < 25; $i++) {
            if($i < 10) $val = '0'.$i.':00'; else $val = $i.':00';
            if($i < 13) $show = $i.' am'; else $show = ($i-12).' pm';
            
            if($val == $time) $sel = 'selected'; else $sel = '';
            
            $option = '<option '.$sel.' value="'.$val.'">'.$show.'</option>'."\n";
            $html .= $option;
        }
        return $html;
    }

    public static function monthsList($month,$name = '',$param = array())
    {
        $html = '';
        $select_tag = false;

        if($name != '') {
            $select_tag = true;
            if(!isset($param['select'])) {
                $class = '';
                $on_change = '';
                if(isset($param['class'])) $class = 'class="'.$param['class'].'" ';
                if(isset($param['onchange'])) $on_change = 'onchange="'.$param['onchange'].'" ';
                $html .= '<select '.$class.' name="'.$name.'" id="'.$name.'" '.$on_change.'>';
            } else {
                $html .= $param['select'];  
            }           
        }   
        
        $names[1] = 'Jan';
        $names[2] = 'Feb';
        $names[3] = 'Mar';
        $names[4] = 'Apr';
        $names[5] = 'May';
        $names[6] = 'Jun';
        $names[7] = 'Jul';
        $names[8] = 'Aug';
        $names[9] = 'Sep';
        $names[10] = 'Oct';
        $names[11] = 'Nov';
        $names[12] = 'Dec';

        for($i = 1; $i <= 12; $i++) {
            if ($month == $i) {
                $option = '<option selected value="';
            } else {
                $option = '<option value="';
            }
            
            $option .= $i.'">'.$names[$i].'</option>'."\n";
            $html .= $option;
        }
        
        if($select_tag) $html .= '</select>';
        
        return $html;
    }

    public static function yearsList($year,$past_years,$future_years,$name = '',$param = array()) 
    {
        $html = '';
        $date = getdate();
        $year_now = $date['year'];
        $select_tag = false;

        if($name != '') {
            $select_tag = true;
            if(!isset($param['select'])) {
                $class = 'form-control';
                $on_change = '';
                if(isset($param['class'])) $class = 'class="'.$param['class'].'" ';
                if(isset($param['onchange'])) $on_change = 'onchange="'.$param['onchange'].'" ';
                $html .= '<select '.$class.' name="'.$name.'" id="'.$name.'" '.$on_change.'>';
            } else {
                $html .= $param['select'];  
            }           
        }   

        for($i = $year_now-$past_years; $i <= $year_now+$future_years; $i++) {
            if($year == $i) {
                $option = '<option selected value="';
            } else {
                $option = '<option value="';
            }
            
            $option .= $i.'">'.$i.'</option>'."\n";
            
            $html .= $option;
        }
        
        if($select_tag) $html .= '</select>';
        
        return $html;
    }

    public static function dateLists($name,$value,$param = array())
    {
        if(!isset($param['format_in'])) $param['format_in'] = 'YYYY-MM-DD';
        if(!isset($param['past_years'])) $param['past_years'] = 50;
        if(!isset($param['future_years'])) $param['future_years'] = 50;
        if(!isset($param['format_out'])) $param['format_out'] = 'DMY';
        
        
        $html = '';
        $select = '';
        $class = '';
        $on_change = '';
        
        if(isset($param['class'])) $class = 'class="'.$param['class'].'" ';
        if(isset($param['onchange'])) $on_change = 'onchange="'.$param['onchange'].'" ';
        
        if(!isset($param['select_day'])) $param['select_day'] = '<select '.$class.' name="'.$name.'_day" id="'.$name.'_day" '.$on_change.'>';
        if(!isset($param['select_month'])) $param['select_month'] = '<select '.$class.' name="'.$name.'_month" id="'.$name.'_month" '.$on_change.'>';
        if(!isset($param['select_year'])) $param['select_year'] = '<select '.$class.' name="'.$name.'_year" id="'.$name.'_year" '.$on_change.'>';
        
        
        $html .= '<span>';
        $date_now = getdate();
        $date_list = Date::getDate($value,$param['format_in']);
        //$year_now=$date['year'];
        $html .= $param['select_day'].self::daysList($date_list['mday']).'</select>';
        $html .= $param['select_month'].self::monthsList($date_list['mon']).'</select>';
        $html .= $param['select_year'].self::yearsList($date_list['year'],$param['past_years'],$param['future_years']).'</select>';
        $html .= '</span>';
        
        return $html;
    }  

    public static function numberList($from,$to,$number) 
    {
        $html = '';
        for($i = $from; $i <= $to; $i++) {
            if($number == $i) {
                $option = '<option value="'.$i.'" selected>';
            } else {
                $option = '<option value="'.$i.'">';
            }
            
            $option .= $i.'</option>'."\n";
            $html .= $option;
        }
        return $html;
    }
    
    public static function rankList($from_rank,$to_rank,$title = 'Display ',$title_pos = 'L') 
    {
        $array = array();
        for($i = $from_rank; $i <= $to_rank; $i++) {
            switch($i) {
                case 1:  $suffix = 'st'; break;
                case 2:  $suffix = 'nd'; break;
                case 3:  $suffix = 'rd'; break;
                default: $suffix = 'th';
            }
            
            if($title_pos == 'L') $value = $title.$i.$suffix;
            $array[$i] = $value;      
        }
        return $array;
        
    }

    public static function filthFilter($text) 
    {
        $filth = array('fuck','shit','wank','whore','cunt','slut','cock','poes');
        str_replace($filth,'****',$text);

        return $text;
    }

    public static function createPassword($param=array()) 
    {
        if(!isset($param['min_length'])) $param['min_length'] = 8;
        if(!isset($param['level'])) $param['level'] = 2;
        if(!isset($param['repeat'])) $param['repeat'] = false;
        if(!isset($param['number'])) $param['number'] = true;
        if(!isset($param['lowercase'])) $param['lowercase'] = true;
        if(!isset($param['uppercase'])) $param['uppercase'] = true;
        
        $password = '';
        $count = 0;
        $l = $param['level'];

        $number = '123456789';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNPQRSTUVWXYZ';
        
        $set[1] = '123456789abcdefghjkmnpqrstuvwxyz';
        $set[2] = '123456789abcdefghjkmnpqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ';
        $set[3] = '123456789_!@#$%&*()-=+/abcdefghjkmnpqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ_!@#$%&*()-=+/';
        
        while($count < $param['min_length']) {
            $char = substr($set[$l], rand(0, strlen($set[$l])-1), 1);
            
            if($param['repeat']) {
                $password .= $char;
                $count++;
            } else {  
                if(strpos($password,$char) === false) {
                    $password .= $char;
                    $count++;
                } 
            } 
        }

        if($param['number'] and !preg_match('`[0-9]`',$password)) {
            $char = substr($number, rand(0,strlen($number)-1), 1);
            $password .= $char;
        }

        if($param['lowercase'] and !preg_match('`[a-z]`',$password)) {
            $char = substr($lowercase, rand(0,strlen($lowercase)-1), 1);
            $password .= $char;
        }

        if($param['uppercase'] and !preg_match('`[A-Z]`',$password)) {
            $char = substr($uppercase, rand(0,strlen($uppercase)-1), 1);
            $password .= $char;
        }

        return $password;
    }
    
    public static function setCookie($name,$value,$expire_days,$options = array()) 
    {
        if(!isset($options['path'])) $options['path'] = '/';
        if(!isset($options['domain'])) $options['domain'] = '';
        if(!isset($options['secure'])) {
            if(empty($_SERVER['https'])) $options['secure'] = false; else $options['secure'] = true;
        } 
        if(!isset($options['httponly'])) $options['httponly'] = true; //true=javascript cannot access cookie value
        if(!isset($options['expire'])) $options['expire'] = time()+(60*60*24*$expire_days); //set to 0 to expire when browser closed
        
        $value=Secure::clean('header',$value);
        //NB: setcookie automatically urlencodes cookie value
        setcookie($name,$value,$options['expire'],$options['path'],$options['domain'],$options['secure'],$options['httponly']); 
    } 
    
    public static function getCookie($name)
    {
        $value = '';
        if(isset($_COOKIE[$name])) $value = $_COOKIE[$name];
        return $value;
    }

    public static function getVariable($variable_name,$sequence = 'GPCS',$default = '') 
    {
        $value = $default;
        
        $n = strlen($sequence);
        for($i = 0; $i < $n; $i++) {
            switch($sequence[$i]) {
                case 'G': if(isset($_GET[$variable_name])) $value = $_GET[$variable_name]; break;
                case 'P': if(isset($_POST[$variable_name])) $value = $_POST[$variable_name]; break; 
                case 'C': if(isset($_COOKIE[$variable_name])) $value = $_COOKIE[$variable_name]; break; 
                case 'S': if(isset($_SESSION[$variable_name])) $value = $_SESSION[$variable_name]; break;
            }  
        }  
        
        return $value;
    }  
     
}
