<?php
namespace Seriti\Tools;

//NB: this should not contain any application specific configuration. Application patrameters can be set via constants or set() function
class Config 
{

    protected $config = array();            
      
    public function __construct() 
    {
        //NB: must come first as constants are referenced in other functions
        $this->constants();

        $this->setup();
        
    }
    
    protected function constants()
    {
        define(__NAMESPACE__.'\SITE_NAME',SITE_NAME);
        define(__NAMESPACE__.'\DECIMAL_SEPARATOR','.'); 
        define(__NAMESPACE__.'\THOUSAND_SEPARATOR',[',',' ']);
        define(__NAMESPACE__.'\DEBUG',DEBUG);
        define(__NAMESPACE__.'\AUDIT',AUDIT);
        define(__NAMESPACE__.'\STORAGE',STORAGE);

        define(__NAMESPACE__.'\CURRENCY_SYMBOL',CURRENCY_SYMBOL);
        
        //system tables have required cols, see TableStructures class
        define(__NAMESPACE__.'\TABLE_SYSTEM',TABLE_SYSTEM);
        define(__NAMESPACE__.'\TABLE_AUDIT',TABLE_AUDIT);
        define(__NAMESPACE__.'\TABLE_FILE',TABLE_FILE);
        define(__NAMESPACE__.'\TABLE_USER',TABLE_USER);
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
        define(__NAMESPACE__.'\BASE_UPLOAD_WWW',BASE_UPLOAD_WWW);
        define(__NAMESPACE__.'\BASE_TEMPLATE',BASE_TEMPLATE);

        //no query parameters included just the path/route _LAST is trailing component of path.
        define(__NAMESPACE__.'\URL_CLEAN',URL_CLEAN);
        define(__NAMESPACE__.'\URL_CLEAN_LAST',URL_CLEAN_LAST);
    }

    protected function setup()
    { 
        $this->config['site']['title'] = SITE_NAME;
        //$this->config['site']['http_root']='urlroot';
        $this->config['site']['logo'] = 'images/sunflower64.png';
        $this->config['site']['theme'] = 'DEFAULT';

        //default page settings relative to ['http_root'] if value *.php
        $this->config['page']['secure'] = 'secure.php';
        $this->config['page']['home'] = 'index.php';

        //login settings
        $this->config['login']['page'] = 'index.php';
        $this->config['login']['template'] = 'login_tmp.php';
        $this->config['login']['redirect'] = 'user_admin.php';

        //database configuration
        $this->config['db']['charset'] = 'utf8';
        $this->config['db']['host'] = '127.0.0.1';
        $this->config['db']['name'] = DB_NAME;
        $this->config['db']['user'] = DB_USER;
        $this->config['db']['password'] = DB_PASSWORD;
        $this->config['db']['encrypt_key'] = 'encryptkey'; //not used curently
        $this->config['db']['encrypt_salt'] = 'encryptpassowrd';
        

        //email configuration. default method = 'php' too use mail() or 'smtp' to use PhpMailer class
        
        $this->config['email']['enabled'] = MAIL_ENABLED;
        $this->config['email']['format'] = 'text';
        $this->config['email']['footer'] = '';
        $this->config['email']['method'] = 'smtp'; 
        $this->config['email']['charset'] = 'UTF-8';
        $this->config['email']['from'] = ['address'=>MAIL_FROM,'name'=>SITE_NAME];
        $this->config['email']['reply'] = MAIL_FROM;
        $this->config['email']['notify'] = MAIL_WEBMASTER;
        $this->config['email']['host'] = MAIL_HOST;
        $this->config['email']['user'] = MAIL_USER;
        $this->config['email']['password'] = MAIL_PASSWORD;
        $this->config['email']['port'] = MAIL_PORT; //set if default port not valid..ie gmail
        $this->config['email']['secure'] = MAIL_SECURE; //set for 'ssl' or 'tls' if secure smtp connection required

        //user details
        $this->config['user']['email_from'] = MAIL_FROM;
        //all possible user access settings, NB sequence is IMPORTANT, GOD > ADMIN > USER > VIEW
        $this->config['user']['access'] = array('GOD','ADMIN','USER','VIEW');
        $this->config['user']['status'] = array('OK','HIDE');
        $this->config['user']['routes'] = array('login'=>'login','logout'=>'login?mode=logout','default'=>'dashboard');
        $this->config['user']['default_route'] = array('GOD'=>'admin/user','ADMIN'=>'admin/articles','USER'=>'admin/articles','VIEW'=>'admin/articles');

        
        //Audit setting
        $this->config['audit']['enabled'] = AUDIT;
        $this->config['audit']['table'] = TABLE_AUDIT;
        $this->config['audit']['table_exclude'] = [];

        //default cache settings are user specific
        $this->config['cache']['type'] = 'MYSQL';
        $this->config['cache']['dir'] = 'admin/cache/'; //directory must be writeable
        $this->config['cache']['ext'] = '.cache';
        $this->config['cache']['table'] = 'cache';

        //Amazon S3 settings
        $this->config['s3']['region'] = AWS_S3_REGION;
        $this->config['s3']['key'] = AWS_S3_KEY;
        $this->config['s3']['secret'] = AWS_S3_SECRET;
        $this->config['s3']['bucket'] = AWS_S3_BUCKET;
        $this->config['s3']['debug'] = false;
        

        //template settings  
        $this->config['templates']['dir'] = 'templates/';

        //error handling settings
        $this->config['errors']['handle'] = false;
        /*
        $this->config['errors']['redirect']=true;
        $this->config['errors']['page']=''; //BASE_URL.'admin/error';
        $this->config['errors']['email']=MAIL_ENABLED;
        $this->config['errors']['email_subject']=SITE_TITLE.'***ERROR***';
        $this->config['errors']['email_from']=MAIL_FROM;
        $this->config['errors']['email_to']=MAIL_WEBMASTER;
        $this->config['errors']['template']='error_tmp.php';
        $this->config['errors']['audit']=true;
        */

        //sms capabilities
        $this->config['sms']['isp'] = 'CLICKATELL'; //supports CLICKATELL or PC2SMS
        $this->config['sms']['protocol'] = 'HTTP';
        $this->config['sms']['user'] = CLICKATELL_USER;
        $this->config['sms']['password'] = CLICKATELL_PWD;
        $this->config['sms']['http_base'] = 'https://api.clickatell.com';
        $this->config['sms']['http_method'] = 'CURL';
        $this->config['sms']['http_post'] = false;
        //$this->config['sms']['footer']=DISCLAIMER_URL;
        $this->config['sms']['api_id'] = CLICKATELL_API_ID;//CLICKATELL specific
        $this->config['sms']['account'] = '';//PC2SMS specific
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

    public function set($section,$key,$value) 
    {
        $this->config[$section][$key] = $value;
    }

}


    
?>
