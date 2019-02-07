<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Crypt;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Audit;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\TableStructures;

use Seriti\Tools\BASE_URL;
use Seriti\Tools\BASE_UPLOAD_WWW;

use Psr\Container\ContainerInterface;

class User extends Model 
{

    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use TableStructures;
    
    //current active user data
    protected $user_id = 0;
    protected $data = array();

    protected $container;
    protected $container_allow = ['system','config','mail'];

    protected $mode = '';
    protected $bypass_security = false; //use to gain access if password unknown
    protected $email = '';
    protected $login_logo = 'images/logo.png';
    protected $login_cookie = 'login_token';
        
    //can login from multiple devices with separate tokens
    protected $login_device_id = 'PRIMARY';
    protected $login_devices = array('PRIMARY'=>'Primary device','ALT'=>'Alternative device');
    protected $login_days = array(1=>'for 1 day',2=>'for 2 days',3=>'for 3 days',7=>'for 1 week',
                                  14=>'for 2 weeks',30=>'for 1 month',182=>'for 6 months',365=>'for 1 Year'); 

    protected $routes = array('login'=>'login','logout'=>'login','default'=>'dashboard');

    //cache id's used to maintain state between requests using setCache()/getcache()
    protected $cache = array('user'=>'user_id','reset'=>'password_reset','human'=>'human_id','page'=>'last_url');
    
    //all possible user access settings, NB sequence is IMPORTANT, GOD > ADMIN > USER > VIEW
    protected $access_levels = array('GOD','ADMIN','USER','VIEW');
    protected $access_level = 'NONE';

    public function __construct(DbInterface $db, ContainerInterface $container, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;

        //$this->setup($param);
    }  

    public function setup($param = array()) 
    {
        //Implemented in Model Class
        if(isset($param['encrypt_key'])) $this->encrypt_key = $param['encrypt_key'];
        if(isset($param['read_only'])) $this->access['read_only'] = $param['read_only'];
        if(isset($param['audit'])) $this->access['audit'] = $param['audit'];

        //local setup
        if(isset($param['access_levels']) and is_array($param['access_levels'])) $this->access_levels = $param['access_levels'];
        if(isset($param['route_login'])) $this->routes['login'] = $param['route_login'];
        if(isset($param['route_logout'])) $this->routes['logout'] = $param['route_logout'];
        if(isset($param['route_default'])) $this->routes['default'] = $param['route_default'];
        if(isset($param['bypass_security'])) $this->bypass_security = $param['bypass_security'];

        //add all standard user_cols which MUST exist, NB: required=>false as many partial field updates
        $this->addCol(['id'=>$this->user_cols['id'],'title'=>'User ID','type'=>'INTEGER','key'=>true,'key_auto'=>true,'list'=>true]);
        $this->addCol(['id'=>$this->user_cols['name'],'title'=>'Name','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['email'],'title'=>'Email','type'=>'EMAIL','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['password'],'title'=>'Password','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['pwd_date'],'title'=>'Password date','type'=>'DATE','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['pwd_salt'],'title'=>'Password Salt','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['login_fail'],'title'=>'Login Fail count','type'=>'INTEGER','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['status'],'title'=>'Status','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['email_token'],'title'=>'Email token','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['login_token'],'title'=>'Login PRIMARY token','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['login_expire'],'title'=>'Login PRIMARY expire','type'=>'DATE','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['login_alt_token'],'title'=>'Login ALT token','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['login_alt_expire'],'title'=>'Login ALT expire','type'=>'DATE','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['csrf_token'],'title'=>'CSRF token','type'=>'STRING','required'=>false]);
    }  
    
    //default function to handle all processing and views
    public function processLogin() 
    {
        $html = '';
        
        //get mode that needs to be processed   
        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        $form = $_POST;

        //redirect to default page if user accidentally went to login page
        if($this->mode === '' and $this->getCache($this->cache['user']) !== '' ) {
            //die('WTF');
            header('location: '.$this->routes['default']);
            exit;
        }  

        if($this->mode === 'logout') $this->manageUserAction('LOGOUT');
        if($this->mode === 'reset_pwd') $this->resetPassword($_GET['token']);
        if($this->mode === 'reset_login') $this->resetLogin($_GET['token']);
        if($this->mode === 'reset_send') $this->resetSend($form);
        if($this->mode === 'login') $this->manageLogin('LOGIN',$form);
        
        $html = $this->viewLogin();
        return $html;
    }
    
    public function getData()
    {
        return $this->data;
    }

    public function getId()
    {
        return $this->user_id;
    }

    public function getCsrfToken()
    {
        return $this->data[$this->user_cols['csrf_token']];
    }

    public function getAccessLevel()
    {
        return $this->access_level;
    }

    public function getAccessLevels()
    {
        return $this->access_levels;
    }

    public function viewLogin($email = '') 
    {
        //check to stop dictionary/csrf/bot attack
        $human = $this->getCache($this->cache['human']);
        if($human == '') {
            $human = Crypt::makeToken();
            $this->setCache($this->cache['human'],$human);
        }
        
        $reset = $this->getCache($this->cache['reset']);
        if($reset !== '') $reset_send = true; else $reset_send = false;

        $system = $this->getContainer('system');
        $logo = $system->getDefault('LOGIN_IMAGE','NONE');
        if($logo !== '') $this->login_logo = BASE_URL.BASE_UPLOAD_WWW.$logo;

        $view['logo'] = $this->login_logo;
        $view['human_input_name'] = $this->cache['human'];
        $view['human_input_value'] = $human;
        $view['messages'] = $this->viewMessages();
        $view['reset_send'] = $reset_send;
        $view['email'] = $this->email;
        
        $view['devices'] = $this->login_devices;
        $view['device_id'] = $this->login_device_id;
        $view['days'] = $this->login_days;
        $view['days_expire'] = 30;
    
        return $view;
    }  
    
    public function manageLogin($action = 'LOGIN', $form = []) 
    {

        $error = '';

        $email = $form['email'];
        //for redisplay in viewLogin()
        $this->email = $email;
        
        $password = $form['password'];
        $human = $form[$this->cache['human']];
        $human_cache = $this->getCache($this->cache['human']);

        $device = Secure::clean('alpha',$form['login_device']);
        if($device !== 'PRIMARY' and $device !== 'ALT') $device = 'PRIMARY';

        //first check if password reset requested ********************************************
        $reset = $this->getCache($this->cache['reset']);
        if(is_array($reset)) {
            $user = $this->getUser('EMAIL',$reset[$this->user_cols['email']]);
            if($user == 0) {
                $this->addError('Unrecognised user email['.$reset[$this->user_cols['email']].'] for password reset');
            } else { 
                //for template display
                $this->email = $user[$this->user_cols['email']]; 
            }    
            
            $rules = [];
            Validate::securePassword($password,$rules,$error);
            if($error != '') $this->addError('Insecure password: '.$error);
                 
            $password_repeat = $form['password_repeat'];  
            if($password_repeat !== $password) $this->addError('Password repeat does NOT match!');
            
            if(!$this->errors_found) {
                //create new salt value rather than use old
                $salt = Crypt::makeSalt();
                $password_hash = Crypt::passwordHash($password,$salt);
                
                $user_id = $user[$this->user_cols['id']];
                $data = array($this->user_cols['email_token']=>'',$this->user_cols['csrf_token']=>'',
                              $this->user_cols['pwd_salt']=>$salt,$this->user_cols['password']=>$password_hash);
                $result = $this->update($user_id,$data);
                if($result['status'] !== 'OK') {
                    $error = 'USER_AUTH_ERROR: could not reset password';
                    if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                    throw new Exception($error);
                } else {
                    $email = $reset[$this->user_cols['email']];
                    $this->setCache($this->cache['reset'],'');
                    
                    $description = 'Email['.$email.'] login password RESET';
                    Audit::action($this->db,$user_id,'LOGIN_RESET',$description);   
                }
            }
        } else { 
            Validate::email('Email address',$email,$error);
            if($error !== '') $this->addError($error);
            Validate::string('Password',1,64,$password,$error);
            if($error !== '') $this->addError($error);
        } 


            
        if(!$this->errors_found and $human !== $human_cache) {
            $this->addError('Are you human? Please enable cookies!');
        }

        if(!$this->errors_found) {
            $user = $this->getUser('EMAIL',$email);
            if($user == 0) {
                $error = 'Invalid email or password!';
                if($this->debug) $error .= ' Email['.$email.'] not recognised.';
                $this->addError($error);
            } else { 
                $user_id = $user[$this->user_cols['id']];
                $salt = $user[$this->user_cols['pwd_salt']];
                $password_db = $user[$this->user_cols['password']];
                $password_check = Crypt::passwordHash($password,$salt);
                if($password_check === $password_db or $this->bypass_security) {
                    //to prevent session "fixation"
                    session_regenerate_id();
                    
                    if(isset($form['remember_me'])) $remember_me = true; else $remember_me = false;
                    $days_expire = Secure::clean('integer',$form['days_expire']);

                    $this->manageUserAction('LOGIN',$user,$device,$remember_me,$days_expire);
                } else {
                    $description = 'Email['.$email.'] pwd[****'.substr($password,6).'] login FAILED';
                    Audit::action($this->db,$user_id,'LOGIN_FAIL',$description);

                    $this->addError('Invalid Email or password!');
                    if($this->debug) {
                        $this->addError('password['.$password.'] salt['.$salt.'] & hash['.$password_check.'] stored hash['.$password.']');
                    }    
                    sleep(3);
                }
            }
        }
        
        //slow things down for dictionary attack 
        if($this->errors_found) sleep(3);
    }  
    
    public function manageUserAction($action = 'LOGIN',$user = array(),$device = 'PRIMARY',$remember_me = false,$expire_days = 0) {
        if($action === 'LOGIN' or $action === 'AUTO_LOGIN') {
            if(count($user) == 0) {
                throw new Exception('USER_AUTH_ERROR: Invalid user data');
            } else { 
                $this->user_id = $user[$this->user_cols['id']];
                $this->setCache($this->cache['user'],$this->user_id);
                $this->db->setAuditUserId($this->user_id);
                $this->data = $user;
            } 

            $update = [];
            //NB any form csrf check will use $this->data above BEFORE this update is applied
            $update[$this->user_cols['csrf_token']] = Crypt::makeToken();

            //if AUTO_LOGIN then remember_me checkbox must have been checked previously
            if($action === 'AUTO_LOGIN' or ($action === 'LOGIN' and $remember_me)) {
                //replace existing login token or create new token
                $login_token = Crypt::makeToken();

                if($action === 'LOGIN') {
                    if($expire_days < 1) $expire_days = 1;
                    $date = getdate();
                    $date_expire = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$expire_days,$date['year']));

                    if($device === 'ALT') {
                        $update[$this->user_cols['login_alt_token']] = $login_token;
                        $update[$this->user_cols['login_alt_expire']] = $date_expire;
                    } else {
                        $update[$this->user_cols['login_token']] = $login_token;
                        $update[$this->user_cols['login_expire']] = $date_expire;
                    }   
                } 

                if($action === 'AUTO_LOGIN') {
                    if($device === 'ALT') {
                        $update[$this->user_cols['login_alt_token']] = $login_token;
                        $date_expire = $user[$this->user_cols['login_alt_expire']];
                    } else {
                        $update[$this->user_cols['login_token']] = $login_token;
                        $date_expire = $user[$this->user_cols['login_expire']];
                    }

                    $expire_days = Date::calcDays(date('Y-m-d'),$date_expire); 
                }
   
                Form::setCookie($this->login_cookie,$login_token,$expire_days);
            }

            $result = $this->update($this->user_id,$update);
            if($result['status'] !== 'OK') {
                $error = 'USER_AUTH_ERROR: could not update user login token';
                if($this->debug) $error .= ' User ID['.$this->user_id.']: '.implode(',',$result['errors']);
                throw new Exception($error);
            } 

            if($action === 'LOGIN') {
                $description = 'Email['.$user[$this->user_cols['email']].'] login SUCCESS';
                Audit::action($this->db,$this->user_id,'LOGIN',$description);
                //redirect to page last visited or default
                $this->redirectLastPage();
            }    
        }
        
        if($action === 'LOGOUT') {
            //erase all cache values
            $_SESSION = [];
            //removes auto login cookie by making days negative(-1)
            Form::setCookie($this->login_cookie,'',-1); 
        }  
    }

    //email user link with token so can reset password of their choice
    public function resetSend($form = []) {
        $error = '';
        //die(var_dump($form)); exit;

        $reset_type = Secure::clean('alpha',$form['reset_type']);
        if($reset_type !== 'PASSWORD' and $reset_type !== 'LOGIN') {
           $this->addError('Invalid reset type requested!'); 
        }
        
        $email = $form['email'];
        Validate::email('Reset email address',$email,$error);
        if($error != '') $this->addError($error);
                
        if(!$this->errors_found) {
            $user = $this->getUser('EMAIL',$email);
            if($user == 0) {
                sleep(3);
                $this->addError('Sorry, but your entered email does not exist in system!');
            } else {
                $date = getdate();  
                $user_id = $user[$this->user_cols['id']];
                $this->db->setAuditUserId($user_id);

                if($reset_type === 'PASSWORD') {
                    $email_token = Crypt::makeToken();
                    $days_expire = 1;
                    $date_str = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$days_expire,$date['year']));
                    $data = array($this->user_cols['email_token']=>$email_token,$this->user_cols['login_expire']=>$date_str);

                    $result = $this->update($user_id,$data);
                    if($result['status'] !== 'OK') {
                        $error = 'USER_AUTH_ERROR: Could not update user email token!';
                        if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                        throw new Exception($error);
                    } 
                    
                    $from = MAIL_FROM;
                    $subject = SITE_NAME.' - password RESET';
                    $body = 'You requested a password reset by email.'."\r\n".
                            'Please click on the link below to reset your password....'."\r\n".
                            BASE_URL.$this->routes['login'].'?mode=reset_pwd&token='.urlencode($email_token);

                    $info = 'Please check your email and click on the link in the email to RESET your password.';
                } 

                if($reset_type === 'LOGIN') {
                    $device = Secure::clean('alpha',$form['login_device']);
                    if(!isset($this->login_devices[$device])) $device = 'PRIMARY';
                    if($device === 'PRIMARY') $device_name = 'PRIMARY'; else $device_name = 'ALTERNATIVE';
                    
                    $login_token = Crypt::makeToken();
                    $days_expire = Secure::clean('integer',$form['days_expire']);
                    $date_str = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$days_expire,$date['year']));
                    if($device === 'PRIMARY') {
                        $data = [$this->user_cols['login_token']=>$login_token,$this->user_cols['login_expire']=>$date_str];
                    } else {
                        $data = [$this->user_cols['login_alt_token']=>$login_token,$this->user_cols['login_alt_expire']=>$date_str];
                    }   

                    $result = $this->update($user_id,$data);
                    if($result['status'] !== 'OK') {
                        $error = 'USER_AUTH_ERROR: Could not update user login token!';
                        if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                        throw new Exception($error);
                    } 
                    
                    $from = MAIL_FROM;
                    $subject = SITE_NAME.' - login RESET';
                    $body = 'You requested a login reset by email for your '.$device_name.' device'."\r\n".
                            'Please click on the link below to login from this device....'."\r\n".
                            'This login will expire on: '.$date_str.' or earlier if you reset cookies for site.'."\r\n".
                            BASE_URL.$this->routes['login'].'?mode=reset_login&token='.urlencode($login_token);

                    $info = 'Please check your email and click on the link in the email to LOGIN for '.$days_expire.' days<br/>'.
                            'This login is for your '.$device_name.' device.';
                }   

                $mailer = $this->getContainer('mail');
                if($mailer->sendEmail($from,$email,$subject,$body,$error)) {
                    $this->addMessage('SUCCESS sending '.$reset_type.' reset to['.$email.'] '); 
                    $this->addMessage($info);
                } else {
                    $this->addError('FAILURE emailing '.$reset_type.' reset to['.$email.']: Please try again later or contact support['.$from.']'); 
                }    
            } 
        }
    }  
    
    public function resetLogin($token) {
        $error = '';
        $token = Secure::clean('alpha',$token);
        if($token !== '') {
            $user = $this->getUser('LOGIN_TOKEN',$token);
            if($user == 0) {
                $error = 'Your login token is not recognised! ';
                if($this->debug) $error .= $token;
                $this->addError($error);
            } else {    
                if($user[$this->user_cols['login_alt_token']] === $token) {
                    $device = 'ALT';
                    $date_expire = $user[$this->user_cols['login_alt_expire']];
                } else {
                    $device = 'PRIMARY';
                    $date_expire = $user[$this->user_cols['login_expire']];  
                }    

                $expire_days = Date::calcDays(date('Y-m-d'),$date_expire);
                Form::setCookie($this->login_cookie,$token,$expire_days);

                $this->manageUserAction('LOGIN',$user,$device);
            }
        }    
    }

    public function resetPassword($email_token = '') {
        $error = '';
        
        if($email_token === '') {
            $this->addError('No valid reset token in your email link! Please contact support.');
        } else {    
            Validate::string('Password reset token',1,64,$email_token,$error);
            if($error != '') $this->addError($error);
        }  
                
        if(!$this->errors_found) {
            $user = $this->getUser('EMAIL_TOKEN',$email_token);
            if($user == 0) {
                sleep(3);
                $this->addError('The reset token in your email link does not match or has expired! Please contact support, or resend reset.');
            } else {  
                $this->setCache($this->cache['reset'],$user);
                $this->email = $user[$this->user_cols['email']];
                $this->addMessage('Your reset token is valid! Please reset your password below...<br/>'.
                                  'NB: password must be at least 8 alphanumeric characters with '.
                                  'at least one lowercase, uppercase, numeric character');
            } 
        }
    }
    
    
    protected function getUser($type,$value)
    {
        if($type === 'ID') {
            $user = $this->get($value);
        }    
         
        if($type === 'EMAIL_TOKEN') {
            $sql = 'SELECT * FROM '.$this->table.' '.
                   'WHERE '.$this->user_cols['email_token'].' = "'.$this->db->escapeSql($value).'" AND '.
                            $this->user_cols['login_expire'].' >= CURDATE() ';
            $user = $this->db->readSqlRecord($sql);
        }    

        if($type === 'LOGIN_TOKEN') {
            $sql = 'SELECT * FROM '.$this->table.' '.
                   'WHERE ('.$this->user_cols['login_token'].' = "'.$this->db->escapeSql($value).'" AND '.
                             $this->user_cols['login_expire'].' > CURDATE()) OR '.
                         '('.$this->user_cols['login_alt_token'].' = "'.$this->db->escapeSql($value).'" AND '.
                             $this->user_cols['login_alt_expire'].' > CURDATE()) '.
                   'LIMIT 1 ';
            $user = $this->db->readSqlRecord($sql);
        }

        if($type === 'EMAIL') {
            $where = array($this->user_cols['email']=>$value);
            $user = $this->db->getRecord($this->table,$where);

        }

        return $user;
    }

    //store last location/page/uri user vists before a redirect 
    public function setLastPage()
    {
        $this->setCache($this->cache['page'],$_SERVER['REQUEST_URI']);
    }

    public function redirectLastPage()
    {
        $last_page = $this->getCache($this->cache['page']);
        if($last_page !== ''){
            header('location: '.BASE_URL.Secure::clean('header',$last_page));
        } else {
            header('location: '.BASE_URL.$this->routes['default']);
        } 
        exit;
    }

    //validate access, autologin if possible, redirect to login if invalid
    public function checkAccessRights(&$route){
        $error = '';
  
        $this->user_id = $this->getCache($this->cache['user']);    
        if($this->user_id !== '') {
            $this->data = $this->get($this->user_id);
            $level = $this->data[$this->user_cols['access']];
        } else {  
            $level = 'NONE';
            //check for auto login cookie and re-login if valid
            $login_token = Form::getCookie($this->login_cookie);
            if($login_token !== ''){
                $user = $this->getUser('LOGIN_TOKEN',$login_token);
                if($user != 0) {
                    $device = 'PRIMARY';
                    if($user[$this->user_cols['login_alt_token']] === $login_token) $device = 'ALT';
                    $level = $user[$this->user_cols['access']];

                    $this->manageUserAction('AUTO_LOGIN',$user,$device);
                } 
            } 
                        
            if($level === 'NONE') {
                $this->setLastPage();
                $route = $this->routes['login'];
                return false;
            } 
        }
        

        //assign current user access level
        $this->access_level = $level;
        if(array_search($level,$this->access_levels) === false) {
            if($level != '') {
                $error = 'INVALID access level:'.$level;
                if($this->debug) $error .= ' allowed: '.var_export($this->access_levels,true);
                throw new Exception('USER_AUTH_ERROR: '.$error);
            } else {  
                $route = $this->routes['login'];
                return false;
            }  
        } else {
            $access = true;

            if($level === 'GOD') {
                $this->access['read_only'] = false;
                $this->access['edit']      = true;
                $this->access['view']      = true;
                $this->access['delete']    = true;
                $this->access['add']       = true;
                $this->access['search']    = true;
                $this->access['email']     = true;
            }

            if($level === 'ADMIN') {
                $this->access['read_only'] = false;
                $this->access['edit']      = true;
                $this->access['view']      = true;
                $this->access['delete']    = true;
                $this->access['add']       = true;
                $this->access['search']    = true;
            }
            
            if($level === 'USER') {
                $this->access['read_only'] = false;
                $this->access['edit']      = true;
                $this->access['view']      = true;
                $this->access['delete']    = false;
                $this->access['add']       = true;
                $this->access['search']    = true;
            }
            
            if($level === 'VIEW') {
                $this->access['read_only'] = true;
                $this->access['view']      = true;
                $this->access['search']    = true;
            } 
            
            //add a placeholder public function here for custom access
            //$this->modifyAccess();
            
            return true;
            //return $this->access;
        }  
        
    }
    
    public function checkUserAccess($level_required) {
        $access = false;
        
        //NB: $this->access_levels sequence is from highest to lowest access
        $user = array_search($this->access_level,$this->access_levels);
        $required = array_search($level_required,$this->access_levels);
        if($user !== false and $user <= $required) $access = true;
        
        return $access;     
    }
    

    // *** HELPER FUNCTIONS FOR ANY USER, NOT JUST CURRENT LOGGED IN USER ****

    //generate a new password and email to ANY user
    public function resetSendPassword($user_id) {
        $error = '';
        $error_tmp ='';
       
        $user = $this->get($user_id);
        if($user == 0) $this->addError('Invalid user id['.$user_id.']'); 

        if(!$this->errors_found) {
            $password = Form::createPassword();
            $salt = Crypt::makeSalt();
            $password_hash = Crypt::passwordHash($password,$salt);
            
            $data = array($this->user_cols['password']=>$password_hash,$this->user_cols['pwd_salt']=>$salt);
            $result = $this->update($user_id,$data);
            if($result['status'] !== 'OK') {
                $error = 'USER_AUTH_ERROR: could not reset password';
                if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                throw new Exception($error);
            } else {
                $email = $user[$this->user_cols['email']];
                $audit_str = 'User['.$user_id.'] Email['.$email.'] login password RESET';
                Audit::action($this->db,$this->user_id,'USER_PASSWORD_RESET',$audit_str);
                
                $from = ''; //default config email from used
                $subject = SITE_NAME.' user password reset';
                $body = SITE_NAME.' Password reset for:  '.$user[$this->user_cols['name']]."\r\n\r\n".
                        'url: '.BASE_URL.$this->routes['login']."\r\n".
                        'email: '.$email."\r\n".
                        'password: '.$password."\r\n";
             
                $mailer = $this->getContainer('mail');
                if(!$mailer->sendEmail($from,$email,$subject,$body,$error_tmp)) {
                    $error = 'FAILURE emailing user['.$user_id.'] password reset to address['.$email.']'; 
                    if($this->debug) $error .= $error_tmp;
                    $this->addError($error);
                }
            }  
        } 

        if(!$this->errors_found) return true; else return false; 
    }

    //generate a new Login token and email to ANY user
    public function resetSendLogin($user_id,$device = 'PRIMARY',$expire_days = 0) {
        $error = '';
       
        $user = $this->get($user_id);
        if($user == 0) $this->addError('Invalid user id['.$user_id.']'); 

        if(!$this->errors_found) {
            $login_token = Crypt::makeToken();
                
            if($expire_days < 1) $expire_days = 1;
            $date = getdate();
            $date_expire = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$expire_days,$date['year']));

            $data = [];
            if($device === 'ALT') {
                $data[$this->user_cols['login_alt_token']] = $login_token;
                $data[$this->user_cols['login_alt_expire']] = $date_expire;
            } else {
                $data[$this->user_cols['login_token']] = $login_token;
                $data[$this->user_cols['login_expire']] = $date_expire;
            }   

            $result = $this->update($user_id,$data);
            if($result['status'] !== 'OK') {
                $error = 'USER_AUTH_ERROR: could not reset login token';
                if($this->debug) $error .= ' User ID['.$user_id.'] device['.$device.']: '.implode(',',$result['errors']);
                throw new Exception($error);
            } else {
                $email = $user[$this->user_cols['email']];
                $audit_str = 'User['.$user_id.'] Email['.$email.'] device['.$device.'] expiry['.$date_expire.'] login token RESET';
                Audit::action($this->db,$this->user_id,'USER_TOKEN_RESET',$audit_str);
                
                $from = ''; //default config email from used
                $subject = SITE_NAME.' user login token reset';
                $body = 'Please click on the link below to login from this device....'."\r\n".
                        'This login will expire on: '.$date_expire.' or earlier if you reset cookies for site.'."\r\n".
                        BASE_URL.$this->routes['login'].'?mode=reset_login&token='.urlencode($login_token);
                
                $mailer = $this->getContainer('mail');
                if(!$mailer->sendEmail($from,$email,$subject,$body,$error_tmp)) {
                    $error .= 'FAILURE emailing user['.$user_id.'] password reset to address['.$email.']'; 
                    if($this->debug) $error .= $error_tmp;
                    $this->addError($error);
                }
            }  
        } 

        if(!$this->errors_found) return true; else return false; 
    }
    
}
