<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Secure;
use Seriti\Tools\Date;

//referenced as "Validate::function_name()"
//NB: some functions modify $value as well to make compatible with database, see cellNo/boolean/number/integer
class Validate 
{
    //modified from https://codeblock.co.za/how-to-validate-a-south-african-id-number-with-php/
    public static function saIdNumber($id_number,&$error,$known = [])
    {
        $error = '';
        $error_tmp = '';
        $validated = false;

        //get array of characters in ID
        $num_array = str_split($id_number);

        if(strlen($id_number) !== 13) $error .= 'Must be 13 characters. ';

        if(!is_numeric($id_number)) $error .= 'Can only contain numbers. ';
        
        $date_str = Date::convertDate(substr($id_number,0,6),'YYMMDD','YYYY-MM-DD',$error_tmp);
        if($error_tmp != '') $error .= 'Invalid birth date['.$error_tmp.']. ';

        if($error === '') {
            // Validate gender
            $id_gender = $num_array[6] >= 5 ? 'male' : 'female';
            if(isset($known['gender']) and strtolower($known['gender']) !== $id_gender) {
                $error .= 'Indicated gender['.$known['gender'].'] does not match ID gender['.$id_gender.'] ';
            }

            // Validate citizenship
            $id_foreigner = $num_array[10]; //0 = south african, 1 = foreigner
            if(isset($known['foreigner']) and (int)$known['foreigner'] !== (int)$id_foreigner ) {
                $error .= 'Indicated citizenship['.$known['foreigner'].'] does not match ID citizenship['.$id_foreigner.'] ';
            }
        }
        
        //Finally apply Luhn Algorithm test
        if($error === '') {    
            $even_digits = [];
            $odd_digits = [];

            foreach ( $num_array as $index => $digit) {
                if ($index === 0 || $index % 2 === 0) {
                    $odd_digits[] = $digit;
                } else {
                    $even_digits[] = $digit;
                }
            }

            $check_digit = array_pop($odd_digits);

            //All digits in odd positions (excluding the check digit) must be added together.
            $added_odds = array_sum($odd_digits);

            //All digits in even positions must be concatenated to form a 6 digit number.
            $concatenated_evens = implode('', $even_digits);

            //This 6 digit number must then be multiplied by 2.
            $evensx2 = $concatenated_evens * 2;

            // Add all the numbers produced from the even numbers x 2
            $added_evens = array_sum( str_split($evensx2) );

            $sum = $added_odds + $added_evens;

            // get the last digit of the $sum
            $last_digit = substr($sum, -1);

            /* 10 - $last_digit
             * $verify_check_digit = 10 - (int)$last_digit; (Will break if $last_digit = 0)
             * Edit suggested by Ruan Luies
             * verify check digit is the resulting remainder of
             *  10 minus the last digit divided by 10
             */
             $verify_check_digit = (10 - (int)$last_digit) % 10;

            // test expected last digit against the last digit in $id_number submitted
            if ((int)$verify_check_digit !== (int)$check_digit) {
                $error .= 'Luhn algorithm failure.';
            }
        }


        if($error === '') return true; else return false;

    }

    public static function cellNo($name,&$value,&$error) 
    {
        $error = '';
        $name = '['.$name.']';
                
        //strip out any spaces in number
        $value = str_replace(' ','',$value);
        //only integer numeric values
        if(preg_match('/[^0-9]/',$value)) $error.='Only numbers allowed in '.$name.'. ';
        
        if(substr($value,0,1) === '0') {
            if(strlen($value) !== 10) $error .= $name.' number with "0" prefix must be 10 digits!(Country code added automatically). '; 
        } else {
            if(strlen($value) !== 11) $error .= $name.' international numbers must be 11 digits including country code!(local cell numbers must have "0" prefix). '; 
        }    
        
        if($error === '')  return true; else return false;
    }
    
    public static function boolean($name,&$value,&$error) 
    {
        $error = '';
        $name = '['.$name.']';
        if((!is_int(intval($value)))) {
            $error .= 'Value for '.$name.' must be boolean(0 or 1). ';
        } else {
            if($value != 0 and $value != 1) $error .= 'Value for '.$name.' must be boolean(0 or 1). ';
        }
        
        if($error === '')  return true; else return false;
    }

    public static function number($name,$min,$max,&$num,&$error)
    {
        $error = '';
        $name = '['.$name.']';
        
        $num = str_replace(THOUSAND_SEPARATOR,'',trim($num));
        //NB: MySQL only recognises "." as a decimal point
        $num = str_replace(DECIMAL_SEPARATOR,'.',trim($num));
        
        if($num === '') {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if(!is_numeric($num)) {
                $error .= 'Value['.$num.'] for '.$name.' is not a valid number. ';
            } else {
                if($num < $min) $error .= 'Value['.$num.'] for '.$name.' is less than minimum allowable value['.$min.']. ';
                if($num > $max) $error .= 'Value['.$num.'] for '.$name.' is greater than maximum allowable value['.$max.']. ';
            }
        }

        if($error === '')  return true; else return false;
    }

    public static function integer($name,$min,$max,&$num,&$error)
    {
        $error = '';
        $name = '['.$name.']';
        
        $num = str_replace(THOUSAND_SEPARATOR,'',trim($num));
        
        if($num === '') {
            $error.='Please enter a value for '.$name.'. ';
        } else {
            if(!is_numeric($num)) {
                $error .= 'Value['.$num.'] for '.$name.' is not a valid integer. ';
            } elseif(!is_int(intval($num))) {
                $error .= 'Value['.$num.'] for '.$name.' is not a valid integer. ';
            } else {  
                if($num < $min) $error .= 'Value['.$num.'] for '.$name.' is less than minimum allowable value['.$min.']. ';
                if($num > $max) $error .= 'Value['.$num.'] for '.$name.' is greater than maximum allowable value['.$max.']. ';
            }
        }

        if($error === '')  return true; else return false;
    }
    
    public static function password($name,$min,$max,$str,&$error)
    {
        $error = '';
        $name = '['.$name.']';
        $min = abs($min);
        $max = abs($max);
        $str_length = strlen($str);
                
        if($str_length == 0 and $min != 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($str_length < $min or $str_length > $max) {
                $error .= 'Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length. ';
            } 
        }
        
        if($error === ''){
            $options = array();
            if(!Secure::check('text',$str,$options,$error)) {
                $error = 'Entry for '.$name.' failed security check: '.$error.'. ';
            } 
        }
        
        if($error === '')  return true; else return false;  
    }
    
    public static function securePassword($password,$rules,&$error) 
    {
        $error = '';
        
        if(!isset($rules['alpha'])) {
            if(defined('PASSWORD_ALPHA_NUMERIC')) $rules['alpha'] = PASSWORD_ALPHA_NUMERIC; else $rules['alpha'] = true;
        }    
        if(!isset($rules['length'])) {
            if(defined('PASSWORD_LENGTH')) $rules['length'] = PASSWORD_LENGTH; else $rules['length'] = 8;
        }
        if(!isset($rules['strong'])) {
            if(defined('PASSWORD_STRONG')) $rules['strong'] = PASSWORD_STRONG; else $rules['strong'] = true;
        }    
                
        if($rules['alpha'] and !ctype_alnum($password)) $error .= 'Password may only contain letters and numbers(no spaces). '; 
        if(strlen($password)<$rules['length'])  $error .= 'Password less than mimimum length of '.$rules['length'].'. ';
        if($rules['strong']) {
            if(!preg_match('`[A-Z]`',$password)) $error .= 'Password must contain at least ONE uppercase letter. ';
            if(!preg_match('`[a-z]`',$password)) $error .= 'Password must contain at least ONE lowercase letter. ';
            if(!preg_match('`[0-9]`',$password)) $error .= 'Password must contain at least ONE number. ';
        }
        
        if($error === '')  return true; else return false;  
    }

    public static function fixedString($name,$required = true,$length,$str,&$error,$secure = true) 
    {
        $error = '';
        $name = '['.$name.']';
        $length = abs($length);
        $str_length = strlen($str);
                
        if($str_length == 0 and $required) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($str_length != 0 and $str_length !== $length) {
                $error .= 'Entry['.$str.'] for '.$name.' must be '.$length.' characters in length. ';
            } 
        }
        
        if($error === '' and $str_length > 0 and $secure){
            $options = array();
            if(!Secure::check('string',$str,$options,$error)) {
                 $error = 'Entry['.$str.'] for '.$name.' failed security check: '.$error.'. ';
            } 
        }
        
        if($error === '')  return true; else return false;  
    }

    public static function string($name,$min,$max,$str,&$error,$secure = true)
    {
        $error = '';
        $name = '['.$name.']';
        $min = abs($min);
        $max = abs($max);
        $str_length = strlen($str);
                
        if($str_length == 0 and $min != 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($str_length < $min or $str_length > $max) {
                $error .= 'Entry['.$str.'] for '.$name.' must be between '.$min.' and '.$max.' characters in length. ';
            } 
        }
        
        if($error === '' and $str_length > 0 and $secure) {
            $options = array();
            if(!Secure::check('string',$str,$options,$error)) {
                 $error = 'Entry['.$str.'] for '.$name.' failed security check: '.$error.'. ';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function text($name,$min,$max,$str,&$error,$secure = true)
    {
        $error = '';
        $name = '['.$name.']';
        $min = abs($min);
        $max = abs($max);
        $str_length = strlen($str);
                
        if($str_length == 0 and $min != 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($str_length < $min or $str_length > $max) {
                $error .= 'Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length. ';
            } 
        }
        
        if($error === '' and $str !== '' and $secure){
            $options = array();
            if(!Secure::check('text',$str,$options,$error)) {
                $error = 'Entry for '.$name.' failed security check: '.$error.'. ';
            } 
        }
        
        if($error === '')  return true; else return false;  
    }
    
    public static function html($name,$min,$max,$str,&$error,$secure = true)
    {
        $error = '';
        $name = '['.$name.']';
        $min = abs($min);
        $max = abs($max);
        $str_length = strlen($str);
                
        if($str_length == 0 and $min != 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($str_length < $min or $str_length > $max) {
                $error.='Entry for '.$name.' must be between '.$min.' and '.$max.' characters in length. ';
            } 
        }
        
        if($error === '' and $str !== '' and $secure){
            $options = array();
            if(!Secure::check('html',$str,$options,$error)) {
                $error = 'Entry for '.$name.' Security check: '.$error.'. ';
            } 
        }
        
        if($error=='')  return true; else return false;  
    }
    
    public static function email($name,&$email,&$error)
    {
        $error = '';
        $name = '['.$name.']';

        //leading and trailing spaces are invalid for email addresses.
        $email = trim($email);
        
        $str_length = strlen($email);
        
        if($str_length == 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if (!preg_match("/^[0-9a-z]+(([\.\-_])[0-9a-z]+)*@[0-9a-z]+(([\.\-])[0-9a-z-]+)*\.[a-z]{2,6}$/i",$email)) {
                $error .= 'Entry['.$email.'] for '.$name.' is not a valid email address. ';
            } 
        }
        
        if($error === ''){
            $options=array();
            if(!Secure::check('email',$email,$options,$error)) {
                $error = 'Security check: '.$error;
            } 
        }
        
        if($error === '')  return true; else return false;  
    }

    public static function url($name,$url,&$error)
    {
        $error = '';
        $name = '['.$name.']';
                    
        $str_length = strlen($url);
        
        if($str_length == 0) {
            $error.='Please enter a value for '.$name.'. ';
        } else {
            if(stripos($url,'//') === false) $url = 'http://'.$url;
            
            $options = array();
            if(!Secure::check('url',$url,$options,$error)) {
                $error .= 'Entry['.$url.'] for '.$name.' is not a valid URL. '.$url;
            } 
        }

        if($error === '')  return true; else return false;  
    }

    public static function date($name,$date,$format='YYYY-MM-DD',&$error) {
        $error = '';
        $name = '['.$name.']';
        
        $str_length = strlen($date);
        
        if($str_length == 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($format === 'YYYY-MM-DD') {
                $year = intval(substr($date,0,4));
                $month = intval(substr($date,5,2));
                $day  = intval(substr($date,8,2));
                if($year == 0) $error .= 'Invalid year number. ';
                if($month == 0) $error .= 'Invalid month number. ';
                if($error === '') {
                    if($year < 1900 or $year > 2100) $error .= 'Year must be between 1900 and 2100. ';  
                    if($month < 1 or $month > 12) $error .= 'Month must be between 1 and 12. '; 
                    if($day < 1 or $day > 31) $error .= 'Day must be between 1 and 31. '; 
                }
                if($error !== '') $error = 'Entry['.$date.'] for '.$name.' is invalid: '.$error.'. ';
            } else {
                $error .= 'Entry['.$date.'] for '.$name.' is not a valid date format. ';
            } 
        }
        
        if($error === '')  return true; else return false;  
    }

    public static function dateParts($name,$year,$month,$day,&$error) {
        $error = '';
        $error = '';
        $name = '['.$name.']';
        
        if(!is_numeric($year)) $error .= 'Invalid year number. ';
        if(!is_numeric($month)) $error .= 'Invalid month number. ';
        if(!is_numeric($day)) $error .= 'Invalid day number. ';
        
        if($error === '') {
            $year = intval($year);
            $month = intval($month);
            $day  = intval($day);
            
            if(!checkdate($month,$day,$year)) $error .= 'INVALID date ';  
            //additional checks
            if($year < 1900 or $year > 2100) $error .= 'Year must be between 1900 and 2100. ';  
            if($month < 1 or $month > 12) $error .= 'Month must be between 1 and 12. '; 
            if($day < 1 or $day > 31) $error .= 'Day must be between 1 and 31. '; 
        }
                
        if($error !== '') $error = 'Entry for '.$name.' is invalid: '.$error.'. ';
            
        $date_str = sprintf('%04d-%02d-%02d',$year,$month,$day);     
        return $date_str;    
    }

    public static function dateTime($name,$datetime,$format = 'YYYY-MM-DD HH:MM',&$error)
    {
        $error = '';
        $name = '['.$name.']';
        
        $str_length = strlen($datetime);
        
        if($str_length == 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($format === 'YYYY-MM-DD HH:MM') {
                $year = intval(substr($datetime,0,4));
                $month= intval(substr($datetime,5,2));
                $day  = intval(substr($datetime,8,2));
                $hour = intval(substr($datetime,11,2));
                $min  = intval(substr($datetime,14,2));
                $sec  = intval(substr($datetime,17,2));
                
                if($year == 0) $error .= 'Invalid year number. ';
                if($month == 0) $error .= 'Invalid month number. ';
                if($day == 0) $error .= 'Invalid day number. ';
                //if($hour==0) $error.='Invalid hours number. ';
                //if($min==0) $error.='Invalid minutes number. ';
                //if($sec==0) $error.='Invalid seconds number. ';
                
                if($error === '') {
                    if($year < 1900 or $year > 2100) $error .= 'Year must be between 1900 and 2100. ';  
                    if($month < 1 or $month > 12) $error .= 'Month must be between 1 and 12. '; 
                    if($day < 1 or $day > 31) $error .= 'Day must be between 1 and 31. '; 
                    if($hour < 0 or $hour > 23) $error .= 'Hour must be between 00 and 23. '; 
                    if($min < 0 or $min > 59) $error .= 'Minutes must be between 00 and 59. ';  
                    if($sec < 0 or $sec > 59) $error .= 'Seconds must be between 00 and 59. ';  
                }
                
                if($error !== '') $error = 'Entry['.$datetime.'] for '.$name.' is invalid: '.$error.'. ';
            } else {
                $error .= 'Entry['.$datetime.'] for '.$name.' is not a valid datetime format. ';
            } 
        }
        
        if($error === '')  return true; else return false;  
    }
    
    public static function time($name,$time,$format='HH:MM:SS',&$error)  
    {
        $error = '';
        $name = '['.$name.']';
        
        $str_length = strlen($time);
                
        if($str_length == 0) {
            $error .= 'Please enter a value for '.$name.'. ';
        } else {
            if($format === 'HH:MM:SS' or $format === 'HH:MM') {
                $hour_str = substr($time,0,2);
                if($str_length > 2) $min_str = substr($time,3,2); else $min_str = '00';
                if($str_length > 5) $sec_str = substr($time,6,2); else $sec_str = '00';
                                
                $hour = intval($hour_str);
                $min = intval($min_str);
                $sec = intval($sec_str);                
                                
                if($hour == 0 and $hour_str != '00') $error .= 'Invalid hours number. ';
                if($min == 0 and $min_str != '00') $error .= 'Invalid minutes number. ';
                if($sec == 0 and $sec_str != '00') $error .= 'Invalid seconds number. ';
                
                if($error === '') {
                    if($hour < 0 or $hour > 23) $error .= 'Hour must be between 00 and 23. '; 
                    if($min < 0 or $min > 59) $error .= 'Minutes must be between 00 and 59. ';  
                    if($sec < 0 or $sec > 59) $error .= 'Seconds must be between 00 and 59. ';  
                }
                
                if($error !== '') $error = 'Entry['.$time.'] for '.$name.' is invalid: '.$error.'. ';
            } else {
                $error .= 'Entry['.$time.'] for '.$name.' is not a valid time format. ';
            } 
        }
        
        if($error === '')  return true; else return false;  
    }

    public static function dateInterval($date_from,$date_to,&$error)  {
       $error = '';
       $error_tmp = '';

       if(!Self::date('Start date',$date_from,'YYYY-MM-DD',$error_tmp)) {
          $error .= $error_tmp;
       }

       if(!Self::date('End date',$date_to,'YYYY-MM-DD',$error_tmp)) {
          $error .= $error_tmp;
       }

       if($error === '') {
           $from = Date::getDate($date_from); 
           $to = Date::getDate($date_to); 

           if($from[0] >= $to[0]) {
              $error = 'Start date['.$date_from.'] cannot be after End date['.$date_to.']';
           } 
       }
    }

    public static function monthInterval($from_month,$from_year,$to_month,$to_year,&$error)  {
       $error = '';
       $error_tmp = '';

       if($from_month < 1 or $from_month > 12) {
          $error .= 'From month['.$from_month.'] is not valid month number.' ;
       }

       if($from_year < 1900 or $from_year > 2100) {
          $error .= 'From year['.$from_year.'] is not valid year.' ;
       }

       if($to_month < 1 or $to_month > 12) {
          $error .= 'To month['.$to_month.'] is not valid month number.' ;
       }

       if($to_year < 1900 or $to_year > 2100) {
          $error .= 'To year['.$to_year.'] is not valid year.' ;
       }

       if($error === '') {
           $from_count = $from_year*12 + $from_month;
           $to_count = $to_year*12 + $to_month;

           if($from_count > $to_count) {
              $error = 'From month['.$from_year.':'.$from_month.'] cannot be after To month['.$to_year.':'.$to_month.']';
           } 
       }
    }

}
