<?php
namespace Seriti\Tools;

use Exception;

//use Seriti\Tools\DbInterface;
/** BASED ON THE ORIGINAL CLASS for FILE type, added MYSQL option:
* Simple Cache class
* API Documentation: https://github.com/cosenary/Simple-PHP-Cache
*
* @author Christian Metz
* @since 22.12.2011
* @copyright Christian Metz - MetzWeb Networks
* @version 1.6
* @license BSD http://www.opensource.org/licenses/bsd-license.php
*/

class Cache 
{
     
    protected $cache_type = 'MYSQL'; 
    //required for type='FILE'
    protected $cache_dir = 'cache/';
    protected $cache_ext = '.cache';
    protected $cache_path = '';
    //required for type='MYSQL'
    protected $db;
    protected $table = 'cache';
    protected $cols = array('id'=>'cache_id','data'=>'data','date'=>'date');
    
    //set when class created
    protected $cache_name = '';
    protected $cache_user = '';
    protected $encode = 'JSON'; //can be JSON or SERIALIZE
    
    public function __construct($type,$param = array()) 
    {
        $this->cache_type = $type;
        $this->cache_name = 'DEFAULT';
        
        if(isset($param['encode'])) $this->encode = $param['encode'];
        //if(isset($param['type'])) $this->cache_type = $param['type'];
        //NB: can set cache user to anything to create cache for all users or groups of users
        if(isset($param['user'])) $this->cache_user = $param['user'];
        
        if($this->cache_type === 'FILE') {
            if(isset($param['dir'])) $this->setDir($param['dir']);
            if(isset($param['ext'])) $this->setExt($param['ext']);
        }
        
        if($this->cache_type === 'MYSQL') {
            if(isset($param['mysql'])) {    
                $this->db = $param['mysql'];
            } else {  
                throw new Exception('CACHE_MYSQL: No database specified');
            }  
            
            if(isset($param['table'])) {
                $this->table = $param['table'];
            } else {
                throw new Exception('CACHE_MYSQL: No table specified');
            }    
        }  
    }

    //setup a named cache. if called independantly then $name is assumed to contain any user specific markers
    public function setCache($name,$user_specific = true) 
    {
        $valid = false;
        
        $this->cache_name = $name;
        if($user_specific) $this->cache_name .= $this->cache_user;
        
        //hash name to prevent access by guessing name...not really necessary for MYSQL type, but prevents SQL injection
        $this->cache_name = $this->getHash($this->cache_name);
        
        if($this->cache_type === 'FILE') {
            if($this->checkCacheDir()) {
                $this->cache_path = $this->cache_dir.$this->cache_name.$this->cache_ext;
                $valid = true;
            } 
        } else {
            $valid = true;
        }    
        return $valid;
    }
    
    //Store data in the cache
    public function store($key, $data, $expiration = 0) 
    {
        $store = array(
            'time' => time(),
            'expire' => $expiration,
            'data' => $data
        );
        
        $cache = $this->loadCache();
        if(is_array($cache)) {
            $cache[$key] = $store;
        } else {
            $cache = array($key =>$store);
        }
            
        return $this->saveCache($cache);    
    }

    //Retrieve cached data by its key
    public function retrieve($key, $timestamp = false) 
    {
        $cache = $this->loadCache();
        if(!$timestamp) $type = 'data'; else $type = 'time';
        if(!isset($cache[$key][$type])) {
            return null;
        } else {  
            return $cache[$key][$type];
        }  
    }

    public function retrieveAll($meta = false) {
        if($meta === false) {
            $data = array();
            $cache = $this->loadCache();
            if($cache){
                foreach($cache as $key => $value) {
                    $data[$key] = $value['data'];
                }
            }
            return $data;
        } else {
            return $this->loadCache();
        }
    }

    public function erase($key) {
        $valid = false;
        $cache = $this->loadCache();
        if(is_array($cache)) {
            if(isset($cache[$key])) {
                unset($cache[$key]);
                $valid = $this->saveCache($cache);   
            } 
        }
        return $valid;
    }
    
    public function eraseExpired() {
        $counter = 0;
        $cache = $this->loadCache();
        if(is_array($cache)) {
            foreach ($cache as $key => $entry) {
                if($this->checkExpired($entry['time'],$entry['expire'])) {
                    unset($cache[$key]);
                    $counter++;
                }
            }
            //only update cache if some data has expired
            
        }
        return $counter;
    }

    public function eraseAll() {
        $valid = false;
        
        if($this->cache_type === 'FILE') {
            if(file_exists($this->cache_path)) {
                $file = fopen($this->cache_path,'w');
                $valid = fclose($file);
            }
        }
        if($this->cache_type === 'MYSQL') {
             $sql = 'DELETE FROM `'.$this->table.'` WHERE '.$this->cols['id'].' = "'.$this->cache_name.'" ';
             $this->db->executeSql($sql,$error_str); 
             if($error_str=='') $valid=true;    
        }   
        
        return $valid;
    }

    public function isCached($key) {
        $valid = false;
        $cache = $this->loadCache();
        if($cache !== false) {
            $valid = isset($cache[$key]['data']);
        }
        return $valid;
    }

    private function encodeData($data) {
        if($this->encode === 'JSON') $data = json_encode($data);    
        if($this->encode === 'SERIALIZE') $data = serialize($data);  
        return $data;
    }  
    
    private function decodeData($data) {
        if($this->encode ===  'JSON') $data = json_decode($data,true); 
        if($this->encode === 'SERIALIZE') $data = unserialize($data);     
        return $data;    
    } 
    
    //save cache to source
    private function saveCache($cache) {
        $valid = false;
        $error_str = '';
        $cache = $this->encodeData($cache);
                
        if($this->cache_type === 'FILE') {
            $bytes = file_put_contents($this->cache_path,$cache);
            if($bytes !== false) $valid = true;
        }  
        if($this->cache_type === 'MYSQL') {
             $sql = 'REPLACE INTO `'.$this->table.'` ('.$this->cols['id'].','.$this->cols['data'].','.$this->cols['date'].') '.
                    'VALUES("'.$this->cache_name.'","'.$this->db->escapeSql($cache).'",NOW())';
             $this->db->executeSql($sql,$error_str); 
             if($error_str == '') $valid = true;    
        }  
        
        return $valid;
    }  

    private function loadCache() {
        $valid = false;
        if($this->cache_type === 'FILE') {
            if(file_exists($this->cache_path)) {
                $cache = file_get_contents($this->cache_path);
                $valid = true;
            }  
        }  
        if($this->cache_type === 'MYSQL') {
            $where = array($this->cols['id'] => $this->cache_name);
            $rec = $this->db->getRecord($this->table,$where);
            if($rec != 0){
                $cache = $rec[$this->cols['data']];
                $valid = true;
            }  
        }
        
        if($valid) {  
            $cache = $this->decodeData($cache);
            return $cache;
        } else {
            return false;
        }    
    }

    private function checkExpired($timestamp,$expiration) {
        $expired = false;
        if($expiration !== 0) {
            $diff = time()-$timestamp;
            if($diff > $expiration) $expired = true; else $expired = false;
        }
        return $expired;
    }
    
    //NB: will create directory if it does not exist
    private function checkCacheDir() {
        if (!is_dir($this->cache_dir) && !mkdir($this->cache_dir,0775,true)) {
            throw new Exception('CACHE_DIRECTORY_ERROR: Unable to create directory '.$this->cache_dir);
        } elseif (!is_readable($this->cache_dir) || !is_writable($this->cache_dir)) {
            if (!chmod($this->cache_dir,0775)) {
                throw new Exception('CACHE_DIRECTORY_ERROR: Directory is not readable and writeable '.$this->cache_dir);
            }
        }
        return true;
    }
    
    //HELPER FUNCTIONS
    
    //hash filename to make it unguessable, and a legitimate file name
    private function getHash($file_name) {
        return sha1($file_name);
    }
    
    //set directory for file based cache
    private function setDir($dir) {
        if(substr($dir,-1) !== '/') $dir .= '/';
        $this->cache_dir = $dir;
    }
    
    //set extension for file based cache
    private function setExt($ext) {
        if(substr($ext,0,1) !== '.') $ext = '.'.$ext;
        $this->cache_ext = $ext;
    }
}
