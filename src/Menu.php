<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;
use Seriti\Tools\Form;
use Seriti\Tools\Date;
use Seriti\Tools\Calc;
use Seriti\Tools\Audit;
use Seriti\Tools\User;

use Seriti\Tools\BASE_URL;
use Seriti\Tools\TABLE_MENU;

class Menu extends Tree 
{
    protected $user;
    protected $check_access = false;

    public function setup($param = array()) 
    {
        //$param=['row_name'=>'menu item','col_label'=>'title'];
        parent::setup($param); 

        //menu specific cols in addition to standard tree cols
        $this->addTreeCol(['id'=>$this->menu_cols['type'],'title'=>'Type','type'=>'STRING']);
        $this->addTreeCol(['id'=>$this->menu_cols['route'],'title'=>'Route','type'=>'STRING']);
        $this->addTreeCol(['id'=>$this->menu_cols['access'],'title'=>'Access','type'=>'STRING']);
        $this->addTreeCol(['id'=>$this->menu_cols['mode'],'title'=>'Mode','type'=>'STRING']);
        
        if(!isset($param['check_access'])) $param['check_access'] = false;
        if($param['check_access']) {
            $this->user = $this->getContainer('user');
            $this->check_access = true;
        }    
    }  
    
    protected function getItemAccess($item = [])
    {
        $access = true;
        if($this->check_access) {
            $access = $this->user->checkUserAccess($item[$this->menu_cols['access']]);
        } 

        return $access;         
    }

    protected function getItemHref($item = [],$options) 
    {
        $href = $options['http_root'].$item[$this->menu_cols['route']].'?mode='.$item[$this->menu_cols['mode']]; 

        return $href;
    }

    //use to check if route is valid for user
    public function checkRouteAccess($route = '')
    {
        $access = true;
        if($this->check_access and $route !== '') {
            $sql = 'SELECT * FROM `'.$this->table.'` '.
                   'WHERE `'.$this->menu_cols['route'].'` = "'.$this->db->escapeSql($route).'" ';
            $item = $this->db->readSqlRecord($sql);  

            //NB: only check access if valid menu item found matching route
            if($item !== 0) {
                $access = $this->user->checkUserAccess($item[$this->menu_cols['access']]);
            }    
        } 

        return $access; 
    }

    public function buildMenu($system = [],$options = []) 
    {
        if(!isset($options['http_root'])) $options['http_root'] = BASE_URL;
        if(!isset($options['active_link'])) $options['active_link'] = URL_CLEAN;
        if(!isset($options['menu_static'])) $options['menu_static'] = '';
        //if false menu items with insufficient access are NOT displayed
        if(!isset($options['show_disabled'])) $options['show_disabled'] = true;

        if(!isset($options['logo_link'])) $options['logo_link'] = $options['http_root'].'/home';
        if(!isset($options['logo'])) $options['logo'] = SITE_MENU_LOGO;
        if(!isset($options['style'])) $options['style'] = SITE_MENU_STYLE;
        
        //set to empty array[] to ignore, route=>menutext
        if(!isset($options['append'])) $options['append'] = ['/login?mode=logout'=>'logout'];

        //icons and search setting are an array see formsat below 
        if(!isset($options['icons'])) $options['icons'] = false;
        if(!isset($options['search'])) $options['search'] = false;
        
        if(!isset($options['merge_system'])) $options['merge_system'] = true;
        if(defined('SYSTEM_MENU') and $options['merge_system']) $system = array_merge($system,SYSTEM_MENU);

        if($this->check_access) {
            $access_levels = $this->user->getAccessLevels();
            //first defined access level is assumed to be highest access level
            $god_level = $access_levels[0];
            $god_access = $this->user->checkUserAccess($god_level);

            //will be true if user has route whitelist configured
            $route_whitelist = $this->user->getRouteAccess();
        } else {
            $god_access = false;
            $route_whitelist = false;
        }    


        $icon_html = '';
        if($options['icons'] !== false) {
            foreach($options['icons'] as $icon) {
                $icon_html .= '<span id="'.$icon['id'].'" class="'.$icon['class'].'"><a href="'.$icon['url'].'">'.$icon['value'].'</a></span>';
            }
        }

        $search_html = '';
        if($options['search'] !== false) {
            $search_html = '<form id="menu_search" class="pull-left" role="search" action="'.$options['search']['action'].'">
                                <div class="input-group">
                                   <input type="text" class="form-control" placeholder="'.$options['search']['placeholder'].'">
                                   <div class="input-group-btn">
                                      <button type="submit" class="btn btn-default"><span class="glyphicon glyphicon-search"></span></button>
                                   </div>
                                </div>
                             </form>';
        }    
                    
        $html = '';
        $html = '<nav class="'.$options['style'].'">'.
                    '<div class="container">'.
                        '<div class="navbar-header">'.
                            '<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">'.
                                '<span class="sr-only">Toggle navigation</span>'.
                                '<span class="icon-bar"></span>'.
                                '<span class="icon-bar"></span>'.
                                '<span class="icon-bar"></span>'.
                            '</button>'.
                            '<a class="navbar-brand" href="'.$options['logo_link'].'">'.$options['logo'].'</a>'.
                            $icon_html.
                            $search_html.
                        '</div>'.    
                        '<div id="navbar" class="navbar-collapse collapse">'.
                        '<ul class="nav navbar-nav">';
        
        //NB:Static menu items must be correctly specified inside navbar html
        $html .= $options['menu_static'];
        
        //menu is user whitelist if specified. Cannot have separate menu per user, so help me god.
        if($route_whitelist) {
            $whitelist = $this->user->getRouteWhitelist();
            $list_html = '';
            foreach($whitelist as $route => $info) {
                if($options['active_link'] == $route) $item_class = 'active';
                if($info['title'] !== 'NONE') {
                    $list_html.='<li><a href="'.$options['http_root'].$route.'">'.$info['title'].'</a></li>';    
                }
            }    
            
            $html .= '<li class="dropdown '.$item_class.'">'.
                     '<a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">'.
                     'Your pages<span class="caret"></span></a>'.
                     '<ul class="dropdown-menu">'.$list_html.'</ul></li>';

        } else {
            //get all top level menu items
            $this->addSql('ORDER',$this->order_by);
            $this->addSql('WHERE','`'.$this->tree_cols['level'].'` = 1 ');
            $menu = $this->list();

            if($menu != 0) {
                foreach($menu AS $id => $item) {
                    $item_class = '';
                    $item_valid = true;
                    if($options['active_link'] == $item[$this->menu_cols['route']]) $item_class = 'active';

                    $href = '#';
                    $user_access = $this->getItemAccess($item);
                    if($user_access === false) {
                        $item_class .= ' disabled';
                        if($options['show_disabled'] === false) $item_valid = false;
                    }    
                    
                    if($item_valid) {         
                        if($item[$this->tree_cols['rank_end']] === $item[$this->tree_cols['rank']]) {
                            if($user_access) $href = $this->getItemHref($item,$options);
                            $html .= '<li class="'.$item_class.'"><a href="'.$href.'">'.$item[$this->tree_cols['title']].'</a></li>';
                        } else {
                            $sub_html = '';
                            if($user_access) {
                                //construct sub menu, single layer with indents for lower levels
                                $this->resetSql();
                                $this->addSql('ORDER',$this->order_by);
                                $this->addSql('RESTRICT','`'.$this->tree_cols['rank'].'` > '.$item[$this->tree_cols['rank']].' AND '.
                                                      '`'.$this->tree_cols['rank'].'` <= '.$item[$this->tree_cols['rank_end']]);
                               
                                $sub_menu = $this->list();
                                if($sub_menu != 0) {
                                    foreach($sub_menu as $sub_item) {
                                        if($options['active_link'] == $sub_item[$this->menu_cols['route']]) $item_class = 'active';

                                        if($sub_item['level'] == 2) {
                                            $prefix = '';
                                        } else {  
                                            $prefix = str_repeat('&nbsp;',($sub_item[$this->tree_cols['level']]-$item[$this->tree_cols['level']]-1)*3).'-';
                                        }
                                        
                                        if($sub_item[$this->menu_cols['type']] === 'DIVIDER') {
                                            $sub_html .= '<li role="separator" class="divider"></li>';
                                        } elseif($sub_item[$this->menu_cols['type']]==='TEXT') {
                                            $sub_html .= '<li class="disabled"><a href="">'.$prefix.$sub_item[$this->tree_cols['title']].'</a></li>';
                                        } else {  
                                            if($user_access) $href = $this->getItemHref($sub_item,$options);
                                            $sub_html .= '<li><a href="'.$href.'">'.$prefix.$sub_item[$this->tree_cols['title']].'</a></li>';
                                        }  
                                    }  
                                }
                            }    
                            
                            $html .= '<li class="dropdown '.$item_class.'">'.
                                        '<a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">'.
                                        $item[$this->tree_cols['title']].'<span class="caret"></span></a>'.
                                        '<ul class="dropdown-menu">'.
                                        $sub_html;

                            $html .= '</ul></li>';
                        }
                    }
                    //end item_valid        
            
                } 
            }  
                    
            //now add system menu for GOD access only!
            if($god_access and count($system)) {
                $system_html = '';
                $item_class = '';

                foreach($system as $route => $title) {
                    if($options['active_link'] == $route) $item_class = 'active';
                    $system_html.='<li><a href="'.$options['http_root'].$route.'">'.$title.'</a></li>';
                }    
                
                $html .= '<li class="dropdown '.$item_class.'">'.
                         '<a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">'.
                         'System<span class="caret"></span></a>'.
                         '<ul class="dropdown-menu">'.$system_html.'</ul></li>';
            }
        }      
        
        //append any additional menu items without 
        if(is_array($options['append']) and count($options['append'])) {
            foreach($options['append'] as $route => $title) {
                $item_class = '';
                if($options['active_link'] == $route) $item_class = 'active';
                $html .= '<li class="'.$item_class.'"><a href="'.$route.'">'.$title.'</a></li>';
            }    
        } 
             
        //finally add footer html
        $html .= '</ul></div>'.
                 '</div></nav>';
                     
                     
        return $html;       
        
    }    

    //constructs simple sub-menu for module or similar from list of links 
    public function buildNav($links=array(),$active_link,$options=array()) 
    {
        $html='';
        
        if(!isset($options['type'])) $options['type'] = SITE_MODULE_NAV;
        
        switch($options['type']) {
            case 'BUTTONS': $html.='<div class="btn-group" role="group" aria-label="...">';
                            foreach($links  as $url=>$title) {
                                $class='btn btn-default';
                                if($url===$active_link) $class.=' active'; 
                                $html.='<a class="'.$class.'" href="'.$url.'">'.$title.'</a>';
                            } 
                            $html.='</div><hr/>';
                            break;
            case  'TABS'  : $html.='<ul class="nav nav-tabs">';
                            foreach($links  as $url=>$title) {
                                if($url===$active_link) $class='class="active"'; else $class='';
                                $html.='<li role="presentation" '.$class.'><a href="'.$url.'">'.$title.'</a></li>';
                            } 
                            $html.='</ul>';
                            break;
            case 'PILLS':   $html.='<ul class="nav nav-pills">';
                            foreach($links  as $url=>$title) {
                                if($url===$active_link) $class='class="active"'; else $class='';
                                $html.='<li role="presentation" '.$class.'><a href="'.$url.'">'.$title.'</a></li>';
                            } 
                            $html.='</ul><hr/>';
                            breal;

        }
 
        return $html;
    }  
      
}
