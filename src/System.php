<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Audit;
use Seriti\Tools\Crypt;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\TableStructures;

use Psr\Container\ContainerInterface;

class System extends Model 
{

    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use TableStructures;

    private $container;
    protected $container_allow = ['mail','user'];
    
    protected $protect_defaults = ['FILES','IMAGES','BACKUP_DOC','KEY_ENCODED','KEY_HASH','KEY_SALT'];
    protected $routes = ['encrypt'=>'encrypt'];

    public function __construct(DbInterface $db, ContainerInterface $container, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;

        //$this->setup($param);
    }
    

    public function setup($param = array()) 
    {
        //Implemented in Model Class
        if(isset($param['encrypt_key'])) $this->encrypt_key = $param['encrypt_key'];
        if(isset($param['read_only'])) $this->access['read_only'] = $param['read_only'];
        if(isset($param['audit'])) $this->access['audit'] = $param['audit'];

        //local setup
        if(isset($param['protect_defaults'])) $this->protect_defaults = $protect_defaults;

        //add all standard system cols which MUST exist,
        //NB: "text" column is not validated 'secure'=>false and can contain any text
        $this->addCol(['id'=>$this->system_cols['id'],'title'=>'System ID','type'=>'STRING','key'=>true,'key_auto'=>false,'list'=>true]);
        $this->addCol(['id'=>$this->system_cols['count'],'title'=>'Counter','type'=>'INTEGER','max'=>1000000000000000,'required'=>false]);
        $this->addCol(['id'=>$this->system_cols['text'],'title'=>'Setting','type'=>'TEXT','secure'=>false,'required'=>false]);
    }  

    public function getDefault($default_id,$default_value,$type = 'text')  
    {
        $rec = $this->get($default_id);

        if($type !== 'text' and $type !== 'count') $type = 'text';

        if($rec === 0) {
            $value = $default_value;
        } else {
            $value = $rec[$this->system_cols[$type]];
        }

        return $value; 
    }

    public function getUserDefault($default_id,$user_id,$default_value,$type = 'text')  
    {
        $default_id = $default_id.$user_id;
        return $this->getDefault($default_id,$default_value,$type);
    }
    
    public function setDefault($default_id,$value,$type = 'text')  
    {
        $error = '';

        if($type !== 'text' and $type !== 'count') $type = 'text';
        
        $data[$this->system_cols[$type]] = $value;

        $existing = $this->getDefault($default_id,'NONE');
        if($existing === 'NONE') {
            $data[$this->system_cols['id']] = $default_id;
            $result = $this->create($data);
        } else {
            $result = $this->update($default_id,$data);
        }    
                    
        if($result['status'] === 'OK') {
           return true; 
        } else {
           $error = 'SYSTEM_DEFAULT_ERROR: could not update default';
           if($this->debug) $error .= $default_id.': '.implode(',',$result['errors']);
           throw new Exception($error);
        }
    }

    public function setUserDefault($default_id,$user_id,$value,$type = 'text')  
    {
        if($user_id != '') {
            $default_id = $default_id.$user_id;
            return $this->setDefault($default_id,$default_value,$type);
        } 
        
        return false;   
    }
    
    public function removeDefault($default_id)  
    {
        $reset = false;
        $error = '';

        if(in_array($default_id,$this->protect_defaults) === false) {
            $result = $this->delete($default_id);
            if($result['status'] !== 'OK') {
                $error = 'SYSTEM_DEFAULT_ERROR: could not delete default';
                if($this->debug) $error .= $default_id.': '.implode(',',$result['errors']);
            }
        } else {
            $error = 'SYSTEM_DEFAULT_ERROR: could not delete default';
            if($this->debug) $error .= $default_id.': is PROTECTED';
        } 
        
        if($error === '') {
           return true;
        } else {       
           throw new Exception($error);
        }
    }

    public function removeUserDefault($default_id,$user_id)  
    {
        if($user_id != '') {
            $default_id = $default_id.$user_id;
            return $this->sremoveDefault($default_id);
        }
        
        return false;    
    }

    //all pages that require encryption should call this function first
    public function configureEncryption($param = []) 
    {
        //redirect route to capture encrypt key password
        if(!isset($param['redirect'])) $param['redirect'] = $this->routes['encrypt'];

        $encrypt_valid = false;
        $error = '';
        
        $encrypt_key = $this->getEncryptKey($error);
        if($error === '' and $encrypt_key !== false) {
            $encrypt_valid = true;  
        } else { 
            //save user page for redirect after capture of encrypt key password
            $user = $this->getContainer('user');
            $user->setLastPage();
            
            $location = $param['redirect'];
            header('location: '.$location);
            die();
        }

        if($encrypt_valid) return $encrypt_key; else return false;
    }  

    //extract master encryption key from client side cookie
    public function getEncryptKey(&$error) 
    {
        $error = '';
        $key = [];

        $key['encoded'] = $this->getDefault('KEY_ENCODED',0);
        if($key['encoded'] === 0 or strlen($key['encoded']) < 32) $error .= 'Invalid key encoded! ';

        $key['hash'] = $this->getDefault('KEY_HASH',0);
        if($key['hash'] === 0 or strlen($key['hash']) < 32) $error .= 'Invalid key hash! ';
                        
        //temporary cookie pwd protected key encoded
        if(!isset($_SESSION['cookie_key_encoded']) or strlen($_SESSION['cookie_key_encoded']) < 32) {
            $error .= 'Invalid user cookie key1! ';
        }
        //master password encrypted using above key decoded with cookie key_token
        if(!isset($_SESSION['cookie_key_encrypted']) or strlen($_SESSION['cookie_key_encrypted'])<32) {
            $error .= 'Invalid user cookie key2! ';
        } 

        //NB: 'key_token' is actually cookie protected key encoded password, a little obscurity!
        if(!isset($_COOKIE['key_token']) or strlen($_COOKIE['key_token']) < 32) {
            $error.='Invalid user cookie key token!<br/>';
        }
        
        //decrypt master encryption key and verify correct
        if($error === '') {
            $cookie_pwd = $_COOKIE['key_token'];
            $protected_key_encoded = $_SESSION['cookie_key_encoded'];
            $key_encoded = Crypt::getKey($protected_key_encoded,$cookie_pwd); 
            $password_encrypted = $_SESSION['cookie_key_encrypted'];
            $password = Crypt::decryptText($password_encrypted,$key_encoded);
            
            if(password_verify($password,$key['hash'] !== true)) {
                $error .= 'INVALID encryption key! ';
            }  
        } 
        
        //NB:$key['encoded'] is protected by password 
        if($error === '') {
            $key_encoded = Crypt::getKey($key['encoded'],$password); 
            return $key_encoded;
        }  
            
        return false; 
    }  

    //configures temporary clientside cookie/pwd to lock/unlock master encryption password 
    //which in turn is used to unlock master encryption key
    public function storeEncryptKey($password,&$error) 
    {
        $error = '';
        
        //first validate that captured master password/key is correct!
        $key_hash = $this->getDefault('KEY_HASH',0);
        if($key_hash === 0 or strlen($key_hash) < 32) {
            $error .= 'Invalid encryption key hash! ';
        } else {
            if(password_verify($password,$key_hash) !== true) {
                $error .= 'The encryption key is INVALID!<br/>';  
            } 
        }    

        //generate random password to encrypt master password with before storing temporary password in cookie on client side
        if($error === '') {
            $cookie_pwd = md5(uniqid(mt_rand(),true));
            $protected_key_encoded = Crypt::createProtectedKeyEncoded($cookie_pwd);
            
            $key_encoded = Crypt::getKey($protected_key_encoded,$cookie_pwd); 
            $password_encrypted = Crypt::encryptText($password,$key_encoded);
            
            $_SESSION['cookie_key_encoded'] = $protected_key_encoded;
            $_SESSION['cookie_key_encrypted'] = $password_encrypted;
            
            //store cookie password on client side 
            $options = [];
            if(!$this->debug) $options['secure'] = true; //will not set cookie unless https ptotocol
            //$options['expire'] = 0; //this will override expire_days and cookie expires next time browser closes
            $expire_days = 1;
            //cookie name is intentionally misleading...a little obscurity never hurt anyone!
            Form::setCookie('key_token',$cookie_pwd,$expire_days,$options);
        }    
            
        if($error === '') return true; else return false;
    }  

    
    //Setup for global encryption key using master password(for all users), save password hash for verification and encoded key
    //NB:password is used to lock/unlock encryption key which is randomly generated on first use
    public function setupEncryptKey($password,&$error) 
    {
        $error = '';
        
        //validate master_key, must be alphanumeric, minimum 16 characters...etc
        $rules['alpha'] = true;
        $rules['length'] = 16;
        $rules['strong'] = true;
        Validate::securePassword($password,$rules,$error);
        //user sees this as a encryption key in secure.php
        if($error !== '') $error = str_replace('Password','Encryption key',$error);
        
        //if key already setup then cannot change!
        if($error === '') {
            $key_hash = $this->getDefault('KEY_HASH','');
            if($key_hash !== '') {
                $error .= 'Encryption key already setup! Cannot modify!<br/>';
            }    
        }  
            
        //generate random key locked with password and key hash and save permanently
        //NB: SHOULD EMAIL TO ADMIN FOR FUTURE SAFEKEEPING SHOULD DATABASE VALUE BE LOST(cannot generate again as will be different).
        if($error === '') {
            $protected_key_encoded = Crypt::createProtectedKeyEncoded($password);
            //NB: PASSWORD_HASH is internal php bcrypt constant
            $key_hash = password_hash($password,PASSWORD_DEFAULT);
            
            $this->setdefault('KEY_ENCODED',$protected_key_encoded);
            $this->setdefault('KEY_HASH',$key_hash);
        }
        
        if($error === '') return true; else return false;
    }

}
