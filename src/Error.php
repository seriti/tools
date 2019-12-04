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

    public static function renderExceptionOrError($exception,$format = 'log')
    {
        if (!$exception instanceof Exception && !$exception instanceof \Error) {
            throw new RuntimeException("Unexpected type. Expected Exception or Error.");
        }

        $output = '';
  
        if($format === 'log') {
            $output .= get_class($exception);
            if(($code = $exception->getCode())) $output .= ', '.$code;
            if(($message = $exception->getMessage())) $output .= ', '.$message;
            if(($file = $exception->getFile())) $output .= ', '.$file;
            if(($line = $exception->getLine())) $output .= ', ln'.$line;

        } 

        if($format === 'html') {
            $output = sprintf('<div><strong>Type:</strong> %s</div>', get_class($exception));

            if (($code = $exception->getCode())) {
                $output .= sprintf('<div><strong>Code:</strong> %s</div>', $code);
            }

            if (($message = $exception->getMessage())) {
                $output .= sprintf('<div><strong>Message:</strong> %s</div>', htmlentities($message));
            }

            if (($file = $exception->getFile())) {
                $output .= sprintf('<div><strong>File:</strong> %s</div>', $file);
            }

            if (($line = $exception->getLine())) {
                $output .= sprintf('<div><strong>Line:</strong> %s</div>', $line);
            }

            if (($trace = $exception->getTraceAsString())) {
                $output .= '<h2>Trace</h2>';
                $output .= sprintf('<pre>%s</pre>',htmlentities($trace));
            }

            $output = $output;
        }    


        return $output;
    }
}