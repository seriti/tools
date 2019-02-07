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
    protected $charset = 'UTF-8';
    protected $secure = '';  //tls on port 587, ssl on port 465 
    protected $subject = '';
    protected $body = '';
    protected $footer = '';
    protected $from = [];   //single ['name'=>'Mark','address'=>'mark@seriti.com']
    protected $reply = [];  //multiple
    protected $to = [];  
    protected $cc = [];
    protected $bcc = []; 
    protected $attach = []; 
    protected $wrap_text = 0;

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
        if(isset($param['from']))     $this->addAddress('FROM',$param['from']);
        if(isset($param['reply']))    $this->addAddress('REPLY',$param['reply']);

        if(isset($param['debug'])) {
            $this->debug = $param['debug'];
        } elseif(defined(__NAMESPACE__.'\DEBUG')) {
            $this->debug = DEBUG;
        } 
        if(isset($param['debug_level'])) $this->debug_level = $param['debug_level'];
    }
    
    //$email can be an array with name and address or just the address which can be "name <email>" format if no $name 
    public function addAddress($type,$email,$name = '')
    {
        $type = strtoupper($type);
        $address = [];

        if(is_array($email)) {
            if(!isset($email['name'])) throw new Exception('EMAIL_SETUP: '.$type.' address array missing "name" key.');
            if(!isset($email['address'])) throw new Exception('EMAIL_SETUP: '.$type.' address array missing "address" key.');
            $address = $email;
        } else {
            if($name === '') {
                $address = $this->parseEmail($email);
            } else {
                $address = ['name'=>$name,'address'=>$email];
            }    
        }

        if(!$this->validEmail($address['address'])) throw new Exception('EMAIL_SETUP: '.$type.' address email invalid.');
        
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

    //for bulk emails use sendEmailList()
    public function sendEmail($from,$to,$subject,$body,&$error,$param = []) {
        $error = '';
        //NB: set to false or other value with extreme caution!  
        if(!isset($param['reset'])) $param['reset'] = 'ALL';
        if(isset($param['format'])) {
           if($param['format'] === 'html') $this->format = 'html'; else $this->format = 'text';
        }  

        $this->prepareSMTP();

        //clear message data from any previous send. Does not reset from address.
        $this->reset($param['reset']);

        //NB: from address is specified when class created, so unless different can leave blank.
        if($from !== '' ) $this->addAddress('from',$from);
        if(count($this->from) === 0) throw new Exception('EMAIL_SEND: No from address specified.');

        $this->addAddress('to',$to);

        if(isset($param['cc'])) $this->addAddress('cc',$param['cc']);
        if(isset($param['bcc'])) $this->addAddress('bcc',$param['bcc']);
        if(isset($param['reply'])) $this->addAddress('reply',$param['reply']);

        if(isset($param['attach'])) $this->addAttachment($param['attach']);

        $this->subject = $subject;
        $this->body = $body;

        $this->prepareEmail();

        if($error === '') {  
            if(!$this->mailer->Send()) {
                $error = 'Error sending email! ';
                if($this->debug) $error .= $this->mailer->ErrorInfo;
            }  
        }
        
        if($error === '') return true; else return false;
    }

    protected function prepareSMTP()
    {
        if($this->debug) $this->mailer->SMTPDebug = $this->debug_level;
        
        $this->mailer->IsSMTP();              // set mailer to use SMTP
        $this->mailer->SMTPAuth = true;
        //$mail->SMTPKeepAlive = true; FOR BULK SENDS

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
        //also an $mail->addStringAttachment() option for encoded images from a database

        if($this->format === 'html') $html = true; else $html = false;
        $this->mailer->IsHTML($html);

        $this->mailer->Subject = $this->subject;
        $this->mailer->Body = $this->body.$this->footer;
        //$this->mailer->msgHTML($this->$body.$this->footer); ALSO CREATES $this->mailer->AltBody OR can set afterwards to overwrite
    }

    protected function reset($type = 'ALL')
    {
        switch($type) {
            case 'ALL'    : $this->mailer->clearAllRecipients();
                            $this->mailer->clearAttachments();
                            $this->mailer->clearReplyTos();
                            $this->reply = [];
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

    //**************************************************
    
    
    //standard php mail function
    public static function sendPhpMail($mail_from,$mail_to,$mail_cc,$mail_bcc,$subject,$body,$options=array())  {
        global $seriti_config;
        $error_str='';
        
        //drop out immediately if mail not enabled!
        if($seriti_config['email']['enabled']===false) return true;
        
        //check configuration options and use defaults if not set
        if(!isset($options['method'])) $options['method']=$seriti_config['email']['method'];
        if(!isset($options['html'])) $options['html']=false;
        if(!isset($options['wrap'])) $options['wrap']=0;
        
        $sent=false;
         
        if($options['method']==='php') {
            $headers='';
            if($options['html']) {
                $headers.='MIME-Version: 1.0'."\r\n";
                $headers.='Content-type: text/html; charset=iso-8859-1'."\r\n";  //utf-8
            }  

            $headers.='From: '.$mail_from."\r\n".
                                'Reply-To: '.$mail_from."\r\n";
                                 
            //comma separate multiple values          
            if($mail_cc!='') $headers.='Cc: '.$mail_cc."\r\n"; 
            if($mail_bcc!='') $headers.='Bcc: '.$mail_bcc."\r\n";
             
            //NB: this must come after cc and bcc fields
            $headers.='X-Mailer: PHP/'.phpversion();
             
            //add any footer/disclaimer 
            self::insert_footer($body,$options['html']); 
             
            if($options['wrap'] and !$options['html']) $body=wordwrap($body,$options['wrap']);  
             
            if(mail($mail_to,$subject,$body,$headers)) $sent=true;
        } 
         
        
         
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
    
    
    
    //*** start IMAP related static functions which are independant of rest of class ***

    public static function imapConnnect($host,$user,$pwd,$options = [],&$error_str) 
    {
        $error_str = '';
        //NB can have 'pop3' or 'nntp' protocol as well
        if(!isset($options['protocol'])) $options['protocol'] = 'imap';
        if(!isset($options['ssl'])) $options['ssl'] = false;   
        if(!isset($options['read_only'])) $options['read_only'] = true;   
        if(!isset($options['port'])) {
            if($options['ssl']) $options['port'] = '993'; else $options['port'] = '110';
        }  
        if(!isset($options['box'])) $options['box'] = 'INBOX';  
        
        $mbox = '{'.$host.':'.$options['port'].'/'.$options['protocol'];
        if($options['ssl']) $mbox .= '/ssl';
        if($options['read_only']) $mbox .= '/readonly';
        $mbox .= '}'.$options['box'];
        
        //try and establish connection
        $conn = imap_open($mbox, $user, $pwd);
        if($conn === false) $error_str .= 'Could not connect: '.imap_last_error();  
        return $conn;   
    }  
    
    public static function imapGetMessage($conn,$msg_id,$options=array()) 
    {
        if(!isset($options['id_type'])) $options['id_type'] = 'UID'; //can be SEQ or UID
        if(!isset($options['header'])) $options['header'] = true;
        if(!isset($options['body'])) $options['body'] = true;
        if(!isset($options['attachments'])) $options['attachments'] = true;
                
        if($options['id_type'] === 'UID') {
            $msg_no = imap_msgno($conn,$msg_id);
        } else {
            $msg_no = $msg_id;
            $msg_id = imap_uid($msg_no);
        }  
        
        $message = array();
        $message['text'] = '';
        $message['html'] = '';
        $message['attachments'] = array();
        $message['id'] = $msg_id;
        $message['no'] = $msg_no;
        //get all header info
        if($options['header']) {
            $header = imap_headerinfo($conn,$msg_no);
            $message['time'] = $header->udate;
            $message['date'] = date('F j, Y, g:i a', $header->udate);
            $message['from_name'] = $header->fromaddress;
            $message['from'] = $header->from[0]->mailbox.'@'.$header->from[0]->host;
            $message['to_name'] = $header->toaddress;
            $message['to'] = $header->to[0]->mailbox.'@'.$header->to[0]->host;
            $message['subject'] = $header->subject;
        }
                
        if($options['body'] or $options['attachments']) {
            $structure = imap_fetchstructure($conn,$msg_id,FT_UID);
            if(!isset($structure->parts)) {  
                $part_no = '0';
                $part = $structure;
                self::imap_get_message_part($conn,$msg_id,$part,$part_no,$options,$message);
            } else {
                foreach($structure->parts as $key => $part) {
                    $part_no = strval($key+1);
                    self::imapGetMessagePart($conn,$msg_id,$part,$part_no,$options,$message);
                }  
            }  
        }   
         
        return $message;
    }  
        
    static function imapGetMessagePart($conn,$msg_id,$part,$part_no,$options,&$message) 
    {
        //$part_no = '1', '2', '2.1', '2.1.3', etc for multipart, 0 if simple
        //get all parameters, like charset, filenames of attachments, etc.
        $params = array();
        if(isset($part->parameters)) {
            foreach($part->parameters as $x) {
                $params[strtolower($x->attribute)] = $x->value;
            }  
        } 
        if(isset($part->dparameters)) {
            foreach($part->dparameters as $x) {
                $params[strtolower($x->attribute)] = $x->value;
            }  
        }
                        
        //ATTACHMENTS
        if($options['attachments'] and isset($part->disposition) and $part->disposition == "ATTACHMENT") {
            $data = imap_fetchbody($conn,$msg_id,$part_no,FT_UID);
            $data = self::imapDecode($data,$part->type);
            
            // filename may be given as 'Filename' or 'Name' or both
            $file_name = ($params['filename'])? $params['filename'] : $params['name'];
            // filename may be encoded, so see imap_mime_header_decode()
            $message['attachments'][$file_name]=$data;// this is a problem if two files have same name
        }
        
        //BODY
        if($options['body']) {
            if($part_no == '0') {
                $data = imap_body($conn,$msg_id,FT_UID);  
            } else {
                $data = imap_fetchbody($conn,$msg_id,$part_no,FT_UID);
            }    
            $data = self::imapDecode($data,$part->type);
            
            if($part->type == 0 and $data) {
                // Messages may be split in different parts because of inline attachments,   // so append parts together with blank row.
                if(strtolower($part->subtype) == 'plain') {
                    $message['text'] .= trim($data)."\n\n";
                } else {
                    $message['html'] .= $data.'<br/><br/>';
                    $message['charset'] = $params['charset'];  // assume all parts are same charset
                }    
            } elseif($part->type == 2 and $data) { // There are no PHP functions to parse embedded messages, so this just appends the raw source to the main message.
                $message['text'] .= $data."\n\n";
            }
        }
                
        //NB: SUBPART RECURSION
        if(isset($part->parts)) {
            foreach($part->parts as $key => $part_2) {
                $part_no2 = $part_no.'.'.strval($key+1);
                self::imapGetMessagePart($conn,$msg_id,$part_2,$part_no2,$options,$message); 
            }  
        }
        
    }
    
    public static function imapDecode($value,$encoding) 
    {
        /* php.net/manual/en/function.imap-fetchstructure.php
        0 7BIT
        1 8BIT
        2 BINARY
        3 BASE64
        4 QUOTED-PRINTABLE
        5 OTHER
        */
        switch($encoding) {
            case 0:$value = imap_8bit($value);break;
            case 1:$value = imap_8bit($value);break;
            case 2:$value = imap_binary($value);break;
            case 3:$value = imap_base64($value);break;
            case 4:$value = imap_qprint($value);break;
            case 5:$value = imap_base64($value);break;
        }

        return $value;
    }
    
}   
?>
