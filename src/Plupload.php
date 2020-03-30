<?php
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\Secure;
use Seriti\Tools\Doc;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\FileExtensions;
use Seriti\Tools\MessageHelpers;

use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\BASE_URL;
use Seriti\Tools\BASE_INCLUDE;

class Plupload {

    use IconsClassesLinks;
    use FileExtensions;
    use MessageHelpers;
    
    protected $mode = 'upload';
    protected $file_name = 'file';
    protected $file_name_plural = 'files';
    
    protected $max_file_size = 10000000;
    protected $upload_dir = '';
    protected $include_url_js = '';
            
    protected $debug = false;
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();
            
    //these cols MUST be specified in file table, but names can be different as specified in =>value
    protected $param = array('upload_url'=>'/admin/upload','upload_dir'=>'','max_file_size'=>'',
                             'container_id'=>'file_container','list_id'=>'file_list',
                             'browse_id'=>'browse_upload','browse_txt'=>'Select files','browse_attr'=>'',
                             'start_id'=>'start_upload','start_txt'=>'Upload files','start_attr'=>'class="btn btn-primary"',
                             'reset_id'=>'reset_upload','reset_txt'=>'Reset file upload','reset_attr'=>'',
                             'reset_function'=>'reset_file_upload()','console_id'=>'upload_console');      
                                                 
    protected $images = array('resize_client'=>true,'width'=>600,'height'=>400,'crop'=>false,'quality'=>90,
                              'width_tn'=>120,'height_tn'=>80);                                        
    
    //constructor function when class created
    public function __construct($param = []) {
        if(defined(__NAMESPACE__.'\DEBUG')) $this->debug = DEBUG;

        if(isset($param['upload_dir'])) {
            $this->upload_dir = $param['upload_dir'];
        } else {
            $this->upload_dir = BASE_UPLOAD.'temp/';
        } 

        if(is_dir($this->upload_dir)) {
            if(substr($this->upload_dir,-1) !== '/' ) $this->upload_dir .= '/';
        } else {
            $error = 'Plupload directory does not exist.';
            if($this->debug) $error .= '['.$this->upload_dir.']'; 
            throw new Exception('PLUPLOAD_ERROR: '.$error);
        }   
        
        if(isset($param['include_url_js'])) {
            $this->include_url_js = $param['include_url_js'];
        } else {
            $this->include_url_js = BASE_URL.BASE_INCLUDE.'plupload2/js/plupload.full.min.js';
        } 

        if(isset($param['upload_url'])) $this->param['upload_url']=$param['upload_url'];
        if(isset($param['allow_ext'])) $this->allow_ext=$param['allow_ext'];
        if(isset($param['max_file_size'])) $this->max_file_size=$param['max_file_size'];
        
        if(isset($param['container_id'])) $this->param['container_id']=$param['container_id'];
        if(isset($param['list_id'])) $this->param['list_id']=$param['list_id'];
        
        if(isset($param['browse_id'])) $this->param['browse_id']=$param['browse_id'];
        if(isset($param['browse_txt'])) $this->param['browse_txt']=$param['browse_txt'];
        if(isset($param['browse_attr'])) $this->param['browse_attr']=$param['browse_attr'];
        
        if(isset($param['start_id'])) $this->param['start_id']=$param['start_id'];
        if(isset($param['start_txt'])) $this->param['start_txt']=$param['start_txt'];
        if(isset($param['start_attr'])) $this->param['start_attr']=$param['start_attr'];
                
        if(isset($param['reset_id'])) $this->param['reset_id']=$param['reset_id'];
        if(isset($param['reset_txt'])) $this->param['reset_txt']=$param['reset_txt'];
        if(isset($param['reset_attr'])) $this->param['reset_attr']=$param['reset_attr'];
        if(isset($param['reset_function'])) $this->param['reset_function']=$param['reset_function'];
    }
    
    //default function to handle all processing and views
    public function process() 
    {
        $html = '';
        $options = array();
        
        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
                
        if($this->mode === 'upload') $html.=$this->fileUpload($options);
                        
        return $html;
    } 
    
    //setup multiple file ajax upload form element
    public function setupFileDiv($options = array()) 
    {
        $html = '';
        if(!isset($options['div_type'])) $options['div_type'] = 'STANDARD';
        
        $browse_css = '';
        if($this->classes['browse_link'] !== '') $browse_css = 'class="'.$this->classes['browse_link'].'"';
        $start_css = '';
        if($this->classes['start_link'] !== '') $start_css = 'class="'.$this->classes['start_link'].'"';
        $reset_css = '';
        if($this->classes['reset_link'] !== '') $reset_css = 'class="'.$this->classes['reset_link'].'"';
        $list_css = '';
        if($this->classes['file_list'] !=='') $list_css = 'class="'.$this->classes['file_list'].'"';
                
        if($options['div_type'] === 'STANDARD') {  
            $html .= '<div id="'.$this->param['container_id'].'">
                        <a id="'.$this->param['browse_id'].'" href="javascript:;" '.$browse_css.'>'.$this->param['browse_txt'].'</a> 
                        <a id="'.$this->param['start_id'].'" href="javascript:;" '.$start_css.'>'.$this->param['start_txt'].'</a>
                        <ul id="'.$this->param['list_id'].'" '.$list_css.'></ul>
                        <a id="'.$this->param['reset_id'].'" href="javascript:'.$this->param['reset_function'].';" '.$reset_css.'>'.$this->param['reset_txt'].'</a>
                      </div>'.
                     '<div id="'.$this->param['console_id'].'"></div>';
        }          
            
        return $html;
    }
    
    //setup javascript
    public function getJavascript($options = array()) {
        $js = '';
        
        if(!isset($options['interface'])) $options['interface'] = 'SIMPLE';
        
        //NB: successfully uploaded files are given hidden form tags "file_id_xxxxx"(value = unique name) and "file_name_xxxxx" (value=original file name)
        if($options['interface'] === 'SIMPLE') {
            
            $resize_str = '';
            if($this->images['resize_client']) {
                if($this->images['crop']) $crop_str = 'true'; else $crop_str = 'false';
                $resize_str .= 'resize : { width : '.$this->images['width'].', height : '.$this->images['height'].', '.
                                          'quality : '.$this->images['quality'].', crop: '.$crop_str.' },';
            }  
            
            $js_ext = '';
            //$js_ext = '{title: "Allowed file types", extensions: "'.implode(',',$this->allow_ext).'"}';
            foreach($this->allow_ext as $name => $extensions) {
                $js_ext.='{title : "'.$name.'('.implode(',',$extensions).')", extensions : "'.implode(',',$extensions).'"},';
            } 

            $js .= '<script type="text/javascript" src="'.$this->include_url_js.'"></script>
                    <script type="text/javascript">

                    function reset_file_upload() {
                        uploader.stop();
                        document.getElementById(\''.$this->param['list_id'].'\').innerHTML="";
                        document.getElementById(\''.$this->param['console_id'].'\').innerHTML="";
                        document.getElementById(\''.$this->param['reset_id'].'\').style.display="none";
                    }  

                    document.getElementById(\''.$this->param['start_id'].'\').style.display="none";
                    document.getElementById(\''.$this->param['reset_id'].'\').style.display="none";

                    var uploader = new plupload.Uploader({
                        browse_button: \''.$this->param['browse_id'].'\', 
                        url: \''.$this->param['upload_url'].'\',
                        unique_names : true,
                        max_file_size: \''.$this->max_file_size.'\', '.$resize_str.'  
                        filters: ['.$js_ext.']
                    });

                    uploader.init();

                    uploader.bind(\'FilesAdded\', function(up, files) {
                        var html = "";
                        plupload.each(files, function(file) {
                            html += \'<li id="\' + file.id + \'">\' + file.name + \' (\' + plupload.formatSize(file.size) + \') <b></b></li>\';
                        });
                        document.getElementById(\''.$this->param['list_id'].'\').innerHTML += html;
                        
                        document.getElementById(\''.$this->param['start_id'].'\').style.display=\'inline\';
                    });

                    uploader.bind(\'FileUploaded\', function(up, file, info) {
                        var html = "";
                        
                        response = JSON.parse(info.response);
                        if(response.error) {
                            up.trigger(\'Error\',{code : response.error.code,message : response.error.message});
                            
                            document.getElementById(file.id).getElementsByTagName(\'b\')[0].innerHTML = \'<span>Error: \'+response.error.message+\'</span>\';
                        } else {
                            html+=\'<input type="hidden" name="file_id_\' + file.id + \'" value="\'+file.target_name+\'">\';
                            html+=\'<input type="hidden" name="file_name_\' + file.id + \'" value="\'+file.name+\'">\';
                            
                            document.getElementById(\''.$this->param['list_id'].'\').innerHTML += html;
                        }  
                        
                        
                    });

                    uploader.bind(\'UploadProgress\', function(up, file) {
                        document.getElementById(file.id).getElementsByTagName(\'b\')[0].innerHTML = \'<span>\' + file.percent + "%</span>";
                    });

                    uploader.bind(\'Error\', function(up, err) {
                        document.getElementById(\''.$this->param['console_id'].'\').innerHTML += "\nError #" + err.code + ": " + err.message;
                    });

                    document.getElementById(\''.$this->param['start_id'].'\').onclick = function() {
                        uploader.start();
                        document.getElementById(\''.$this->param['start_id'].'\').style.display=\'none\';
                        document.getElementById(\''.$this->param['reset_id'].'\').style.display=\'inline\';
                    };

                    </script>';
        }          
        
        return $js;
    }
    
    //process ajax uploads
    public function fileUpload($options = array()) {
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        // 5 minutes execution time
        @set_time_limit(5 * 60);
        
        $targetDir = $this->upload_dir;
        
        $cleanupTargetDir = true; // Remove old files
        $maxFileAge = 5 * 3600; // Temp file age in seconds

        
        if(!file_exists($targetDir)) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Target directory['.$targetDir.'] is invalid!"}, "id" : "id"}');
        }

        // Get a file name
        if (isset($_REQUEST["name"])) {
            $fileName = $_REQUEST["name"];
        } elseif (!empty($_FILES)) {
            $fileName = $_FILES["file"]["name"];
        } else {
            $fileName = uniqid("file_");
        }
     
        //check file has valid extension
        $info = Doc::fileNameParts($fileName);
        $info['extension'] = strtolower($info['extension']);
        $type = '';
        //check that download file has valid extension and return $type if exntsion valid
        if(!$this->checkFileExtension($info['extension'],$type)) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "download file extension['.$info['extension'].'] is invalid!"}, "id" : "id"}');
        }  
        
        //directory separator already in $targetDir       
        $filePath = $targetDir.$fileName;

        // Chunking might be enabled
        $chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
        $chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;

        // Remove old temp files    
        if($cleanupTargetDir) {
            if (!is_dir($targetDir) || !$dir = opendir($targetDir)) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "id" : "id"}');
            }

            while (($file = readdir($dir)) !== false) {
                $tmpfilePath = $targetDir.$file;

                // If temp file is current file proceed to the next
                if ($tmpfilePath == "{$filePath}.part") {
                    continue;
                }

                // Remove temp file if it is older than the max age and is not the current file
                if (preg_match('/\.part$/', $file) && (filemtime($tmpfilePath) < time() - $maxFileAge)) {
                    @unlink($tmpfilePath);
                }
            }
            closedir($dir);
        }   


        // Open temp file
        if (!$out = @fopen("{$filePath}.part", $chunks ? "ab" : "wb")) {
            die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
        }

        if (!empty($_FILES)) {
            if ($_FILES["file"]["error"] || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
            }

            // Read binary input stream and append it to temp file
            if (!$in = @fopen($_FILES["file"]["tmp_name"], "rb")) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
            }
        } else {    
            if (!$in = @fopen("php://input", "rb")) {
                die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
            }
        }

        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }

        @fclose($out);
        @fclose($in);

        // Check if file has been uploaded
        if (!$chunks || $chunk == $chunks - 1) {
            // Strip the temp .part suffix off 
            rename("{$filePath}.part", $filePath);
        }

        // Return Success JSON-RPC response
        die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');
    }  
    
    
}  