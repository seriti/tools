<?php
namespace Seriti\Tools;

//NB: this should not contain any application specific configuration. Application patrameters can be set via constants or set() function
class Config 
{

    protected $config = array();            
      
    public function __construct() 
    {
        //NB: must come first as constants are referenced in setup()
        $this->constants();

        $this->setup();
        
    }
    
    protected function constants()
    {
        define(__NAMESPACE__.'\VERSION','1.0.0');
        
        define(__NAMESPACE__.'\SITE_NAME',SITE_NAME);
        define(__NAMESPACE__.'\DECIMAL_SEPARATOR','.'); 
        define(__NAMESPACE__.'\THOUSAND_SEPARATOR',[',',' ']);
        define(__NAMESPACE__.'\DEBUG',DEBUG); //boolean
        define(__NAMESPACE__.'\AUDIT',AUDIT); //boolean
        define(__NAMESPACE__.'\STORAGE',STORAGE); // 'S3' or 'LOCAL'

        define(__NAMESPACE__.'\CURRENCY_SYMBOL',CURRENCY_SYMBOL);
        
        //NB: system tables have required cols, see TableStructures class
        define(__NAMESPACE__.'\TABLE_SYSTEM',TABLE_SYSTEM);
        define(__NAMESPACE__.'\TABLE_AUDIT',TABLE_AUDIT);
        define(__NAMESPACE__.'\TABLE_FILE',TABLE_FILE);
        define(__NAMESPACE__.'\TABLE_USER',TABLE_USER);
        define(__NAMESPACE__.'\TABLE_ROUTE',TABLE_ROUTE);
        define(__NAMESPACE__.'\TABLE_TOKEN',TABLE_TOKEN);
        define(__NAMESPACE__.'\TABLE_CACHE',TABLE_CACHE);
        define(__NAMESPACE__.'\TABLE_BACKUP',TABLE_BACKUP);
        define(__NAMESPACE__.'\TABLE_QUEUE',TABLE_QUEUE);
        define(__NAMESPACE__.'\TABLE_MENU',TABLE_MENU);
        
        //NB: all paths assumed to include trailing "/"
        define(__NAMESPACE__.'\BASE_PATH',BASE_PATH);
        define(__NAMESPACE__.'\BASE_URL',BASE_URL);
        define(__NAMESPACE__.'\BASE_INCLUDE',BASE_INCLUDE);
        define(__NAMESPACE__.'\BASE_UPLOAD',BASE_UPLOAD);
        define(__NAMESPACE__.'\UPLOAD_DOCS',UPLOAD_DOCS);
        define(__NAMESPACE__.'\UPLOAD_TEMP',UPLOAD_TEMP);
        define(__NAMESPACE__.'\UPLOAD_ROUTE',UPLOAD_ROUTE);
        define(__NAMESPACE__.'\BASE_UPLOAD_WWW',BASE_UPLOAD_WWW);
        define(__NAMESPACE__.'\BASE_TEMPLATE',BASE_TEMPLATE);
        define(__NAMESPACE__.'\AJAX_ROUTE',AJAX_ROUTE);
        define(__NAMESPACE__.'\ENCRYPT_ROUTE',ENCRYPT_ROUTE);

        //no query parameters included just the path/route _LAST is trailing component of path.
        define(__NAMESPACE__.'\URL_CLEAN',URL_CLEAN);
        define(__NAMESPACE__.'\URL_CLEAN_LAST',URL_CLEAN_LAST);
    }

    //Not really necessary but shows expected standard configuration layout even if ->set() elsewhere
    //To check for usage search on "->get('category'" or "->set('category'"
    //Only specify config for Seriti/Tools/ classes 
    protected function setup()
    { 
        //database configuration
        $this->config['db']['charset'] = DB_CHARSET; //'utf8'
        $this->config['db']['host'] = DB_HOST; // 'localhost';
        $this->config['db']['name'] = DB_NAME;
        $this->config['db']['user'] = DB_USER;
        $this->config['db']['password'] = DB_PASSWORD;
        
        //email configuration. default method = 'php' too use mail() or 'smtp' to use PhpMailer class
        $this->config['email']['enabled'] = MAIL_ENABLED;
        $this->config['email']['format'] = MAIL_FORMAT; // 'text' 'html'
        $this->config['email']['footer'] = '';
        $this->config['email']['method'] = MAIL_METHOD; // 'smtp' 'php'
        $this->config['email']['charset'] = MAIL_CHARSET; // 'UTF-8'
        $this->config['email']['from'] = ['address'=>MAIL_FROM,'name'=>SITE_NAME];
        if(defined('MAIL_REPLY')) {
            $this->config['email']['reply'] = ['address'=>MAIL_REPLY,'name'=>SITE_NAME];;
        } else {
            $this->config['email']['reply'] = $this->config['email']['from'];
        }
        $this->config['email']['notify'] = MAIL_WEBMASTER;
        $this->config['email']['host'] = MAIL_HOST;
        $this->config['email']['user'] = MAIL_USER;
        $this->config['email']['password'] = MAIL_PASSWORD;
        $this->config['email']['port'] = MAIL_PORT; //set if default port not valid..ie gmail
        $this->config['email']['secure'] = MAIL_SECURE; //set for 'ssl' or 'tls' if secure smtp connection required

        //user connfiguration
        $this->config['user']['access'] = ['GOD','ADMIN','USER','VIEW']; //NB sequence is IMPORTANT, GOD > ADMIN > USER > VIEW
        $this->config['user']['zone'] = ['ALL','PUBLIC'];
        $this->config['user']['status'] = ['OK','HIDE'];
        $this->config['user']['route_access'] = false;

        //Audit setting *** used ***
        $this->config['audit']['enabled'] = AUDIT;
        $this->config['audit']['table'] = TABLE_AUDIT;
        $this->config['audit']['table_exclude'] = [];

        
        //default cache settings are user specific
        $this->config['cache']['type'] = 'MYSQL';
        $this->config['cache']['table'] = TABLE_CACHE;
        //$this->config['cache']['type'] = 'FILE';
        //$this->config['cache']['dir'] = 'admin/cache/'; //directory must be writeable
        //$this->config['cache']['ext'] = '.cache';
        

        //Amazon S3 settings *** used ***
        $this->config['s3']['region'] = AWS_S3_REGION;
        $this->config['s3']['key'] = AWS_S3_KEY;
        $this->config['s3']['secret'] = AWS_S3_SECRET;
        $this->config['s3']['bucket'] = AWS_S3_BUCKET;
        $this->config['s3']['debug'] = false;
       
        //sms sending
        /*
        $this->config['sms']['isp'] = 'CLICKATELL'; //supports CLICKATELL or PC2SMS
        $this->config['sms']['protocol'] = 'HTTP';
        $this->config['sms']['user'] = CLICKATELL_USER;
        $this->config['sms']['password'] = CLICKATELL_PWD;
        $this->config['sms']['http_base'] = 'https://api.clickatell.com';
        $this->config['sms']['http_method'] = 'CURL';
        $this->config['sms']['http_post'] = false;
        $this->config['sms']['footer']=DISCLAIMER_URL;
        $this->config['sms']['api_id'] = CLICKATELL_API_ID;//CLICKATELL specific
        $this->config['sms']['account'] = '';//PC2SMS specific
        */
    }


    public function get($section,$key = '') 
    {

        if($key === '') {
          if(isset($this->config[$section])) return $this->config[$section];
        } else {
          if(isset($this->config[$section][$key])) return $this->config[$section][$key];     
        }

        return false;
    }

    //only allows overwrite of existing values if $replace = true
    public function set($section,$key,$value,$replace = false) 
    {
        if(!$replace) {
           if(isset($this->config[$section][$key])) {
                $msg = 'You cannot replace an existing seriti config value';
                if(DEBUG) $msg .= 'for section['.$section.'] key['.$key.']';
                die($msg);
                exit;
           }  
        }

        if($section === 'module') {
            if($key === 'system') {
                die('Config [module:system] reserved for internal use!');
                exit;    
            }
            if($value['table_prefix'] === 'sys') {
                die('Config module table prefix[sys] reserved for internal use!');
                exit; 
            }
        }

        $this->config[$section][$key] = $value;
    }

}
