<?php
namespace Seriti\Tools;

trait  ModelViews 
{

    protected function viewNavigation($context = 'TABLE') 
    {
        //used to maintain state when multiple pages have different implementations
        $state_param = $this->linkState();
        
        $html = '<div id="navigate_div">';
        if($this->child) {
            if($this->master['show_sql'] != '') {
                $sql = str_replace('{KEY_VAL}',$this->db->escapeSql($this->master['key_val']),$this->master['show_sql']);
                $master_row = $this->db->readSqlValue($sql);   
            } else {
                $master_row = 'ID='.$this->master['key_val'];
            }
            $html .= '<h1>'.$master_row.'</h1>'; 
        } 

        $html .= '<a class="nav_list" href="?mode=list_all'.$state_param.'">List all '.$this->row_name_plural.'</a>';
        if($this->show_info) $html .= '&nbsp;-&nbsp;<a class="nav_info" href="javascript:toggle_display_scroll(\'info_div\')">Info</a>';
        //'delete' included for when there is an error while attempting to delete
        if($this->mode === 'list' or $this->mode === 'search' or $this->mode === 'index' or $this->mode === 'delete') {
            if($this->show_search) $html .= '&nbsp;-&nbsp;<a class="nav_search" href="javascript:toggle_display_scroll(\'search_div\')">Search</a>';

            if(!$this->access['read_only']) {
                if($this->access['import']) $html .= '&nbsp;-&nbsp;<a class="nav_import" href="javascript:toggle_display_scroll(\'import_div\')">Import</a>';
                if($this->access['add']) {
                    if($this->add_href !== '') {
                        $html .= '&nbsp;-&nbsp;<a class="nav_add" href="'.$this->add_href.'">Add a new '.$this->row_name.'</a>';
                    } else {  
                        $html .= '&nbsp;-&nbsp;<a class="nav_add" href="?mode=add'.$state_param.'">Add a new '.$this->row_name.'</a>';
                    }  
                }
            }      
            
            $html.='&nbsp;:&nbsp;'; 
             
            if($context === 'TABLE') {
                $start_row = ($this->page_no-1)*$this->max_rows+1;
                $end_row = min($this->page_no*$this->max_rows,$this->row_count);
                if($start_row > 1) $html .= '<a class="nav_prev" href="?mode=list&page='.($this->page_no-1).'&row=1'.$state_param.'"><<< </a>';
                $html .= '<span class="nav_page">'.$start_row.' - '.$end_row.' '.$this->row_name_plural.' of '.$this->row_count.' in total</span>';
                if($end_row < $this->row_count) $html .= '<a class="nav_next" href="?mode=list&page='.($this->page_no+1).'&row=1'.$state_param.'"> >>></a>'; 
            }    

            if($context === 'TREE') {
              $html .= '<span class="nav_page">'.$this->row_count.' '.$this->row_name_plural.'</span>';
            }  
        }  
    
        if(!$this->access['read_only']) {
            if($this->mode == 'add' or ($this->mode == 'update' and $_POST['edit_type'] == 'INSERT')) {
                $html.='&nbsp;-&nbsp;Add a new '.$this->row_name.' ('.$this->icons['required'].'required)';
            }    
            if($this->mode == 'edit' or ($this->mode == 'update' and $_POST['edit_type'] == 'UPDATE')) {
                $html.='&nbsp;-&nbsp;Modify existing '.$this->row_name.' ('.$this->icons['required'].'required)';
            }    
        }  
         
        if($context === 'TABLE') {        
            if($this->mode === 'list' or $this->mode === 'search' or $this->mode === 'index') {
                $html .= '&nbsp;<span class="nav_sort">(Sorted by '.$this->order_by[$this->order_by_current].')</span>'; 
            }    
        }  
        
        if($this->mode === 'add' or $this->mode === 'edit' or $this->mode === 'view' or $this->mode === 'view_image' or $this->mode === 'update') {
            $html.=' '.$this->js_links['back'];
        }

        if($this->pop_up) {
            $updated = $this->getCache('popup_updated'); 
            if($updated and $this->update_calling_page) {
                $html .= ' '.$this->js_links['close_update'];
            } else {
                $html .= ' '.$this->js_links['close'];
            }  
        }
        
        $html .= '</div>';   
        
        return $html;
    }

    protected function viewActions($data,$row_no,$pos = 'L') 
    {
        $html = '';
        $state_param = $this->linkState();
         
        if(count($this->actions) != 0) {
            foreach($this->actions as $action) {
                $valid = true;
                if($action['verify']) $valid = $this->verifyRowAction($action,$data);
                
                if($valid and ($action['pos'] === $pos or $pos === 'ALL')) {
            
                    if($action['class'] != '') $html .= '<span class="'.$action['class'].'">';
                    
                    $show = '';
                    if($action['icon'] !== false) $show .= $action['icon'];
                    if(isset($action['col_id'])) $show .= $data[$action['col_id']];
                    if($action['text'] != '') $show .= $action['text']; 
                        
                    if($action['type'] === 'popup') {
                        if(!strpos($action['url'],'?')) $url_mod = '?'; else $url_mod = '&';
                        $url = $action['url'].$url_mod.'id='.$data[$this->key['id']].$state_param;
                        $html .= '<a class="action" href="Javascript:open_popup(\''.$url.'\','.
                                     $action['width'].','.$action['height'].')">'.$show.'</a>';     
                    } elseif($action['type'] === 'link') {
                        if(isset($action['target'])) $target = 'target="'.$action['target'].'"'; else $target='';
                        if(!strpos($action['url'],'?')) $url_mod = '?'; else $url_mod='&';
                        $href = $action['url'].$url_mod.'id='.$data[$this->key['id']].$state_param;
                        if($action['mode'] != '') $href .= '&mode='.$action['mode'];
                        $html .= '<a class="action" '.$target.' href="'.$href.'" >'.$show.'</a>'; 
                    } elseif($action['type'] === 'check_box'){
                        $param['class'] = 'checkbox_action';
                        $html .= Form::checkbox('checked_'.$data[$this->key['id']],'YES',$action['checked'],$param).$show;
                    } else {    
                        $onclick = '';
                        if($action['type'] == 'delete') {
                            $item = $this->row_name.'['.$data[$this->col_label].']';
                            $onclick = 'onclick="javascript:return confirm(\'Are you sure you want to DELETE '.$item.'?\')" '; 
                        }
                        $href = '?mode='.$action['mode'].'&page='.$this->page_no.'&row='.$row_no.'&id='.$data[$this->key['id']].$state_param;  
                        $html .= '<a class="action" href="'.$href.'" '.$onclick.'>'.$show.'</a>';  
                    } 
                    
                    if($action['class'] != '') $html .= '</span>';
                    //space between actions, if &nbsp; then no auto breaks
                    $html .= $action['spacer'];       
                } 
            }
        } 
        
        return $html;
    }

    protected function viewMessages() 
    {
        $html = '';
        
        $xtra = $this->getCache('errors');
        if($xtra != '') {
            if(is_array($xtra)) {
                $this->errors = array_merge($this->errors,$xtra);
            } else {
                $this->addError($xtra);
            }    
            $this->setCache('errors','');
        }  
        $xtra = $this->getCache('messages');
        if($xtra != '') {
            if(is_array($xtra)) {
                $this->messages = array_merge($this->messages,$xtra);
            } else {
                $this->addMessage($xtra);
            }    
            $this->setCache('messages','');
        }  
                                
        if($this->errors_found) {
            $html .= '<div id="error_div" class="'.$this->classes['error'].'">'.
                     '<button type="button" class="close" data-dismiss="alert">x</button><ul>';
            foreach($this->errors as $error) {
                $html .= '<li>'.$error.'</li>';  
            }  
            $html .= '</ul></div>';
        }  
        if(count($this->messages) != 0) {
            $html .= '<div id="message_div" class="'.$this->classes['message'].'">'.
                     '<button type="button" class="close" data-dismiss="alert">x</button><ul>';
            foreach($this->messages as $message) {
                $html .= '<li>'.$message.'</li>';  
            }  
            $html .= '</ul></div>';
        }
        
        return $html;
    }

    protected function viewInfo($mode) 
    {
        $html = '';
        $info = 0;
        
        if(count($this->info) and isset($this->info[$mode])) {
            $info = $this->info[$mode];
        } else {
            if($mode === 'ADD') {
                $info = 'Enter all required '.$this->row_name.' data. '.
                        'Finally you need to click [Submit] button at bottom of page to save data to server.';
            }
            if($mode === 'EDIT') {
                $info = 'Modify/Enter all required '.$this->row_name.' data. '.
                        'Finally you need to click [Submit] button at bottom of page to save data to server.';
            }
            if($mode === 'LIST') {
                $info = 'All '.$this->row_name_plural.': Click <b>edit</b> link to edit '.$this->row_name.' details; '.
                        'Click <b>delete</b> link to remove '.$this->row_name.' (you will be asked for confirmation). '.
                        'Click <b>Search</b> link to view '.$this->row_name.' search options.'.
                        'Finally click [Search] button to view matching '.$this->row_name_plural;
        }  
            if($mode === 'IMPORT') {
                $info = 'You can import '.$this->row_name.' data in CSV(Comma Separated Values) format. '.
                        'NB: the first line of the file must have column headers exactly as displayed, '.
                        'dates are always in YYYY-MM-DD format, decimal separator must be "." .'.
                        'Select CSV format file and then Click [Import] button to upload and import.';
            }
            if($mode === 'IMAGE') {
                $info = 'View '.$this->row_name.' image. '.
                        'Click download link to save image to your device.';
            }
        }
        
        if($info === 0) {
            $html .= '<div id="info_div" class="'.$this->classes['info'].'" style="display:none">'.
                     'No information is available for this page.</div>'; 
        } else {  
            $html .= '<div id="info_div" class="'.$this->classes['info'].'" style="display:none">'.
                     '<button type="button" class="close" data-dismiss="alert">x</button><strong>Info:</strong> '.$info.'</div>';
        }  
                 
        return $html;
    } 

    protected function viewColValue($col_id,$value)
    {
        $col = $this->cols[$col_id];

        switch($col['type']) {
            case 'DATE' : {
                $value = Date::formatDate($value,'MYSQL',$col['format']);
                break;
            } 
            case 'DATETIME' : {
                $value = Date::formatDateTime($value,'MYSQL',$col['format']);
                break;
            }
            case 'TIME' : {
                $value = Date::formatTime($value,'MYSQL',$col['format']);
                break;
            } 
            case 'EMAIL' : {
                $value = Secure::clean('email',$value);
                $value = '<a href="mailto:'.$value.'">'.$value.'</a>'; 
                break;
            }
            case 'URL' : {
                $value = Secure::clean('url',$value);
                if(strpos($value,'//') === false) $http = 'http://'; else $http = '';
                $value = '<a href="'.$http.$value.'" target="_blank">'.$value.'</a>';
                break;
            } 
            case 'BOOLEAN' : {
                if($value == 1) $value = $this->icons['true']; else $value = $this->icons['false'];
                break;
            } 
            case 'PASSWORD' : {
                $value = '****';
                break;
            } 
            case 'STRING' : {
                if($col['secure']) $value=Secure::clean('string',$value);
                break;
            } 
            case 'TEXT' : {
                if($col['secure']) {
                    if($col['html']) {
                        if($col['encode']) $value = Secure::clean('html',$value);
                    } else {
                        $value = Secure::clean('text',$value);
                        $value = nl2br($value);
                    }
                } else {
                    if(!$col['html']) $value = nl2br($value);
                }   
                break;
            }  
                                  
            default : $value = Secure::clean('string',$value);
        }

        return $value;
    }

    protected function viewEditValue($col_id,$value,$edit_type,$param=[])  
    {
        $html = '';
        $input_param = [];
        
        $html = $this->modifyEditValue($col_id,$value,$edit_type,$param);
        if($html != '') return $html;
        
        //get column details and setup form input parameters
        $col = $this->cols[$col_id];
        if(isset($param['form_id']) and $param['form_id'] !== '') $input_param['form'] = $param['form_id'];
        if($col['class'] === '') $input_param['class'] = $this->classes['edit']; else $input_param['class'] = $col['class'];
        if(isset($col['onchange'])) $input_param['onchange'] = $col['onchange'];
        if(isset($col['onkeyup'])) $input_param['onkeyup'] = $col['onkeyup'];
        if(isset($col['onblur'])) $input_param['onblur'] = $col['onblur'];
        
        //setup form input name/id
        if(isset($param['name'])) {
            $name = $param['name'];
        } else {
            $name = $col_id;
            if(isset($param['repeat']) and $param['repeat']) $name .= '_repeat';
        }

        //assign values for new record                
        if($edit_type === 'INSERT' and $value == '') {
            $value = $col['new'];
            if($col['type'] === 'PASSWORD' and $value == '') $value = Form::createPassword();
        }
           
                    
        if(isset($this->select[$col_id]) and $this->select[$col_id]['edit'] == true) {
            if(isset($this->select[$col_id]['onchange'])) $input_param['onchange'] = $this->select[$col_id]['onchange'];
            if(isset($this->select[$col_id]['xtra'])) $input_param['xtra'] = $this->select[$col_id]['xtra'];
            
            if(isset($this->select[$col_id]['sql'])) {
                $html .= Form::sqlList($this->select[$col_id]['sql'],$this->db,$name,$value,$input_param);
            } elseif(isset($this->select[$col_id]['list'])) { 
                $html .= Form::arrayList($this->select[$col_id]['list'],$name,$value,$this->select[$col_id]['list_assoc'],$input_param);
            }
        } else {
            switch($col['type']) {
                case 'STRING' : {
                    if($col['secure']) $value = Secure::clean('string',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                }
                case 'PASSWORD' : {
                    $value = Secure::clean('string',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                    
                    $input_param['onclick'] = 'javascript:document.update_form.'.$name.'.value=\'\'';
                    if($edit_type === 'UPDATE') $html .= Form::checkbox('change_password','YES',0,$input_param).
                                                         '<span class="edit_label">Create new?</span>';
                    break;  
                }
                case 'INTEGER' : {
                    $value = Secure::clean('integer',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                }
                case 'DECIMAL' : {
                    $value = Secure::clean('float',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                }
                case 'TEXT' : {
                    if($col['secure']) {
                        if($col['html']) {
                            $value = Secure::clean('html',$value);
                        } else {
                            $value = Secure::clean('text',$value);
                        }
                    } 
                    if($input_param['class'] === 'HTMLeditor') $col['rows'] += 3; 
                    $html .= Form::textAreaInput($name,$value,$col['cols'],$col['rows'],$input_param);
                    break;
                }
                case 'DATE' : {
                    if($value == '') $this->dates['new'];
                    if($value == '0000-00-00') $value = $this->dates['zero'];
                    $value=Secure::clean('date',$value);
                    if($this->classes['date'] != '') $input_param['class'] .= ' '.$this->classes['date'];
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                }
                case 'DATETIME' : {
                    if($value == '') $value = date('Y-m-d H:i:s');
                    if($value == '0000-00-00 00:00:00') $value = $this->dates['zero'].' 00:00:00';
                    $value=Secure::clean('date',$value);
                    if($this->classes['date'] != '') $input_param['class'] .= ' '.$this->classes['date'];
                    $html .= '<table cellpadding="0" cellspacing="0"><tr>'.
                             '<td>'.Form::textInput($name,substr($value,0,10),$input_param).'</td>'.
                             '<td>@ <input style="display:inline;" type="text" name="'.$name.'_time" value="'.substr($value,11,8).'" class="'.$this->classes['time'].'"></td>'.
                             '</tr></table>';
                    break;
                }
                case 'TIME' : {
                    if($value == '') $value = date('H:i:s');
                    $value = Secure::clean('string',$value);
                    if($col['format'] == 'HH:MM') $value = substr($value,0,5);
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                } 
                case 'EMAIL' : {
                    $value = Secure::clean('email',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                }
                case 'URL' : {
                    $value = Secure::clean('url',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                    break;
                } 
                case 'BOOLEAN' : {
                    $html .= Form::checkBox($name,'1',$value,$input_param);
                    break;   
                }
                
                default : {
                    $value = Secure::clean('string',$value);
                    $html .= Form::textInput($name,$value,$input_param);
                }   
            } 
        }
                
        return $html;
    } 

    protected function viewSearch($form=array()) 
    {
        //determine layout requirements
        $field_no = count($this->search)+1; //+1 for order by list
        $field_no += count($this->search_xtra);
        $per_row = ceil($field_no/$this->search_rows);
        
        if($this->mode === 'search') $style = 'style="display:block"'; else $style = 'style="display:none"';
        $html = '<div id="search_div" '.$style.'>';
        
        $html .= '<form method="post" enctype="multipart/form-data" action="?mode=search" name="search_form" id="search_form">';
        $html .= $this->formState();        
        $html .= '<table cellspacing="0" cellpadding="4">';
        $html .= '<tr>';
        $html .= '<td rowspan="'.$this->search_rows.'"><input type="submit" name="submit" value="Search" class="'.$this->classes['button'].'"></td>';
        
        $html .= '<td class="search_label" align="right">Order by:</td><td>';
        if(isset($form['order_by'])) $order_key=$form['order_by']; else $order_key=$this->order_by_current;
        $param = array();
        $param['class'] = $this->classes['search'];
        $html .= Form::arrayList($this->order_by,'order_by',$order_key,true,$param);
        if(isset($form['order_by_desc']) and $form['order_by_desc']) $checked = true; else $checked = false;
        $html .= Form::checkbox('order_by_desc','YES',$checked).'<span class="search_label">DESCENDING</span>';
        $html .= '</td>';
        
        $f = 1;
        $r = 1;
        //standard table search cols
        foreach($this->search as $col_id) {
            $f++;
            $html .= '<td align="right" class="search_label">'.$this->cols[$col_id]['edit_title'].':</td><td>';
            if(isset($form[$col_id])) $value = $form[$col_id]; else $value = '';
            $html .= $this->viewSearchValue($col_id,$value);
                        
            if($f == $per_row) {
                $html .= '</tr><tr>';
                $f = 0;
            }  
        }
        
        //xtra search cols normally from a join *** NB: array key is col['id'] with "." replaced by "_" ***
        foreach($this->search_xtra as $xtra_id => $col) {
            $f++;
            $html .= '<td align="right" class="search_label">'.$col['title'].':</td><td>';
            if(isset($form[$xtra_id])) $value = $form[$xtra_id]; else $value = '';
            $html.=$this->viewSearchValue($xtra_id,$value,true);
            
            if($f == $per_row) {
                $html .= '</tr><tr>';
                $f = 0;
            }  
        }
        
        $html .= '</tr></table></form>';
        $html .= '</div>';
        return $html;
    }

    protected function viewSearchValue($col_id,$value,$xtra = false)  
    {
        $html = '';
        $param = array();
        
        if($xtra) {
            $col = $this->search_xtra[$col_id];
            $col['class_search'] = $col['class'];
        } else {
            $col = $this->cols[$col_id];
        }  
        
        if($col['class_search'] == '') {
            $param['class'] = $this->classes['search']; 
        } else {
            $param['class'] = $col['class_search'];
        }    
                        
        if(isset($this->select[$col_id])) {
            $param['xtra'] = 'ALL';
            if(isset($this->select[$col_id]['onchange'])) $param['onchange'] = $this->select[$col_id]['onchange'];

            if(isset($this->select[$col_id]['sql'])) {
                $html .= Form::sqlList($this->select[$col_id]['sql'],$this->db,$col_id,$value,$param);
            } elseif(isset($this->select[$col_id]['list'])) { 
                $html .= Form::arrayList($this->select[$col_id]['list'],$col_id,$value,$this->select[$col_id]['list_assoc'],$param);
            }  
        } elseif($col['type'] === 'BOOLEAN') {
            $param['xtra'] = 'ALL';
            $boolean=array('YES'=>'TRUE','NO'=>'FALSE');
            $html.=Form::arrayList($boolean,$col_id,$value,true,$param);
        } elseif($col['type']=='DATE' or $col['type']=='DATETIME') {
            //NB If set $value must be of type array
            if($value === '') {
                $value = [];
                $value['from'] = date('Y-m-d',mktime(0,0,0,date("m"),date("d")-$this->dates['from_days'],date("Y")));
                $value['from_use'] = false;
                $value['to'] = date('Y-m-d',mktime(0,0,0,date("m"),date("d")+$this->dates['to_days'],date("Y")));
                $value['to_use'] = false;
            }   
            $param['class'] = $this->classes['date'];
                        
            $form_id = $col_id.'_from';
            $html .= '<table cellpadding="0" cellspacing="0"><tr>';
            $html .= '<td class="search_label">from</td><td>'.Form::checkbox($col_id.'_from_use','YES',$value['from_use']).'</td><td>';
            $html .= Form::textInput($form_id,$value['from'],$param).'</td>';

            $form_id = $col_id.'_to';
            $html .= '</tr><tr>';
            $html .= '<td align="right" class="search_label">to</td><td>'.Form::checkbox($col_id.'_to_use','YES',$value['to_use']).'</td><td>';
            $html .= Form::textInput($form_id,$value['to'],$param).'</td>';
            $html .= '</tr></table>';
        } else {
            //strips out 'search' operators before cleaning and then adds back in for display
            $value = Secure::clean('search',$value); 
            $html .= Form::textInput($col_id,$value,$param);       
        }  
                
        return $html;
    }

    protected function viewImages($data) 
    {
        $html = '';
        $html = '';
        $error_tmp = '';

        $location_id = $this->images['location'].$this->db->escapeSql($data[$this->key['id']]);
        if(isset($this->images['icon'])) $show = $this->images['icon']; else $show = $this->icons['images'];
                
        if($this->images['manage']) {
            $url = $this->images['link_url'].'?id='.$this->db->escapeSql($data[$this->key['id']]);
            $html = '<a href="Javascript:open_popup(\''.$url.'\','.$this->images['width'].','.$this->images['height'].')">'.$show.'</a>';
        }  
        
        $image_count = 0;
        if($this->images['list']) {
            $sql = 'SELECT '.$this->file_cols['file_id'].' AS id,'.$this->file_cols['file_name_orig'].' AS name , '.$this->file_cols['file_name_tn'].' AS thumbnail  '.
                   'FROM '.$this->images['table'].' '.
                   'WHERE '.$this->file_cols['location_id'].' = "'.$location_id.'" '.
                   'ORDER BY '.$this->file_cols['location_rank'].','.$this->file_cols['file_date'].' DESC LIMIT '.$this->images['list_no'];
            $images = $this->db->readSqlArray($sql);
            if($images !== 0) {
                $html .= '<br/>';
                
                foreach($images as $image_id => $image) {
                    $image_count++;
                    
                    if($this->images['list_thumbnail']) {
                        if($this->images['storage'] === 'amazon') {
                            $url = $this->images['s3']->getS3Url($image['thumbnail']);
                            if($this->images['https'] and strpos($url,'https') === false) $url = str_replace('http','https',$url);
                        } else {
                            if($this->images['path_public']) {
                                $url = BASE_URL.$this->images['path'].$image['file_name_tn'];
                            } else {
                                $path = $this->images['path'].$image['thumbnail'];
                                //NB: this returns encoded image and NOT url as image normally stored outside public directory
                                $url = Image::getImage('SRC',$path,$error);
                                if($error != '') $this->addError('Thumbnail error: '.$error);
                            }    
                        }   
                        
                        $html .= '<img class="list_image" align="left" src="'.$url.'" '.
                                 'title="'.$image['name'].'"  border="0" height"50"><br/>';
                    } else {
                        $url = $this->images['link_url'].'?mode=view_image&id='.$image_id;
                        $html .= '<a href="Javascript:open_popup(\''.$url.'\',600,600)">'.
                                 $this->icons['view'].$image['name'].'</a><br/>';
                    }  
                }
            } 
        } else {
            $sql = 'SELECT COUNT(*) FROM '.$this->images['table'].' WHERE location_id ="'.$location_id.'" ';
            $count = $this->db->readSqlValue($sql,0);
            if($count != 0) $html .= '&nbsp;('.$count.')';
        }
        
        //wrap in a scroll box)
        if($image_count > 10) $style = 'style="overflow: auto; height:200px;"'; else $style = '';
        $html = '<div '.$style.'>'.$html.'</div>';
        
        return $html;
    }
    
    protected function viewFiles($data,$view = 'BLOCK') 
    {
        $html = '';

        $location_id = $this->files['location'].$this->db->escapeSql($data[$this->key['id']]);
        if($this->files['icon'] != '') $show = $this->files['icon']; else $show = $this->icons['files'];

        if($this->files['manage']) {
            $url = $this->files['link_url'].'?id='.Secure::clean('basic',$data[$this->key['id']]);
            $html .= '<a href="Javascript:open_popup(\''.$url.'\','.$this->files['width'].','.$this->files['height'].')">'.$show.'</a>';
        }  
        
        $file_count = 0;
        if($this->files['list'] and $view === 'BLOCK') {
            $sql = 'SELECT '.$this->file_cols['file_id'].' AS id,'.$this->file_cols['file_name_orig'].' AS name , '.$this->file_cols['file_size'].' AS size '.
                   'FROM '.$this->files['table'].' '.
                   'WHERE '.$this->file_cols['location_id'].' = "'.$location_id.'" '.
                   'ORDER BY '.$this->file_cols['location_rank'].','.$this->file_cols['file_date'].' DESC LIMIT '.$this->files['list_no'];
            $file_list = $this->db->readSqlArray($sql);
            if($file_list !== 0) {
                $html .= '<br/>';
                foreach($file_list as $id => $data) {
                    $file_count++;
                    
                    $url = $this->files['link_url'].'?mode=download&id='.$id;
                    $html .= '<a id="file'.$id.'" href="'.$url.'" onclick="link_download(\'file'.$id.'\')" target="_blank">'.
                                 $this->icons['download'].$data['name'].'</a>';
                    if($data['size'] > 0) $html .= '('.Calc::displayBytes($data['size'],0).')';
                    $html .= '<br/>';
                }  
            } 
        } 

        if($view === 'SUMMARY') {
            $sql = 'SELECT COUNT(*) AS num, MAX('.$this->file_cols['file_date'].') AS latest '.
                   'FROM '.$this->files['table'].' WHERE location_id = "'.$location_id.'" ';
            $files = $this->db->readSqlRecord($sql);
            if($files != 0 and $files['num'] > 0) $html .= '&nbsp;('.$files['num'].$this->files['name'].' '.$files['latest'].')';
        }   
        
        //wrap in a scroll box)
        if($view === 'BLOCK') { 
            if($file_count > 10) $style = 'style="overflow: auto; height:200px;"'; else $style = '';
            $html = '<div '.$style.'>'.$html.'</div>';
        }    
        
        return $html;
    }

    /*** PLACEHOLDERS ***/
    protected function modifyRowValue($col_id,$data,&$value) {} 
    protected function modifyRecordValue($col_id,$data,&$value) {}
    protected function viewEditXtra($id,$form,$edit_type) {}
    protected function verifyRowAction($action,$data) {}
    protected function modifyEditValue($col_id,$value,$edit_type,$param) {}
    protected function customEditValue($col_id,$value,$edit_type,$form) {}  
    protected function customSearchValue($col_id,$value) {}
     
}