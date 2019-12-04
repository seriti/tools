<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Form;

//php-encryption class namespaces
use Defuse\Crypto\KeyProtectedByPassword;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\File;
use Defuse\Crypto\Key;

//class intended as a pseudo namespace for a group of functions to be referenced as "Crypt::function_name()"
class Crypt 
{
    
    public static function makeToken($options = array())  
    {
        if(!isset($options['max'])) $options['max'] = 64;
                
        $token = md5(uniqid(mt_rand(),true));    
                
        if(strlen($token) > $options['max']) $token = substr($token,0,$options['max']);
                
        return $token;
    }
    
    public static function makeSalt($options = array())  
    {
        if(!isset($options['max'])) $options['max']=250;
                
        $salt=md5(uniqid(mt_rand(),true));    
                
        if(strlen($salt)>$options['max']) $salt=substr($salt,0,$options['max']);
                
        return $salt;
    }

    public static function passwordHash($password,$salt,$options = array()) 
    {
        $hash = '';
        if(CRYPT_SHA256) {
            $salt = substr($salt,0,16); //required?
            $hash = crypt($password,'$5$'.$salt.'$');
        } else {
            $hash = crypt($password,$salt);
        }  
        return $hash;
    }  

    //new php-encryption class based encryption
    public static function encryptText($text,$key_encoded,$options=array()) 
    {
        $error = '';
        if(!isset($options['debug'])) $options['debug'] = false;
        
        $key = Key::loadFromAsciiSafeString($key_encoded);
        try {
            $text_encrypted = Crypto::encrypt($text,$key);
        } catch (\Defuse\Crypto\Exception\EnvironmentIsBrokenException $e) {
            $error = 'Encryption environment not working: '.$e->getMessage();
        }    
        
        if($error === '') {
            return $text_encrypted;;
        } else {
            if($options['debug']) return $error; else return 'ERROR encrypting text!';
        } 
    }  
    
    //new php-encryption class based decryption
    public static function decryptText($text_encrypted,$key_encoded,$options=array()) 
    {
        $error = '';
        if(!isset($options['debug'])) $options['debug'] = false;
        
        $key = Key::loadFromAsciiSafeString($key_encoded);
        try {
            $text = Crypto::decrypt($text_encrypted,$key);
        } catch (\Defuse\Crypto\Exception\EnvironmentIsBrokenException $e) {
            $error = 'Encryption environment not working: '.$e->getMessage();
        } catch (\Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $e) {
            $error = 'Wrong key or modified cipher text: '.$e->getMessage();
        }      
        
        if($error === '') {
            return $text;
        } else {
            if($options['debug']) return $error; else return 'ERROR decrypting text!';
        }  
    }  
    
    //new php-encryption class based encryption
    public static function encryptFile($file_path_plain,$file_path_encrypt,$key_encoded,$options = array()) 
    {
        $error = '';
        if(!isset($options['debug'])) $options['debug'] = false;
        
        $key = Key::loadFromAsciiSafeString($key_encoded);
        try {
            File::encryptFile($file_path_plain,$file_path_encrypt,$key);
        } catch (\Defuse\Crypto\Exception\IOException $e) {
            $error = 'IO error: '.$e->getMessage();
        } catch (\Defuse\Crypto\Exception\EnvironmentIsBrokenException $e) {
            $error = 'Encryption environment not working: '.$e->getMessage();
        } 
        
        if($error === '') {
            return true; 
        } else {
            if($options['debug']) return $error; else return 'ERROR ENcrypting file';
        }  
    } 
    
    public static function decryptFile($file_path_encrypt,$file_path_plain,$key_encoded,$options = array()) 
    {
        $error='';
        if(!isset($options['debug'])) $options['debug'] = false;
        
        $key = Key::loadFromAsciiSafeString($key_encoded);
        try {
            File::decryptFile($file_path_encrypt,$file_path_plain,$key);
        } catch (\Defuse\Crypto\Exception\IOException $e) {
            $error = 'IO error: '.$e->getMessage();
        } catch (\Defuse\Crypto\Exception\EnvironmentIsBrokenException $e) {
            $error = 'Encryption environment not working: '.$e->getMessage();
        } catch (\Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $e) {
            $error = 'Wrong key or modified encrypted file: '.$e->getMessage();
        } 
        
        if($error === '') {
            return true; 
        } else {
            if($options['debug']) return $error; else return 'ERROR DEcrypting file';  
        }
    } 
    
    //new php-encryption class password to unique key conversion
    //NB: this will create a different encoded key every time called with same password!!! NOT A HASH
    public static function createProtectedKeyEncoded($password) 
    {
        $protected_key = KeyProtectedByPassword::createRandomPasswordProtectedKey($password);
        $protected_key_encoded = $protected_key->saveToAsciiSafeString();
        return $protected_key_encoded;
    } 
    
    //new php-encryption class decodes protected key encoded using password
    public static function getKey($protected_key_encoded,$password) 
    {
        $protected_key = KeyProtectedByPassword::loadFromAsciiSafeString($protected_key_encoded);
        $key = $protected_key->unlockKey($password);
        $key_encoded = $key->saveToAsciiSafeString(); 
        return $key_encoded;
    }  
    
    public static function testingText($password) 
    {
        $output=array();
        
        //use self::createProtectedKeyEncoded($password) to generate a new $protected_key_encoded
        
        //$password = 12345abc
        $protected_key_encoded='def10000def50200291ca9b6db74c84d8e54d4680fc6a269b092576f4206dea1fa9d4a07e2bea94f475c2a551000ad88e004329ad3d4889882a61d45b502f9b318d4a8250b268d4d49105d1902268cf159e66e44b9fdca148187d7f09edb448a91909fd0a84635080a47b3b084535e634055f7bdbcdb99803e15ee8df45f62094ed7f665e1be4c77fbc09c022241c923570a55ba890b75e1737f2cefb2ad1eb01426836dbca225e2284bfba2a8b327a869e4a6aeafefb181ce22ea6cec089ee168e5a0f2beaa5cfed28e92b24574bbc6de4744a5868b0ea6702a862a6b5fa7225499a8e7c28975a7425534acf71db458a79c8e3330db7d5e2fb725a3f07ce6a5';
        $output['protected_key_encoded']=$protected_key_encoded;
        
        $user_key_encoded=self::getKey($protected_key_encoded,$password);
        //$protected_key = KeyProtectedByPassword::loadFromAsciiSafeString($protected_key_encoded);
        //$user_key = $protected_key->unlockKey($password);
        //$user_key_encoded= $user_key->saveToAsciiSafeString(); 
        $output['user_key_encoded']=$user_key_encoded;
        
        
        
        $credit_card_number='1234567890';
        $encrypted_card_number=self::encryptText($credit_card_number,$user_key_encoded);
        //****************
        //$user_key = Key::loadFromAsciiSafeString($user_key_encoded);
        //$encrypted_card_number = Crypto::encrypt($credit_card_number, $user_key);
        //$encrypted_card_number='PPdef502000bb650bf752274e26f4194ce92c6914593668d76b26dedaeb0ad3c519159038ec79c28d7b517d13a768ed2a817a7d191795d1c16397d72e008da8eae622a9f0acea17aeefa994c90acf65ae2d15ca500ab2162be38d6a1922bb6';
        
        //$output['user_key']=$user_key;
        $output['card_no']=$credit_card_number;
        $output['card_no_encrypted']=$encrypted_card_number;
        
        
        $output['card_no_decrypted']=self::decryptText($encrypted_card_number,$user_key_encoded);
        
        //$output['card_no_decrypted'] = Crypto::decrypt($encrypted_card_number, $user_key);
        
        /*
        try {
            $output['card_no_decrypted'] = Crypto::decrypt($encrypted_card_number, $user_key);
        } catch(\Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex) {
                // Either there's a bug in our code, we're trying to decrypt with the
                // wrong key, or the encrypted credit card number was corrupted in the
                // database.
                
                $output['ERROR']='WTF...'.$ex->getMessage();
        }
        */
        
        return $output;
    }  
        
}
