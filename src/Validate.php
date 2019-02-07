<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Secure;

//referenced as "Validate::function_name()"
//NB: some functions modify $value as well to make compatible with database, see cellNo/boolean/number/integer
class Validate 
{
    
    public static function cellNo($name,&$value,&$error) 
    {
        $error='';
        $name='['.$name.']';
                
        //strip out any spaces in number
        $value=str_replace(' ','',$value);
        //only integer numeric values
        if(preg_match('/[^0-9]/',$value)) $error.='Only numbers allowed in '.$name.'!<br/>';
        
        if(substr($value,0,1)==='0') {
            if(strlen($value)!==10) $error.=$name.' number with "0" prefix must be 10 digits!(Country code added automatically)<br/>'; 
        } else {
            if(strlen($value)!==11) $error.=$name.' international numbers must be 11 digits including country code!(local cell numbers must have "0" prefix)<br/>'; 
        }    
        
        if($error=='')  return true; else return false;
    }
    
    public static function boolean($name,&$value,&$error) 
    {
        $error='';
        $name='['.$name.']';
        if((!is_int(intval($value)))) {
            $error.='Value for '.$name.' must be boolean(0 or 1)!<br/>';
        } else {
            if($value!=0 and $value!=1) $error.='Value for '.$name.' must be boolean(0 or 1)!<br/>';
        }
        
        if($error=='')  return true; else return false;
    }

    public static function number($name,$min,$max,&$num,&$error)
    {
        $error='';
        $name='['.$name.']';
        
        $num=str_replace(THOUSAND_SEPARATOR,'',trim($num));
        //NB: MySQL only recognises "." as a decimal point
        $num=str_replace(DECIMAL_SEPARATOR,'.',trim($num));
        
        if($num=='')
        {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if(!is_numeric($num))
            {
                $error.='Value['.$num.'] for '.$name.' is not a valid number!<br/>';
            } else {
                if($num<$min) $error.='Value for '.$name.' is less than minimum allowable value['.$min.']!<br/>';
                if($num>$max) $error.='Value for '.$name.' is greater than maximum allowable value['.$max.']!<br/>';
            }
        }

        if($error=='')  return true; else return false;
    }

    public static function integer($name,$min,$max,&$num,&$error)
    {
        $error='';
        $name='['.$name.']';
        
        $num=str_replace(THOUSAND_SEPARATOR,'',trim($num));
        
        if($num=='')
        {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if(!is_numeric($num))
            {
                $error.='Value for '.$name.' is not a valid integer!<br/>';
            } elseif(!is_int(intval($num))) {
                $error.='Value['.$num.'] for '.$name.' is not a valid integer!<br/>';
            } else {  
                if($num<$min) $error.='Value for '.$name.' is less than minimum allowable value['.$min.']!<br/>';
                if($num>$max) $error.='Value for '.$name.' is greater than maximum allowable value['.$max.']!<br/>';
            }
        }

        if($error=='')  return true; else return false;
    }
    
    public static function password($name,$min,$max,$str,&$error)
    {
        $error='';
        $name='['.$name.']';
        $min=abs($min);
        $max=abs($max);
        $str_length=strlen($str);
                
        if($str_length==0 and $min!=0)
        {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($str_length<$min or $str_length>$max)
            {
                $error.='Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length!<br/>';
            } 
        }
        
        if($error==''){
            $options=array();
            if(!Secure::check('text',$str,$options,$error)) {
                $error='Entry for '.$name.' failed security check: '.$error.'<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function securePassword($password,$rules,&$error) 
    {
        $error='';
        
        if(!isset($rules['alpha'])) $rules['alpha']=true;
        if(!isset($rules['length'])) $rules['length']=8;
        if(!isset($rules['strong'])) $rules['strong']=true;
                
        if($rules['alpha'] and !ctype_alnum($password)) $error.='Password may only contain letters and numbers(no spaces)!<br/>'; 
        if(strlen($password)<$rules['length'])  $error.='Password less than mimimum length of '.$rules['length'].'!<br/>';
        if($rules['strong']) {
            if(!preg_match('`[A-Z]`',$password)) $error.='Password must contain at least ONE uppercase letter<br/>';
            if(!preg_match('`[a-z]`',$password)) $error.='Password must contain at least ONE lowercase letter<br/>';
            if(!preg_match('`[0-9]`',$password)) $error.='Password must contain at least ONE number<br/>';
        }
        
        if($error=='')  return true; else return false;  
    }

    public static function fixedString($name,$required = true,$length,$str,&$error,$secure = true) 
    {
        $error='';
        $name='['.$name.']';
        $length=abs($length);
        $str_length=strlen($str);
                
        if($str_length==0 and $required) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($str_length!=0 and $str_length!==$length) {
                $error.='Entry for '.$name.' must be '.$length.' characters in length!<br/>';
            } 
        }
        
        if($error=='' and $str_length>0 and $secure){
            $options=array();
            if(!Secure::check('string',$str,$options,$error)) {
                 $error='Entry for '.$name.' failed security check: '.$error.'<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }

    public static function string($name,$min,$max,$str,&$error,$secure = true)
    {
        $error='';
        $name='['.$name.']';
        $min=abs($min);
        $max=abs($max);
        $str_length=strlen($str);
                
        if($str_length==0 and $min!=0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($str_length<$min or $str_length>$max)
            {
                $error.='Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length!<br/>';
            } 
        }
        
        if($error=='' and $str_length>0 and $secure) {
            $options=array();
            if(!Secure::check('string',$str,$options,$error)) {
                 $error='Entry for '.$name.' failed security check: '.$error.'<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function text($name,$min,$max,$str,&$error,$secure = true)
    {
        $error='';
        $name='['.$name.']';
        $min=abs($min);
        $max=abs($max);
        $str_length=strlen($str);
                
        if($str_length==0 and $min!=0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($str_length<$min or $str_length>$max)
            {
                $error.='Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length!<br/>';
            } 
        }
        
        if($error=='' and $str!=='' and $secure){
            $options=array();
            if(!Secure::check('text',$str,$options,$error)) {
                $error='Entry for '.$name.' failed security check: '.$error.'<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function html($name,$min,$max,$str,&$error,$secure = true)
    {
        $error='';
        $name='['.$name.']';
        $min=abs($min);
        $max=abs($max);
        $str_length=strlen($str);
                
        if($str_length==0 and $min!=0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($str_length<$min or $str_length>$max)
            {
                $error.='Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length!<br/>';
            } 
        }
        
        if($error=='' and $str!=='' and $secure){
            $options=array();
            if(!Secure::check('html',$str,$options,$error)) {
                $error='Entry for '.$name.' Security check: '.$error.'<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function email($name,$email,&$error)
    {
        $error='';
        $name='['.$name.']';
        
        $str_length=strlen($email);
        
        if($str_length==0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if (!preg_match("/^[0-9a-z]+(([\.\-_])[0-9a-z]+)*@[0-9a-z]+(([\.\-])[0-9a-z-]+)*\.[a-z]{2,6}$/i",$email)) {
                $error.='Entry for '.$name.' is not a valid email address!<br/>';
            } 
        }
        
        if($error==''){
            $options=array();
            if(!Secure::check('email',$email,$options,$error)) {
                $error='Security check: '.$error;
            } 
        }
        
        if($error=='')  return true; else return false;  
    }

    public static function url($name,$url,&$error)
    {
        $error='';
        $name='['.$name.']';
                    
        $str_length=strlen($url);
        
        if($str_length==0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if(stripos($url,'//')===false) $url='http://'.$url;
            
            $options=array();
            if(!Secure::check('url',$url,$options,$error)) {
                $error='Entry for '.$name.' is not a valid URL!<br/>'.$url;
            } 
        }

        if($error=='')  return true; else return false;  
    }

    public static function date($name,$date,$format='YYYY-MM-DD',&$error) {
        $error='';
        $name='['.$name.']';
        
        $str_length=strlen($date);
        
        if($str_length==0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($format=='YYYY-MM-DD') {
                $year =intval(substr($date,0,4));
                $month=intval(substr($date,5,2));
                $day  =intval(substr($date,8,2));
                if($year==0) $error.='Invalid year number! ';
                if($month==0) $error.='Invalid month number! ';
                if($error=='') {
                    if($year<1900 or $year>2100) $error.='Year must be between 1900 and 2100! ';  
                    if($month<1 or $month>12) $error.='Month must be between 1 and 12! '; 
                    if($day<1 or $day>31) $error.='Day must be between 1 and 31! '; 
                }
                if($error!='') $error='Entry for '.$name.' is invalid: '.$error.'<br/>';
            } else {
                $error.='Entry for '.$name.' is not a valid date format!<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }

    public static function dateParts($name,$year,$month,$day,&$error) {
        $error='';
        $error='';
        $name='['.$name.']';
        
        if(!is_numeric($year)) $error.='Invalid year number! ';
        if(!is_numeric($month)) $error.='Invalid month number! ';
        if(!is_numeric($day)) $error.='Invalid day number! ';
        
        if($error=='') {
            $year =intval($year);
            $month=intval($month);
            $day  =intval($day);
            
            if(!checkdate($month,$day,$year)) $error.='INVALID date ';  
            //additional checks
            if($year<1900 or $year>2100) $error.='Year must be between 1900 and 2100! ';  
            if($month<1 or $month>12) $error.='Month must be between 1 and 12! '; 
            if($day<1 or $day>31) $error.='Day must be between 1 and 31! '; 
        }
                
        if($error!='') $error.='Entry for '.$name.' is invalid: '.$error.'<br/>';
            
        $date_str=sprintf('%04d-%02d-%02d',$year,$month,$day);     
        return $date_str;    
    }

    public static function dateTime($name,$datetime,$format = 'YYYY-MM-DD HH:MM',&$error)
    {
        $error='';
        $name='['.$name.']';
        
        $str_length=strlen($datetime);
        
        if($str_length==0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($format=='YYYY-MM-DD HH:MM') {
                $year =intval(substr($datetime,0,4));
                $month=intval(substr($datetime,5,2));
                $day  =intval(substr($datetime,8,2));
                $hour =intval(substr($datetime,11,2));
                $min  =intval(substr($datetime,14,2));
                $sec  =intval(substr($datetime,17,2));
                
                if($year==0) $error.='Invalid year number! ';
                if($month==0) $error.='Invalid month number! ';
                if($day==0) $error.='Invalid day number! ';
                //if($hour==0) $error.='Invalid hours number! ';
                //if($min==0) $error.='Invalid minutes number! ';
                //if($sec==0) $error.='Invalid seconds number! ';
                
                if($error=='') {
                    if($year<1900 or $year>2100) $error.='Year must be between 1900 and 2100! ';  
                    if($month<1 or $month>12) $error.='Month must be between 1 and 12! '; 
                    if($day<1 or $day>31) $error.='Day must be between 1 and 31! '; 
                    if($hour<0 or $hour>23) $error.='Hour must be between 00 and 23! '; 
                    if($min<0 or $min>59) $error.='Minutes must be between 00 and 59! ';  
                    if($sec<0 or $sec>59) $error.='Seconds must be between 00 and 59! ';  
                }
                
                if($error!='') $error='Entry for '.$name.' is invalid: '.$error.'<br/>';
            } else {
                $error.='Entry for '.$name.' is not a valid datetime format!<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function time($name,$time,$format='HH:MM:SS',&$error)  
    {
        $error='';
        $name='['.$name.']';
        
        $str_length=strlen($time);
                
        if($str_length==0) {
            $error.='Please enter a value for '.$name.'<br/>';
        } else {
            if($format=='HH:MM:SS' or $format=='HH:MM') {
                $hour_str=substr($time,0,2);
                if($str_length>2) $min_str=substr($time,3,2); else $min_str='00';
                if($str_length>5) $sec_str=substr($time,6,2); else $sec_str='00';
                                
                $hour=intval($hour_str);
                $min=intval($min_str);
                $sec=intval($sec_str);                
                                
                if($hour==0 and $hour_str!='00') $error.='Invalid hours number! ';
                if($min==0 and $min_str!='00') $error.='Invalid minutes number! ';
                if($sec==0 and $sec_str!='00') $error.='Invalid seconds number! ';
                
                if($error=='') {
                    if($hour<0 or $hour>23) $error.='Hour must be between 00 and 23! '; 
                    if($min<0 or $min>59) $error.='Minutes must be between 00 and 59! ';  
                    if($sec<0 or $sec>59) $error.='Seconds must be between 00 and 59! ';  
                }
                
                if($error!='') $error='Entry for '.$name.' is invalid: '.$error.'<br/>';
            } else {
                $error.='Entry for '.$name.' is not a valid time format!<br/>';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
}
