<?php
namespace Seriti\Tools;

use Exception;

//class intended as a pseudo namespace for a group of functions to be referenced as "Doc::function_name()"
class Doc 
{
    public static function fileNameParts($file_name) 
    {
        $info = pathinfo($file_name);//returns [dirname] [basename] [extension] [filename]
        
        if($info['dirname'] == '.') $info['dirname'] = '';
        return $info;
    } 
    
    //legacy shite, dont use
    public static function stripDocText($doc_path) 
    {
        $error_str='';
        $content='';
        
        $preg_xml='/<.*?>/'; // '/(<.[^(><.)]+>)/'
        $preg_replace=' ';
        
        $doc_info=pathinfo($doc_path);
        $doc_ext=$doc_info['extension'];
        if($doc_ext=='docx' or $doc_ext=='xlsx' or $doc_ext=='ods' or $doc_ext=='odt')
        {
            $zip=new ZipArchive;
            if($zip->open($doc_path)===true)
            {
                for($i=0;$i<$zip->numFiles;$i++)
                {
                    $zip_path=$zip->getNameIndex($i);
                    $dir=strtolower(dirname($zip_path));
                    $dir_arr=explode('/',$dir);
                    $file=strtolower(basename($zip_path));
                    
                    if(($doc_ext=='ods' or $doc_ext=='odt') and $file==='content.xml')
                    {
                        $content.=preg_replace($preg_xml,$preg_replace,$zip->getFromIndex($i));
                    }
                    
                    if($doc_ext=='docx' and $file==='document.xml')
                    {
                        $content.=preg_replace($preg_xml,$preg_replace,$zip->getFromIndex($i));
                    }
                    
                    if($doc_ext=='xlsx' and in_array('xl',$dir_arr)!==false)
                    {
                        if($file=='sharedstrings.xml' or in_array('worksheets',$dir_arr)!==false)
                        {
                            $content.=preg_replace($preg_xml,$preg_replace,$zip->getFromIndex($i));
                        }
                    }
                }

                $zip->close();
            } else {
                $error_str='Error opening compressed document!';
            }
        } else {
            $error_str='Not a supported document type!';
        }

        if ($error_str=='') return $content; else return 'NONE';
    }

    public static function outputDoc($data,$name = '',$destination = '',$type = '') {
        //some other samples of header settings
        //header("Content-Type:  application/vnd.ms-excel");
        //header("Expires: 0");
        //header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        //header("Content-type: application/octet-stream");
        //header( "Content-Type: application/save-as" );
        //header("Content-Disposition: attachment; filename=contact_file.xls");
        //header("Pragma: no-cache");
        //header("Expires: 0");

        $destination = strtoupper($destination);
        if($destination === '') $destination = 'DOWNLOAD';

        //if no variables specified
        if($name === '') $name = 'dummy.doc';
        if($type === '') {
            $doc_info = pathinfo($name);
            $type = strtolower($doc_info['extension']);
        } else {
            $type = strtolower($type);
        }

        switch($destination)
        {
                case 'INLINE':
                        //Send to standard output
                        if(ob_get_contents())
                                self::ouput_error('Some data has already been output, can\'t send document');

                        if(php_sapi_name()!='cli')
                        {
                                //We send to a browser
                                header('Content-Type: application/'.$type);
                                if(headers_sent())
                                    self::outputError('Some data has already been output to browser, can\'t send document[<b>'.$name.'</b>]');

                                header('Content-Length: '.strlen($data));
                                header('Content-disposition: inline; filename="'.$name.'"');
                        }

                        echo $data;
                        break;
                case 'DOWNLOAD':
                        //Download file
                        if(ob_get_contents())
                            self::outputError('Some data has already been output, can\'t send document');

                        if(isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'],'MSIE'))
                            header('Content-Type: application/force-download');
                        else
                            header('Content-Type: application/octet-stream');

                        if(headers_sent())
                                self::outputError('Some data has already been output to browser, can\'t send document');

                        header('Content-Length: '.strlen($data));
                        header('Content-disposition: attachment; filename="'.$name.'"; size='.strlen($data).' ');

                        echo $data;
                        break;
                case 'FILE':
                        //Save to local file
                        $f=fopen($name,'wb');
                        if(!$f) self::outputError('Unable to create output file: '.$name);
                        fwrite($f,$data,strlen($data));
                        fclose($f);
                        break;
                default:
                        self::outputError('Incorrect output destination: '.$destination);
        }
        return '';
    }

    public static function downloadDoc($doc_path,$destination = 'DOWNLOAD',&$error)
    {
        $error = '';
        
        $info = Self::fileNameParts($doc_path);
        $doc_name = $info['filename'];
        $extension = strtolower($info['extension']);
        
        $content_type = 'application/octet-stream';
        
        if($destination === 'INLINE') {
            $disposition = 'inline'; 
          
            switch($extension) {
                case 'pdf'  : $content_type='application/pdf'; break;
                case 'jpg'  : $content_type='image/jpeg'; break;
                case 'jpeg' : $content_type='image/jpeg'; break;
                case 'gif'  : $content_type='image/gif'; break;
                case 'png'  : $content_type='image/png'; break;
                case 'tiff' : $content_type='image/tiff'; break;
                case 'mp3'  : $content_type='audio/mpeg'; break;
                case 'wav'  : $content_type='audio/x-wav'; break;
                case 'mp4'  : $content_type='video/mp4'; break;
                case 'wmv'  : $content_type='video/x-ms-wmv'; break;
            }   
        } else {
            $disposition = 'attachment';
        }  
            
        if(file_exists($doc_path)) {
            $file_size = filesize($doc_path);
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Content-Description: File Transfer');
            header('Content-Type: '.$content_type);
            header('Content-Length: '.$file_size);
            header('Content-Disposition: '.$disposition.'; filename="'.$doc_name.'"');
            readfile($doc_path);
        } else {
            $error .= 'document['.$doc_name.'] no longer exists!';
        }
    }

    public static function compressDoc($doc_path,$doc_name,$compress_path,&$error_str,$options=array()) {
        $error_str='';
        $zip=new ZipArchive;
        
        if(!is_file($compress_path)) {
            $result=$zip->open($compress_path,ZipArchive::CREATE);
        } else {
            $result=$zip->open($archive);
        }
        
        if($result===true) {
            if(($zip->addFile($doc_path,$doc_name))===false) {
                $error_str.='Could not add file['.$doc_name.'] to compressed file.'; 
            }  
            
        } else {
            $error_str.='Could not open or create compressed file['.$compress_path.']'; 
        } 
        
        $zip->close(); 
        if($error_str=='') return true; else return false;  
    }  

    public static function outputError($msg)  {
        $error = 'DOCUMENT_PROCESS_ERROR: ';
        if(defined(__NAMESPACE__.'\DEBUG') and DEBUG) $error .= $msg;
        throw new Exception($error);
    }
}
?>
