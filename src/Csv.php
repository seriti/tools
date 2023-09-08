<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Date;
use Seriti\Tools\TABLE_CACHE;

//class intended as a pseudo namespace for a group of functions to be referenced as "Csv::function_name()"
class Csv 
{
    //this is for a 2 dimensional array [col][row] configured similar to an excel spreadsheet
    public static function arrayDumpCsv($array) {
        $data = '';
        $col_count = count($array);
        $row_count = count($array[0]);
        
        for($r = 0; $r < $row_count; $r++) {
            $row = array();
            if($array[0][$r] === 'CUSTOM_ROW') {
                //only custom option is BLANK row currently for compatability with Pdf and Html classes
                $data .= "\r\n";
            } else {  
                for($c = 0; $c < $col_count; $c++) {
                    $row[] = str_replace('"','""',$array[$c][$r]);
                }  
                $data .= '"'.implode('","',$row).'"'."\r\n";
            }  
        } 

        return $data;
    }
    
    //for arrays created using Mysql::readSqlArray()
    public static function sqlArrayDumpCsv($key_name,$array,$options = []) {
        $data='';
        
        if(!isset($options['show_key'])) $options['show_key'] = true; 

        $header = false;
        $header_line = array();
        if($options['show_key']) $header_line[] = self::csvPrep($key_name);
                
        foreach($array as $key => $array2) {
            $line = array();
            if($options['show_key']) $line[] = self::csvPrep($key);
            foreach($array2 as $key2 => $value) {
                if(!$header) $header_line[] = self::csvPrep($key2);
                $line[] = self::csvPrep($value);
            } 
            $header = true;
            $data .= implode(',',$line)."\r\n"; 
        }
        
        $data = implode(',',$header_line)."\r\n".$data; 

        return $data;
    }
    
    public static function mysqlDumpCsv($data_set)  {
        $data = '';
        $col_count = mysqli_num_fields($data_set);

        //column headers
        for ($i = 0; $i < $col_count; $i++) {
            $field = mysqli_fetch_field_direct($data_set,$i);
            $temp = str_replace('"','""',$field->name);
            $data .= '"'.$temp.'",';
        }
        $data = substr($data,0,-1)."\r\n";

        //now add rows
        while($row = mysqli_fetch_row($data_set)) {
            $temp = str_replace('"','""',$row);
            $data .= '"'.join('","',$temp)."\"\r\n";
        }

        return $data;
    }

    
    // convert space delimited line into csv line
    //NB1: $layout specifies name/key,start position,length,type
    //NB2: $line is assume to be encoded in ISO-8859-1
    public static function convertLineCsv($line,$layout=array(),$options=array(),&$data=array()) {
        $data = array();
        $csv_line = '';
        // "\xA0", a non breaking space, has been added to standard trim character set
        if(!isset($options['trim'])) $options['trim'] = " \t\n\r\0\x0B\xA0";
                
        $prep_options['delimiter'] = '"';
        $prep_options['escape'] = '"';
                
        if(count($layout) > 0) {
            foreach($layout as $key => $val) {
                $str = substr($line,$val['start']-1,$val['length']); 
                $str = trim($str,$options['trim']);
                if($val['type'] === 'DECIMAL' or $val['type'] === 'INTEGER') {
                    $str = ltrim($str,'0');
                    if($str === '') $str = '0';
                }  
                
                $prep_options['type'] = $val['type'];
                $data[$key] = self::csvPrep($str,$prep_options) ;
            }
            
            $csv_line = implode(',',$data);
        }
        
        return $csv_line;
    }  
    
    /* functions for cleaning csv import values */
    public static function csvStrip($value,$options = array()){
        $value = trim($value);
        if(substr($value,0,1) == '"') $value = substr($value,1);
        if(substr($value,-1) == '"') $value = substr($value,0,-1);
        $value=trim($value);
        
        return $value;
    }
    
    public static function cashStrip($value,$options = array()){
        if(!isset($options['currency'])) $options['currency'] = '';
        if(!isset($options['decimal'])) $options['decimal'] = '.';
        if(!isset($options['thousands'])) $options['thousands'] = ',';
        
        $str = self::csvStrip($value);
        $str = str_replace(' ','',$str);
        $str = str_replace($options['thousands'],'',$str);
        //strip out any currency symbols
        if($options['currency']!= '' and stripos($str,$options['currency']) === 0) {
            $str = substr($str,strlen($options['currency']));
        }  
        
        if(is_numeric($str)) $value = $str; else $value = '0.00';
        
        return $value;
    }
    
    public static function dateStrip($value,$options = array()){
        $error_st = '';
        if(!isset($options['sequence'])) $options['sequence'] = 'YMD';
        if(!isset($options['format'])) $options['format'] = 'YYYY-MM-DD';
        
        $value = self::csvStrip($value);
        $value = Date::convertAnyDate($value,$options['sequence'],$options['format'],$error_str);
    }  
    
    public static function booleanStrip($value,$options = array()) {
        $bool = '0';
        if(!isset($options['true'])) $options['true'] = 'Y';
        
        $value = self::csv_strip($value);
        if(strpos($value,$options['true']) !== false) {
            $bool = '1';
        }   
        
        return $bool;
    }

    //NB: Cache class also uses the "cache" table but with hashed cache_id so clash extremely unlikely
    //legacy code, rather use Cache class!
    public static function csvUpdate($db,$csv_id,$csv_data){
        $sql='REPLACE INTO `'.TABLE_CACHE.'` (`cache_id`,`date`,`data`) '.
             'VALUES ("'.$db->escapeSql($csv_id).'","'.date('Y-m-d').'","'.$db->escapeSql($csv_data).'") ';
        $db->executeSql($sql,$error_str);
        if($error_str=='') return true; else return false;
    }
    
    public static function csvAddRow($row,&$data) {
        $data.=$row."\r\n";
    }

    public static function csvPrep($value,$options = array())  {
        if(!isset($options['type'])) $options['type'] = 'DETECT';
        if(!isset($options['delimiter'])) $options['delimiter'] = '"';
        if(!isset($options['escape'])) $options['escape'] = '"';
        if(!isset($options['strip_html'])) $options['strip_html'] = true;


        if($options['strip_html']) {
            $value = strip_tags($value);
            $value = html_entity_decode($value);
        }    
        
        if($options['type'] === 'DETECT') {
            if(is_numeric($value)) $options['type'] = 'NUMBER'; else $options['type'] = 'STRING';
        }  
                 
        if($options['type'] === 'NUMBER' or $options['type'] === 'DECIMAL') {
            if($value < 0.00001 and $value > -0.00001) $value = 0;
        } else {
            //escape any occurrences of delimiter
            $value = str_replace($options['delimiter'],($options['escape'].$options['delimiter']),$value);
            $value = $options['delimiter'].$value.$options['delimiter'];
        } 
                    
        return $value;
    }  
}