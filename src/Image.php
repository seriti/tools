<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Doc;

class Image
{
    public static function getImage($format,$path,&$error)
    {
        $error = '';
        $data = false;
        $allow = ['jpg','jpeg','gif','tif','tiff','png']; 

        if(!file_exists($path)) $error .= 'Image does not exist!';

        $info = Doc::fileNameParts($path);
        if(!in_array($info['extension'],$allow)) $error .= 'Image extension['.$info['extension'].'] Invalid!';

        switch($info['extension']) {
            case 'jpg'  : $content_type = 'image/jpeg'; break;
            case 'jpeg' : $content_type = 'image/jpeg'; break;
            case 'gif'  : $content_type = 'image/gif';  break;
            case 'png'  : $content_type = 'image/png';  break;
            case 'tif'  : $content_type = 'image/tiff'; break;
            case 'tiff' : $content_type = 'image/tiff'; break;
        }

        if($error !== '') return false;

        if($format === 'SRC') $data = 'data:'.$content_type.';base64,'.base64_encode(file_get_contents($path));
        
        if($format === 'RAW') $data = file_get_contents($path);

        if($format === 'BASE64') $data = base64_encode(file_get_contents($path));

        return $data;
    }

    public static function resizeImage($type,$from_path,$to_path,$crop = true,$width,$height,&$error) {
        $error = '';
        
        $allow_type = array('gif','png','jpg','jpeg');
        
        if(!in_array($type,$allow_type)) $error .= 'cannot resize image type['.$type.'] only['.implode(',',$allow_type).'] allowed!<br/>';
        if(!file_exists($from_path)) $error .= 'Image file path['.$from_path.'] invalid!<br/>';
        
        if($error != '') return false;
        
        list($orig_width,$orig_height) = getimagesize($from_path);
        
        if($type == 'jpg') $type = 'jpeg';
        switch($type) {
            case 'gif' : $image_orig = imagecreatefromgif($from_path); break;
            case 'png' : $image_orig = imagecreatefrompng($from_path); break;
            case 'jpeg': $image_orig = imagecreatefromjpeg($from_path); break;
        }
        
        //resize from original image src=source dst=destination
        if($crop) { 
            $src_width = $orig_width;
            $src_height = floor(($height/$width)*$orig_width);
            if($src_height > $orig_height) $src_height = $orig_height;
              
            $dst_width = $width;
            $dst_height = $height;
        } else {
            if($width < $orig_width)  {
                $dst_width = $width;
                $dst_height = floor(($orig_height/$orig_width)*$width);
            } else {
                $dst_width = $orig_width;
                $dst_height = $orig_height;
            }
            $src_width = $orig_width;
            $src_height = $orig_height;
        }  

        $image_tn = imagecreatetruecolor($dst_width,$dst_height);
        imagecopyresampled($image_tn,$image_orig,0,0,0,0,$dst_width,$dst_height,$src_width,$src_height);

        switch($type) {
          case 'gif' : imagepng($image_tn,$to_path); break;
          case 'png' : imagepng($image_tn,$to_path); break;
          case 'jpeg': imagejpeg($image_tn,$to_path); break;
        }

        if($error === '') return true; else return false;
    }

    public static function buildGallery($images = [],$type,$options = []) {
        $html = '';
        if(count($images) == 0) return $html;
        
        if(!isset($options['id'])) $options['id'] = 'gallery';
        if(!isset($options['src_root'])) $options['src_root'] = '';
        if(!isset($options['img_class'])) $options['img_class'] = 'img-responsive center-block';
        if(!isset($options['img_style'])) $options['img_style'] = '';
        if(!isset($options['auto_rotate'])) $options['auto_rotate'] = false;
        
        $img_style = '';
        if($options['img_style'] !== '') $img_style .= 'style="'.$options['img_style'].'"';
        
        $data_ride = '';
        if($options['auto_rotate']) $data_ride = 'data-ride="carousel"';
        
        if($type === 'CAROUSEL') {
            $html .= '<div id="'.$options['id'].'" class="carousel slide" '.$data_ride.'>'.
                     '<ol class="carousel-indicators">';
            $i = 0;
            foreach($images as $image) {
                if($i == 0) $class = 'class="active"'; else $class = '';
                $html .= '<li data-target="#'.$options['id'].'" data-slide-to="'.$i.'" '.$class.'></li>';   
                $i++;
            }               
            $html .= '</ol>'.
                        '<div class="carousel-inner">';
            $i = 0;
            foreach($images as $image) {
                if($i == 0) $class = 'class="item active"'; else $class = 'class="item"';
                $src = $options['src_root'].$image['file_name'];
                $html .= '<div '.$class.'>'.
                         '<img src="'.$src.'" alt="'.$image['title'].'" class="'.$options['img_class'].'" '.$img_style.'>';
                if($image['title'] != '') {
                    $html .= '<div class="carousel-caption"><h3>'.$image['title'].'</h3><p>'.$image['description'].'</p></div>';    
                }         
                $html .= '</div>';   
                $i++;
            }                         
            $html .= '</div>'.
                     '<a class="carousel-control left" href="#'.$options['id'].'" data-slide="prev">'.
                     '<span class="glyphicon glyphicon-chevron-left"></span>'.
                     '</a>'.
                     '<a class="carousel-control right" href="#'.$options['id'].'" data-slide="next">'.
                     '<span class="glyphicon glyphicon-chevron-right"></span>'.
                     '</a>'.
                     '</div>';

        }  
        
        
        if($type === 'THUMBNAIL') {
            $html .= '<div class="row">';
            
            $i = 0;
            foreach($images as $image) {
                //if($i==0) $class='class="item active"'; else $class='class="item"';
                $src = $options['src_root'].$image['file_name'];
                $html .= '<div class="col-sm-6 col-md-4">'.
                                 '<div class="thumbnail">'.
                                     '<img src="'.$src.'" alt="'.$image['title'].'" class="'.$options['img_class'].'" '.$img_style.'>'.
                                     '<div class="caption">'.
                                         '<h3>'.$image['title'].'</h3>'.
                                         '<p>'.$image['description'].'</p>'.
                                     '</div>'.
                                 '</div>'.
                             '</div>';   
                $i++;
            }                         
            $html .= '</div>';
        }  
        
        
        return $html;
    }
}