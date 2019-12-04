<?php 
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Calc;

//NB0: All html pages should be in UTF-8 encoding but often this is not the case..can create weird problems if white_list checking is on
//NB1: code assumes that this file is UTF-8 ENCODED and hence also character sets below!
//NB2: code also assumes that html attributes are ALWAYS enclosed in double quotes("") !
define('CHAR_ENCODING','UTF8');
define('CHAR_NUMERIC','0123456789');
define('CHAR_FORMAT',"\n\r");
define('CHAR_ALPHA','abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ');//NB: do NOT remove space at end!!
define('CHAR_ALPHA_XTRA','ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ');
define('CHAR_SPECIAL','\':;.,-_@&()/`!*\%'); 
define('CHAR_SPECIAL_TEXT','\'"“”‘’:;.,-_!@¥$£€^*÷+=~?#%&()/{}`[]©'); 
define('CHAR_BLACKLIST','"<>|');
define('CHAR_BLACKLIST_TEXT','<>|');

//class intended as a pseudo namespace for a group of functions to be referenced as "Secure::function_name()"
class Secure 
{
    
    //step 1 in CSRF protection, namely check if HTTP_REFERER is from actual site and not an infected site
    public static function checkReferer($site_url,$param=array())  
    {
        if(!isset($param['debug'])) $param['debug']=false;
                        
        //not always set by browser
        if(isset($_SERVER['HTTP_REFERER'])) {
            $pos=stripos($_SERVER['HTTP_REFERER'],$site_url);
            //assumes $site_url = 'http://www.site.co.za/....' which should always match initial part of http referer
            if($pos===false or $pos!==0) {
                $msg='Invalid access attempted!';
                if($param['debug']) $msg.=' URL['.$site_url.'] REFERER['.$_SERVER['HTTP_REFERER'].']';
                throw new Exception('HTTP_REFERER_MISMATCH['.$msg.']');
            }  
        }
    }  
    
    //NB: intended for output to HTML, as filters non-alphanumeric string/text output to &#XX; htmlentity code!!
    public static function clean($type='string',$value,$options=array())  
    {
         
        if(!isset($options['white_list'])) $options['white_list']=true;
        if(!isset($options['black_list'])) $options['black_list']=true;    
        if(!isset($options['htmlentity'])) $options['htmlentity']=false;
        if(!isset($options['strip_tags'])) $options['strip_tags']=true;
        if(!isset($options['context']))    $options['context']='html'; //only used with 'string' and 'text' types
        
        $allow=array('header','numeric','alpha','basic','date','string','path','text','integer','float','html','url','email','search');
        if(!in_array($type,$allow)) $type='string';
        
        switch($type) {
            //header: use whenever setting a response header like header('location:....') or setcookie()
            case 'header' : {
                $pattern='~(\r\n|\r|\n|%0a|%0d|%0D|%0A)~';
                $value=preg_replace($pattern,'',$value);
                break;
            }
            //numeric values only
            case 'numeric' : {
                $value=preg_replace('/[^0-9]/','',$value);
                break;
            } 
            //alphanumeric and space only
            case 'alpha' : {
                $value=preg_replace('/[^A-Za-z0-9 ]/','-',$value);
                break;
            }
            //alphanumeric and space,underscore,hyphen,period only
            case 'basic' : {
                $value=preg_replace('/[^A-Za-z0-9_\-\.\, ]/','-',$value);
                break;
            }
            //YYYY-MM-DD HH:MM:SS mysql format only
            case 'date' : {
                $value=substr($value,0,20);
                $value=preg_replace('/[^0-9\-:\/ ]/','-',$value);
                break;
            }
            //alpha extended NB: DO NOT ALLOW " as this can be used to close/step outside attribute value in form inputs
            case 'string' : {
                if($options['strip_tags']) $value=strip_tags($value);
                
                if($options['black_list']) {
                    $arr=str_split(CHAR_BLACKLIST);
                    $value=str_replace($arr,'\'',$value);
                }  
                
                if($options['context']=='input' and $options['white_list']) {
                    $value_clean='';
                    $white_list=CHAR_ALPHA.CHAR_ALPHA_XTRA.CHAR_SPECIAL;
                    
                    if(CHAR_ENCODING==='UTF8') {
                        $arr=Calc::splitStrUtf8($value);
                    } else {     
                        $arr=str_split($value);
                    }  
                    
                    foreach($arr as $c) {
                        if(strpos($white_list,$c)!==false) $value_clean.=$c;
                    } 
                    $value=$value_clean; 
                } else {  
                    $value=filter_var($value,FILTER_SANITIZE_STRING);
                }  
                
                break;
            }
            //special case for search input where parsed for operators and wild cards
            case 'search' : {
                $prefix='';
                $suffix='';

                if(strpos($value,'<>')===0 or strpos($value,'>=')===0 or strpos($value,'<=')===0) {
                    $prefix=substr($value,0,2);
                    $value_tmp=substr($value,2);
                } elseif(strpos($value,'>')===0 or strpos($value,'<')===0 or strpos($value,'=')===0) {
                    $prefix=substr($value,0,1);
                    $value_tmp=substr($value,1);
                } elseif(substr($value,0,1)==='"' and substr($value,-1)==='"') {
                    $prefix='&quot;'; 
                    $suffix='&quot;';
                    $value_tmp=substr($value,1,-1);
                } else {
                    $value_tmp=$value;  
                }    
                
                if($options['black_list']) { 
                    $arr=str_split(CHAR_BLACKLIST);
                    $value_tmp=str_replace($arr,'\'',$value_tmp);
                }  
                
                $value_tmp=filter_var($value_tmp,FILTER_SANITIZE_STRING);
                
                $value=$prefix.$value_tmp.$suffix;
                break;
            }
            //alpha extended for file path
            case 'path' : {
                if(!isset($options['xtra'])) $options['xtra']='\\\:\._\-\'\/';
                $value=preg_replace('/[^A-Za-z0-9 '.$options['xtra'].']/','-',$value);
                break;
            } 
            //alpha broadest
            case 'text' : {
                if($options['strip_tags']) $value=strip_tags($value);
                
                if($options['black_list']) {         
                    $arr=str_split(CHAR_BLACKLIST_TEXT);
                    $value=str_replace($arr,'\'',$value);
                } 
                 
                if($options['context']=='input' and $options['white_list']) {
                    $value_clean='';
                    $white_list=CHAR_ALPHA.CHAR_ALPHA_XTRA.CHAR_SPECIAL_TEXT.CHAR_FORMAT;
                    
                    if(CHAR_ENCODING==='UTF8') {
                        $arr=Calc::splitStrUtf8($value);
                    } else {     
                        $arr=str_split($value);
                    }  
                    
                    foreach($arr as $c) {
                        if(strpos($white_list,$c)!==false) $value_clean.=$c;
                    } 
                    $value=$value_clean; 
                } else {  
                    $value=filter_var($value,FILTER_SANITIZE_STRING);
                }  
                
                break;
            }
            case 'integer' : {
                $value=intval($value); //prevents '1.00' being converted into '100'
                $value=filter_var($value,FILTER_SANITIZE_NUMBER_INT);
                break;
            }
            case 'float' : {
                $value=filter_var($value,FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND);
                break;
            }
            case 'html' : {
                $value=filter_var($value,FILTER_SANITIZE_SPECIAL_CHARS, FILTER_FLAG_ENCODE_HIGH);
                break;
            }
            case 'url' : {
                //FILTER_SANITIZE_URL way too permissive...but any issues should be caught by htmlspecial chars!!!
                $value=filter_var($value,FILTER_SANITIZE_URL); 
                break;
            } 
            case 'email' : {
                $value=filter_var($value,FILTER_SANITIZE_EMAIL);
                break;
            }
        }
                
        //NB convert any <tags> to their html &xxx; literal equivalents
        if($options['htmlentity']) $value=htmlentities($value); 
        return $value;  
    }
    
    public static function check($type='text',$value,$options=array(),&$error_str) 
    {
        $error_tmp='';
        
        if(!isset($options['white_list'])) $options['white_list']=false;
        if(!isset($options['black_list'])) $options['black_list']=true;
        if(!isset($options['context'])) $options['context']='input'; //not applied yet...all code assumes for <form> input(restrictive)
        
        $allow=array('header','alpha','string','text','html','url','email');
        if(!in_array($type,$allow)) $type='text';
        
        switch($type) {
            //header: use whenever setting a response header like header('location:....') or setcookie()
            case 'header' : {
                $pattern='~(\r\n|\r|\n|%0a|%0d|%0D|%0A)~';
                if(preg_match($pattern,$value)) $error_tmp='Cannot insert carriage returns or line feeds into response header!';
                break;
            } 
            //alphanumeric and space only
            case 'alpha' : {
                $pattern='/[^A-Za-z0-9 ]/';
                if(preg_match($pattern,$value)) $error_tmp='Only Letters and numbers allowed!';
                break;
            }
            //alpha extended
            case 'string' : {  
                if(CHAR_ENCODING==='UTF8') {
                    $arr=Calc::splitStrUtf8($value);
                } else {     
                    $arr=str_split($value);
                }  
                
                if($options['white_list']) {
                    $white_list=CHAR_ALPHA.CHAR_ALPHA_XTRA.CHAR_SPECIAL;
                    foreach($arr as $c) {
                        if(strpos($white_list,$c)===false) $error_tmp.=$c.' ';
                    }
                }  
                
                if($options['black_list']) {
                    $black_list=CHAR_BLACKLIST;        
                    foreach($arr as $c) {
                        if(strpos($black_list,$c)!==false) $error_tmp.=$c.' ';
                    }  
                }
                
                if($error_tmp!='') $error_tmp='Invalid characters('.$error_tmp.')';
                
                break;
            }
            //alpha broadest
            case 'text' : {                                 
                if(CHAR_ENCODING==='UTF8') {
                    $arr=Calc::splitStrUtf8($value);
                } else {     
                    $arr=str_split($value);
                } 
                
                if($options['white_list']) {
                    $white_list=CHAR_ALPHA.CHAR_ALPHA_XTRA.CHAR_SPECIAL_TEXT.CHAR_FORMAT;
                    foreach($arr as $c) {
                        if(strpos($white_list,$c)===false) $error_tmp.=$c.' ';
                    }
                }  
                
                if($options['black_list']) {
                    $black_list=CHAR_BLACKLIST_TEXT;        
                    foreach($arr as $c) {
                        if(strpos($black_list,$c)!==false) $error_tmp.=$c.' ';
                    }  
                }
                
                if($error_tmp!='') $error_tmp='Invalid characters('.$error_tmp.')';
                
                break;
            }
            case 'html' : {
                $checker = new SafeHtmlChecker;
                $checker->check('<all>'.$value.'</all>');
                if(!$checker->isOK()) {
                    $errors=$checker->getErrors();
                    foreach($errors as $err) $error_tmp.=' '.$err.'<br/>';
                }
                break;
            }
            case 'url' : {
                if(filter_var($value,FILTER_VALIDATE_URL)===false) $error_tmp='Invalid URL!';
                break;
            } 
            case 'email' : {
                if(filter_var($value,FILTER_VALIDATE_EMAIL)===false) $error_tmp='Invalid email address!';
                break;
            }
        }
        
        $error_str.=$error_tmp;
        if($error_tmp=='')  return true; else return false;  
    }
}

/* SafeHtmlChecker - checks HTML against a subset of 
     elements to ensure safety and XHTML validation.
     
     Simon Willison, 23rd Feb 2003
     
     Note: HTML sent to the checker must be wrapped in an '<all>' tag.
     HTML can be sent to the checker in chunks, with multiple calls to 
     the check() method.
     
     Usage:
     
     $checker = new SafeHtmlChecker;
     $checker->check('<all>'.$html.'</all>');
     if ($checker->isOK()) {
             echo 'Everything is fine';
     } else {
             echo '<ul>';
             foreach ($checker->getErrors() as $error) {
                     echo '<li>'.$error.'</li>';
             }
             echo '</ul>';
     }

     Updated 15th September 2003: Added extra <? and <script filters.

*/

// Entity classes, adapted from XHTML 1.0 strict DTD
define('E_INLINE_CONTENTS', 'br em strong dfn code q samp kbd var cite abbr acronym sub sup a #PCDATA');
define('E_BLOCK_CONTENTS', 'dl ul ol blockquote p'); //div
define('E_FLOW_CONTENTS', E_BLOCK_CONTENTS.' '.E_INLINE_CONTENTS);

class SafeHtmlChecker {
        // Array showing what tags each tag can contain
        var $tags = array(
                'all' => E_FLOW_CONTENTS,
                //'div' => E_FLOW_CONTENTS,
                'p' => E_INLINE_CONTENTS,
                'blockquote' => E_BLOCK_CONTENTS,
                'br' => '',
                // Lists
                'ul' => 'li',
                'ol' => 'li',
                'li' => E_FLOW_CONTENTS,
                'dl' => 'dt dd',
                'dt' => E_INLINE_CONTENTS,
                'dd' => E_FLOW_CONTENTS,
                // Inline elements
                'em' => E_INLINE_CONTENTS,
                'strong' => E_INLINE_CONTENTS,
                'dfn' => E_INLINE_CONTENTS,
                'code' => E_INLINE_CONTENTS,
                'q' => E_INLINE_CONTENTS,
                'samp' => E_INLINE_CONTENTS,
                'kbd' => E_INLINE_CONTENTS,
                'var' => E_INLINE_CONTENTS,
                'cite' => E_INLINE_CONTENTS,
                'abbr' => E_INLINE_CONTENTS,
                'acronym' => E_INLINE_CONTENTS,
                'sub' => E_INLINE_CONTENTS,
                'sup' => E_INLINE_CONTENTS,
                'a' => E_INLINE_CONTENTS
        );
        // Array showing allowed attributes for tags
        var $tagattrs = array(
                'blockquote' => 'cite',
                'q' => 'cite',
                'a' => 'href title target',
                'dfn' => 'title',
                'acronym' => 'title',
                'abbr' => 'title'
                //'div'=>'id class',
                //'ul'=>'id class',
                //'li'=>'id class'
        );
        // Internal variables
        var $errors = array();
        var $parser;
        var $stack = array();
        function SafeHtmlChecker() {
                $this->parser = xml_parser_create();
                xml_set_object($this->parser,$this);
                xml_set_element_handler($this->parser, 'tag_open', 'tag_close');
                xml_set_character_data_handler($this->parser, 'cdata');
                xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, false);
        }
        function check($xhtml) {
                //&nbsp; &ldquo; &rdquo; considered not valid XML....only (&amp; &lt; &gt; &apos; and &quot) are valid entities;
                $xhtml = str_replace('&nbsp;', ' ', $xhtml);
                $xhtml = str_replace('&ldquo;','"', $xhtml);
                $xhtml = str_replace('&rdquo;','"', $xhtml);
                //$xhtml = str_replace('&#x2018;','"', $xhtml);
                //$xhtml = str_replace('&#x2019;','"', $xhtml);
                $xhtml = str_replace('&rsquo;','\'', $xhtml); 
                // Open comments are dangerous
                $xhtml = str_replace('<!--', '', $xhtml);
                // So is CDATA
                $xhtml = str_replace('<![CDATA[', '', $xhtml);
                // And processing directives
                $xhtml = str_replace('<?', '', $xhtml);
                // And script elements require double checking just to be sure
                $xhtml = preg_replace('/<script/i', '', $xhtml);
                if (!xml_parse($this->parser, $xhtml)) {
                        $b=xml_get_current_byte_index($this->parser);
                        $str=' near text "'.self::clean('string',substr($xhtml,$b,50)).'" ';
                        $this->errors[] = 'XHTML is not well-formed : '.xml_error_string(xml_get_error_code($this->parser)).$str;
                }
        }
        function tag_open($parser, $tag, $attrs) {
                if ($tag == 'all') {
                        $this->stack[] = 'all';
                        return;
                }
                $previous = $this->stack[count($this->stack)-1];
                // If previous tag is illegal, no point in running tests
                if (!in_array($previous, array_keys($this->tags))) {
                        $this->stack[] = $tag;
                        return;
                }
                // Is tag a legal tag?
                if (!in_array($tag, array_keys($this->tags))) {
                        $this->errors[] = "Illegal tag: <code>$tag</code>";
                        $this->stack[] = $tag;
                        return;
                }
                // Is tag allowed in the current context?
                if (!in_array($tag, explode(' ', $this->tags[$previous]))) {
                        if ($previous == 'all') {
                                $this->errors[] = "Tag <code>$tag</code> must occur inside another tag";
                        } else {
                                $this->errors[] = "Tag <code>$tag</code> is not allowed within tag <code>$previous</code>";
                        }
                }
                // Are tag attributes valid?
                foreach ($attrs as $attr => $value) {
                        if (!isset($this->tagattrs[$tag]) || !in_array($attr, explode(' ', $this->tagattrs[$tag]))) {
                                $this->errors[] = "Tag <code>$tag</code> may not have attribute <code>$attr</code>";
                        }
                        // Special case for javascript: in href attribute
                        if ($attr == 'href' && preg_match('/^javascript/i', trim($value))) {
                                $this->errors[] = "<code>href</code> attributes may not contain the <code>javascript:</code> protocol";
                        }
                        // Special case for data: in href attribute
                        if ($attr == 'href' && preg_match('/^data/i', trim($value))) {
                                $this->errors[] = "<code>href</code> attributes may not contain the <code>data:</code> protocol";
                        }
                        // Special case for javascript: in blockquote cites (for use with blockquotes.js)
                        if ($attr == 'cite' && preg_match('/^javascript/i', trim($value))) {
                                $this->errors[] = "<code>cite</code> attributes may not contain the <code>javascript:</code> protocol";
                        }
                        // Special case for data: in blockquote cites (for use with blockquotes.js)
                        if ($attr == 'cite' && preg_match('/^data/i', trim($value))) {
                                $this->errors[] = "<code>cite</code> attributes may not contain the <code>data:</code> protocol";
                        }
                }
                // Set previous, used for checking nesting context rules
                $this->stack[] = $tag;
        }
        function cdata($parser, $cdata) {
                // Simply check that the 'previous' tag allows CDATA
                $previous = $this->stack[count($this->stack)-1];
                // If previous tag is illegal, no point in running test
                if (!in_array($previous, array_keys($this->tags))) {
                        return;
                }
                if (trim($cdata) != '') {
                        if (!in_array('#PCDATA', explode(' ', $this->tags[$previous]))) {
                                $this->errors[] = "Tag <code>$previous</code> may not contain raw character data";
                        }
                }
        }
        function tag_close($parser, $tag) {
                // Move back one up the stack
                array_pop($this->stack);
        }
        function isOK() {
                return count($this->errors) < 1;
        }
        function getErrors() {
                return $this->errors;
        }
}
