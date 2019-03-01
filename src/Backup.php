<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Amazon;
use Seriti\Tools\Date;
use Seriti\Tools\Audit;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\SecurityHelpers;
use Seriti\Tools\TableStructures;

use Psr\Container\ContainerInterface;

class Backup extends Model 
{
    use IconsClassesLinks;
    use ModelViews; 
    use ModelHelpers;
    use ContainerHelpers;
    use SecurityHelpers;
    use TableStructures;
    
    private $container;
    protected $container_allow = ['config','mail','system'];
    
    //basic types for primary database and source code as defined by config, can be extended by add_database() and add_source()                            
    protected $types = array('DATA'=>'Primary database','SOURCE'=>'Primary source code');                                               
                                                                                            
    protected $mode = 'list';
    protected $time_out = 120; //default timeout in seconds
    protected $actions = array();
    protected $exclude_table = array();
    protected $path = array(); //local directories
    protected $exclude_dir = array(); //local directories to ignore for source backups
    protected $backup = array(); //backup locations source=>LOCAL/AMAZON
    protected $add_param = array();
    protected $location = '';
    protected $user_id;
    protected $user_csrf_token;
    protected $csrf_token = '';
         
    public function __construct(DbInterface $db, ContainerInterface $container, $table)
    {
        parent::__construct($db,$table);

        $this->container = $container;

        //$this->setup($param);
    } 
        
    //constructor function when class created
    public function setup($param = []) {
        if(isset($param['location'])) $this->location = $param['location']; //Could use URL_CLEAN_LAST

        //backup source: AMAZON,FTP,LOCAL,GDRIVE....etc
        if(isset($param['source'])) {
            $this->backup['source'] = $param['source']; 
        } else {
            $this->backup['source'] = 'LOCAL';
        } 

        //check EXTERNAL source parameters
        if($this->backup['source'] === 'AMAZON') {
            if(isset($param['bucket'])) {
                $this->backup['bucket'] = $param['bucket'];
            } else {
                $this->addError('No Amazon S3 backup bucket specified!'); 
            }
        }  

        if(isset($param['time_out'])) $this->time_out = $param['time_out'];
                
        //check all paths/locations starting with base
        if(isset($param['path_base'])) {
            $this->path['base'] = $param['path_base'];
        } else {
            $this->addError('No base file location specified!'); 
        }

        if(isset($param['path_files'])) {
            $this->path['files'] = $param['path_files'];
        } else {
            $this->addError('No document file location specified!'); 
        }

        if(isset($param['path_temp'])) {
            $this->path['temp'] = $param['path_temp'];
        } else {
            $this->addError('No temporary file location specified!'); 
        }

        if(isset($param['path_backup'])) {
            $this->path['backup'] = $param['path_backup'];
        } else {
            $this->addError('No backup file location specified!'); 
        }
                
        //setup default non--source directories to ignore(can overide!)
        $this->setupIgnore();
        
        $this->addCol(['id'=>$this->backup_cols['id'],'title'=>'Backup ID','type'=>'INTEGER','key'=>true,'key_auto'=>true,'list'=>true]);
        $this->addCol(['id'=>$this->backup_cols['date'],'title'=>'Backup date','type'=>'DATE']);
        $this->addCol(['id'=>$this->backup_cols['type'],'title'=>'Type','type'=>'STRING']);
        $this->addCol(['id'=>$this->backup_cols['comment'],'title'=>'Comment','type'=>'TEXT','required'=>false]);
        $this->addCol(['id'=>$this->backup_cols['file_name'],'title'=>'File name','type'=>'STRING','required'=>false]);
        $this->addCol(['id'=>$this->backup_cols['file_size'],'title'=>'File size','type'=>'INTEGER','required'=>false]);
        $this->addCol(['id'=>$this->backup_cols['status'],'title'=>'Status','type'=>'STRING','required'=>false]);

        $this->user_access_level = $this->getContainer('user')->getAccessLevel();
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);
    }  
    
    //configure which directories to ignore when backing up source
    public function setupIgnore($type = 'DEFAULT',$value = '') 
    {
        if($type === 'DEFAULT') {
            //exclude standard non-source directories   
            //NB: trailing / removed  
            if(isset($this->path['files'])) $this->exclude_dir[] = substr($this->path['files'],0,-1);
            if(isset($this->path['backup'])) $this->exclude_dir[] = substr($this->path['backup'],0,-1);
            if(isset($this->path['temp'])) $this->exclude_dir[] = substr($this->path['temp'],0,-1);
            
            //exclude backup table from dumps as restore
            $this->exclude_table[]=$this->table;
        }
        
        if($type === 'DIRECTORY' and $value !== '') {
            if(!in_array($value,$this->exclude_dir)) $this->exclude_dir[] = $value;
        }
        
        if($type === 'TABLE' and $value !== '') {
            if(!in_array($value,$this->exclude_table)) $this->exclude_table[] = $value;
        }
             
        if($type === 'RESET') {
            $this->exclude_dir = [];
            $this->exclude_table = [];
        }
    } 
    
    //define xtra DBs that are not part of primary db, host should be on same server.
    public function addDatabase($type,$param)
    {
        if(!isset($this->types[$type])) {
            $this->types['DATA_'.$type] = $type.' database';  
            $this->add_param['DATA_'.$type] = $param;
        }  
    }
    //define xtra source folders, typically a CMS or other public facing code, must be on same server.
    public function addSource($type,$param) 
    {
        if(!isset($this->types[$type])) {
            $this->types['SOURCE_'.$type] = $type.' source code';  
            $this->add_param['SOURCE_'.$type] = $param;
        }  
    }
            
    //default public function to handle all processing and views
    public function process() 
    {
        $html = '';
        $param = array();
        $id = 0;
        
        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));

        if(isset($_GET['mode'])) $this->mode = Secure::clean('basic',$_GET['mode']);
        
        if(isset($_GET['id'])) $id = Secure::clean('basic',$_GET['id']); 
        if(isset($_POST['id'])) $id = Secure::clean('basic',$_POST['id']);
        
        if($this->mode === 'list') $html .= $this->viewBackups();
        if($this->access['read_only'] === false) {
            if($this->mode === 'add')    $html .= $this->setupBackup();
            if($this->mode === 'confirm') $html .= $this->confirmBackup($id,$_POST['backup_type'],$_POST['backup_comment']);
            if($this->mode === 'process') $html .= $this->processBackup($id);
            if($this->mode === 'restore') $html .= $this->confirmRestore($id);
            if($this->mode === 'process_restore') $html .= $this->restoreBackup($id);
            if($this->mode === 'delete') $html .= $this->deleteBackup($id);
            if($this->mode === 'download') $html .= $this->backupDownload($id);
        } 
                
        $html = $this->viewMessages().$html;
                        
        return $html;
    }
    
    public function setupBackup() 
    { 
        $comment = '';
        $html = '';
        $this->addMessage('Select the type of backup, enter any comments and Submit. Then you will be asked to confirm!');
        
        $html .= $this->viewNavigation();
        $param = array();
        $param['class'] = 'form-control input-medium';
        
        $html .= '<div id="edit_div">';
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=confirm" name="update_form" id="update_form">';
        $html .= $this->formState();
        $html .= '<table width="100%" cellspacing="0" cellpadding="4">';
        $html .= '<tr><td>Type</td><td>'.Form::arrayList($this->types,'backup_type','DATA',1,$param).'</td></tr>';
        $html .= '<tr><td>Comments</td><td>'.Form::textAreaInput('backup_comment',$comment,'','','',$param).'</td></tr>';
        $html .= '<tr><td>Create</td><td><input id="edit_submit" type="submit" name="submit" value="Submit" class="'.$this->classes['button'].'"></td></tr>';
        $html .= '</table>';
        $html .= '</form>';
        $html .= '</div>';
        
        return $html;
    } 
    
    public function confirmBackup($id,$type,$comment) 
    {
        $html = '';
        $this->addMessage('Click Process backup button to process backup. NB: please be patient, processing may take some time');
         
        $data[$this->backup_cols['status']] = 'NEW';
        $data[$this->backup_cols['date']] = date('Y-m-d H:i:s');
        $data[$this->backup_cols['type']] = $type;
        $data[$this->backup_cols['comment']] = $comment;
        $id = $this->db->create($data);
        if($this->errors_found) {
            $this->addError('Could not create backup record!');
        } else {  
            $html .= '<div id="edit_div">';
            $html .= '<form method="post" enctype="multipart/form-data" action="?mode=process&id='.$id.'" name="update_form" id="update_form">';
            $html .= $this->formState();
            $html .= '<table width="100%" cellspacing="0" cellpadding="4">';
            $html .= '<tr><td>Type</td><td>'.$this->types[$data[$this->backup_cols['type']]].'</td></tr>';
            $html .= '<tr><td>Comments</td><td>'.nl2br($data[$this->backup_cols['comment']]).'</td></tr>';
            $html .= '<tr><td>Create</td><td>'.
                         '<input id="process_backup" type="submit" name="submit" value="Process backup" class="'.$this->classes['button'].'" '.
                         'onclick="link_download(\'process_backup\');">'.
                         '</td></tr>';
            $html .= '</table>';
            $html .= '</form>';
            $html .= '</div>';
        }
        
        return $html;
    } 
    
    public function confirmRestore($id) 
    {
        $html = '';
        
        $data = $this->get($id);
        if($data == 0) {
            $this->addError('Could not find backup data!');
        } else {
            $file_name = $data[$this->backup_cols['file_name']];
            if($file_name === '') $this->addError('No backup file exists!');
        }    
            
        if(!$this->errors_found) { 
            $this->addMessage('Click CONFIRM RESTORE button to replace current database with selected backup. NB: please be patient, processing may take some time');
            
            $html .= '<div id="edit_div">';
            $html .= '<h2>NB: This is NOT a reversible process! Your current database will be overwritten with the data in backup file!</h2>';
            $html .= '<form method="post" enctype="multipart/form-data" action="?mode=process_restore&id='.$id.'" name="update_form" id="update_form">';
            $html .= $this->formState();
            $html .= '<table class="'.$this->classes['table'].'">';
            $html .= '<tr><td>Type</td><td>'.$this->types[$data[$this->backup_cols['type']]].'</td></tr>';
            $html .= '<tr><td>Backup File</td><td><b>'.$file_name.'</b></td></tr>';
            $html .= '<tr><td>Comments</td><td>'.nl2br($data[$this->backup_cols['comment']]).'</td></tr>';
            $html .= '<tr><td>&nbsp;</td><td>'.
                         '<input id="process_restore" type="submit" name="submit" value="CONFIRM RESTORE" class="'.$this->classes['button'].'" '.
                         'onclick="link_download(\'process_restore\');">'.
                         '</td></tr>';
            $html .= '</table>';
            $html .= '</form>';
            $html .= '</div>';
        }
        
        return $html;
    }
    
    public function processBackup($id) {
        $html = '';
        $error = '';

        $this->verifyCsrfToken();

        //allow for extended time-out
        set_time_limit($this->time_out);
        
        $data = $this->get($id);
        $type=$data[$this->backup_cols['type']];
        if(substr($type,0,4)==='DATA')  $file_name = $this->backupDatabase($id,$type);
        if(substr($type,0,6)==='SOURCE')  $file_name = $this->backupSource($id,$type);  
        
        //get filesize and move backup file to external location if required
        if(!$this->errors_found) {
            if($this->backup['source'] === 'AMAZON') {
                $s3 = $this->getContainer('s3');

                $file_path = $this->path['base'].$this->path['temp'].$file_name;
                $file_size = filesize($file_path);

                $s3_files[] = ['name'=>$file_name,'path'=>$file_path];
                $s3->putFiles($s3_files,$error);
                if($error != '' ) $this->addError('Could NOT upload database backup file to Amazon S3!: '.$error);

                //remove file from temp upload processing directory
                if(!unlink($file_path)) $this->addError('Could NOT remove backup file from temporary local directory!');
            } else {
                $file_path = $this->path['base'].$this->path['backup'].$file_name;
                $file_size = filesize($file_path); 
            }   
        }
        
        //finally update backup table with filename and status
        if(!$this->errors_found) {
            $this->addMessage('SUCCESSFULY backed up!');
            
            unset($data[$this->backup_cols['backup_id']]);
            $data[$this->backup_cols['file_name']] = $file_name;
            $data[$this->backup_cols['file_size']] = $file_size;
            $data[$this->backup_cols['status']] = 'OK';
            
            $this->update($id,$data);
        }
        
        $html .= $this->viewBackups(); 
        return $html; 
    }  
    
    public function restoreBackup($id) 
    {
        $html = '';
        
        $this->verifyCsrfToken();

        $data = $this->get($id);
        
        //get backup type and process accordingly
        $type = $data[$this->backup_cols['type']];
        if(substr($type,0,4) === 'DATA') {
            $file_name = $data[$this->backup_cols['file_name']];
            if($this->backup['source'] === 'LOCAL') {
                $file_path = $this->path['base'].$this->path['backup'].$file_name;
            }
            
            if($this->backup['source'] === 'AMAZON') {
                $s3 = $this->getContainer('s3');

                $file_path = $this->path['base'].$this->path['temp'].$file_name;
                $s3->getFile($data[$this->backup_cols['file_name']],$file_path,$error);
                if($error != '') $this->addError($error);
            }
            
            //finally run mysql restore command on backup file
            if(!$this->errors_found) restoreDatabase($type,$file_path);
        }  
        
        if(!$this->errors_found) {
            $this->addMessage('SUCCESSFULY restored data from ['.$file_name.']!');
            
            unset($data[$this->backup_cols['backup_id']]);
            $data[$this->backup_cols['comment']] .= "\r\n".'RESTORED on '.$date('Y-m-d ');
            $data[$this->backup_cols['status']] = 'RESTORED';
            
            $this->update($id,$data);
        }
        
        $html .= $this->viewBackups(); 
        return $html; 
    }
    
    public function deleteBackup($id) 
    {
        $html = '';
        $error = '';
        
        $this->verifyCsrfToken();
        
        $data = $this->get($id);
        
        //remove any backup files first
        if($data[$this->backup_cols['file_name']] !== '') {
            if($this->backup['source'] === 'LOCAL') {
                $file_path = $this->path['base'].$this->path['backup'].$data[$this->backup_cols['file_name']];
                if(file_exists($file_path)) {
                    if(!unlink($file_path)) $this->addError('Could NOT delete backup file');
                } else {
                    $this->addError('Coud not find backup file['.$file_path.']'); 
                }   
            } 
            
            if($this->backup['source'] === 'AMAZON') {
                $s3 = $this->getContainer('s3');
                $s3->deleteFile($data[$this->backup_cols['file_name']],$error);
                if($error_str=='') {
                    $this->addError('Could NOT remove backup file from Amazon S3 storage!');
                }
            }  
        }  
        
        $this->delete($id);

        if($this->errors_found) {
            $this->addError('Could not delete backup record');
        } else {
            $this->addError('SUCCESS deleting backup record');
        } 
        
        $html .= $this->viewBackups();
        return $html; 
    }  
    
    public function backupDownload($id,$output = 'BROWSER') {
        $error = '';
        $this->beforeDownload($id,$error);

        if($error !== '') {
            $this->addError($error);
        } else {
            $data = $this->get($id);
            $file_name = $data[$this->backup_cols['file_name']];
            
            if($this->backup['source'] === 'LOCAL') {
                $file_path = $this->path['base'].$this->path['backup'].$file_name;
            }
            
            if($this->backup['source'] === 'AMAZON') {
                $file_path = $this->path['base'].$this->path['temp'].$file_name;

                $s3->getFile($data[$this->backup_cols['file_name']],$file_path,$error);
                if($error != '') $this->addError('Could not retrieve backup file['.$file_name.'] from Amazon S3');
            }
        }  
            
        //send file to browser for viewing or saving depending on disposition
        if(!$this->errors_found) {
            Doc::downloadDoc($file_path,'DOWNLOAD',$error);
        } 
                
        if(!$this->errors_found) {
            $this->addError('Download failed!');  
        } else { 
            if($output === 'BROWSER') {
                if($this->backup['source'] !== 'LOCAL') unlink($file_path);
                exit; 
            } else {
                return $file_path;
            }  
        } 
    } 
 
    protected function viewNavigation() {
        $state_param = $this->linkState();

        $html='<div id="navigate_div">';
        $html.='<a href="?mode=list'.$state_param.'">View all backups</a>';    
        $html.='&nbsp;&nbsp;<a href="?mode=add'.$state_param.'">Create a new backup</a>';    
        $html.='</div>';
        
        return $html;
      }  
    
    protected function viewBackups() 
    {
        $html = '';

        //show latest backup first
        $this->addSql('ORDER',$this->backup_cols('id').' DESC');
        
        $backups = $this->list($param);

        //redirect to create a backup if none found
        if($backups==0) {
            if($this->access['add']) {
                $location=$this->location.'?mode=add';
                $location=Secure::clean('header',$location);
                header('location: '.$location);
                exit;
            } else {
                $this->addMessage('No backups exist! You are not authorised to create backups!');
            } 
        } else {
            $html .= $this->viewNavigation();
            
            $html .= '<div id="list_div">'; 

            //construct header
            $header = '<tr class="thead">';
            $header .= '<th>Actions</th>';
            foreach($this->backup_cols as $value) {
                $header .= '<th>'.$value.'</th>'; 
            }
            $header .= '</tr>';

            //populate table   
            $html .= '<table class="'.$this->classes['table'].'">'.$header;
            $row_no = 0;
            foreach($backups as $data) {
                $row_no++;
                $id = $data[$this->backup_cols['id']];

                $html .= '<tr>';
                $html .= '<td>';
                $html .= $this->viewActions($data,$row_no,'L');
                
                if($this->access['restore'] and $data[$this->backup_cols['type']] === 'DATA') {
                    $html .= '<a href="?mode=restore&id='.$id.'">restore</a>';
                }  
                $html .= '</td>';
                
                $html .= '<td>'.$id.'</td>';
                foreach($this->backup_cols as $key => $value) {
                    if($key !== 'id') {
                        if($key === 'file_size') $data[$value] = Calc::displayBytes($data[$value]);
                        if($key === 'file_name') {
                             $data[$value] = '<a id="file'.$id.'" href="?mode=download&id='.$id.'" onclick="link_download(\'file'.$id.'\')">'.
                            $this->icons['download'].$data[$value].'</a>'; 
                        } 
                         
                        $html .= '<td>'.$data[$value].'</td>'; 
                    }  
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
            $html .= '</div>';
        }  
        
        $html = $this->viewMessages().$html;
        
        return $html;
    } 
    
    
    /*** EVENT PLACEHOLDER FUNCTIONS ***/
    public function verifyRowAction($action,$data) {}
    public function beforeDownload($id,&$error_str) {}
    
    public function backupDatabase($id,$type) 
    {
        $file_name = 'backup-'.strtolower($type).'-'.$id.'_'.date('Y-m-d').'.sql.gz';
        if($this->backup['source'] === 'LOCAL') $dir = $this->path['backup']; else $dir = $this->path['temp'];
        $file_path = $this->path['base'].$dir.$file_name;

        //construct mysqldump system command using primary db or additional settings
        $param = array();
        if($type === 'DATA') {
            $db = $this->getContainer('config')->get('db');

            $param['db_host'] = $db['host'];
            $param['db_user'] = $db['user'];
            $param['db_password'] = $db['password'];
            $param['db_name'] = $db['name'];
            $param['file_path'] = $file_path;
            $param['exclude_table'] = $this->exclude_table;
        } else {
            $param = $this->add_param[$type];
            $param['file_path'] = $file_path;
        }    
        $command = $this->getCommand('MYSQLDUMP',$param);
        
        //echo $command;
        //exit;
            
        //create mysqldump command to dump ALL data EXCEPT the "backup" table
        //$command= 'mysqldump --opt -h'.$this->db['host'].' -u'.$this->db['user'].' -p'.$this->db['password'].' '.$this->db['name'].' '.
        //          '--ignore-table='.$this->db['name'].'.'.$this->table.' | gzip > '.$file_path;

        system($command,$return_val);
        if($return_val === false) {
            $this->addError('Error backing up database: '.$return_val);
        } 
        
        return $file_name;
    }  
    
    public function restoreDatabase($type,$file_path) 
    {
        //construct mysqldump system command using primary db or additional settings
        $param = array();
        if($type === 'DATA') {
            $db = $this->getContainer('config')->get('db');

            $param['db_host'] = $db['host'];
            $param['db_user'] = $db['user'];
            $param['db_password'] = $db['password'];
            $param['db_name'] = $db['name'];
            $param['file_path'] = $file_path;
        } else {
            $param = $this->add_param[$type];
            $param['file_path'] = $file_path;
        }    
        $command = $this->getCommand('MYSQL',$param);
        
        //NB gunzip -c option keeps original .gz file
        //$command= 'gunzip -c < '.$file_path.' | '.
        //          'mysql -h'.$this->db['host'].' -u'.$this->db['user'].' -p'.$this->db['password'].' '.$this->db['name'].' ';
        
        system($command,$return_val);
        if($return_val === false) {
            $this->addError('Error restoring database: '.$return_val);
        } 
        
        //remove file from temp directory
        if($this->backup['source'] === 'AMAZON') {
            if(!unlink($file_path)) $this->addError('Could NOT remove backup file from temporary local directory!');
        }  
    } 
    
    public function backupSource($id,$type) {
        //specify backup file name and path
        $file_name = 'backup-'.strtolower($type).'-'.$id.'_'.date('Y-m-d').'.tar.gz';
        //$file_name='backup-src-'.$id.'_'.date('Y-m-d').'.tar.gz';
        if($this->backup['source'] === 'LOCAL') $dir = $this->path['backup']; else $dir = $this->path['temp'];
        $file_path = $this->path['base'].$dir.$file_name;
        
        //construct tar command excluding non-source directories    
        $param = array();
        if($type === 'SOURCE') {
            $param['root_path'] = '';
            $param['file_path'] = $file_path;
            $param['exclude_dir'] = $this->exclude_dir;
        } else {
            $param = $this->add_param[$type];
            $param['file_path'] = $file_path; 
        }    
        $command = $this->getCommand('SOURCE',$param);
                
        system($command,$return_val);
        if($return_val === false) {
            $this->addError('Error backing up source code: '.$return_val);
        } 
        
        return $file_name;
    } 
    
    //NB: this is intentionally independant of class so can be used as seriti_backup::get_command()
    public static function getCommand($type,$param) {
        $command = '';
        //dumps mysql tables to a .sql.gz file
        if($type === 'MYSQLDUMP') {
            $command = 'mysqldump --opt -h'.$param['db_host'].' -u'.$param['db_user'].' -p'.$param['db_password'].' '.$param['db_name'].' ';
            if(isset($param['exclude_table'])) {
                if(is_array($param['exclude_table'])) {
                    foreach($param['exclude_table'] as $table) {
                        $command .= '--ignore-table='.$param['db_name'].'.'.$table.' ';
                    }
                } elseif($param['exclude_table'] != '') {
                    $command .= '--ignore-table='.$param['db_name'].'.'.$param['exclude_table'].' ';
                }  
            }  
            $command .= ' | gzip > '.$param['file_path'];
        }
        //reads contained sql commands after unzipping file
        if($type === 'MYSQL') {
            //NB gunzip -c option keeps original .gz file
            $command = 'gunzip -c < '.$param['file_path'].' | '.
                       'mysql -h'.$param['db_host'].' -u'.$param['db_user'].' -p'.$param['db_password'].' '.$param['db_name'].' ';
        }  
        
        if($type === 'SOURCE') {
            if($param['root_path'] !== '') {
                $dir = $param['root_path'];
                //remove trailing '/'
                if(substr($dir,-1) === '/') $dir = substr($dir,0,-1);
                //-C flag changes directory
                $tar_path = '-C '.$dir.' .';
            } else {
                //compress everything in current directory/sub-directories: can use "." but "*" better
                $tar_path = '*';  
            }   
            
            $command = 'tar -czof '.$param['file_path'].' '.$tar_path.' ';
            
            foreach($param['exclude_dir'] as $dir) {
                //trailing "/" must be removed
                if(substr($dir,-1) === '/') $dir = substr($dir,0,-1);
                $command .= '--exclude "'.$dir.'" ';
            } 
        }  
        
        return $command;   
    }  
    
    //NB: backup a database independant of backup table to external source (generally not called directly from UI)
    public function backupAnyDatabase($param) {
        $html = '';
        
        if(!isset($param['db_host'])) $this->addError('No DB host specified');
        if(!isset($param['db_name'])) $this->addError('No DB name specified');
        if(!isset($param['db_user'])) $this->addError('No DB user specified');
        if(!isset($param['db_password'])) $this->addError('No DB password specified');
        if(!isset($param['exclude_table'])) $param['exclude_table'] = [];
        //NB: if directory specified must have trailing "/" included
        if(!isset($param['file_dir'])) {
            if($this->backup['source'] === 'LOCAL') {
                $param['file_dir'] = $this->path['backup'];
            } else {
                $param['file_dir'] = $this->path['temp'];
            }  
        }

        if($this->errors_found) {
            $html .= $this->viewMessages();
            return $html;
        }

        //construct file name depending on parameters
        if(!isset($param['file_name'])){
            if(!isset($param['name_suffix'])) $param['name_suffix'] = 'DATE';
            if(!isset($param['name_prefix'])) $param['name_prefix'] = $param['db_name'];
            
            if($param['name_suffix'] === 'DATE') $suffix = date('Y-m-d');
            //use these for rolling backups that overwrite previous ones as cycle repeats
            if($param['name_suffix'] === 'DAY') $suffix = 'd'.date('d'); //01-31
            if($param['name_suffix'] === 'WEEK') $suffix = 'w'.date('W'); //1-52
            if($param['name_suffix'] === 'MONTH') $suffix = 'm'.date('m'); //01-12
                        
            $file_name = $param['name_prefix'].'_'.$suffix.'.sql.gz';
        } else {
            $file_name = $param['file_name'].'.sql.gz';
        }
        
        $param['file_path'] = $this->path['base'].$param['file_dir'].$file_name;
        
        $command = $this->getCommand('MYSQLDUMP',$param);
        system($command,$return_val);
        if($return_val === false) {
            $this->addError('Error backing up database: '.$return_val);
        } 
        
        //move file to amazon if required
        if($this->backup['source'] === 'AMAZON' and !$this->errors_found) {
            $s3 = $this->getContainer('s3');

            $file_path = $this->path['base'].$this->path['temp'].$file_name;
            $file_size = filesize($param['file_path']);

            $s3_files[] = ['name'=>$file_name,'path'=>$param['file_path']];
            $s3->putFiles($s3_files,$error);
            if($error != '' ) $this->addError('Could NOT upload database backup file to Amazon S3!: '.$error);

            //remove file from temp upload processing directory
            if(!unlink($param['file_path'])) $this->addError('Could NOT remove backup file from temporary local directory!');
        }
        
        if(!$this->errors_found) $this->addMessage('SUCCESS backing up database: '.$file_name.' !');
        
        $html .= $this->viewMessages();
        
        return $html;   
    }  
    
    //NB: this incrementally backs up documents in a given directory to external source (generally not called directly from UI)
    public function backupFiles($type,$batch_no = 100,$param = []) {
        $error = '';
        $file_count = 0;
        $html = '';
        
        //unpack relevant parameters
        if(!isset($param['dir_path'])) $param['dir_path'] = $this->path['base'].$this->path['files'];

        if($type === 'FILE_DATE') {
            if(!isset($param['system_id'])) $param['system_id'] = 'BACKUP_DOC';
        } 
        if($type === 'FILE_DB') {
            if(!isset($param['system_id'])) $param['system_id'] = 'FILES';
            if(!isset($param['table'])) $param['table'] = 'files';
            if(!isset($param['backup_id'])) $param['backup_id'] = 'OK';
        }  
                
        //configure backup destination
        if($this->backup['source'] === 'AMAZON') {
            $param['backup_id'] = 'S3';
            $s3 = $this->getContainer('s3');
        }
        
        //backup files in a directory based on last backup time and file time
        if($type === 'FILE_DATE' and !$this->errors_found) {
            $system = $this->getContainer('system');
            $last_time = $system->getDefault($param['system_id'],0,'count');
            $last_file_time = $last_time;
            
            //now check for any new documents in upload directory\
            if(is_dir($param['dir_path']) == false) {
                $this->addError($param['dir_path'].' INVALID file directory to backup!');
            } else {  
                $backup_files = array();
                $dir = opendir($param['dir_path']);
                while(($file = readdir($dir)) !== false) {
                    $file_path = $param['dir_path'].'/'.$file;
                    //only interested in files within directory, not sub-directories or their files
                    if(!is_dir($file_path)) {
                        $file_time = filemtime($file_path);
                        //store files after last backup time in array for sorting by time
                        if($file_time > $last_time) $backup_files[$file] = $file_time;
                    }
                }
                closedir($dir);
                
                //now sort by filetime  and process backups up to batch number
                if(count($backup_files)) {
                    asort($backup_files,SORT_NUMERIC);
                    $batch_end = false;
                    $batch_files = [];
                    foreach($backup_files as $file => $file_time) {
                        //make sure that files with SAME timestamp as last_file_time in batch are included in this batch!
                        //as next batch run will look for timestamps > last_file_time ONLY. So file count may be slighty greater than batch no.
                        if($file_count >= $batch_no and $file_time !== $last_file_time) $batch_end = true;
                        
                        if($file_count < $batch_no or $batch_end === false) {
                            $file_count++;
                            
                            $batch_files[] = ['name'=>$file,'path'=>$param['dir_path'].'/'.$file];
                           
                            $html .= 'uploading : '.$file.'<br/>';
                            if($file_time > $last_file_time) $last_file_time = $file_time;
                        }
                        
                    }  
                } else {
                    $this->addMessage('NO files in['.$param['dir_path'].'] dated after last backup cut-off:'.date('Y-m-d',$last_time));
                }  
                
                if($this->backup['source'] === 'AMAZON' and $file_count > 0) {
                    $s3->putFiles($batch_files,$error);
                    if($error != '' ) $this->addError('Could NOT upload file backups to Amazon S3 storage: '.$error);
                }  
                                
                if(!$this->errors_found and $file_count > 0) {
                    $system->setDefault($param['system_id'],$last_file_time,'count');
                }
            }
        }       
     
        //backup files based on files table values
        if($type === 'FILE_DB' and !$this->errors_found) {
            //NB: This assumes files table has standard structure
            $sql = 'SELECT '.$this->file_cols['file_id'].','.$this->file_cols['file_name'].','.$this->file_cols['file_name_tn'].','.$this->file_cols['file_name_orig'].' '.
                   'FROM '.$param['table'].' WHERE '.$this->file_cols['file_backup'].' = "" '.
                   'ORDER BY '.$this->file_cols['file_id'].' LIMIT '.$batch_no;
            $files = $this->db->readSqlArray($sql);
            if($files != 0) {
                if($this->backup['source'] === 'AMAZON') {
                    $s3 = $this->getContainer('s3');
                    $batch_files = [];
                    foreach($files as $file) {
                        $file_count++;
                        $html .= 'uploading : '.$file['file_name'].'<br/>';
                        $batch_files[] = ['name'=>$file['file_name'],'path'=>$param['dir_path'].$file['file_name']];
                        if($file['file_name_tn']!='') {
                            $file_count++;
                            $html.='uploading : '.$file['file_name_tn'].'<br/>';
                            $batch_files[] = ['name'=>$file['file_name_tn'],'path'=>$param['dir_path'].$file['file_name_tn']];
                        } 
                    }  
                    
                    $s3->putFiles($batch_files,$error);
                    if($error != '' ) $this->addError('Could NOT upload file backups to Amazon S3 storage: '.$error);
                }  
                
                //set file backup details in db on success
                if(!$this->errors_found and $file_count > 0) {
                    foreach($files as $file_id => $file) {
                        $sql='UPDATE '.$param['table'].' SET '.$this->file_cols['file_backup'].' = "'.$param['backup_id'].'" '.
                             'WHERE '.$this->file_cols['file_id'].' = "'.$this->db->escapeSql($file_id).'" ';
                        $this->db->executeSql($sql,$error);
                        if($error!='') $this->addError('Could not update file ID['.$file_id.'] for backup.'); 
                    } 
                }  
            }
        }  
        
        if(!$this->errors_found and $file_count > 0) $this->addMessage('SUCCESS backing up '.$file_count.' files!');
        
        $html .= $this->viewMessages();
        
        return $html;   
    }
    
    //NB: fetches a file from external backup source (generally not called directly from UI)
    public function restoreFile($type,$file_id,$param = array()) {
        $error = '';
        $html = '';
        
        if(!isset($param['dir_path'])) $param['dir_path'] = $this->path['base'].$this->path['files'];
        if(!isset($param['table'])) $param['table'] = 'files';
        
        //configure backup destination
        if($this->backup['source'] === 'AMAZON') {
            $s3 = $this->getContainer('s3');
            $batch_files = [];        
        }
        
        if($type === 'FILE_DB' and !$this->errors_found) {   
            //NB: This assumes files table has standard structure
            $sql='SELECT * FROM '.$param['table'].' '.
                 'WHERE '.$this->file_cols['file_id'].' = "'.$this->db->escapeSql($file_id).'" ';
            $file = $this->db->readSqlRecord($sql);
            if($file == 0) {
                $this->addError('File ID['.$file_id.'] does not exist!');
            } else {  
                if($this->backup['source'] === 'AMAZON') {
                    $file_name = $file[$this->file_cols['file_name']];
                    $file_path = $param['dir_path'].$file_name;

                    $s3->getFile($file_name,$file_path,$error);
                    if($error != '') $this->addError('Could not retrieve file['.$file_name.'] from Amazon S3');

                    //get thumbnail if applicable
                    if($file['file_name_tn']!='') {
                        $file_name = $file['file_name_tn'];
                        $file_path = $param['dir_path'].$file_name;

                        $s3->getFile($file_name,$file_path,$error);
                        if($error != '') $this->addError('Could not retrieve file['.$file_name.'] thumbnail from Amazon S3');
                    }
                } 
            }
        }  
     
        if($type === 'FILE_NAME' and !$this->errors_found) { 
            if($this->backup['source'] === 'AMAZON') {
                $file_name = $file_id;
                $file_path = $param['dir_path'].$file_name;

                $s3->getFile($file_name,$file_path,$error);
                if($error != '') $this->addError('Could not retrieve file['.$file_name.'] from Amazon S3');
            }    
            
        }  
        
        if(!$this->errors_found) {
            $this->addMessage('SUCCESS restoring file['.$file_name.']!'); 
        }    
        
        $html .= $this->viewMessages();
        return $html; 
    } 
    
}

?>
