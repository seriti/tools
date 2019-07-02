<?php
namespace Seriti\Tools;

use Exception;

//class intended as a pseudo namespace for a group of functions to be referenced as "Date::function_name()"
class Date 
{
    //use when imporfting csv dates of unknown/variable format
    public static function convertAnyDate($date_str,$sequence='YMD',$format_convert='YYYY-MM-DD',&$error_str,$options=array())
    {
        $error_str='';
        $default='0000-00-00';
        $month_arr=array('jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                                         'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12');
                                         
        if(!isset($options['y2k_cut'])) $options['y2k_cut']=70;
                
        $str=trim($date_str);
        $length=strlen($str);
                
        if($length<=8 and is_numeric($str)) { //assume that no spacers between values
            if($length==8) {
                if($sequence=='YMD') {
                    $year=substr($str,0,4);
                    $month=substr($str,4,2);
                    $day=substr($str,6,2);
                }  
            } elseif($length==6) {
                if($sequence=='YMD') {
                    $year=substr($str,0,2);
                    if($year<$options['y2k_cut']) $year+=2000; else $year+=1900;
                    $month=substr($str,2,2);
                    $day=substr($str,4,2);
                }  
            } else {
                $error_str.='UNKNOWN date string['.$str.']'; 
            }      
        } else {  
            $str=str_replace(' ','-',$str);
            $str=str_replace('/','-',$str);
            $arr=explode('-',$str);
            if(count($arr)!==3) {
                $error_str.='Date string does not contain 3 parts!';
            } else {  
                if(is_numeric($arr[1])) { //dd-mm-yyyy or yyyy-mm-dd
                    if($arr[2]>31) { //dd-mm-yyyy
                        $day=$arr[0];
                        $month=$arr[1];
                        $year=$arr[2];
                    } else { //yyyy-mm-dd
                        $day=$arr[2];
                        $month=$arr[1];
                        $year=$arr[0];
                    }
                } else { //dd-mmm-yyyy
                    $day=$arr[0];
                    $arr[1]=strtolower($arr[1]);
                    $month=$month_arr[substr($arr[1],0,3)];
                    $year=$arr[2];
                } 
                if(strlen($year)<3) {
                    if($year<$options['y2k_cut']) $year+=2000; else $year+=1900;
                }  
            }  
        }  

        if($error_str=='') {
            if(checkdate($month,$day,$year)) {
                $date_out=sprintf('%04d-%02d-%02d',$year,$month,$day);
            } else {
                $error_str.='Invalid date Y='.$year.' M='.$month.' D='.$day;
            }
        } 
        
        if($error_str!='') $date_out=$default;
        
        return $date_out;
    }
    
    public static function convertDate($date_str,$format_in='DD-MM-YYYY',$format_out='YYYY-MM-DD',&$error_str)
    {
        $error_str='';
        $default='0000-00-00';
        
        if($date_str=='') {
            $error_str='No date to convert!';
        } else {  
            $year=0;;
            $month=0;
            $day=0;
            
            if($format_in==='YYYYMMDD') {
                $year =intval(substr($date_str,0,4));
                $month=intval(substr($date_str,4,2));
                $day  =intval(substr($date_str,6,2));
            }
            if($format_in==='MM-DD-YYYY') {
                $month=intval(substr($date_str,0,2));
                $day  =intval(substr($date_str,3,2));  
                $year =intval(substr($date_str,6,4));
            }  
            if($format_in==='DD-MM-YYYY') {
                $day=intval(substr($date_str,0,2));
                $month=intval(substr($date_str,3,2));  
                $year =intval(substr($date_str,6,4));
            } 
            if($format_in==='MM-DD-YY') {
                $month=intval(substr($date_str,0,2));
                $day  =intval(substr($date_str,3,2));  
                $year =intval(substr($date_str,6,2));
                if($year>30) $year+=1900; else $year+=2000;
            }  
            if($format_in==='YYYY-MM-DD') {
                $year =intval(substr($date_str,0,4));
                $month=intval(substr($date_str,5,2));
                $day  =intval(substr($date_str,8,2));
            }  
            
            if(checkdate($month,$day,$year)) {
                $date_out=sprintf('%04d-%02d-%02d',$year,$month,$day);
            } else {
                $error_str.='Invalid date Y='.$year.' M='.$month.' D='.$day;
            }
        } 

        if($error_str!='') $date_out=$default;
        
        return $date_out;
    }  
    
    public static function getDate($date_str,$format='YYYY-MM-DD')
    {
        $year=0;;
        $month=0;
        $day=0;
        $hour=0;
        $min=0;
        $sec=0;
        
        if($date_str!='') {
            if($format==='YYYYMMDD') {
                $year =intval(substr($date_str,0,4));
                $month=intval(substr($date_str,4,2));
                $day  =intval(substr($date_str,6,2));
            }
            if($format==='MM-DD-YYYY') {
                $month=intval(substr($date_str,0,2));
                $day  =intval(substr($date_str,3,2));  
                $year =intval(substr($date_str,6,4));
            }  
            if($format==='DD-MM-YYYY') {
                $day=intval(substr($date_str,0,2));
                $month=intval(substr($date_str,3,2));  
                $year =intval(substr($date_str,6,4));
            } 
            if($format==='MM-DD-YY') {
                $month=intval(substr($date_str,0,2));
                $day  =intval(substr($date_str,3,2));  
                $year =intval(substr($date_str,6,2));
                if($year>30) $year+=1900; else $year+=2000;
            }  
            if($format==='YYYY-MM-DD') {
                $year =intval(substr($date_str,0,4));
                $month=intval(substr($date_str,5,2));
                $day  =intval(substr($date_str,8,2));
                if(strlen($date_str)>10) {
                    $hour=intval(substr($mysql_date,11,2));
                    $min =intval(substr($mysql_date,14,2));
                    $sec =intval(substr($mysql_date,17,2));
                } 
            }  
        }  
             
        $time=mktime($hour,$min,$sec,$month,$day,$year);
        $date_array=getdate($time);
        
        //modifies mktime default if date is 0000-00-00
        if($year==0 and $month==0 and $day==0) {
            $date_array['mday']=0;
            $date_array['mon']=0;
            $date_array['month']=0;
            $date_array['year']=0;
            $date_array['wday']=0;
            $date_array['weekday']=0;
            $date_array['yday']=0;
        }  

        return $date_array;
    } 
 
    public static function convertMonth($month_str,$format_in='MMYYYY',&$error_str) 
    {
        $result=array();
        $error_str='';
        
        if($month_str=='') {
            $error_str='No month to convert!';
        } else {  
            $year=0;;
            $month=0;
            $day=1;
            
            if($format_in==='MMYYYY') {
                $month=intval(substr($month_str,0,2));
                $year =intval(substr($month_str,2,4));
            }
            if($format_in==='MM-YYYY') {
                $month=intval(substr($month_str,0,2));
                $year =intval(substr($month_str,3,4));
            } 
            if($format_in==='YYYYMM') {
                $year =intval(substr($month_str,0,4));
                $month=intval(substr($month_str,4,2));
            }
            if($format_in==='YYYY-MM') {
                $year =intval(substr($month_str,0,4));
                $month=intval(substr($month_str,5,2));
            }  
            
            if(checkdate($month,$day,$year)) {
                $result['month']=$month;
                $result['year']=$year;
            } else {
                $error_str.='Invalid month('.$month_str.') Year='.$year.' Month='.$month;
            }
        } 
        
        if($error_str=='') return $result; else return false;
    }  
 
    public static function getMonthInfo($month,$year) 
    {
        $info=array();
        
        $info['name']=self::monthName($month);
        $time=mktime(0,0,0,$month,1,$year);
        $info['first']=date('Y-m-d',$time);
        $info['days']=date('t',$time);
        $info['last']=date('Y-m-d',mktime(0,0,0,$month+1,0,$year));
        
        //previous month
        $info['prev']['month']=self::incrementMonth($month,-1);
        if($info['prev']['month']>$month) $info['prev']['year']=$year-1; else $info['prev']['year']=$year;
        
        //next month
        $info['next']['month']=self::incrementMonth($month,1);
        if($info['next']['month']<$month) $info['next']['year']=$year+1; else $info['next']['year']=$year;
        
        
        return $info;
    }  
    
    public static function formatDate($date,$format_in='MYSQL',$format_out='DD-MMM-YYYY',$options=array())
    {
        if(!isset($options['separator'])) {
            if($format_out=='DD MON YYYY') $options['separator']=' '; else $options['separator']='-';
        } 
        
        if($date=='' or is_null($date))
        {
            $date_out=''; //was "UNKNOWN"
        } else {
                        
            switch($format_in)
            {
                case 'ARRAY': $tmp=$date;break;
                case 'MYSQL': $tmp=self::mysqlGetDate($date); break;
                case  'TIME': $tmp=getdate($date); break;
                default: $tmp=getdate(); break;
            }

            switch($format_out)
            {
                case 'DD MON YYYY': {
                    $date_out=$tmp['mday'].$options['separator'].$tmp['month'].$options['separator'].$tmp['year'];
                    break;
                } 
                case 'DD-MMM-YYYY': {
                    $date_out=$tmp['mday'].$options['separator'].substr($tmp['month'],0,3).$options['separator'].$tmp['year'];
                    break;
                } 
                case 'MMMYY': {
                    $date_out=substr($tmp['month'],0,3).substr($tmp['year'],-2);
                    break;
                } 
                case 'MMM-YYYY': {
                    $date_out=substr($tmp['month'],0,3).$options['separator'].$tmp['year'];
                    break;
                } 
                case 'YYYY-MM-DD': {
                    $date_out=sprintf('%04d'.$options['separator'].'%02d'.$options['separator'].'%02d',$tmp['year'],$tmp['mon'],$tmp['mday']);
                    break;
                } 
                case 'DD-MM-YYYY': {
                    $date_out=sprintf('%02d'.$options['separator'].'%02d'.$options['separator'].'%04d',$tmp['mday'],$tmp['mon'],$tmp['year']);
                    break;
                } 
                
                default: $date_out=$tmp['mday'].'-'.substr($tmp['month'],0,3).'-'.$tmp['year']; break;
            }
            
        }
        return $date_out;
    }

    public static function formatDateTime($datetime,$format_in='MYSQL',$format_out='DD-MMM-YYYY HH:MM')
    {
        if($datetime=='' or is_null($datetime))
        {
            $str_out=''; //was "UNKNOWN"
        } else {
            switch($format_in)
            {
                case 'ARRAY': $tmp=$datetime;break;
                case 'MYSQL': $tmp=self::mysqlGetDate($datetime); break;
                case  'TIME': $tmp=getdate($datetime); break;
                default: $tmp=getdate(); break;
            }

            //$time_str=sprintf("%02d:%02d:%02d",$tmp['hours'],$tmp['minutes'],$tmp['seconds']);
            $time_str=sprintf("%02d:%02d",$tmp['hours'],$tmp['minutes']);     

            switch($format_out)
            {
                case 'DD MON YYYY HH:MM': $str_out=$tmp['mday'].' '.$tmp['month'].' '.$tmp['year'].' '.$time_str; break;
                case 'DD-MMM-YYYY HH:MM': $str_out=$tmp['mday'].'-'.substr($tmp['month'],0,3).'-'.$tmp['year'].' '.$time_str; break;
                default: $str_out=$tmp['mday'].'-'.substr($tmp['month'],0,3).'-'.$tmp['year']; break;
            }
            
        }
        return $str_out;
    }

    public static function formatTime($time,$format_in='MYSQL',$format_out='HH:MM')
    {
        if($time=='' or is_null($time)) {
            $str_out=''; 
        } else {
            switch($format_in)
            {
                case 'ARRAY': $tmp=$time;break;
                case 'MYSQL': $tmp=self::mysqlGetTimeArray($time); break;
                case  'TIME': $tmp=getdate($time); break;
                default: $tmp=getdate(); break;
            }

            switch($format_out) {
                case 'HH:MM': $str_out=sprintf("%02d:%02d",$tmp['hours'],$tmp['minutes']);   break;
                case 'HH:MM:SS': $str_out=sprintf("%02d:%02d:%02d",$tmp['hours'],$tmp['minutes'],$tmp['seconds']); break;
                default: $str_out=sprintf("%02d:%02d:%02d",$tmp['hours'],$tmp['minutes'],$tmp['seconds']); break;
            }
            
        }
        return $str_out;
    }

    public static function mysqlGetTime($mysql_date) 
    {
        $year =intval(substr($mysql_date,0,4));
        $month=intval(substr($mysql_date,5,2));
        $day  =intval(substr($mysql_date,8,2));

        $time=mktime(0,0,0,$month,$day,$year);

        return $time;
    }
      
    public static function mysqlGetTimeArray($mysql_time)
    {
        $tmp=explode(':',$mysql_time);
        
        $time['hours']=intval($tmp[0]);
        if(count($tmp)>1) $time['minutes']=intval($tmp[1]); else $time['minutes']=0;
        if(count($tmp)>2) $time['seconds']=intval($tmp[2]); else $time['seconds']=0;
        
        $time['total']=$time['hours']*3600 + $time['minutes']*60 + $time['seconds'];
        
        return $time;
    }

    public static function mysqlGetDate($mysql_date) 
    {
        $year =intval(substr($mysql_date,0,4));
        $month=intval(substr($mysql_date,5,2));
        $day  =intval(substr($mysql_date,8,2));
        $hour =0;
        $min  =0;
        $sec  =0;
        
        if(strlen($mysql_date)>10) {
          $hour=intval(substr($mysql_date,11,2));
          $min =intval(substr($mysql_date,14,2));
          $sec =intval(substr($mysql_date,17,2));
        } 
        
        $time=mktime($hour,$min,$sec,$month,$day,$year);
        $date_array=getdate($time);
        
        //modifies mktime default if date is 0000-00-00
        if($year==0 and $month==0 and $day==0) {
          $date_array['mday']=0;
          $date_array['mon']=0;
          $date_array['month']=0;
          $date_array['year']=0;
          $date_array['wday']=0;
          $date_array['weekday']=0;
          $date_array['yday']=0;
        }  

        return $date_array;
    }

    public static function mysqlDate($year,$month,$day,$time) 
    {
        if ($time>0)
        {
          $date_str=date('Y-m-d',$time);
        } else {
          //NB mktime accepts out of range values and will automaticaly infer a valid date
          $date_str=date('Y-m-d',mktime(0,0,0,$month,$day,$year));
        }

        return $date_str;
    }

    public static function calcNights($date_in,$date_out,$format='MYSQL')
    {
        $no_nights=0;
         
        if($date_in=='' or is_null($date_in) or $date_out=='' or is_null($date_out))
        {
            $no_nights=0;
        } else {
            switch($format)
            {
                case 'ARRAY': $time_in=$date_in[0]; $time_out=$date_out[0]; break;
                case 'MYSQL': $time_in=self::mysqlGetTime($date_in); $time_out=self::mysqlGetTime($date_out); break;
                case  'TIME': $time_in=$date_in; $time_out=$date_out; break;
            }

            if($time_out>$time_in)
            {
                $no_nights=round(($time_out-$time_in)/(24*60*60) );
            }
            
        }
        return $no_nights;
    }

    public static function calcDays($date_from,$date_to,$format='MYSQL',$options=array()) 
    {
        $no_days=0;
        if(!isset($options['include_first'])) $options['include_first']=true;
        
        if($options['include_first']) $inc=1; else $inc=0;
         
        if($date_from=='' or is_null($date_from) or $date_to=='' or is_null($date_to)) {
            $no_days=0;
        } else {
            switch($format) {
                case 'ARRAY': $time_from=$date_from[0]; $time_to=$date_to[0]; break;
                case 'MYSQL': $time_from=self::mysqlGetTime($date_from); $time_to=self::mysqlGetTime($date_to); break;
                case  'TIME': $time_from=$date_from; $time_to=$date_to; break;
            }

            if($time_to>$time_from) {
                $no_days=round($inc+($time_to-$time_from)/(24*60*60));
            }
            
        }
        return $no_days;
    }
    
    public static function dateInRange($date_from,$date_to,$date_check,$format='MYSQL')
    {
        $in_range=false;
         
        switch($format)
        {
            case 'ARRAY': $time_from=$date_from[0]; $time_to=$date_to[0]; $time_check=$date_check[0]; break;
            case 'MYSQL': {
                $time_from=self::mysqlGetTime($date_from);
                $time_to=self::mysqlGetTime($date_to);
                $time_check=self::mysqlGetTime($date_check);
                break;
            } 
            case  'TIME': $time_from=$date_from; $time_to=$date_to; $time_check=$date_check; break;
        }

        if($time_check>=$time_from and $time_check<=$time_to) $in_range=true;

        return $in_range;
    }


    public static function daysInMonth($date,$format='MYSQL') 
    {
        switch($format)
        {
            case 'ARRAY': $time=$date[0]; break;
            case 'MYSQL': $time=self::mysqlGetTime($date); break;
            case 'TIME' : $time=$date; break;
        }
        $days=date('t',$time);
        return $days;
    }

    public static function monthName($month) 
    {
        $name='';
        
        switch($month) {
            case 1: $name='January'; break;
            case 2: $name='February'; break;
            case 3: $name='March'; break;
            case 4: $name='April'; break;
            case 5: $name='May'; break;
            case 6: $name='June'; break;
            case 7: $name='July'; break;
            case 8: $name='August'; break;
            case 9: $name='September'; break;
            case 10: $name='October'; break;
            case 11: $name='November'; break;
            case 12: $name='December'; break;
            default : $name='Unknown';
        }
        return $name;
    }
    
    public static function monthName2($month,$year) 
    {
        if($month>0 and $year>0)  {
            $name=substr(self::monthName($month),0,3).' '.$year;
        } else {
            $name='Invalid';  
        }
        return $name;
    }
    
    public static function monthNumber($name,$format='MM') 
    {
        $number=0;
        $name_arr=array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                                        'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);
        $name=strtolower($name);
        if(strlen($name)>3) $name=substr($name,0,3);
        if(isset($name_arr[$name])) {
            $number=$name_arr[$name];
            if($format==='MM' and $number<10) $number='0'.$number;
        }  
                
        return $number;
    }  
    
    public static function getNoMonthsTime($include='ALL',$from_time,$to_time) 
    {
        $no_months=0;
        
        if($to_time>$from_time)
        {
            $from=getdate($from_time);
            $to  =getdate($to_time);
            
            $no_months=(12-$from['mon'])+($to['mon']-12);
            $no_months+=($to['year'] - $from['year']) * 12;
            if($include=='ALL') $no_months+=1;
        }

        return $no_months;
    } 
    
    public static function getNoMonths($from_month,$from_year,$to_month,$to_year) 
    {
        $no_months=($to_year-$from_year)*12 + ($to_month-$from_month) + 1;
        if($no_months<0) $no_months=0;
         
        return $no_months;
    }
    
    public static function incrementMonth($month,$no_months) 
    {
        $month=$month+$no_months ;
        if($month>12) $month=$month-12;
        if($month<1) $month=$month+12;
         
        return $month;
    } 
    
    public static function incrementMonthYear($month,$year,$no_months) 
    {
        $time=mktime(0,0,0,$month+$no_months,1,$year);
        $date=getdate($time);
        $period['month']=$date['mon'];
        $period['year']=$date['year'];
        
        return $period;
    } 
}
?>
