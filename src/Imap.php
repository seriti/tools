<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Secure;
use Seriti\Tools\Doc;
use Seriti\Tools\Calc;

//*** IMAP related static functions ***
Class Imap
{
    public static function imapConnnect($host,$user,$pwd,$options = [],&$error_str) 
    {
        $error_str = '';
        //NB can have 'pop3' or 'nntp' protocol as well
        if(!isset($options['protocol'])) $options['protocol'] = 'imap';
        if(!isset($options['ssl'])) $options['ssl'] = true; 
        if(!isset($options['validate_cert'])) $options['validate_cert'] = false;   
        if(!isset($options['read_only'])) $options['read_only'] = false;   
        if(!isset($options['port'])) {
            if($options['ssl']) $options['port'] = '993'; else $options['port'] = '110';
        }  
        if(!isset($options['box'])) $options['box'] = 'INBOX';  
        
        $mbox = '{'.$host.':'.$options['port'].'/'.$options['protocol'];
        if($options['ssl']) {
            $mbox .= '/ssl';
            if(!$options['validate_cert']) $mbox .= '/novalidate-cert'; // validate-cert is default
        }    
        if($options['read_only']) $mbox .= '/readonly';
        $mbox .= '}'.$options['box'];
        
        //try and establish connection
        $conn = \imap_open($mbox, $user, $pwd);
        if($conn === false) $error_str .= 'Could not connect['.$mbox.'] user['.$user.']: '.\imap_last_error();  
        return $conn;   
    }  
    
    public static function imapGetMessage($conn,$msg_id,$options=array()) 
    {
        if(!isset($options['id_type'])) $options['id_type'] = 'UID'; //can be SEQ or UID
        if(!isset($options['header'])) $options['header'] = true;
        if(!isset($options['body'])) $options['body'] = true;
        if(!isset($options['attachments'])) $options['attachments'] = true;
                
        if($options['id_type'] === 'UID') {
            $msg_no = \imap_msgno($conn,$msg_id);
        } else {
            $msg_no = $msg_id;
            $msg_id = \imap_uid($conn,$msg_no);
        }  
        
        $message = array();
        $message['text'] = '';
        $message['html'] = '';
        $message['attachments'] = array();
        $message['id'] = $msg_id;
        $message['no'] = $msg_no;
        //get all header info
        if($options['header'] and $msg_no > 0) {
            $header = \imap_headerinfo($conn,$msg_no);
            $message['time'] = $header->udate;
            $message['date'] = date('F j, Y, g:i a', $header->udate);
            $message['from_name'] = $header->fromaddress;
            $message['from'] = $header->from[0]->mailbox.'@'.$header->from[0]->host;
            $message['to_name'] = $header->toaddress;
            $message['to'] = $header->to[0]->mailbox.'@'.$header->to[0]->host;
            $message['subject'] = $header->subject;
        }
                
        if($options['body'] or $options['attachments']) {
            $structure = \imap_fetchstructure($conn,$msg_id,FT_UID);
            if(!isset($structure->parts)) {  
                $part_no = '0';
                $part = $structure;
                self::imapGetMessagePart($conn,$msg_id,$part,$part_no,$options,$message);
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
            $data = \imap_fetchbody($conn,$msg_id,$part_no,FT_UID);
            $data = self::imapDecode($data,$part->type);
            
            // filename may be given as 'Filename' or 'Name' or both
            $file_name = ($params['filename'])? $params['filename'] : $params['name'];
            // filename may be encoded, so see imap_mime_header_decode()
            $message['attachments'][$file_name]=$data;// this is a problem if two files have same name
        }
        
        //BODY
        if($options['body']) {
            if($part_no == '0') {
                $data = \imap_body($conn,$msg_id,FT_UID);  
            } else {
                $data = \imap_fetchbody($conn,$msg_id,$part_no,FT_UID);
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
            case 0:$value = \imap_8bit($value);break;
            case 1:$value = \imap_8bit($value);break;
            case 2:$value = \imap_binary($value);break;
            case 3:$value = \imap_base64($value);break;
            case 4:$value = \imap_qprint($value);break;
            case 5:$value = \imap_base64($value);break;
        }

        return $value;
    }

}
