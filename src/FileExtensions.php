<?php
namespace Seriti\Tools;

trait  FileExtensions 
{

    protected $allow_ext = array('Documents'=>array('doc','xls','ppt','pdf','rtf','docx','xlsx','pptx','ods','odt','txt','csv','zip','gz','msg'),
                                 'Images'=>array('jpg','jpeg','bmp','gif','tif','tiff','png','pnt','pict','pct','pcd','pbm'),
                                 'Audiovisual'=>array('mp3','mp4','m4v','mpg','mpeg','mpeg4','wav','swf','wmv','mov','ogg','ogv','webm','avi','3gp','3g2'));

    protected $encrypt_ext = array('doc','xls','ppt','pdf','rtf','docx','xlsx','pptx','ods','odt','txt','csv','zip','gz','msg'); 

    protected $image_resize_ext = array('jpg','jpeg','png','gif');
    
    protected $inline_ext = array('pdf'); //default to inline donwload option rather than force as file download 
  
    protected function checkFileExtension($ext,&$type)
    {
        $valid = false;
        foreach($this->allow_ext as $name => $extensions) {
            if(in_array($ext,$extensions)) {
                $valid = true;
                $type = $name;
            }  
        }

        return $valid;
    }
}
