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
use Seriti\Tools\Error;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\TableStructures;

use Seriti\Tools\BASE_URL;
use Seriti\Tools\URL_CLEAN;
use Seriti\Tools\BASE_UPLOAD_WWW;
use Seriti\Tools\TABLE_TOKEN;
use Seriti\Tools\TABLE_ROUTE;

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
    protected $data = [];

    protected $container;
    protected $container_allow = ['system','config','mail'];

    protected $mode = '';
    protected $bypass_security = false; //use to gain access if password unknown
    protected $email = '';
    protected $login_logo = 'images/logo.png';
    protected $login_cookie = 'login_token';
    protected $temp_cookie = 'temp_token';
    protected $cookie_expire_days = 30;
    protected $login_fail_max = 10;
        
    protected $login_days = [1=>'for 1 day',2=>'for 2 days',3=>'for 3 days',7=>'for 1 week',
                             14=>'for 2 weeks',30=>'for 1 month',182=>'for 6 months',365=>'for 1 Year']; 

    //if true will check USER_ROUTE table for route whitelist access 
    protected $route_access = false;
    protected $routes = ['login'=>'login','logout'=>'login','default'=>'admin/user/dashboard','error'=>'error'];
    //'ANY' value will ignore any occurrence of route key; 'EXACT' value will only ignore exact match of route key
    protected $routes_redirect_ignore = ['ajax'=>'ANY','login'=>'EXACT','admin/data/encrypt'=>'EXACT'];

    //cache id's used to maintain state between requests using setCache()/getcache()
    protected $cache = ['user'=>'user_id','reset'=>'password_reset','human'=>'human_id','page'=>'last_url'];
    
    //all possible user access settings, NB sequence is IMPORTANT, GOD > ADMIN > USER > VIEW > PUBLIC
    protected $access_levels = ['GOD','ADMIN','USER','VIEW'];
    protected $access_level = 'NONE';
    protected $access_zone = 'NONE';

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

        //local setup
        if(isset($param['access_levels']) and is_array($param['access_levels'])) $this->access_levels = $param['access_levels'];
        if(isset($param['route_error'])) $this->routes['error'] = $param['route_error'];
        if(isset($param['route_login'])) $this->routes['login'] = $param['route_login'];
        if(isset($param['route_logout'])) $this->routes['logout'] = $param['route_logout'];
        if(isset($param['route_default'])) $this->routes['default'] = $param['route_default'];
        if(isset($param['route_access'])) $this->route_access = $param['route_access'];
        if(isset($param['bypass_security'])) $this->bypass_security = $param['bypass_security'];

        //add all standard user_cols which MUST exist, NB: required=>false as many partial field updates
        $this->addCol(['id'=>$this->user_cols['id'],'title'=>'User ID','type'=>'INTEGER','key'=>true,'key_auto'=>true,'list'=>true]);
        $this->addCol(['id'=>$this->user_cols['name'],'title'=>'Name','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['email'],'title'=>'Email','type'=>'EMAIL','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['access'],'title'=>'Access','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['zone'],'title'=>'Zone','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['password'],'title'=>'Password','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['pwd_date'],'title'=>'Password date','type'=>'DATE','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['pwd_salt'],'title'=>'Password Salt','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['login_fail'],'title'=>'Login Fail count','type'=>'INTEGER','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['status'],'title'=>'Status','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['email_token'],'title'=>'Email token','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['email_token_expire'],'title'=>'Login PRIMARY expire','type'=>'DATE','required'=>false]);
        $this->addCol(['id'=>$this->user_cols['csrf_token'],'title'=>'CSRF token','type'=>'STRING','required'=>false]);

        //NB: make users with status = HIDE invisible
        $this->addSql('WHERE',$this->user_cols['status'].' <> "HIDE" ');
    }  
    
    public function createUser($name,$email,$password,$access,$zone,$status,&$error) 
    {
        $error = '';
        $error_tmp = '';
    
        Validate::string('Name: ',1,250,$name,$error_tmp);
        if($error_tmp !== '') $this->addError($error_tmp);

        $rules = [];
        Validate::securePassword($password,$rules,$error_tmp);
        if($error_tmp !== '') $this->addError('Insecure password: '.$error_tmp);

        Validate::email('Email address',$email,$error_tmp);
        if($error_tmp !== '') $this->addError($error_tmp);

        if(array_search($access,$this->access_levels) === false) {
            $error_tmp = 'INVALID access level!';
            $this->addError($error_tmp);
        } 

        Validate::string('Zone: ',1,64,$zone,$error_tmp);
        if($error_tmp !== '') $this->addError($error_tmp);   

        Validate::string('Status: ',1,64,$status,$error_tmp);
        if($error_tmp !== '') $this->addError($error_tmp);

        if(!$this->errors_found) {
            $salt = Crypt::makeSalt();
            $password_hash = Crypt::passwordHash($password,$salt);

            $data[$this->user_cols['name']] = $name;
            $data[$this->user_cols['email']] = $email;
            $data[$this->user_cols['password']] = $password_hash;
            $data[$this->user_cols['pwd_salt']] = $salt;
            $data[$this->user_cols['pwd_date']] = date('Y-m-d');
            $data[$this->user_cols['access']] = $access;
            $data[$this->user_cols['zone']] = $zone;
            $data[$this->user_cols['status']] = $status;
            $this->create($data);
        }

        if($this->errors_found) {
            $error = implode('. ',$this->errors);
        } else {
            return true;     
        }    
    }

    //default function to handle all processing and views
    public function processLogin() 
    {
        $html = '';
                
        //get mode that needs to be processed   
        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        $form = array_map('trim',$_POST);
        
        //redirect to default page if user accidentally went to login page
        //NB: if user tries to access a route that thay do not have access to then this can become an infinite loop
        if($this->mode === '') {
            
            //NB: Will check if user logged in and setup data only. Required as checkAccessRights() not called here.
            $this->setupUserData();            
            if($this->user_id != 0) {
                $this->addMessage('You are already logged in! You can login as another user or '.$this->js_links['back']);

                if($this->debug) {
                    $name = $this->data[$this->user_cols['name']];
                    $level = $this->data[$this->user_cols['access']];
                    $zone = $this->data[$this->user_cols['zone']];
                    $this->addMessage("Name[$name] level[$level] zone[$zone]");
                }

                $_SESSION['login_redirect'] = $_SESSION['login_redirect'] + 1 ;
                if($_SESSION['login_redirect'] > 5) {
                    $this->addError('Something is wrong with your access credentials. Please contact support.');
                } else {
                    $this->redirectLastPage();
                }
            }
        }  

        if($this->mode === 'logout') $this->manageUserAction('LOGOUT');
        if($this->mode === 'reset_pwd') $this->resetPassword($_GET['token']);
        if($this->mode === 'reset_login') $this->resetLoginLink($_GET['token'],$_GET['days']);
        if($this->mode === 'reset_send') $this->resetSend($form);
        if($this->mode === 'login') $this->manageLogin('LOGIN',$form);
        
        $html = $this->viewLogin();
        return $html;
    }
    
    //NB: Only used where checkAccessRights() not called but user may be known 
    public function setupUserData() 
    {
        $this->user_id = $this->getCache($this->cache['user']);    
        if($this->user_id !== '') {
            $this->data = $this->getUser('ID',$this->user_id);
            $this->access_zone = $this->data[$this->user_cols['zone']];
        } else {
            $this->user_id = 0;
        }

        return $this->user_id;   
    }

    public function getData()
    {
        return $this->data;
    }

    public function getId()
    {
        return $this->user_id;
    }

    public function getName()
    {
        if(count($this->data)) return $this->data[$this->user_cols['name']]; else return '';
    }

    public function getCsrfToken()
    {
        if(count($this->data)) return $this->data[$this->user_cols['csrf_token']]; else return '';
    }

    public function getEmail()
    {
        if(count($this->data)) return $this->data[$this->user_cols['email']]; else return '';
    }

    public function getAccessLevel()
    {
        return $this->access_level;
    }

    public function getAccessLevels()
    {
        return $this->access_levels;
    }

    public function getRouteAccess()
    {
        return $this->route_access; 
    }

    public function getRouteWhitelist()
    {
        return $this->routes['whitelist']; 
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
        
        $view['days'] = $this->login_days;
        $view['days_expire'] = 30;
    
        return $view;
    }  
    
    //process normal login as well as reset password
    public function manageLogin($action = 'LOGIN', $form = []) 
    {
        $error = '';
        $error_tmp = '';

        $email = $form['email'];
        //for redisplay in viewLogin()
        $this->email = $email;

        $password = $form['password'];
        $human = $form[$this->cache['human']];
        $human_cache = $this->getCache($this->cache['human']);

        //*** first check if password reset requested and verified via email token ***
        $reset = $this->getCache($this->cache['reset']);
        if(is_array($reset)) $reset_password = true; else $reset_password = false;
        
        if($reset_password) {
            $email = $reset[$this->user_cols['email']];
            $user = $this->getUser('EMAIL',$email);
            if($user == 0) {
                $this->addError('Unrecognised user email['.$reset[$this->user_cols['email']].'] for password reset');
            } else { 
                //for template display
                $this->email = $user[$this->user_cols['email']]; 
            }    
            
            $rules = [];
            Validate::securePassword($password,$rules,$error);
            if($error !== '') $this->addError('Insecure password: '.$error);
                 
            $password_repeat = $form['password_repeat'];  
            if($password_repeat !== $password) $this->addError('Password repeat does NOT match!');
            
            if(!$this->errors_found) {
                //create new salt value rather than use old
                $salt = Crypt::makeSalt();
                $password_hash = Crypt::passwordHash($password,$salt);
                
                $user_id = $user[$this->user_cols['id']];
                $this->db->setAuditUserId($user_id);

                $data = array($this->user_cols['pwd_salt']=>$salt,
                              $this->user_cols['password']=>$password_hash);
                $result = $this->update($user_id,$data);
                if($result['status'] !== 'OK') {
                    $error = 'USER_AUTH_ERROR: could not reset password';
                    if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                    throw new Exception($error);
                } else {
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
                $debug_info = 'Email['.$email.'] not recognised.';
                $user_id = 0;
                $this->loginFail('USER',$user_id,['email'=>$email,'password'=>$password,'debug_info'=>$debug_info]);
            } else { 
                $user_id = $user[$this->user_cols['id']];
                $salt = $user[$this->user_cols['pwd_salt']];
                $password_db = $user[$this->user_cols['password']];
                $password_check = Crypt::passwordHash($password,$salt);
                if($password_check === $password_db or $this->bypass_security) {
                    //to prevent session "fixation"
                    session_regenerate_id();
                    
                    if($reset_password) {
                        $remember_me = true;
                        $days_expire = 30;
                    } else {
                        if(isset($form['remember_me'])) $remember_me = true; else $remember_me = false;
                        $days_expire = Secure::clean('integer',$form['days_expire']);
                    }    

                    $this->manageUserAction('LOGIN',$user,$remember_me,$days_expire);
                } else {
                    $debug_info = 'password entered['.$password.'] ';
                    $this->loginFail('PASSWORD',$user_id,['email'=>$email,'password'=>$password,'debug_info'=>$debug_info]);
                }
            }
        }
        
        //slow things down for dictionary attack 
        if($this->errors_found) sleep(3);
    }  
    
    protected function loginFail($type,$user_id,$param = [])
    {
        if($type === 'PASSWORD') {
            $description = 'Email['.$param['email'].'] pwd[****'.substr($param['password'],6).'] login FAILED. INVALID PASSWORD.';
            if(isset($_SERVER['REMOTE_ADDR'])) $description .= ' IP['.$_SERVER['REMOTE_ADDR'].']';
            Audit::action($this->db,$user_id,'LOGIN_FAIL',$description);

            $this->addError('Invalid Email or Password!');
            if($this->debug) {
                $this->addError($param['debug_info']);
            }

            $sql = 'UPDATE '.$this->table.' SET '.$this->user_cols['login_fail'].' = '.$this->user_cols['login_fail'].' + 1 '.
                   'WHERE '.$this->user_cols['id'].' = "'.$user_id.'" '; 
            $this->db->executeSql($sql,$error_tmp);
            if($error_tmp !== '') {
                $error = 'USER_AUTH_ERROR: could not update fail count';
                if($this->debug) $error .= ' User ID['.$user_id.']: '.$error_tmp;
                throw new Exception($error);
            }
        }    

        if($type === 'USER') {
            $description = 'Email['.$param['email'].'] pwd[****'.substr($param['password'],6).'] login FAILED. UNKNOWN USER.';
            if(isset($_SERVER['REMOTE_ADDR'])) $description .= ' IP['.$_SERVER['REMOTE_ADDR'].']';
            Audit::action($this->db,$user_id,'LOGIN_UNKNOWN',$description);

            $this->addError('Invalid Email or Password!');
            if($this->debug) {
                $this->addError($param['debug_info']);
            }

        }
        


        sleep(3);
    }

    public function manageUserAction($action = 'LOGIN',$user = [],$remember_me = false,$days_expire = 0,$token = '') {
        if($action === 'LOGIN' or $action === 'LOGIN_AUTO' or $action === 'LOGIN_REGISTER') {
            if(count($user) == 0) {
                throw new Exception('USER_AUTH_ERROR: Invalid user data');
            } else { 
                $this->user_id = $user[$this->user_cols['id']];
                $this->setCache($this->cache['user'],$this->user_id);
                $this->db->setAuditUserId($this->user_id);
                $this->data = $user;
                $this->access_zone = $user[$this->user_cols['zone']];
            } 

            $update = [];
            //NB any form csrf check will use $this->data above BEFORE this update is applied
            $update[$this->user_cols['csrf_token']] = Crypt::makeToken();
            $update[$this->user_cols['login_fail']] = 0;

            //Set/Reset login cookie
            if($remember_me) {
                if($days_expire < 1) $days_expire = 1;

                if($action === 'LOGIN' or $action === 'LOGIN_REGISTER') {
                    //expire days as selected by user on login
                    $login_token = $this->insertLoginToken($this->user_id,$days_expire);
                } 

                if($action === 'LOGIN_AUTO') {
                    //expiry date of token unchanged from original LOGIN
                    $login_token = $this->updateLoginToken($token,$this->user_id);
                }

                //NB: cookie expire days are independent of token expiry date
                Form::setCookie($this->login_cookie,$login_token,$this->cookie_expire_days);
            }

            $result = $this->update($this->user_id,$update);
            if($result['status'] !== 'OK') {
                $error = 'USER_AUTH_ERROR: could not update user csrf token';
                if($this->debug) $error .= ' User ID['.$this->user_id.']: '.implode(',',$result['errors']);
                throw new Exception($error);
            } 

            if($action === 'LOGIN') {
                $description = 'Email['.$user[$this->user_cols['email']].'] login SUCCESS';
                Audit::action($this->db,$this->user_id,$action,$description);
                //redirect to page last visited or default
                $this->redirectLastPage();
            } 

            if($action === 'LOGIN_REGISTER') {
                $description = 'Email['.$user[$this->user_cols['email']].'] login SUCCESS after registration';
                Audit::action($this->db,$this->user_id,$action,$description);
            } 

            if($action === 'LOGIN_AUTO') {
                $description = 'Email['.$user[$this->user_cols['email']].'] auto login SUCCESS';
                Audit::action($this->db,$this->user_id,$action,$description);
            } 
        }
        
        if($action === 'LOGOUT') {
            //erase all session values
            $_SESSION = [];

            //remove server version of login cookie if exists
            $token = Form::getCookie($this->login_cookie);
            if($token !== '') {
                $this->deleteLoginToken($token);
            }

            //removes auto login cookie by making days negative(-1)
            Form::setCookie($this->login_cookie,'',-1); 
        }  
    }

    //use to identify user when not logged in. For public website usage.
    public function getTempToken($create = true)
    {
        $token = Form::getCookie($this->temp_cookie);

        if($token === '' and $create) {
            $token = Crypt::makeToken();
            Form::setCookie($this->temp_cookie,$token,$this->cookie_expire_days);
        }

        return $token;    
    }

    //called from login page 
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
                    if($this->resetSendPasswordLink($user_id)) {
                       $this->addMessage('Please check your email and click on the link in the email to RESET your password.'); 
                    }
                } 

                if($reset_type === 'LOGIN') {
                    $days_expire = Secure::clean('integer',$form['days_expire']);
                    if($this->resetSendLoginLink($user_id,$days_expire)) {
                       $this->addMessage('Please check your email and click on the link in the email to LOGIN');  
                    }
                }   
            } 
        }
    }  
    
    //NB: login token only set in manageUserAction() after verifying email token
    public function resetLoginLink($token,$days_expire = 0) {
        $error = '';
        $token = Secure::clean('alpha',$token);
        $days_expire = Secure::clean('integer',$days_expire);

        if($token !== '') {
            $user = $this->getUser('EMAIL_TOKEN',$token);
            if($user == 0) {
                $error = 'Your email token is not recognised or has expired! ';
                if($this->debug) $error .= $token;
                $this->addError($error);
            } else {    
                $remember_me = true;
                $this->manageUserAction('LOGIN',$user,$remember_me,$days_expire,$token);
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

                if(defined('PASSWORD_MESSAGE')) {
                    $pwd_msg = PASSWORD_MESSAGE; 
                } else {
                    $pwd_msg = 'NB: password must be at least 8 alphanumeric characters with at least one lowercase, uppercase, numeric character';    
                }
                
                
                $this->addMessage('Your reset token is valid! Please reset your password below...<br/>'.$pwd_msg);
            } 
        }
    }
    
    
    public function getUser($type,$value)
    {
        if($type === 'ID') {
            $user = $this->get($value);
        }    
         
        if($type === 'EMAIL_TOKEN') {
            $sql = 'SELECT * FROM '.$this->table.' '.
                   'WHERE '.$this->user_cols['email_token'].' = "'.$this->db->escapeSql($value).'" AND '.
                            $this->user_cols['email_token_expire'].' >= CURDATE() AND '.
                            $this->user_cols['login_fail'].' <= '.$this->login_fail_max.' AND '.
                            $this->user_cols['status'].' <> "HIDE" ';
            $user = $this->db->readSqlRecord($sql);
        }    

        if($type === 'LOGIN_TOKEN') {
            $token = $this->checkLoginToken($value);
            if($token !== 0) {
                $user = $this->get($token[$this->user_cols['id']]);
            } else {
                $user = 0;
            }
        }

        if($type === 'EMAIL') {
            $sql = 'SELECT * FROM '.$this->table.' '.
                   'WHERE '.$this->user_cols['email'].' = "'.$this->db->escapeSql($value).'" AND '.
                            $this->user_cols['login_fail'].' <= '.$this->login_fail_max.' AND '.
                            $this->user_cols['status'].' <> "HIDE" ';
            $user = $this->db->readSqlRecord($sql);
        }

        //NB: only use this option to check if user exists NOT if user valid.
        if($type === 'EMAIL_EXIST') {
            $sql = 'SELECT * FROM '.$this->table.' '.
                   'WHERE '.$this->user_cols['email'].' = "'.$this->db->escapeSql($value).'" ';
            $user = $this->db->readSqlRecord($sql);
        }

        return $user;
    }

    //get route required depending on route definition and user zone
    public function getRoute($type,$zone)
    {   
        $route = '';
        if(isset($this->routes[$type])) {
            if(is_array($this->routes[$type])) {
                if(isset($this->routes[$type][$zone])) {
                    $route = $this->routes[$type][$zone];   
                } else {
                    $error = 'NO default route exists for user access Zone.';
                    if($this->debug) $error .= 'Zone['.$zone.'] not found in: '.var_export($this->routes[$type],true);
                    throw new Exception('USER_ZONE_ERROR: '.$error); 
                }
            } else {
                if(isset($this->routes[$type])) {
                    $route = $this->routes[$type];    
                } else {
                    $error = 'NO default route exists.';
                    if($this->debug) $error .= 'type['.$type.'] not found in: '.var_export($this->routes,true);
                    throw new Exception('USER_ZONE_ERROR: '.$error); 
                }
            }
        }

        return $route;
    }

    //where user specific route whitelist defined
    protected function setupRouteWhitelist()
    {
        //array key will be route from url root
        $sql = 'SELECT '.$this->route_cols['route'].','.$this->route_cols['access'].','.$this->route_cols['title'].','.$this->route_cols['config'].' '.
               'FROM '.TABLE_ROUTE.' '.
               'WHERE '.$this->route_cols['user_id'].' = "'.$this->db->escapeSql($this->user_id).'" '.
               'ORDER BY '.$this->route_cols['sort'];
        $list = $this->db->readSqlArray($sql);

        //set user default page
        if($list == 0) {
            $error = 'NO user allowed pages configured.';
            if($this->debug) $error .= 'User ID['.$this->user_id.']';
            throw new Exception('USER_ROUTE_ERROR: '.$error); 
        } else {   
            $this->route_access = true; 
            $default = Calc::getArrayFirst($list);
            $this->routes['default'] = $default['key'];    
            $this->routes['whitelist'] = $list;
        }

        return $list;
    }

    //store last location/page/uri user vists before a redirect 
    public function setLastPage()
    {
        //NB: strip leading / from url 
        $page_url = substr($_SERVER['REQUEST_URI'],1);
        $page_valid = true;

        foreach($this->routes_redirect_ignore as $ignore=>$match) {
            if($match === 'EXACT') {
                if($page_url === $ignore) $page_valid = false; 
            } else {
                if(stripos($page_url,$ignore) !== false) $page_valid = false;    
            }
            
        }

        if($page_valid) $this->setCache($this->cache['page'],$page_url);    
    }

    public function redirectLastPage()
    {
        $last_page = $this->getCache($this->cache['page']);

        //do not want to redirect to some routes, like an ajax call or login page
        foreach($this->routes_redirect_ignore as $ignore=>$match) {
            if($match === 'EXACT') {
                if($last_page === $ignore) $last_page = '';
            } else {
                if(stripos($last_page,$ignore) !== false) $last_page = '';    
            }
        }

        //check if user is limited to route whitelist
        if($this->data[$this->user_cols['route_access']]) {
            //NB: also sets default page for user 
            $routes_allow = $this->setupRouteWhitelist();
            if(!isset($routes_allow[$last_page])) $last_page = ''; 
        }
        
        if($last_page !== ''){
            header('location: '.BASE_URL.Secure::clean('header',$last_page));
        } else {
            $route = $this->getRoute('default',$this->access_zone);
            header('location: '.BASE_URL.$route);
        } 
        exit;
    }

    //validate user, autologin if possible
    public function checkAccessRights($access_zone){
        $error = '';
        
        $level = 'NONE';
        $zone = 'NONE';
        $level_valid = false;
        $zone_valid = false;
        //route access must be activated, off by default
        $route_check = false;
        $route_valid = true;
    
        $this->user_id = $this->getCache($this->cache['user']); 
        if($this->user_id !== '') {
            $this->data = $this->get($this->user_id);
            //sometimes Cache can be out of sync with DB
            if($this->data) {
                $level = $this->data[$this->user_cols['access']];
                $zone = $this->data[$this->user_cols['zone']];
                $route_check = $this->data[$this->user_cols['route_access']];
            } else {
                $this->user_id = 0;
            } 
        } else {  
            //check for auto login cookie and re-login if valid
            $login_token = Form::getCookie($this->login_cookie);
            if($login_token !== ''){
                $user = $this->getUser('LOGIN_TOKEN',$login_token);
                if($user != 0) {
                    $level = $user[$this->user_cols['access']];
                    $zone = $user[$this->user_cols['zone']];
                    $route_check = $user[$this->user_cols['route_access']];

                    $remember_me = true;
                    $days_expire = 30; //ignored as login token expiry already set. 
                    $this->manageUserAction('LOGIN_AUTO',$user,$remember_me,$days_expire,$login_token);
                } 
            } 
        }

        //check route access assuming primary access level valid
        if($level !== 'NONE' and $route_check) {
            $routes_allow = $this->setupRouteWhitelist();
            if(!isset($routes_allow[URL_CLEAN])) {
                $route_valid = false;
            } else {
                $route = $routes_allow[URL_CLEAN];
                //set route specific access level
                $level = $route[$this->route_cols['access']];
            }    
        }


        //check user level is valid
        if($level === 'NONE') {
            $this->setLastPage();
        } else  { 
            if(array_search($level,$this->access_levels) === false) {
                $error = 'INVALID access level:'.$level;
                if($this->debug) $error .= ' allowed: '.var_export($this->access_levels,true);
                throw new Exception('USER_AUTH_ERROR: '.$error);
            } else {
                $this->access_level = $level;
                $level_valid = true;
            }
        }

        //check user zone is valid
        if($zone === 'ALL' or $zone === $access_zone) {
            $this->access_zone = $zone;
            $zone_valid = true;
        } 

        if($level_valid and $zone_valid and $route_valid) return true; else return false;     
    }
    
    //NB: ASSUMES $this->access_levels sequence is from highest to lowest access
    public function checkUserAccess($level_required) {
        $access = false;
         
        if($level_required === 'NONE') {
            $access = true;
        } else {       
            $user = array_search($this->access_level,$this->access_levels);
            $required = array_search($level_required,$this->access_levels);
            if($user !== false and $user <= $required) $access = true;
        }    
        
        //echo 'required:'.$level_required.' user:'.$this->access_level.'<br/>';
        return $access;     
    }
    
    // *** HELPER FUNCTIONS FOR ANY USER, NOT JUST CURRENT LOGGED IN USER ****

    //Update user email token and return login url for use in custom emails only.  
    public function resetEmailLoginToken($user_id,$days_expire = 1,$login_mode = 'reset_login')
    {
        $user = $this->get($user_id);
        if($user == 0) {
            $error = 'USER_TOKEN_ERROR: Invalid user';
            if($this->debug) $error .= ' ID['.$user_id.'] ';
            throw new Exception($error);
        }

        $date = getdate(); 
        $email_token = Crypt::makeToken();
        $date_str = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$days_expire,$date['year']));
        
        $data = [$this->user_cols['email_token']=>$email_token,
                 $this->user_cols['email_token_expire']=>$date_str];

        $result = $this->update($user_id,$data);
        if($result['status'] !== 'OK') {
            $error = 'USER_TOKEN_ERROR: Could not update user email token!';
            if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
            throw new Exception($error);
        }

        $zone = $user[$this->user_cols['zone']];
        $route = $this->getRoute('login',$zone);
        $login_url = BASE_URL.$route.'?mode='.$login_mode.'&token='.urlencode($email_token);

        return $login_url;
    }

    //send ANY user link so they can reset password of their own choice
    public function resetSendPasswordLink($user_id)
    {
        $error = '';

        $user = $this->get($user_id);
        if($user == 0) $this->addError('Invalid user id['.$user_id.']'); 

        if(!$this->errors_found) {
            $date = getdate(); 

            $email_token = Crypt::makeToken();
            $days_expire = 1;
            $date_str = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$days_expire,$date['year']));
            
            $data = [$this->user_cols['email_token']=>$email_token,
                     $this->user_cols['email_token_expire']=>$date_str];

            $result = $this->update($user_id,$data);
            if($result['status'] !== 'OK') {
                $error = 'USER_TOKEN_ERROR: Could not update user email token!';
                if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                throw new Exception($error);
            } 
            
            $to = $user[$this->user_cols['email']];
            $from = MAIL_FROM;
            $subject = SITE_NAME.' - password RESET';

            $zone = $user[$this->user_cols['zone']];
            $route = $this->getRoute('login',$zone);
            $reset_url = BASE_URL.$route.'?mode=reset_pwd&token='.urlencode($email_token);

            $body = 'You requested a password reset by email.'."\r\n".
                    'Please click on the link below to reset your password....'."\r\n".
                    $reset_url;

            if(!$this->errors_found) {
                $mailer = $this->getContainer('mail');
                if($mailer->sendEmail($from,$to,$subject,$body,$error)) {
                    $this->addMessage('SUCCESS emailing password reset link to['.$to.'] '); 
                } else {
                    $this->addError('FAILURE emailing password reset link to['.$to.']: Please try again later or contact support['.$from.']'); 
                } 
            }    
        }

        if(!$this->errors_found) return true; else return false;     
    }

    //generate a new password and email to ANY user
    public function resetSendPassword($user_id) 
    {
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

                $zone = $user[$this->user_cols['zone']];
                $route = $this->getRoute('login',$zone);
                $login_url = BASE_URL.$route;
                
                $from = ''; //default config email from used
                $subject = SITE_NAME.' user password reset';
                $body = SITE_NAME.' Password reset for:  '.$user[$this->user_cols['name']]."\r\n\r\n".
                        'url: '.$login_url."\r\n".
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

    //generate a new Email token and email to ANY user so they generate a new login token
    public function resetSendLoginLink($user_id,$days_expire = 0) 
    {
        $error = '';
        $error_tmp = '';
       
        $user = $this->get($user_id);
        if($user == 0) $this->addError('Invalid user id['.$user_id.']'); 

        if(!$this->errors_found) {
            $date = getdate(); 

            $email_token = Crypt::makeToken();
            $email_days_expire = 1;
            $date_expire = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$email_days_expire,$date['year']));
            
            $data = [$this->user_cols['email_token']=>$email_token,
                     $this->user_cols['email_token_expire']=>$date_expire];

            $result = $this->update($user_id,$data);
            if($result['status'] !== 'OK') {
                $error = 'USER_TOKEN_ERROR: Could not update user email token!';
                if($this->debug) $error .= ' User ID['.$user_id.']: '.implode(',',$result['errors']);
                throw new Exception($error);
            } 
            
            $zone = $user[$this->user_cols['zone']];
            $route = $this->getRoute('login',$zone);
            $reset_url = BASE_URL.$route.'?mode=reset_login&token='.urlencode($email_token).'&days='.$days_expire;

            $to = $user[$this->user_cols['email']];
            $from = ''; //default config email from used
            $subject = SITE_NAME.' user login token';
            $body = 'Please click on the link below to login from this device....'."\r\n".
                    'This link will expire on: '.$date_expire.'.'."\r\n".
                    $reset_url;
                                
            $mailer = $this->getContainer('mail');
            if(!$mailer->sendEmail($from,$to,$subject,$body,$error_tmp)) {
                $error .= 'FAILURE emailing user['.$user_id.'] login token to address['.$to.']'; 
                if($this->debug) $error .= $error_tmp;
                $this->addError($error);
            } else {
                $this->addMessage('SUCCESS emailing login link to['.$to.'] '); 
            }

        } 

        if(!$this->errors_found) return true; else return false; 
    }

    //get rid of any expired login tokens.
    protected function expireLoginTokens($user_id) 
    {
        $error = '';

        $sql = 'DELETE FROM '.TABLE_TOKEN.' '.
               'WHERE '.$this->token_cols['user_id'].' = "'.$this->db->escapeSql($user_id).'" AND '.
                        $this->token_cols['date_expire'].' <= NOW()';

        $this->db->executeSql($sql,$error); 
        if($error !== '') {
            $error = 'USER_TOKEN_ERROR: could not create new login token';
            if($this->debug) $error .= ' User ID['.$user_id.']';
            throw new Exception($error);
        }                
    }

    //delete a single token regardless of user
    protected function deleteLoginToken($token) 
    {
        $token = Secure::Clean('alpha',$token);

        if($token !== '') {
            $sql = 'DELETE FROM '.TABLE_TOKEN.' '.
                   'WHERE '.$this->token_cols['token'].' = "'.$this->db->escapeSql($token).'" ';

            $this->db->executeSql($sql,$error); 
            if($error !== '') {
                $error = 'USER_TOKEN_ERROR: could not delete token on logout';
                if($this->debug) $error .= ' Token['.$token.']';
                throw new Exception($error);
            }
        }                    
    }

    //check all user login tokens and return token user_id and date_expire (0 IF NOT FOUND)
    protected function checkLoginToken($token)
    {
        //token should only contain alphanumeric characters
        $token = Secure::Clean('alpha',$token);

        $sql = 'SELECT '.$this->token_cols['user_id'].' AS '.$this->user_cols['id'].','.$this->token_cols['date_expire'].' '.
               'FROM '.TABLE_TOKEN.' '.
               'WHERE '.$this->token_cols['token'].' = "'.$this->db->escapeSql($token).'" AND '.
                        $this->token_cols['date_expire'].' >= NOW() ';

        $rec = $this->db->readSqlRecord($sql);
        //wait 3 seconds to slow down any attack
        if($rec === 0) sleep(3); 

        return $rec;          
    }

    //replace existing token with new one
    protected function updateLoginToken($token,$user_id)
    {
        $error = '';
        $error_tmp = '';

        $token_new = Crypt::makeToken();

        $sql = 'UPDATE '.TABLE_TOKEN.' SET '.$this->token_cols['token'].' = "'.$this->db->escapeSql($token_new).'" '.
               'WHERE '.$this->token_cols['token'].' = "'.$this->db->escapeSql($token).'" AND '.
                        $this->token_cols['user_id'].' = "'.$this->db->escapeSql($user_id).'" ';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp !== '') {
            $error = 'USER_TOKEN_ERROR: could not replace login token';
            if($this->debug) $error .= ' User ID['.$user_id.'] Old token['.$token.'] New token['.$token_new.'] Error:'.$error_tmp;
            throw new Exception($error);
        }

        return $token_new;    
    }

    //create a new login token in addition to any existing tokens
    protected function insertLoginToken($user_id,$days_expire = 0)
    {
        $error = '';
        $error_tmp = '';

        $token = Crypt::makeToken();

        if($days_expire < 1) $days_expire = 1;
        if($days_expire > 365) $days_expire = 365;

        $date = getdate();
        $date_expire = date('Y-m-d',mktime(0,0,0,$date['mon'],$date['mday']+$days_expire,$date['year']));

        $data = [];
        $data[$this->token_cols['user_id']] = $user_id;
        $data[$this->token_cols['token']] = $token;
        $data[$this->token_cols['date_expire']] = $date_expire;
        $this->db->insertRecord(TABLE_TOKEN,$data,$error_tmp);
        if($error_tmp !== '') {
            $error = 'USER_TOKEN_ERROR: could not create new login token';
            if($this->debug) $error .= ' User ID['.$user_id.'] '.$error_tmp;
            throw new Exception($error);
        } 

        //house keeping: good place to get rid of any expired tokens
        $this->expireLoginTokens($user_id);

        return $token;  
    }
    
}
