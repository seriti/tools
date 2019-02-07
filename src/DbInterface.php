<?php
namespace Seriti\Tools;


interface DbInterface 
{
       
    /*return search $value stripped of <,>,<>,=,<=,>=,*," operators 
    which are translated into equivalent SQL commands
    $sql['prefix'] = '';
    $sql['suffix'] = '';
    $sql['operator'] = '';
    */
    function parseSearchTerm(&$value,&$sql = array());
    
    //returns array of FieldID=>Value or 0 if no matching record
    //$where is array of [col1=>val,col2=>val] required constraints
    function getRecord($table,$where = array());
    
    //return true/false and error string
    function deleteRecord($table,$where = array(),&$error);
    
    //return true/false and error string
    function updateRecord($table,$rec = array(),$where = array(),&$error);
    
    //return true/false and error string
    function insertRecord($table,$rec = array(),&$error) ;
    
    //return true/false     
    function checkTableExists($table);
    
    //return num_affected_records or false and error string     
    function executeSql($sql,&$error);

    //return value or $no_value
    function readSqlValue($sql,$no_value = 0);
    
    /*return [n]=col numeric indexed array of values if single col in SQL 
      returns [first_col]=second_col array of values if more than 1 col
      returns 0 if no result from SQL
    */
    function readSqlList($sql); 
    
    /*return [n]=col numeric indexed array of values if single col in SQL 
      returns [first_col]=[col1=>val,col2=>val....] array of values 
      returns [n]=[col1=>val,col2=>val....] array of values if first_col_key = false 
      returns 0 if no result from SQL
    */
    function readSqlArray($sql,$first_col_key = true);
    
    //returns array of FieldID=>Value or 0 if no result
    function readSqlRecord($sql);

    //return true/false
    function closeMysql();
    
    //returns escaped $value
    function escapeSql($value);
}
?>
