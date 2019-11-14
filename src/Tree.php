<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Mysql;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Csv;
use Seriti\Tools\Doc;
use Seriti\Tools\Image;
use Seriti\Tools\Audit;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\ModelViews;
use Seriti\Tools\ModelHelpers;
use Seriti\Tools\ContainerHelpers;
use Seriti\Tools\SecurityHelpers;
use Seriti\Tools\TableStructures;

use Psr\Container\ContainerInterface;

//NB1: $tree_cols must all exist in table for this class to work
//NB2: Class intended to maintain small limited trees like folder structure / menus / categories
class Tree extends Model 
{
    use IconsClassesLinks; 
    use ModelViews;
    use ModelHelpers; 
    use ContainerHelpers;
    use SecurityHelpers;
    use TableStructures;

    protected $container;
    protected $container_allow = ['config','s3','mail','user','system'];

    protected $col_label = '';
    protected $nav_show = 'TOP_BOTTOM'; //can be TOP or BOTTOM or TOP_BOTTOM or NONE
    protected $show_info = false;
    protected $mode = 'list_all';
    protected $add_href = '';//change add nav link to some external page or wizard

    protected $pop_up = false;
    protected $update_calling_page = false; //update calling page if changes made in pop_up
    protected $add_repeat = false; //set to true to continue adding nodes after submit rather than show list view
    protected $data = array(); //use to store current edit/view data between public function calls 
    protected $data_xtra = array(); //use to store arbitrary xtra data between function calls 
    
    protected $location = '';
    protected $row_name = 'node';
    protected $row_count;
    protected $row_name_plural = '';
    protected $order_by = '';
    protected $excel_csv = true;
    protected $node_original = array();
    protected $actions = array();
    protected $show_search = false;
    protected $images = array();
    protected $image_upload = false;
    protected $files = array();
    protected $file_upload = false;

    protected $user_access_level;
    protected $user_id;
    protected $user_csrf_token;
    protected $csrf_token  = '';

    protected $tree_type = ''; //'JQuery';
   
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
        
        //implemented locally

        //*** standard Table class parameters ***
        $this->dates['new'] = date('Y-m-d');
        if(isset($param['location'])) $this->location = $param['location']; //Could use URL_CLEAN_LAST

        if(isset($param['row_name'])) $this->row_name = $param['row_name'];
        if(isset($param['row_name_plural'])) {
            $this->row_name_plural = $param['row_name_plural'];
        } else {
            $this->row_name_plural = $this->row_name.'s';
        }    
        if(isset($param['nav_show']))  $this->nav_show = $param['nav_show'];
        if(isset($param['col_label'])) $this->col_label = $param['col_label'];
        if(isset($param['show_info'])) $this->show_info = $param['show_info'];
        if(isset($param['pop_up'])) {
            $this->pop_up = $param['pop_up'];
            if(isset($param['update_calling_page'])) $this->update_calling_page = $param['update_calling_page'];
        }  
        if(isset($param['excel_csv'])) $this->excel_csv = $param['excel_csv'];
        
        //add all standard tree cols which MUST exist, 
        $this->addTreeCol(['id'=>$this->tree_cols['node'],'title'=>'Node','type'=>'INTEGER','key'=>true,'key_auto'=>true,'list'=>false]);
        
        $join = $this->tree_cols['title'].' FROM '.$this->table.' WHERE '.$this->tree_cols['node'];
        $this->addTreeCol(['id'=>$this->tree_cols['parent'],'title'=>'Parent','type'=>'INTEGER','join'=>$join]);
        
        $this->addTreeCol(['id'=>$this->tree_cols['title'],'title'=>'Title','type'=>'STRING']);
        $this->addTreeCol(['id'=>$this->tree_cols['level'],'title'=>'Level','type'=>'INTEGER','edit'=>false]);
        $this->addTreeCol(['id'=>$this->tree_cols['lineage'],'title'=>'Lineage','type'=>'STRING','edit'=>false]);
        $this->addTreeCol(['id'=>$this->tree_cols['rank'],'title'=>'Rank','type'=>'INTEGER','edit'=>false]);
        $this->addTreeCol(['id'=>$this->tree_cols['rank_end'],'title'=>'Rank END','type'=>'INTEGER','edit'=>false]);

        //for dropdown list of node parent
        $select['xtra'] = array('0'=>'TREE ROOT');
        $select['sql'] = 'SELECT '.$this->tree_cols['node'].','.
                         'CONCAT(IF('.$this->tree_cols['level'].' > 1,REPEAT("--",'.$this->tree_cols['level'].' - 1),""),'.$this->tree_cols['title'].') '.
                         'FROM '.$this->table.'  ORDER BY '.$this->tree_cols['rank'];
        $this->addSelect($this->tree_cols['parent'],$select);

        //If you change this then nothing will work
        $this->order_by = $this->tree_cols['rank'];

        $this->user_access_level = $this->getContainer('user')->getAccessLevel();
        $this->user_id = $this->getContainer('user')->getId();
        $this->user_csrf_token = $this->getContainer('user')->getCsrfToken();
        $this->setupAccess($this->user_access_level);

    }
    
    
    public function processTree() 
    {
        $html='';
        $id=0;
        $param=array();
        $form=array();

        $this->csrf_token = Secure::clean('basic',Form::getVariable('csrf_token','GP'));

        if(isset($_GET['mode'])) $this->mode=Secure::clean('basic',$_GET['mode']);
        if(isset($_GET['id'])) $id=Secure::clean('basic',$_GET['id']); 
        if(isset($_POST['id'])) $id=Secure::clean('basic',$_POST['id']);
        $this->key['value']=$id;
     
        if(isset($_GET['msg'])) $this->addMessage(Secure::clean('alpha',$_GET['msg']));
     
        if($this->mode=='list_all') {
            $this->setCache('sql','');
            $this->mode='list';
            
            if($this->child and $id!==0) $this->setCache('master_id',$id);
        } 
 
        if($this->child) {
            $this->master['key_val'] = $this->getCache('master_id');
            if($this->master['key_val'] === '') {
                throw new Exception('MASTER_TABLE_ERROR: Linked '.$this->row_name.' id unknown!');
            }
        } else {
            $this->master['key_val'] = 0;
        } 
    
        $this->beforeProcess($id);    
        
        if($this->mode=='excel') {
            if(isset($_GET['cols'])) $param['cols']=Secure::clean('basic',$_GET['cols']); 
            if(!$this->dumpData('excel',$param)) $this->mode = 'list';
        } 

        if($this->mode=='list')   $html.=$this->viewTree($param);
        //if($this->mode=='view')   $html.=$this->viewRecord($id);
        if($this->access['read_only']===false) {
            if($this->mode=='edit')   $html.=$this->viewEdit($id,$form,'UPDATE');
            if($this->mode=='add')    $html.=$this->viewEdit($id,$form,'INSERT');
            if($this->mode=='update') $html.=$this->updateNode($id,$_POST);
            if($this->mode=='delete') $html.=$this->deleteNode($id,'SINGLE');
        }  
        if($this->mode=='search') $html.=$this->search();
         
        if($this->mode=='custom') $html.=$this->processCustom($id);
                                
        return $html;
    }

    public function addTreeCol($col) 
    {
        $col = $this->addCol($col);
        $col = $this->setupCol($col); 

        $this->cols[$col['id']]=$col;
    }

    protected function setupCol($col) 
    {
        //table display parameters
        if(!isset($col['edit_title'])) $col['edit_title']=$col['title'];
        if(!isset($col['class'])) $col['class']='';
        if(!isset($col['class_search'])) $col['class_search']=$col['class'];
        if(!isset($col['new'])) $col['new']='';
        if(!isset($col['hint'])) $col['hint']='';
         
         //assign type specific settings and defaults if not set
        if($col['type']=='DATE') {
            if(!isset($col['format'])) $col['format']='DD-MMM-YYYY';
        }
         
        if($col['type']=='DATETIME') {
            if(!isset($col['format'])) $col['format']='DD-MMM-YYYY HH:MM';
        }
         
        if($col['type']=='TIME') {
            if(!isset($col['format'])) $col['format']='HH:MM';
        }
         
        if($col['type']=='TEXT') {
            if(!isset($col['encode'])) $col['encode']=true;
            if(!isset($col['rows'])) $col['rows']=5;
            if(!isset($col['cols'])) $col['cols']=50;
        }
                 
        //assign column value to use in row level warnings and messages if not already set
        if($this->col_label === '') $this->col_label=$col['id'];
        
        return $col;
    }

    protected function updateNode($id,$form)  
    {
        $html = '';

        if(!$this->verifyCsrfToken($error)) $this->addError($error);
                
        $edit_type = $form['edit_type'];
        if($edit_type !== 'UPDATE' and $edit_type !== 'INSERT') {
           $this->addError('Cannot determine if UPDATE or INSERT!'); 
        }

        if(!$this->errors_found) {
            if($edit_type === 'INSERT') {
                $output = $this->create($form);
                if($output['status'] === 'OK') $id = $output['id'];
            }
            if($edit_type === 'UPDATE') {
                $output = $this->update($id,$form);
            }
        }    
        
        if($this->errors_found) {
            $html .= $this->viewEdit($id,$form,$edit_type);
        } else {
            if($this->pop_up) $this->setCache('popup_updated',true);
            
            if($this->add_repeat and $edit_type === 'INSERT') {
                $form = array();
                $this->addMessage('Successfully added '.$this->row_name.'! Please continue capturing '.$this->row_name_plural);
                $html .= $this->viewEdit('0',$form,'INSERT');
            } else {  
                $location = $this->location.'?mode=list'.$this->linkState().
                                    '&msg='.urlencode('successful '.$edit_type.' of '.$this->row_name);
                $location = Secure::clean('header',$location);
                header('location: '.$location);
                exit;
            }  
        }   
        
        return $html;
    }

    //called from Model class
    protected function beforeUpdate($id,$edit_type,&$form,&$error) 
    {
        if($edit_type === 'UPDATE' ) $this->node_original = $this->getNode($id);
    }

    //called from Model class
    protected function afterUpdate($id,$edit_type,$form) 
    {
        if(!$this->errors_found) {

            $node_id = $id;
            $node_id_parent = $form[$this->tree_cols['parent']];

            $parent_node_rank = Secure::clean('integer',$_POST['parent_node_rank']);

            $rebuild_ranking = false;
            if($edit_type === 'UPDATE' and $this->node_original['parent'] != $node_id_parent) $rebuild_ranking = true;
            if($edit_type === 'INSERT' or $parent_node_rank > 0) $rebuild_ranking = true;
           
            if($rebuild_ranking) {
                $node_parent = $this->getNode($node_id_parent);
                $node_level = $node_parent['level']+1;
                
                //calculate new ranking based on desired position within parent node
                $sql = 'SELECT '.$this->tree_cols['rank'].' AS rank FROM '.$this->table.' '.
                       'WHERE '.$this->tree_cols['parent'].' = "'.$node_id_parent.'" '.
                       'ORDER BY '.$this->tree_cols['rank'].' ';
                $rank_list = $this->db->readSqlList($sql);
                $node_count = count($rank_list);
                if($node_count == 1) {
                    $node_rank = $node_parent['rank']+1;
                } elseif($parent_node_rank>0 and $parent_node_rank<$node_count) { 
                    if($edit_type === 'INSERT') {
                        //INSERT: first item in rank_list will be new record as rank = 0
                        $node_rank=$rank_list[$parent_node_rank];
                    } else {
                        //UPDATE: parent node rank
                        if($this->node_original['rank'] < $rank_list[$parent_node_rank-1]) {
                            $node_rank = $rank_list[$parent_node_rank];
                        } else {
                            $node_rank = $rank_list[$parent_node_rank-1];
                        } 
                    }
                  
                } else {
                    //add to end of nodes under parent
                    $node_rank = $rank_list[$node_count-1];
                }
                
                //to insert after node instead of before because last child under parent
                if($node_rank == $rank_list[$node_count-1]) $node_rank++; 
                
                //increment ALL nodes ranking after calculated node rank
                $sql = 'UPDATE '.$this->table.' '.
                       'SET '.$this->tree_cols['rank'].' = '.$this->tree_cols['rank'].' + 1 '.
                       'WHERE '.$this->tree_cols['rank'].' >= '.$node_rank.' ';
                $this->db->executeSql($sql,$error_tmp);
                if($error_tmp != '') $this->addError('update incrementing ranking: '.$error_tmp);
                        
                //Finally update node record
                $sql = 'UPDATE '.$this->table.' '.
                       'SET '.$this->tree_cols['level'].' = "'.$this->db->escapeSql($node_level).'", '.
                              $this->tree_cols['rank'].' = "'.$node_rank.'" '.
                       'WHERE '.$this->tree_cols['node'].' = "'.$node_id.'" ';
                $this->db->executeSql($sql,$error_tmp);
                if($error_tmp != '') $this->addError('update new ranking error: '.$error_tmp);
                
                //crawl and rebuild entire tree  
                $temp_rank = 0;
                $this->crawlNode('0',$temp_rank);
                $this->updateNodeEnd();
                $this->updateNodeLineage();
            } 
        }
    }  

    protected function deleteNode($id,$type = 'SINGLE') 
    {
        $html = '';
        $data = '';
        $error = '';
        
        if(!$this->verifyCsrfToken($error)) $this->addError($error);

        if(!$this->errors_found) {
            $node = $this->getNode($id);

            //check NO child nodes before delete
            $sql = 'SELECT COUNT(*) FROM '.$this->table.' '.
                   'WHERE '.$this->tree_cols['parent'].' = "'.$this->db->escapeSql($id).'" ';
            $count = $this->db->readSqlValue($sql,0);
            if($count > 0) {
                $error = 'You cannot delete node['.$node['title'].'] as you will create orphaned child nodes!'.
                         'Please move or delete ['.$count.'] child nodes first!';
                if($this->debug) $error .= ' Node ID['.$id.']';
                $this->addError($error);
            }
        }    

        if(!$this->errors_found) {
            $this->delete($id);
        }    
                
        //delete any images/files or other data associated with record. 
        if(!$this->errors_found) { 
        } 
        
        //NB: if multiple nodes deleted then this must be called by custom code
        if(!$this->errors_found and $type === 'SINGLE') {         
            $temp_rank = 0;
            $this->crawlNode('0',$temp_rank);
            $this->updateNodeEnd();
            $this->updateNodeLineage();
        }

        if($this->errors_found) {
            if($type === 'SINGLE') {
                $this->mode = 'list';
                $html .= $this->viewTree();
            } elseif($type ==='MULTIPLE') {
                $html .= 'ERROR: ['.$this->viewMessages().']';
            }
        } else {
            if($this->pop_up) $this->setCache('popup_updated',true);
            
            if($type === 'SINGLE') {
                $location = $this->location.'?mode=list'.$this->linkState().
                            '&msg='.urlencode('successfully deleted '.$this->row_name.' '.$node['title']);
                $location = Secure::clean('header',$location);
                header('location: '.$location);
                exit;
            } elseif($type === 'MULTIPLE') {
                $html .= $this->row_name.'['.$node['title'].']';
            }
        }   
        
        return $html;
    } 

    public function viewTree($param = array()) 
    {
        $count = $this->count();
        $this->row_count = $count['row_count'];
              
        $html = '';

        if($this->row_count == 0)  {
            $form = [];
            $this->addMessage('No '.$this->row_name.' data exists! Please add your first '.$this->row_name);
            $html .= $this->viewEdit('0',$form,'INSERT');
            return $html;
        }

        $html .= $this->viewMessages();

        if($this->show_info) $info = $this->viewInfo('LIST'); else $info = '';
        
        $nav = $this->viewNavigation('TREE'); 
                        
        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;//'<p align="center">'.$nav.'</p>';
        if($info != '') $html .= $info;
        
        $html .= '<div id="list_div">'; 

        $html .= '<div id="tree_div">'.$this->viewTreeList().'</div>'; 
        $html .= '<div id="node_div">'.$this->viewTreeInfo().'</div>';
        
        $html .= '</div>';
        
        if(strpos($this->nav_show,'BOTTOM' !== false and $this->row_count>10)) $html .= $nav;
        
        return $html;
    }

    public function viewTreeList($param = array()) 
    {

        $this->addSql('ORDER',$this->order_by);

        $tree = $this->list($param);

        $html = ''; 

        if($this->tree_type === 'JQuery' ) {
            $html .= '<div id="treecontrol_seriti_tree" style="display: block;">'.
                     '<a title="Collapse entire tree" href="#">'.$this->icons['collapse'].'Collapse</a>&nbsp;'.
                     '<a title="Expand entire tree" href="#">'.$this->icons['expand'].' Expand</a></div>'.
                     '<ul id="seriti_tree">';
        } else {
            $html .= '<ul>';
        }  

        $level = $this->tree_cols['level'];
        $old_level = 1;
        $num[$old_level] = 0;
        $num_str = '';
        foreach($tree as $node) {
            $level = $node[$this->tree_cols['level']];
            if($old_level != $level) {
                if($old_level < $level) {
                    $html .= '<ul>';
                    $num[$level] = 1;
                } else {
                    $html .= '</li>';
                    //close additional levels if any
                    $html .= str_repeat('</ul></li>',$old_level-$level);
                  
                    $num[$level]++;  
                    //unset($num[$old_level]);
                    for($l = $old_level; $l > $level; $l--) unset($num[$l]);
                }  
                
                $old_level = $level;
            } else {
                $html .= '</li>'; 
                $num[$level]++;
            }
            
            $node_num = implode('.',$num).') ';
            $html .= '<li>'.$node_num.$this->viewNode($node).'</li>';
        }
        $html .= '</ul>';
        
        return $html;
    }
    
    //public function viewNode($id,$name) 
    public function viewNode($data) 
    {
        $html = '';
        
        $id = $data[$this->tree_cols['node']];
        $name = $data[$this->tree_cols['title']];

        if($this->access['edit']) {
            $href = '?mode=edit&id='.$id;
        } else {
            $href = '#'; //view access is meaningless
        }

        $html .= '<a href="'.$href.'">'.$name.'</a>';
        
        $html .= '&nbsp;'.$this->viewActions($id,$name,'L');
        
        if($this->image_upload) $html .= $this->viewImages($data,'SUMMARY');
        if($this->file_upload) $html .= $this->viewFiles($data,'SUMMARY');
        
        return $html;
    }

    protected function viewEdit($id,$form=array(),$edit_type='UPDATE') 
    {
        $html = '';
        $html .= $this->viewMessages();

        if($this->show_info) $info = $this->viewInfo('EDIT'); else $info = '';
        
        $nav = $this->viewNavigation('TREE'); 
                        
        if(strpos($this->nav_show,'TOP') !== false) $html .= $nav;//'<p align="center">'.$nav.'</p>';
        if($info != '') $html .= $info;
        
        $html .= '<div id="list_div">'; 

        $html .= '<div id="tree_div">'.$this->viewTreeList().'</div>'; 
        $html .= '<div id="node_div">'.$this->viewEditNode($id,$form,$edit_type);
        $html .= '</div>'; 

        $html .= '</div>';
        
        if(strpos($this->nav_show,'BOTTOM' !== false and $this->row_count>10)) $html .= $nav;
        
        return $html;
    }

    protected function viewEditNode($id,$form,$edit_type) 
    {
        $html = '';
        $param = array();
        $parent_node_rank = '';

        $this->checkAccess($edit_type,$id);

        $data = $this->get($id);
        $this->data = $data;

        $class_label = 'class="'.$this->classes['col_label'].'"';
        $class_value = 'class="'.$this->classes['col_value'].'"';
        $class_submit= 'class="'.$this->classes['col_submit'].'"';
        
        $html .= '<div id="edit_div">';
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=update&id='.$id.'" name="update_form" id="update_form">';
        $html .= $this->formState();        
        //$html .= '<div class="container">';
        
        $html .= '<input type="hidden" name="edit_type" value="'.$edit_type.'">'; 
                                
        if($edit_type === 'UPDATE') {   
            $delete_link = '';
            if($this->access['delete']) {
                $onclick = 'onclick="javascript:return confirm(\'Are you sure you want to DELETE '.$this->row_name.'?\')" '; 
                $href = '?mode=delete&id='.$id.$this->linkState() ;
                $delete_link .= '<a class="action" href="'.$href.'" '.$onclick.'>(Delete)</a>&nbsp;&nbsp;'; 
            }  
                                 
            $html .= '<div class="row"><div '.$class_label.'>'.$delete_link.$this->key['title'].':</div>'.
                                                            '<div '.$class_value.'>'.
                         '<input type="hidden" name="'.$this->key['id'].'" value="'.$id.'"><b>'.$id.'</b>';
            if($this->access['copy']) {
                $html .= '&nbsp;&nbsp;(&nbsp;'.Form::checkbox('copy_record','YES',0).
                         '&nbsp;Create a new '.$this->row_name.' using displayed data? )';
            }    
            $html .= '</div></div>';       
        }

        if($edit_type === 'INSERT') {        
            $html .= '<div class="row"><div '.$class_label.'>'.$this->key['title'].':</div>'.
                     '<div '.$class_value.'>';
            if($this->key['key_auto']) {
                $html .= '<input type="hidden" name="'.$this->key['id'].'" value="0"><b>Automatically generated!</b>';        
            } else {
                $html .= '<input type="text" class="'.$this->classes['edit'].'" name="'.$this->key['id'].'" '.
                         'value="'.@$form[$this->key['id']].'">(Must be a UNIQUE identifier)';  
            } 
            $html .= '</div></div>';         
        }          
        
        foreach($this->cols as $col) {
            if($col['edit']) {
                $param = [];

                if($col['required']) $title = $this->icons['required'].$col['edit_title']; else $title = $col['edit_title'];
                $html .= '<div id="tr_'.$col['id'].'" class="row"><div '.$class_label.'>'.$title.':</div>'.
                         '<div '.$class_value.'>';
                if(isset($form[$col['id']])) {
                    $value = $form[$col['id']];
                    $param['redisplay'] = true;
                } else {
                    $value = $data[$col['id']];
                    $param['redisplay'] = false;
                } 
                $repeat = false;        
                                
                if($col['hint'] != '' and $col['type'] !== 'BOOLEAN') $html .= '<span class="edit_hint">'.$col['hint'].'<br/></span>';
                
                if($col['type'] === 'CUSTOM') {
                    $html .= $this->customEditValue($col['id'],$value,$edit_type,$form);
                } else {
                    $html .= $this->viewEditValue($col['id'],$value,$edit_type,$param);
                }  
                
                if($col['hint'] != '' and $col['type']==='BOOLEAN') {
                    $html.='<span class="edit_hint" style="display: inline">'.$col['hint'].'<br/></span>';
                }    
                
                $html.='</div></div>';
                
                if($col['repeat']) {
                    $form_id = $col['id'].'_repeat';
                    $html .= '<div class="row"><div '.$class_label.'">'.$col['edit_title'].' repeat:</div>'.
                             '<div '.$class_value.'>';
                    $param['repeat'] = true;
                    if(isset($form[$form_id])) $value = $form[$form_id]; else $value = $data[$col['id']];
                    $html .= $this->viewEditValue($col['id'],$value,$edit_type,$param);
                    $html .= '</div></div>';
                } 
            } 
        }
        
        $html .= $this->viewEditXtra($id,$form,$edit_type);
        
        //tree specific stuff
        if($this->access['edit']) {
            $param['class'] = $this->classes['edit_small'];
            $html .= '<div class="row"><div '.$class_label.'><b>Parent rank :</div>';
            $html .= '<div '.$class_value.'>(to set position within parent node)<br/>'.
                      Form::textInput('parent_node_rank',$parent_node_rank,$param);
            $html .= '</div></div>';
        }
        
        if($edit_type !== 'INSERT') {
            $node = $this->getNode($id);

            $html .= '<div class="row"><div '.$class_label.'><b>Tree level :</b></div>'.
                     '<div '.$class_value.'>'.$node['level'].' (automatically set)</div></div>';
            $html .= '<div class="row"><div '.$class_label.'><b>Tree rank :</b></div>'.
                     '<div '.$class_value.'>'.$node['rank'];
            if($node['rank'] != $node['rank_end']) $html .= '->'.$node['rank_end'];
            $html .= ' (automatically set)</div></div>';
        } 
        //end tree specific stuff

        $html .= '<div class="row"><div '.$class_submit.'>'.
                 '<input id="edit_submit" type="submit" name="submit" value="Submit" class="'.$this->classes['button'].'">'.
                 '</div></div>';

        $html .= '</form>';
        $html .= '</div>';

        if($edit_type !== 'INSERT' and $this->file_upload) {
            $html.='<div class="row"><div '.$class_label.'><b>'.$this->row_name.' Files :</b></div>'.
                   '<div '.$class_value.'>'.$this->viewFiles($data).
                   '</div></div>';
        }

        if($edit_type !== 'INSERT' and $this->image_upload) {
            $html.='<div class="row"><div '.$class_label.'><b>'.$this->row_name.' Images :</b></div>'.
                   '<div '.$class_value.'>'.$this->viewImages($data).
                   '</div></div>';
        }
        
        $html = $this->viewMessages().$html;

        return $html;

    }

    protected function getNode($id)
    {
        if($id == 0) {
            //where looking up root node parent
            $node = array('id'=>0,'level'=>0,'rank'=>0,'rank_end'=>0);
        } else {
            $sql = 'SELECT '.$this->tree_cols['level'].' AS level ,'.$this->tree_cols['rank'].' AS rank,'.$this->tree_cols['rank_end'].' AS rank_end,'.
                             $this->tree_cols['node'].' AS id , '.$this->tree_cols['parent'].' AS parent, '.$this->tree_cols['title'].' AS title '.
                   'FROM '.$this->table.' '.
                   'WHERE '.$this->tree_cols['node'].' = '.$this->db->escapeSql($id).' ';
            $node = $this->db->readSqlRecord($sql);
        }

        return $node;
    }

    protected function crawlNode($id,&$rank) 
    {
        $sql = 'SELECT '.$this->tree_cols['node'].','.
               '(SELECT '.$this->tree_cols['level'].' FROM '.$this->table.' WHERE '.$this->tree_cols['node'].' = "'.$this->db->escapeSql($id).'" ) '.
               'FROM '.$this->table.' '.
               'WHERE '.$this->tree_cols['parent'].' = "'.$this->db->escapeSql($id).'" '.
               'ORDER BY '.$this->tree_cols['rank'].' ';
        $id_list = $this->db->readSqlList($sql);
        if($id_list !== 0) {
            foreach($id_list as $node_id => $parent_level) {
                $rank++;
                $level = $parent_level+1;
                $sql = 'UPDATE '.$this->table.' SET '.$this->tree_cols['rank'].' = "'.$rank.'", '.$this->tree_cols['level'].' = "'.$level.'" '.
                       'WHERE '.$this->tree_cols['node'].' = "'.$this->db->escapeSql($node_id).'"';
                $this->db->executeSql($sql,$error_str);
                //*** recursion ***
                $this->crawlNode($node_id,$rank);
            }
        } 
    }
      
    protected function updateNodeEnd() 
    {
        //need maximum rank for last nodes in tree
        $sql = 'SELECT MAX('.$this->tree_cols['rank'].') FROM '.$this->table;
        $rank_max = $this->db->readSqlValue($sql);
     
        //step through all nodes in tree
        $sql = 'SELECT '.$this->tree_cols['node'].' AS id,'.$this->tree_cols['parent'].' AS parent_id,'.
                         $this->tree_cols['rank'].' AS rank,'.$this->tree_cols['level'].' AS level '.
               'FROM '.$this->table.' ORDER BY '.$this->tree_cols['rank'].' ';
        $nodes = $this->db->readSqlArray($sql);
        if($nodes != 0)  {
            foreach($nodes as $node_id => $node) {  
                $sql = 'SELECT MIN('.$this->tree_cols['rank'].') - 1 FROM '.$this->table.' '.
                       'WHERE '.$this->tree_cols['level'].' <= '.$node['level'].' AND '.$this->tree_cols['rank'].' > '.$node['rank'].' ';
                $rank_end = $this->db->readSqlValue($sql,0);
                if($rank_end == 0) $rank_end = $rank_max;
                  
                $sql = 'UPDATE '.$this->table.' SET '.$this->tree_cols['rank_end'].' = "'.$rank_end.'" '.
                       'WHERE '.$this->tree_cols['node'].' = "'.$this->db->escapeSql($node_id).'" ';
                $this->db->executeSql($sql,$error_str);
            }
        } 
    }
  
    protected function updateNodeLineage() 
    {
        $lineage = '';
        $old_level = 0;
        
        //step through all nodes in tree
        $sql = 'SELECT '.$this->tree_cols['node'].' AS id,'.$this->tree_cols['parent'].' AS parent_id,'.
                         $this->tree_cols['rank'].' AS rank,'.$this->tree_cols['level'].' AS level '.
               'FROM '.$this->table.' ORDER BY '.$this->tree_cols['rank'].' ';
        $nodes = $this->db->readSqlArray($sql);
        if($nodes != 0)  {
            foreach($nodes as $node_id => $node) {  
                if($node['level'] == 1) {
                    $lineage = ''; 
                } else {
                    if($node['level'] > $old_level) {
                        if($lineage == '') $lineage = $node['parent_id']; else $lineage .= ','.$node['parent_id'];
                    }

                    if($node['level'] < $old_level) {
                        $n = $old_level-$node['level'];
                        $array = explode(',',$lineage);
                        for($i = 0; $i < $n; $i++) array_pop($array);
                        $lineage = implode(',',$array);
                    } 
                } 
                    
                $sql = 'UPDATE '.$this->table.' SET '.$this->tree_cols['lineage'].' = "'.$lineage.'" '.
                       'WHERE '.$this->tree_cols['node'].' = "'.$this->db->escapeSql($node_id).'" ';
                $this->db->executeSql($sql,$error_str);
                
                $old_level = $node['level'];
            }
        } 
    }


    /*** PLACEHOLDERS ***/
    protected function beforeProcess($id = 0) {}
    protected function processCustom($id) {}
    protected function viewTreeInfo() {}
}
?>
