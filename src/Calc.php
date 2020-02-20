<?php 
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\DbInterface;
use Seriti\Tools\Date;

use Seriti\Tools\TABLE_SYSTEM;

//class intended as a pseudo namespace for a group of generic helper functions to be referenced as "Calc::function_name()"
class Calc 
{

    public static function compareString($str1,$str2,$method = 'LEVENSHTEIN')
    {
        $match_pct = 0;

        $str1 = strtolower($str1);
        $str2 = strtolower($str2);

        $base = max(strlen($str1), strlen($str2));
        
        if($method === 'LEVENSHTEIN') {
            
            if($base > 255) {
                //levenshtein cannot operate on > 255 char
                $str1 = substring($str1,0,255);
                $str2 = substring($str2,0,255);
                $base = 255;
            }

            $match_pct = 100 * (1 - (levenshtein($str1,$str2) / $base));
        }

        if($method === 'SIMILAR') {
            $count = similar_text($str1,$str2);

            $match_pct = 100 * ($count / $base);

        }    
        
        return $match_pct;
    }

    public static function checkTimeout($time_start,$time_max,$time_tolerance = 5)
    {
        if($time_start == 0 or $time_max == 0) return false;
        
        $time_passed = time()-$time_start;
        $time_trigger = $time_max-$time_tolerance;
                
        if($time_passed > $time_trigger) return true; 

        return false;
    }

    public static function getArrayFirst($arr = []) 
    {
        $first = [];
        foreach ($arr as $key => $value) {
            $first = ['key'=>$key,'value'=>$value];
            break;
        }
       
        return $first;
    }

    public static function isAssocArray($arr = []) 
    {
        return (array_values($array) !== $array);
    }

    //bitmask operator to display which flags set
    public static function getBitmaskFlags($bitmask,$flags = array()) 
    {
        $flags_set = array();
        foreach($flags as $i => $flag) {
            $bit = pow(2,$i);
            if($bitmask & $bit) $flags_set[] = $flag; 
        } 
        return $flags_set; 
    }

    //combine set flags into single bitmask
    public static function setBitmaskFlags($set_flags = array(),$flags = array()) 
    {
        $bitmask = 0;
        foreach($flags as $i => $flag) {
            $bit = pow(2,$i);
            if(in_array($flag,$set_flags)) {
                $bitmask = ($bitmask | $bit);
            } 
        } 
        return $bitmask; 
    }
        
    //UTF8 is variable length so each bit does not always represent a single character..
    public static function splitStrUtf8($str,$l = 0) 
    {
        if($l > 0) {
            $ret = array();
            $len = mb_strlen($str, "UTF-8");
            for ($i = 0; $i < $len; $i += $l) {
                $ret[] = mb_substr($str, $i, $l, "UTF-8");
            }
            return $ret;
        }
        return preg_split("//u", $str, -1, PREG_SPLIT_NO_EMPTY);
    }
    
    public static function mergeData($original = array(),$duplicate = array(),$options = array()) 
    {
        if(!isset($options['merge'])) $options['merge'] = 'EMPTY';
        if(!isset($options['empty_value'])) $options['empty_value'] = '';
        
        if($options['merge'] === 'EMPTY') {
            foreach($original as $key => $value) {
                if($value == $options['empty_value'] and isset($duplicate[$key]) and $duplicate[$key] != $options['empty_value']) {
                    $original[$key] = $duplicate[$key];
                }  
            }
        }  
        
        return $original;
    }  
    
    //get NEXT valid file id and update system table accordingly
    public static function getFileId(DbInterface $db)  
    {
        $table = TABLE_SYSTEM;
        
        $id = 0;
        $error = '';
        $error_tmp = '';
                     
        $sql = 'LOCK TABLES `'.$table.'` WRITE';
        $db->executeSql($sql,$error_tmp);
        if($error_tmp != '') $error .= 'Could NOT lock system table for FILE counter!'; 
                            
        if($error == '') {          
            $sql = 'SELECT sys_count FROM `'.$table.'` WHERE system_id = "FILES" ';
            $id = $db->readSqlValue($sql,0);
            if($id == 0) {
                $error .= 'Could not read System table FILES value!';
            } else {
                $id = $id+1;   
            }
        }
        
        if($error == '') {          
            $sql = 'UPDATE `'.$table.'` SET sys_count = sys_count + 1 WHERE system_id = "FILES" ';
            $db->executeSql($sql,$error_tmp);
            if($error_tmp != '') $error .= 'Could not update system FILES value';
        }
                
        $sql = 'UNLOCK TABLES';
        $db->executeSql($sql,$error_tmp);
        if($error_tmp != '') $error .= 'Could NOT UNlock system table for INVOICE counter!';
            
        if($error !== '') {
            throw new Exception('SYSTEM_INVOICE_ID_ERROR['.$error.']');
        }      
        
        return $id;
    } 
    
    public static function floatVal($value,$options = array()) 
    {
        if(!isset($options['separator'])) $options['separator'] = ',';
        if(!isset($options['quote'])) $options['quote'] = '"';
        
        $value = trim($value);
        $value = str_replace(' ','',$value);
        $value = str_replace($options['separator'],'',$value);
        $value = str_replace($options['quote'],'',$value);
        $value = floatval($value);
        
        return $value;
    }  
    
    //this will sort an array of arrays based on sort_key
    public static function sortArray(&$array = array(),$sort_key,$options = array())  
    {
        if(!isset($options['order'])) $options['order'] = 'ASC';
        usort($array, self::buildSorter($sort_key,$options));    
    }
    
    public static function buildSorter($key,$options) 
    {
        return function ($a, $b) use ($key,$options) {
            if($options['order'] === 'DESC') {      
                return strnatcmp($b[$key],$a[$key]);
            } else {
                return strnatcmp($a[$key],$b[$key]);
            }    
        };
    }
    
    //convert bytes value into more sensible units
    public static function displayBytes($bytes,$precision = 2,$units = 'AUTO') 
    {
        $byte_str = '0B';
        if(is_numeric($bytes) and $bytes > 0) {
            $base = log($bytes)/log(1024);
            //NB: position in array is vital
            $units_arr = array('B', 'KB', 'MB', 'GB', 'TB','PB');   
            
            if($units === 'AUTO') {
                $byte_str = round(pow(1024,$base-floor($base)),$precision).$units_arr[floor($base)];  
            } else {
                if(!in_array($units,$units_arr)) $units = 'MB';
                $key = array_search($units,$units_arr);
                $div = pow(1024,$key);
                $byte_str = round($bytes/$div,$precision).$units;
            }   
        }
        return $byte_str;
    }
    
    public static function formatNum($num,$dec,$options = array()) 
    {
        if(!isset($options['negative'])) $options['negative'] = '(RED)';
        if(!isset($options['1000_spacer'])) $options['1000_spacer'] = ' ';
        if(!isset($options['curr_symbol'])) $options['curr_symbol'] = '';
        if(!isset($options['dec_point'])) $options['dec_point'] = '.';
        
        
        if(round($num,$dec)<0.0) $neg = true; else $neg = false;
        
        $num = abs($num);
        $str = number_format($num,$dec,$options['dec_point'],$options['1000_spacer']);
        
        $str = $options['curr_symbol'].$str;
        if($neg  and $options['negative'] == '(RED)')  $str = '<font color="#FF0000">('.$str.')</font>';
        
            
        return $str;
    }
    
    public static function formatReturn($return,$format_in = 'BASIC',$format_out = 'BASIC') 
    {
        if(is_null($return)) {
            $str_out = 'UNKNOWN';
        } else {
            if($format_in == 'MYSQL_DATE_RET') {
                $date_str = substr($return,0,10);
                $return = substr($return,10);
                $date = Date::mysqlGetDate($date_str);
            }
            
            if($format_out == 'BASIC') $str_out = sprintf("%01.2f",$return);
            
            if($format_out == '(RED)' or $format_out == 'MMMYY(RED)') {
                $str_out = sprintf("%01.2f",abs($return));
                
                if($return < 0.0) {
                    $str_out = '<font color="#FF0000">('.$str_out.'%)</font>'; 
                } else {
                    $str_out = $str_out.'%';
                }
            }

            if($format_out == 'MMMYY(RED)') {
                $str_out = substr($date['month'],0,3).substr($date['year'],-2).':'.$str_out;
            }
        }
        return $str_out;
    }
    
    //puts any text into a span and add copy to clipboard link. needs seriti/javascript.js
    public static function viewTextCopyLink($type,$id,$text)
    {
        if($type === 'MASK') {
            $text = '<span id="'.$id.'" style="display:none;">'.$text.'</span>*****&nbsp;';
        } else {
            $text = '<span id="'.$id.'">'.$text.'</span>&nbsp;';
        }

        $text .= '<a href="javascript:void(0);" onclick="copy_to_clipboard(\''.$id.'\');">[copy]</a>';
        
        return $text;
    } 
}