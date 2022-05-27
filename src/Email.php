<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Secure;
use Seriti\Tools\Doc;
use Seriti\Tools\Calc;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

//class intended as a pseudo namespace for a group of functions to be referenced as "Email:function_name()"
class Email 
{
    protected $mailer;
    protected $enabled = true;
    protected $debug = false;
    protected $debug_level = 4;
    protected $method = 'smtp';  //smtp,mail,imap
    protected $format = 'text';  //text,html
    protected $host = 'localhost'; //'smtp1.example.com;smtp2.example.com' can be multiple servers
    protected $user = '';
    protected $password = '';
    protected $port = 587;
    protected $charset = 'UTF-8'; //ISO-8859-1
    protected $secure = '';  //tls on port 587, ssl on port 465 
    protected $subject = '';
    protected $body = '';
    protected $footer = '';
    protected $from = [];   //single ['name'=>'Mark','address'=>'mark@seriti.com']
    protected $reply = [];  //multiple
    protected $to = [];  
    protected $cc = [];
    protected $bcc = []; 
    protected $attach = []; //for file attachements
    protected $embed = [];  //for embedded images
    protected $wrap_text = 0;
    protected $add_error = '';

    public function __construct(PHPMailer $mailer,$param = [])
    {
        $this->mailer = $mailer;

        if(isset($param['method']))   $this->method = $param['method']; 
        if(isset($param['enabled']))  $this->enabled = $param['enabled'];
        if(isset($param['host']))     $this->host = $param['host'];
        if(isset($param['user']))     $this->user = $param['user'];
        if(isset($param['password'])) $this->password = $param['password'];
        if(isset($param['port']))     $this->port = $param['port'];
        if(isset($param['charset']))  $this->charset = $param['charset'];
        if(isset($param['format']))   $this->format = $param['format'];
        if(isset($param['secure']))   $this->secure = $param['secure']; 
        if(isset($param['wrap_text']))$this->wrap_text = $param['wrap_text'];
        if(isset($param['footer']))   $this->footer = $param['footer'];
        if(isset($param['from']))     $this->addAddress('from',$param['from']);
        if(isset($param['reply']))    $this->addAddress('reply',$param['reply']);

        if(isset($param['debug'])) {
            $this->debug = $param['debug'];
        } elseif(defined(__NAMESPACE__.'\DEBUG')) {
            $this->debug = DEBUG;
        } 
        if(isset($param['debug_level'])) $this->debug_level = $param['debug_level'];
    }
    
    public function getSetting($name = '')
    {
        if(isset($this->$name)) return $this->$name;
    }

    //$email can be an array with name and address, multiple of same, multiple addresses only, or just the address which can be "name <email>" format if no $name 
    public function addAddress($type,$email,$name = '')
    {
        $type = strtoupper($type);
        $address = [];
        $address_list = [];

        if(is_array($email)) {
            //check for ['name'=>X,'address'=>Y] email format
            if(!isset($email['name'])) {
                //assume array of multiple email addresses
                foreach($email as $address) {
                    if(is_array($address)) {
                        if(!isset($address['address'])) throw new Exception('EMAIL_SETUP: '.$type.' multiple email array missing "address" key.');
                        if(!isset($address['name'])) $address['name'] = '';
                        $address_list[] = $address;
                    } else {
                        $address_list[] = ['name'=>'','address'=>$address];
                    }
                }
            } else {
                if(!isset($email['address'])) throw new Exception('EMAIL_SETUP: '.$type.' email array missing "address" key.');
                $address_list[] = $email;
            }    
        } else {
            if($name === '') {
                $address = $this->parseEmail($email);
            } else {
                $address = ['name'=>$name,'address'=>$email];
            } 
            $address_list[] = $address;   
        }

        foreach($address_list as $address) {
            if(!$this->validEmail($address['address'])) {
                //throw new Exception('EMAIL_SETUP: '.$type.' address email invalid.');
                $this->add_error .= 'Invalid '.$type.' address['.$address['address'].'] ';
            }    
            
            if($address['name'] === '') {
                $parts = explode('@',$address['address']);
                $address['name'] = $parts[0];
            }    

            //from address is single, others can be multiple
            switch($type) {
                case 'FROM'  : $this->from    = $address; break; 
                case 'TO'    : $this->to[]    = $address; break;
                case 'REPLY' : $this->reply[] = $address; break;
                case 'CC'    : $this->cc[]    = $address; break;
                case 'BCC'   : $this->bcc[]   = $address; break;
            }    
        }

        
    }

    //$attach can be single file path or an array of paths/names
    public function addAttachment($attach,$name = '')
    {
        $error = '';
        $attach_files = [];
        if(is_array($attach)) {
            foreach($attach as $key=>$file) {
                if(!isset($file['path'])) throw new Exception('EMAIL_ATTACH_FILE: No file path set in multiple attachement array.');
                if(!isset($file['name'])) $file['name'] = '';
                $attach_files[] = $file;
            }    
        } else {
            $file = ['path'=>$attach,'name'=>$name];
            $attach_files[] = $file;
        }

        foreach($attach_files as $key=>$file) {
            if(!file_exists($file['path'])) {
                $error = 'EMAIL_ATTACH_FILE: File path does not exist.';
                if($this->debug) $error .= ' Path['.$file['path'].']';
                throw new Exception($error);
            }    
            if($file['name'] === '') {
                $info = Doc::fileNameParts($file['path']);
                $file['name'] = $info['basename'];
            }    

            $this->attach[] = $file;
        }    
    }

    //$embed can be single file path or an array of paths/names/links
    public function addEmbeddedImage($embed,$link_id,$name = '')
    {
        $error = '';
        $embed_files = [];
        if(is_array($embed)) {
            foreach($embed as $file) {
                if(!isset($file['path'])) throw new Exception('EMAIL_EMBED_FILE: No file path set in multiple embed array.');
                if(!isset($file['name'])) $file['name'] = '';
                if(!isset($file['link'])) throw new Exception('EMAIL_EMBED_FILE: No link id set in multiple embed array.');
                $embed_files[] = $file;
            }    
        } else {
            $file = ['path'=>$embed,'link'=>$link_id,'name'=>$name];
            $embed_files[] = $file;
        }


        foreach($embed_files as $file) {
            if(!file_exists($file['path'])) {
                $error = 'EMAIL_EMBED_FILE: File path does not exist.';
                if($this->debug) $error .= ' Path['.$file['path'].']';
                throw new Exception($error);
            }    
            if($file['name'] === '') {
                $info = Doc::fileNameParts($file['path']);
                $file['name'] = $info['basename'];
            }    

            $this->embed[] = $file;
        }    
    }

    
    public function sendEmail($from,$to,$subject,$body,&$error,$param = []) 
    {
        $error = '';
        $this->add_error = '';

        //NB: set to false or other value with extreme caution!  
        if(!isset($param['reset'])) $param['reset'] = 'ALL';
        if(isset($param['format'])) {
           if($param['format'] === 'html') $this->format = 'html'; else $this->format = 'text';
        }  

        $this->prepareSMTP();

        //NB: 'ALL' Clears message data and recipients from any previous send. Does not reset From or Reply addresses.
        $this->reset($param['reset']);

        //NB: From & Reply addresses is specified when class created, so unless different can leave blank.
        if($from !== '' )  $this->addAddress('from',$from);
        if(count($this->from) === 0) throw new Exception('EMAIL_SEND: No from address specified.');

        //Can have multiple reply addresses but unusual so need to specify all in $param rather than add to default
        if(isset($param['reply'])) {
            $this->reset('REPLY');
            $this->addAddress('reply',$param['reply']);
        }  
        
        $this->addAddress('to',$to);

        if(isset($param['cc'])) $this->addAddress('cc',$param['cc']);
        if(isset($param['bcc'])) $this->addAddress('bcc',$param['bcc']);
        if(isset($param['attach'])) $this->addAttachment($param['attach']);
        if(isset($param['embed'])) $this->addEmbeddedImage($param['embed']);
        
        if($this->add_error !== '') {
            $error .= $this->add_error;
        } else {
            $this->subject = $subject;
            $this->body = $body;

            $this->prepareEmail();
        }    

        if($error === '') {  
            if(!$this->mailer->Send()) {
                $error = 'Error sending email! ';
                if($this->debug) $error .= $this->mailer->ErrorInfo;
            }  
        }
        
        if($error === '') return true; else return false;
    }

    //use in conjunction with sendBulkEmail().
    public function setupBulkMail($from,$subject,$body,$param = [],&$error) 
    {
        $error = '';
        $this->add_error = '';

        //NB: set to false or other value with extreme caution!  
        if(!isset($param['reset'])) $param['reset'] = 'ALL';
        if(isset($param['format'])) {
           if($param['format'] === 'html') $this->format = 'html'; else $this->format = 'text';
        }  

        $this->prepareSMTP(['keep_alive'=>true]);

        //NB: 'ALL' Clears message data and recipients from any previous send. Does not reset From or Reply addresses.
        $this->reset($param['reset']);

        //NB: From & Reply addresses is specified when class created, so unless different can leave blank.
        if($from !== '' ) $this->addAddress('from',$from);
        if(count($this->from) === 0) throw new Exception('EMAIL_SEND: No from address specified.');

        //Can have multiple reply addresses but unusual so need to specify all in $param rather than add to default
        if(isset($param['reply'])) {
            $this->reset('REPLY');
            $this->addAddress('reply',$param['reply']);
        }   

        if(isset($param['cc'])) $this->addAddress('cc',$param['cc']);
        if(isset($param['bcc'])) $this->addAddress('bcc',$param['bcc']);
        if(isset($param['attach'])) $this->addAttachment($param['attach']);
        if(isset($param['embed'])) $this->addEmbeddedImage($param['embed']);

        if($this->add_error !== '') {
            $error .= $this->add_error;
        } else {
            $this->subject = $subject;
            $this->body = $body;

            $this->prepareEmail();
        }    
    }

    public function sendBulkEmail($to_address,$to_name,$subject = '',$body = '',&$error) 
    {
        $error = '';
        $this->add_error = '';

        $this->reset('TO');
        $this->mailer->addAddress($to_address,$to_name);
        if($subject !== '') $this->mailer->Subject = $subject;
        if($body !== '') $this->mailer->Body = $body;

        if($this->add_error !== '') {
            $error .= $this->add_error;
        } else {
            if(!$this->mailer->Send()) {
                $error = 'Error sending email! ';
                if($this->debug) $error .= $this->mailer->ErrorInfo;
            }  
        }    

        if($error === '') return true; else return false;
    }

    public function closeBulkEmail() 
    {
        $this->mailer->smtpClose();
    }

    protected function prepareSMTP($param = [])
    {
        //defaults to ISO-8859-1, so must set
        $this->mailer->CharSet = $this->charset;

        //keep_alive for BULK sends only
        if(!isset($param['keep_alive'])) $param['keep_alive'] = false;

        if($this->debug) $this->mailer->SMTPDebug = $this->debug_level;
        
        $this->mailer->IsSMTP();              // set mailer to use SMTP
        $this->mailer->SMTPAuth = true;
        if($param['keep_alive']) $this->mailer->SMTPKeepAlive = true; 

        $this->mailer->Host = $this->host;    
        $this->mailer->SMTPAuth = true;       
        $this->mailer->Username = $this->user;
        $this->mailer->Password = $this->password;
        $this->mailer->Port = $this->port;

        if($this->secure === '') {
            $this->mailer->SMTPAutoTLS = false;
        } else {    
            $this->mailer->SMTPSecure = $this->secure;
            if($this->secure === 'tls') $this->mailer->SMTPAutoTLS = true; else $this->mailer->SMTPAutoTLS = false;
        }    
    }

    protected function prepareEmail() 
    {
        $this->mailer->setFrom($this->from['address'],$this->from['name']);
        
        if(count($this->reply) === 0) $this->reply[] = $this->from;
        foreach($this->reply as $email) $this->mailer->addReplyTo($email['address'],$email['name']);

        foreach($this->to as $email) $this->mailer->addAddress($email['address'],$email['name']);
        foreach($this->cc as $email) $this->mailer->addCC($email['address'],$email['name']);
        foreach($this->bcc as $email) $this->mailer->addBCC($email['address'],$email['name']);

        if($this->format === 'text' and  $this->wrap_text) $this->mailer->WordWrap = $this->wrap_text;

        foreach($this->attach as $file) $this->mailer->addAttachment($file['path'],$file['name']);
        
        foreach($this->embed as $file) $this->mailer->AddEmbeddedImage($file['path'],$file['link'],$file['name']);
        //also an $mailer->addStringAttachment() option for encoded images from a database

        if($this->format === 'html') $html = true; else $html = false;
        $this->mailer->IsHTML($html);

        $this->mailer->Subject = $this->subject;
        $this->mailer->Body = $this->body.$this->footer;
        //$this->mailer->msgHTML($this->$body.$this->footer); ALSO CREATES $this->mailer->AltBody OR can set afterwards to overwrite
    }

    protected function reset($type = 'ALL')
    {
        switch($type) {
            //NB: 'ALL' does not clear From and Reply addresses. This must be done explicitly if required.
            case 'ALL'    : $this->mailer->clearAllRecipients();
                            $this->mailer->clearAttachments();
                            $this->to = [];
                            $this->cc = [];
                            $this->bcc = [];
                            $this->attach = [];
                            break;
            case  'TO'    : $this->mailer->clearAddresses();
                            $this->to = [];
                            break;
            case  'CC'    : $this->mailer->clearCCs();
                            $this->cc = [];
                            break;
            case  'BCC'   : $this->mailer->clearBCCs();
                            $this->bcc = [];
                            break;
            case  'ATTACH': $this->mailer->clearAttachments();
                            $this->attach = [];
                            break;
            case  'REPLY' : $this->mailer->clearReplyTos();
                            $this->reply = [];
                            break;

        }
    }

        
    
    //standard php mail function
    public static function sendPhpMail($mail_from,$mail_to,$mail_cc,$mail_bcc,$subject,$body,$options = [])  {
        
        if(!isset($options['html'])) $options['html'] = false;
        if(!isset($options['wrap'])) $options['wrap'] = 0;
        if(!isset($options['footer'])) $options['footer'] = '';
        if(!isset($options['charset'])) $options['charset'] = 'iso-8859-1'; //utf-8
        
        $sent = false;
         
        $headers = '';
        if($options['html']) {
            $headers .= 'MIME-Version: 1.0'."\r\n";
            $headers .= 'Content-type: text/html; charset='.$options['charset']."\r\n"; 
        }  

        $headers .= 'From: '.$mail_from."\r\n".
                    'Reply-To: '.$mail_from."\r\n";
                             
        //comma separate multiple values          
        if($mail_cc !== '') $headers .= 'Cc: '.$mail_cc."\r\n"; 
        if($mail_bcc !== '') $headers .= 'Bcc: '.$mail_bcc."\r\n";
         
        //NB: this must come after cc and bcc fields
        $headers .= 'X-Mailer: PHP/'.phpversion();
         
        if($options['footer'] !== '') $body .= $options['footer'];
         
        if($options['wrap'] and !$options['html']) $body = wordwrap($body,$options['wrap']);  
         
        if(mail($mail_to,$subject,$body,$headers)) $sent = true;
    
         
        return $sent;
    }

    //***  HELPER functions ***
    protected function validEmail($email) 
    {
        $regex = "/^[0-9a-z]+(([\.\-_])[0-9a-z]+)*@[0-9a-z]+(([\.\-])[0-9a-z-]+)*\.[a-z]{2,6}$/i"; 
        if(preg_match($regex,$email)) {
            return true;
        } else {
            return false;
        }  
    }
    
    //extracts name and email components from "name" <joe@blog.com> format
    protected function parseEmail($str) 
    {
        $email = array();
        
        $pos = stripos($str,'<');
        if($pos !== false) {
            $email['name'] = substr($str,0,$pos);
            $email['name'] = trim(str_replace('"','',$email['name']));
                
            $email['address'] = substr($str,$pos+1);
            $email['address'] = trim(str_replace('>','',$email['address']));
        } else {
            $email['name'] = '';
            $email['address'] = trim($str);
        } 
        
        return $email;   
    }
    
    //returns an array of email addresses from any text
    public static function extractEmails($text,$options = []) 
    {
        $emails = array();
         
        $text = str_replace(';',',',$text);
        $text = str_replace(' ',',',$text);
        $text = str_replace("\r\n",',',$text);
        $temp_arr = explode(',',$text);
        foreach($temp_arr as $email) {
            if(trim($email) != '') {
                $emails[] = $email;
            }  
        }
        
        return $emails;
    }  
    
}   
