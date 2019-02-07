<?php
namespace Seriti\Tools;

use Exception;

//class intended as a pseudo namespace for a group of functions to be referenced as "Http::function_name()"
class Http 
{
  public static function request($url,$post,$options = array(),&$error) {
    $error = '';
    $response = '';
    
    if(!isset($options['method'])) $options['method'] = 'CURL';
    if(!isset($options['http_header'])) $options['http_header'] = array();
    
    if($options['method'] === 'CURL') {            
      $ch = curl_init();
      //curl_setopt($ch,CURLOPT_HTTPHEADER,array("Content-Type: text/xml")); 
      //curl_setopt($ch,CURLOPT_HTTPHEADER,array("Content-Type: application/xml")); 
      //curl_setopt($ch,CURLOPT_HTTPHEADER,array("Content-Type: application/x-www-form-urlencoded")); 
      if(count($options['http_header']) !=                               0) {
        curl_setopt($ch,CURLOPT_HTTPHEADER,$options['http_header']); 
      }  
      curl_setopt($ch,CURLOPT_HEADER,0);
      curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
      curl_setopt($ch,CURLOPT_URL,$url);
      curl_setopt($ch,CURLOPT_FORBID_REUSE,1);
      //does NOT verify ssl certificate if https used
      curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);
      curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
      //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
      
      //$post can be an urlencoded argument string OR an array, and array can include a file
      if($post != '') {
        curl_setopt($ch,CURLOPT_POST,1);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$post);
      }
      
      $response = curl_exec($ch);
      $error .= curl_error($ch);
      curl_close($ch);
    }
              
    if($options['method'] === 'FILE') {  
      //NB: this requires allow_url_fopen = true in php
      $response = file_get_contents($url);
    }
        
    return $response; 
  }
}
?>
