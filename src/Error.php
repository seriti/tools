<?php 
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\BASE_URL;
use Seriti\Tools\Secure;

class Error 
{
    public static function fatalError($route,$title,$message){
        $error = [];
        $error['title'] = $title;
        $error['message'] = $message;

        $_SESSION['seriti_error'] = $error;
        
        $location = BASE_URL.Secure::clean('header',$route);
        header('location: '.$location);
        exit;
    }
}


?>
