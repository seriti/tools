<?php
namespace Seriti\Tools;

use Exception;

class Sms {
    
    protected $isp = '';
    protected $protocol = 'HTTP';
    protected $http_method = 'FILE'; //can also be CURL
    protected $http_base = '';
    protected $http_post = false;
    protected $user = '';
    protected $password = '';
    protected $api_id = ''; 
    protected $session_id = '';
    protected $account = '';
    protected $footer = '';
    protected $max_chars = 160; //single message, service provider may allow automatic chaining
    
    public function __construct($param = array()) {
        
        $this->isp = $param['isp'];
        
        if(isset($param['user'])) $this->user = $param['user'];
        
        if(isset($param['password'])) $this->password = $param['password'];
        
        if(isset($param['protocol'])) $this->protocol = $param['protocol'];
                
        if(isset($param['footer'])) $this->footer = $param['footer'];
        
        if(isset($param['max_chars'])) $this->max_chars = $param['max_chars'];
        
        //additional parameters dependant on above
        if($this->protocol === 'HTTP') {
            if(isset($param['http_base'])) {
                $this->http_base = $param['http_base'];
            } else {
                throw new Exception('SMS_SETUP: No http base specified');
            } 

            if(isset($param['http_method'])) $this->http_method = $param['http_method'];
            
            if(isset($param['http_post'])) $this->http_post = $param['http_post'];
        }  
        
        if($this->isp === 'CLICKATELL') {
            if(isset($param['api_id'])) {
                $this->api_id = $param['api_id'];
            } else {
                throw new Exception('SMS_SETUP: No clickatell api id specified');
            }
        }
        
        if($this->isp === 'PC2SMS') {
            if(isset($param['account'])) {
                $this->account = $param['account'];
            } else {
                throw new Exception('SMS_SETUP: No pc2sms account specified');
            }
        }
    }  
    
    public function sendSms($cell_no,$text,&$error_str,$options = array()) {
        $error_tmp = '';
        $error = '';
        $success_id = '';
        $post_data = '';
        
        //make sure simple alphanumeric
        if(isset($options['id'])) $sms_id = $options['id']; else $sms_id = false;
        
        //attach any footer/disclaimer
        if($this->footer !== '') $text .= ' '.$this->footer;
        
        //shorten message if max limit set
        if($this->max_chars !== 0) {
            $text = substr($text,0,$this->max_chars);
        }  
        
        //modify any line feeds for these custom idiots 
        if($this->isp === 'SMSPORTAL') {  
            $text = str_replace("\r\n",'|',$text); 
        }  
                
        //$text=substr(urlencode($text),0,$this->max_chars);
        $text = urlencode($text);
                
        $cell_no = urlencode($this->setupCellNo($cell_no,$error_tmp));
        if($error_tmp !== '') $error .= 'INVALID cell number['.$error_tmp.']';
                
        if($error === '') {
            if($this->isp === 'CLICKATELL') {
                if($this->session_id !== '') {
                    $url = $this->http_base.'/http/sendmsg?session_id='.$this->session_id.'&to='.$cell_no.'&text='.$text;  
                } else {  
                    $url = $this->http_base.'/http/sendmsg?api_id='.$this->api_id.'&user='.$this->user.
                           '&password='.$this->password.'&to='.$cell_no.'&text='.$text;  
                }
            }    
            
            if($this->isp === 'PC2SMS') { 
                $url = $this->http_base.'/submit/single/';
                $data = 'username='.$this->user.'&password='.$this->password.'&account='.$this->account.'&da='.$cell_no.'&ud='.$text;
                //NB can include unique '&id=XXXX' which will be included in Delivery endpoint data
                if($sms_id !== false) $data .= '&id='.$sms_id;
                if($this->http_post) {
                    //can be an array but then would need to remove urlencoding
                    $post_data = $data;
                } else {
                    $url .= '?'.$data;
                }     
            }
            
            if($this->isp === 'SMSPORTAL') { 
                $url = $this->http_base.'/api5/http5.aspx';
                $data = 'Type=sendparam&username='.$this->user.'&password='.$this->password.'&numto='.$cell_no.'&data1='.$text;
                if($this->http_post) {
                    //can be an array but then would need to remove urlencoding
                    $post_data = $data;
                } else {
                    $url .= '?'.$data;
                }     
            }
            
            //echo $post_data;
            $success_id = $this->httpRequest($url,$post_data,$error_tmp);
            if($error_tmp != '') $error .= 'SMS send failed['.$error_tmp.']!';
        }  
        
        if($error === '') return $success_id; else return false;
    } 
    
    public function setupCellNo($cell_no,&$error,$param = array()) {
        $error = '';
        if(!isset($param['country'])) $param['country'] = 'RSA'; //South Africa
        
        $cell_no = trim($cell_no);
        $length = strlen($cell_no);
             
        if($param['country'] === 'RSA') {
            if($length === 10 & substr($cell_no,0,1) === '0') {
                $cell_no = '27'.substr($cell_no,1);
            } else {
                $country_code = substr($cell_no,0,2);
                if($country_code != '27') $error .= 'Invalid country code['.$country_code.'] must be 27!';
            }   
        }
            
        if($error === '') {  
            $length = strlen($cell_no);
            if($length !== 11) $error .= 'Invalid Cell number['.$cell_no.']: must be 11 characters';  
        } 
        
        if($this->isp === 'PC2SMS') {
            $cell_no = '+'.$cell_no; 
        }       
        
        if($error === '') return $cell_no; else return false;
     
    }   
    
    public function setupSession(&$error) {
        $error = '';
                                
        if($this->isp === 'CLICKATELL') {
            
            if($this->protocol === 'HTTP') {
                $url = $this->http_base.'/http/auth?user='.$this->user.'&password='.$this->password.'&api_id='.$this->api_id;
                $reply = file($url);
                         
                $session = explode(':',$reply[0]);
                if($session[0] == 'OK') {
                    $this->session_id = trim($session[1]);
                } else {
                    $error = 'Authentication failure: '.$reply[0];
                }
            }  
        }
        
        if($error === '') return true; else return false;
    }
    
    public function httpRequest($url,$post,&$error) {
        $error = '';
        $success_id = ''; 
        
        if($this->http_method === 'CURL') {            
            $ch = curl_init();
            //curl_setopt($ch,CURLOPT_HTTPHEADER,array("Content-Type: text/xml")); 
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
                            
        if($this->http_method === 'FILE') {  
            //NB: this requires allow_url_fopen = true in php
            $response = file_get_contents($url);
        }
        
        if($error === '') {
            //beakdown response into lines
            $response = str_replace("\r\n","\n",$response);
            $lines = explode("\n",$response);
            
            if($this->isp === 'CLICKATELL') {
                $send = explode(':',$lines[0]);
                if($send[0] === 'ID') {
                    $success_id = trim($send[1]);
                } else {
                    $error .= $response;
                }
            }

            if($this->isp === 'PC2SMS') {     
                if(strpos($response,'Code: 700') === false and strpos($response,'Accepted') === false) {
                    $error .= $response; 
                } else {
                    if(strpos($response,'Accepted') !== false) {
                        $success_id = substr($response,22);
                    } else {  
                        $success_id = 'OK'; 
                    }  
                }  
            }  
            if($this->isp === 'SMSPORTAL') {
                //echo $response;
                $xml = simplexml_load_string($response);
                if((string)$xml->call_result->result === 'True') {
                    $success_id = (string)$xml->send_info->eventid;
                } else {
                    $error .= 'ERROR:'.(string)$xml->call_result->error;
                } 
                unset($xml);   
            }
        }
        
        if($error === '') return $success_id; else return false;
    }
}  
    