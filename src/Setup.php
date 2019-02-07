<?php
namespace Seriti\Tools;

use Seriti\Tools\Mysql;

class Setup 
{

    protected $config;
    protected $error = []; 
    protected $message = []; 
    protected $db;           
    
    public function __construct(Config $config) 
    {
        $this->message[] = 'Setup class constructed';

        $this->config = $config;

        $this->checkMysql();
    }
    
    protected function checkMysql()
    {
        $error_tmp = '';

        if(!$param = $this->config->get('db')) {
            $this->error[] = 'NO Config settings for Database[db]';
        } else {
            if(!isset($param['host']) or $param['host'] == '') $this->error[] = "No ['db']['host'] specified"; 
            if(!isset($param['name']) or $param['name'] == '') $this->error[] = "No ['db']['name'] specified"; 
            if(!isset($param['user']) or $param['user'] == '') $this->error[] = "No ['db']['user`'] specified"; 
            if(!isset($param['password']) or $param['password'] == '') $this->error[] = "No ['db']['pwd'] specified"; 
        }

        if(!count($this->error)) {
            $param['audit'] = false;
            $this->db = new Mysql($param);

            $sql = 'SHOW TABLES ';
            $tables = $this->db->readSqlList($sql);

            if(!in_array(TABLE_SYSTEM,$tables)) $this->setupSystemTable(TABLE_SYSTEM); else $this->message[]='System table['.TABLE_SYSTEM.'] exists';
            if(!in_array(TABLE_MENU,$tables)) $this->setupMenuTable(TABLE_MENU); else $this->message[]='Menu table['.TABLE_MENU.'] exists';
            if(!in_array(TABLE_AUDIT,$tables)) $this->setupAuditTable(TABLE_AUDIT); else $this->message[]='Audit table['.TABLE_AUDIT.'] exists';
            if(!in_array(TABLE_FILE,$tables)) $this->setupFileTable(TABLE_FILE); else $this->message[]='File table['.TABLE_FILE.'] exists';
            if(!in_array(TABLE_USER,$tables)) $this->setupUserTable(TABLE_USER); else $this->message[]='User table['.TABLE_USER.'] exists';
            if(!in_array(TABLE_QUEUE,$tables)) $this->setupQueueTable(TABLE_QUEUE); else $this->message[]='Queue table['.TABLE_QUEUE.'] exists';
            if(!in_array(TABLE_BACKUP,$tables)) $this->setupBackupTable(TABLE_BACKUP); else $this->message[]='Queue table['.TABLE_BACKUP.'] exists';
         
        }
    }  


    protected function setupSystemTable($table)
    {
      
        $sql = 'CREATE TABLE `'.$table.'` (
               `system_id` varchar(64) NOT NULL DEFAULT "",
               `sys_count` bigint(20) unsigned NOT NULL DEFAULT 0,
               `sys_text` text NOT NULL,
                PRIMARY KEY (`system_id`)
                ) ENGINE=MyISAM ';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing system table['.$table.']';

            $sql = 'INSERT INTO `'.$table.'` VALUES("FILES",100,"") ';
            $this->db->executeSql($sql,$error_tmp); 
            if($error_tmp == '') {
                $this->message[] = 'Succesfully created initial system table values';
            } else {
                $this->error[] = 'Could NOT create system initial values : '.$error_tmp;
            } 
        } else {
            $this->error[] = 'Could NOT create system table['.$table.'] : '.$error_tmp;
        } 
    }

    protected function setupMenuTable($table)
    {
      
        $sql = 'CREATE TABLE `'.$table.'` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `id_parent` int(11) NOT NULL,
                  `title` varchar(255) NOT NULL,
                  `level` int(11) NOT NULL,
                  `lineage` varchar(255) NOT NULL,
                  `rank` int(11) NOT NULL,
                  `rank_end` int(11) NOT NULL,
                  `menu_type` varchar(64) NOT NULL,
                  `menu_link` varchar(255) NOT NULL,
                  `menu_access` varchar(64) NOT NULL,
                  `link_mode` varchar(64) NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing custom menu table['.$table.']';


            $sql = 'INSERT INTO `'.$table.'` (id_parent,title,level,lineage,menu_link,menu_type,menu_access) '.
                   'VALUES("0","Dashboard","1","","admin/dashboard","LINK_SYSTEM","VIEW")';
            $this->db->executeSql($sql,$error_tmp); 
            if($error_tmp == '') {
                $this->message[] = 'Succesfully created initial menutable values';
            } else {
                $this->error[] = 'Could NOT create menu initial values : '.$error_tmp;
            } 
        } else {
            $this->error[] = 'Could NOT create menu table['.$table.'] : '.$error_tmp;
        } 
    }

    protected function setupUserTable($table)
    {
        
        $sql = 'CREATE TABLE `'.$table.'` (
                  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                  `name` varchar(64) NOT NULL,
                  `access` varchar(64) NOT NULL,
                  `password` varchar(250) NOT NULL,
                  `email` varchar(250) NOT NULL,
                  `pwd_date` date NOT NULL,
                  `pwd_salt` varchar(250) NOT NULL,
                  `login_fail` int(10) unsigned NOT NULL DEFAULT 0,
                  `status` varchar(64) NOT NULL DEFAULT "OK",
                  `email_token` varchar(64) NOT NULL DEFAULT "",
                  `login_token` varchar(64) NOT NULL DEFAULT "",
                  `login_expire` date NOT NULL DEFAULT "2000-01-01",
                  `login_alt_token` varchar(64) NOT NULL DEFAULT "",
                  `login_alt_expire` date NOT NULL DEFAULT "2000-01-01",
                  `csrf_token` varchar(64) NOT NULL DEFAULT "",
                  PRIMARY KEY (`user_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing system table['.$table.']';
            //pwd = SUNFLOWER, ALWAYS CHANGE AFTER SETUP!!!
            $sql = 'INSERT INTO `'.$table.'` (name,access,password,email,pwd_date,pwd_salt) '.
                   'VALUES("Mark","GOD","$5$fe9cf8aed270072f$xU3O4YT52inJe.c8m7.JngvpXJ4ruH8YWNqRy6PN5EC","mark@seriti.com",
                   "2019-01-01","fe9cf8aed270072f267a89052d4d42c1") ';
            $this->db->executeSql($sql,$error_tmp); 
            if($error_tmp == '') {
                $this->message[] = 'Succesfully created initial user table values';
            } else {
                $this->error[] = 'Could NOT create user initial values : '.$error_tmp;
            }   
        } else {
            $this->error[] = 'Could NOT create user table['.$table.'] : '.$error_tmp;
        }   
    }

    protected function setupAuditTable($table)
    {
      
        $sql = 'CREATE TABLE `'.$table.'` (
                  `audit_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                  `user_id` int(10) NOT NULL,
                  `date` datetime NOT NULL,
                  `action` varchar(250) NOT NULL DEFAULT "",
                  `text` text NOT NULL,
                  PRIMARY KEY (`audit_id`)
                ) ENGINE=MyISAM ';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing audit table['.$table.']';
        } else {
            $this->error[] = 'Could NOT create audit table['.$table.'] : '.$error_tmp;
        } 
    }

    protected function setupFileTable($table)
    {
      
      $sql = 'CREATE TABLE `'.$table.'` (
                  `file_id` int(10) unsigned NOT NULL,
                  `title` varchar(255) NOT NULL  DEFAULT "",
                  `file_name` varchar(255) NOT NULL,
                  `file_name_orig` varchar(255) NOT NULL,
                  `file_text` longtext NOT NULL,
                  `file_date` date NOT NULL,
                  `location_id` varchar(64) NOT NULL DEFAULT "",
                  `location_rank` int(11) NOT NULL,
                  `key_words` text,
                  `description` text ,
                  `file_size` int(11) NOT NULL,
                  `encrypted` tinyint(1) NOT NULL DEFAULT "0",
                  `file_name_tn` varchar(255) NOT NULL DEFAULT "",
                  `file_ext` varchar(16) NOT NULL,
                  `file_type` varchar(64) NOT NULL,
                  PRIMARY KEY (`file_id`),
                  FULLTEXT KEY `search_idx` (`key_words`)
              ) ENGINE=MyISAM ';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing file table['.$table.']';
        } else {
            $this->error[] = 'Could NOT create file table['.$table.'] : '.$error_tmp;
        } 
    }

    protected function setupQueueTable($table)
    {
        
        $sql = 'CREATE TABLE `'.$table.'` (
                  `queue_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                  `process_id` varchar(64) NOT NULL,
                  `process_key` varchar(64) NOT NULL,
                  `process_data` text,
                  `date_create` datetime NOT NULL,
                  `date_process` datetime NOT NULL DEFAULT "2000-01-01",
                  `process_status` varchar(64) NOT NULL,
                  `process_result` text,
                  `process_complete` tinyint(1) NOT NULL DEFAULT "0",
                  PRIMARY KEY (`queue_id`)
                ) ENGINE=MyISAM ';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing queue table['.$table.']';
            
        } else {
            $this->error[] = 'Could NOT create queue table['.$table.'] : '.$error_tmp;
        }   
    }

    protected function setupBackupTable($table)
    {
        
        $sql = 'CREATE TABLE `'.$table.'` (
                  `backup_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                  `type` varchar(64) NOT NULL,
                  `comment`  text,
                  `file_name` varchar(250) NOT NULL,
                  `file_size` INT(11),
                  `status` varchar(64) NOT NULL,
                  PRIMARY KEY (`backup_id`)
                ) ENGINE=MyISAM ';

        $this->db->executeSql($sql,$error_tmp); 
        if($error_tmp == '') {
            $this->message[] = 'Succesfully created missing backup table['.$table.']';
            
        } else {
            $this->error[] = 'Could NOT create backup table['.$table.'] : '.$error_tmp;
        }   
    }

    public function viewOutput() 
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"/>'.
                  '<title>SERITI SETUP</title></head><body>';

        $html .= '<h1>Errors:</h1><ul>';
        foreach($this->error as $txt) $html .= '<li>'.$txt.'</li>';
        $html .= '</ul>';
          
        $html .= '<h1>Messages:</h1><ul>';
        foreach($this->message as $txt) $html .= '<li>'.$txt.'</li>';
        $html .= '</ul>';

        $html .= '</body></html>';

        return $html; 
    }

  

}


  
?>
