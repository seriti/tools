<?php
namespace Seriti\Tools;

use Exception;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\Exception\AwsException;
use Aws\CommandPool;

class Amazon 
{
    
    protected $s3;
    protected $bucket;
    protected $debug = false;

    protected function __construct($param = [])
    {
        if(isset($param['debug'])) {
            $this->debug = $param['debug'];
        } elseif(defined(__NAMESPACE__.'\DEBUG')) {
            $this->debug = DEBUG;
        } 
    }

    public static function setupS3($param = array()) 
    {
        $obj = new static($param);

        $error = '';
        if(!isset($param['region'])) $error .= 'NO Amazon S3 region specified! ';
        if(!isset($param['bucket'])) $error .= 'NO Amazon S3 bucket specified! ';
        if(!isset($param['key'])) $error .= 'NO Amazon S3 key specified! ';
        if(!isset($param['secret'])) $error .= 'NO Amazon S3 secret specified! ';
        if($error !== '') throw new Exception('AWS_S3_SETUP: Err['.$error.']');
        
        $obj->setBucket($param['bucket']);

        $credentials = ['key'=>$param['key'],'secret'=>$param['secret']];
        $client_param=['version'=>'latest','region'=>$param['region'],'credentials'=>$credentials];
        if($obj->debug) $client_param['debug'] = true; 

        $obj->s3 = new S3Client($client_param);
        
        if(!$obj->s3->doesBucketExist($param['bucket'])) {
           throw new Exception('AWS_S3_SETUP: Bucket['.$param['bucket'].'] does not exist!');
        }  
        
        return $obj;
    }

    public function s3Streamewrapper()
    {
        //enables "s3://" protocol in PHP stream functions
        $this->s3->registerStreamWrapper();
    }

    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
    }

    public function fOpen($file_name,$mode) 
    {
       $path = 's3://'.$this->bucket.'/'.$file_name;

       switch ($mode) {
           case 'read'   : $fopen_mode = 'r'; break; //read only
           case 'write'  : $fopen_mode = 'w'; break; //write only, overwrites any existing data
           case 'append' : $fopen_mode = 'a'; break; //write only, appends to any existing data
           case 'create' : $fopen_mode = 'x'; break; //write-only, error if file already exists
           default : $fopen_mode = 'INVALID';
       }

       if($fopen_mode === 'INVALID') throw new Exception('AWS_S3_SETUP: file open mode['.$mode.'] invalid!');

       return fopen($path,$fopen_mode); 
    }

    public function fileGetContents($file_name) 
    {
       $path = 's3://'.$this->bucket.'/'.$file_name;
       return file_get_contents($path); 
    }

    public function filePutContents($file_name,$data) 
    {
       $path = 's3://'.$this->bucket.'/'.$file_name;
       return file_put_contents($path,$data); 
    }

    public function getFile($file_name,$save_file_path,&$error) 
    {
        $error = '';
        
        try {
            $result = $this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$file_name,'SaveAs'=>$save_file_path]);
        } catch (S3Exception $e) {
            $error .= 'Could not retrieve file from Amazon S3: '.$e->getMessage().'<br/>';
        } 

        if($error === '') return true; else return false;
    }  
    
    public function putFiles($files = array(),&$error) 
    {
        $error = '';
        $error_tmp = '';
        
        if(count($files) == 0) $error .= 'NO files specified for upload to amazon S3!';
            
        if($error == '') {  
            foreach($files as $file) {
                $this->putFile($file['name'],$file['path'],$error_tmp);
                if($error_tmp != '') $error .= $error_tmp;
            }  
        }  
        
        if($error === '') return true; else return false;
    }

    //experimental, might be no faster than put files
    public function putFilesBulk($files = array(),&$error) 
    {
        $error = '';
             
        if(count($files) == 0) $error .= 'NO files specified for upload to amazon S3!';
            
        if($error == '') { 
            $commands = [];    
            foreach($files as $file) {
                $commands[] = $this->s3->getCommand('PutObject',['Bucket' => $this->bucket,'Key'=> $file['name'],'Body'=>$file['path']]);
            }

            $results = CommandPool::batch($this->s3,$commands);
            foreach($results as $result) {
               //could not find error setup for resultinterface!!
               // if($result instanceof Exception) ..
            }  
        }  
        
        if($error === '') return true; else return false;
    }

    public function putFile($file_name,$file_path,&$error) 
    {
        $error = '';
        
        if($file_name == '') $error .= 'NO file specified for upload to amazon S3!';
            
        try {
            $result = $this->s3->putObject(['Bucket'=>$this->bucket,'Key'=>$file_name,'SourceFile'=>$file_path]);
        } catch (S3Exception $e) {
            $error .= 'Error uploading file['.$file_name.'] '.$e->getMessage().'<br/>';
        }  
        
        if($error === '') return true; else return false;
    } 

    public function deleteFile($file_name,&$error) 
    {
        $error = '';
        
        if($file_name == '') $error .= 'NO file specified for deletion on amazon S3!';
            
        try {
            $result = $this->s3->deleteObject(['Bucket'=>$this->bucket,'Key'=>$file_name]);
        } catch (S3Exception $e) {
            $error .= 'Error deleting file['.$file_name.'] '.$e->getMessage().'<br/>';
        }  
        
        if($error === '') return true; else return false;
    }  
    


    public function createS3Bucket($bucket_name,&$error) 
    {
        $error = '';
        
        try {
            $result = $this->$s3->createBucket([
                'Bucket' => $bucket_name,
            ]);
        } catch (AwsException $e) {
            $error = 'Error creating bucket:'.$e->getMessage();
        } 

        if($error === '') return true; else return false;   
    }  

    public function listS3Buckets() 
    {
        $buckets = [];
        
        $result = $this->s3->listBuckets();

        foreach ($result['Buckets'] as $bucket) {
            $buckets[] = $bucket['Name'];
        }

        return $buckets;
    } 
    
    //NB $folder is actually key PREFIX search as S3 does not have real folders
    public function getBucketFiles($folder = '',$options = array(),&$error) 
    {
        $error = '';
        $files = array();
        
        if(!isset($options['folder_delimiter'])) $options['folder_delimiter'] = '/';
        if(!isset($options['max_files'])) $options['max_files'] = 1000; 
    
        try {
            $result = $$this->s3->listObjectsV2(['Bucket'=>$this->bucket,'MaxKeys'=>$options['max_files'],'prefix'=>$folder]);
        } catch(Exception $e) {
            $error .= 'Could not get bucket files: '.$e->getMessage().'<br/>';
        } 
    
        if($error == '') {
            foreach($result->Contents as $item) {
                if(empty($item)) continue;
                $object = (string) $item->Key;
                $date = (string) $item->LastModified;
                $size = (integer) $item->Size;

                $file = array();
                
                //check for filename only, with no sub-directories
                if(strrpos($object,$options['folder_delimiter']) === false) {
                    $file['dir'] = '';
                    $file['key'] = $object;
                    $file['name'] = $object;
                    $file['date'] = $date;
                    $file['size'] = $size;
                    $files[] = $file;
                } else {  
                    //check if actual file or "folder" only
                    if(substr($object,-1) != $options['folder_delimiter'] and $size != 0) {
                        $dir = explode($options['folder_delimiter'],$object);
                        $dir_no = count($dir);
                    
                        $file['dir'] = '';
                        for($i = 1; $i < ($dir_no-1); $i++) $file['dir'] .= $dir[$i];
                        $file['key'] = $object;
                        $file['name'] = $dir[$dir_no-1];
                        $file['date'] = $date;
                        $file['size'] = $size;
                        $files[] = $file;
                    }  
                }  
            }   
        }
        
        return $files; 
    }
    
    public function getS3Url($file_name,$expiry = '5 minutes',$param = array()) 
    {
        $url = '';

        $cmd_param['Bucket'] = $this->bucket;
        $cmd_param['Key'] = $file_name;
        if(isset($param['file_name_change'])) {
           $cmd_param['ContentDisposition'] = 'attachment; filename="'.$param['file_name_change'].'"';
        }

        $cmd = $this->s3->getCommand('GetObject',$cmd_param);

        $request = $this->s3->createPresignedRequest($cmd,$expiry);

        $url = (string)$request->getUri();

        return $url;
    }  
         
}
?>
