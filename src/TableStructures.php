<?php
namespace Seriti\Tools;

//standard table structures that are refernced in multiple classes, all cols MUST exist
//NB: modify value but NOT key if you wish to use different table field names 
trait TableStructures 
{
     
    protected $system_cols = ['id'=>'system_id',                //STRING, NOT auto increment, PRIMARY KEY
                              'count'=>'sys_count',             //INTEGER
                              'text'=>'sys_text'                //TEXT
                              ];         

    protected  $file_cols = ['file_id'=>'file_id',               //INTEGER, NOT auto increment, PRIMARY KEY
                             'file_name'=>'file_name',           //STRING
                             'file_name_tn'=>'file_name_tn',     //STRING
                             'file_name_orig'=>'file_name_orig', //STRING
                             'file_text'=>'file_text',           //TEXT
                             'file_date'=>'file_date',           //DATETIME
                             'file_size'=>'file_size',           //INTEGER
                             'encrypted'=>'encrypted',           //BOOLEAN or TINYINT
                             'file_ext'=>'file_ext',             //STRING
                             'file_type'=>'file_type',           //STRING
                             'location_id'=>'location_id',       //STRING, Indexed, NOT unique
                             'location_rank'=>'location_rank'    //INTEGER
                            ];

    protected $tree_cols = ['node'=>'id',                       //INTEGER, auto increment, PRIMARY KEY
                            'parent'=>'id_parent',              //INTEGER
                            'title'=>'title',                   //STRING
                            'level'=>'level',                   //INTEGER
                            'lineage'=>'lineage',               //STRING 
                            'rank'=>'rank',                     //INTEGER
                            'rank_end'=>'rank_end'];            //INTEGER

    protected $audit_cols = ['id'=>'audit_id',                  //INTEGER, auto increment, PRIMARY KEY
                             'user_id'=>'user_id',              //INTEGER
                             'date'=>'date',                    //DATETIME
                             'action'=>'action',                //STRING
                             'text'=>'text',                    //TEXT
                             'data'=>'data',                    //TEXT
                             'link'=>'link_table',              //STRING
                             'link_id'=>'link_id'               //INTEGER                     
                            ];

    protected $user_cols =  ['id'=>'user_id',                               //INTEGER, auto increment, PRIMARY KEY
                             'name'=>'name',                                //STRING
                             'access'=>'access',                            //STRING "GOD,ADMIN,USER,VIEW"
                             'zone'=>'zone',                                //STRING "ALL,PUBLIC..."
                             'password'=>'password',                        //STRING
                             'email'=>'email',                              //STRING
                             'pwd_date'=>'pwd_date',                        //DATE
                             'pwd_salt'=>'pwd_salt',                        //STRING
                             'login_fail'=>'login_fail',                    //INTEGER
                             'status'=>'status',                            //STRING
                             'email_token'=>'email_token',                  //STRING
                             'email_token_expire'=>'email_token_expire',    //DATE
                             'csrf_token'=>'csrf_token'                     //STRING                       
                            ];

    protected $cache_cols =  ['id'=>'cache_id',                 //STRING, PRIMARY KEY
                             'data'=>'data',                    //LONGTEXT
                             'date'=>'date'                     //DATE
                             ];

    protected $token_cols = ['token'=>'token',                  //STRING, PRIMARY KEY
                             'user_id'=>'user_id',              //INTEGER
                             'date_expire'=>'date_expire'       //DATETIME
                             ];


    protected $queue_cols = ['id'=>'queue_id',                      //INTEGER, auto increment, PRIMARY KEY
                             'process_id'=>'process_id',            //STRING
                             'process_key'=>'process_key',          //STRING
                             'process_data'=>'process_data',        //TEXT
                             'date_create'=>'date_create',          //DATETIME
                             'date_process'=>'date_process',        //DATETIME
                             'process_status'=>'process_status',    //STRING
                             'process_result'=>'process_result',    //TEXT
                             'process_complete'=>'process_complete' //BOOLEAN or TINYINT
                            ];

    protected $backup_cols = ['id'=>'backup_id',                     //INTEGER, auto increment, PRIMARY KEY
                              'date'=>'date',                        //DATE
                              'type'=>'type',                        //STRING
                              'comment'=>'comment',                  //TEXT
                              'file_name'=>'file_name',              //STRING
                              'file_size'=>'file_size',              //INTEGER
                              'status'=>'status'                     //STRING
                             ];

    //NB these cols are in ADDITION to standard tree cols
    protected $menu_cols = ['type'=>'menu_type',                    //STRING
                            'route'=>'menu_link',                   //STRING
                            'access'=>'menu_access',                //STRING
                            'mode'=>'link_mode',                    //STRING
                           ];            

}
