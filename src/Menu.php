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
        $access = false;
        if($this->check_access) {
            $access = $this->user->checkUserAccess($item[$this->menu_cols['access']]);
        } else {
            $access = true; 
        }    

        return $access;         
    }

    //gets item urls/routes for menu links 
    protected function getItemHref($item = [],$options) 
    {
        $href = '#';
        
        if($item[$this->menu_cols['type']] === 'LINK_SYSTEM') { //system pages from webroot
            $href = $options['http_root'].$item[$this->menu_cols['route']].'?mode='.$item[$this->menu_cols['mode']]; 
        } elseif($item[$this->menu_cols['type']] === 'LINK_PAGE') { //user created public page from WEBSITE module
            $href = $options['http_root'].$options['link_page'].'?page='.$item[$this->menu_cols['route']];
        }elseif($item[$this->menu_cols['type']] === 'LINK_TABLE') { //user created database table management pages from CUSTOM module
            $href = $options['http_root'].$options['link_table'].'?table='.$item[$this->menu_cols['route']]; //db_manage.php of old
        } elseif($item[$this->menu_cols['type']] === 'LINK_STANDARD') { //any link page from webroot
            $href = $options['http_root'].$item[$this->menu_cols['route']].'?mode='.$item[$this->menu_cols['mode']];
        } else { 
            $href = $options['http_root'].$item[$this->menu_cols['route']].'?mode='.$item[$this->menu_cols['mode']]; 
            //$module_id = substr($item[$this->menu_cols['type']],5);
            //$href = $options['http_root'].$module_id.'/'.$item[$this->menu_cols['route']].'?mode='.$item[$this->menu_cols['mode']];
        }
        
        return $href;
    }

    public function buildMenu($system = [],$options = []) 
    {

        if(defined('SYSTEM_MENU')) $system = array_merge($system,SYSTEM_MENU);

        if(!isset($options['http_root'])) $options['http_root'] = BASE_URL;
        if(!isset($options['active_link'])) $options['active_link'] = URL_CLEAN;
        if(!isset($options['link_page'])) $options['link_page'] = '';
        if(!isset($options['link_table'])) $options['link_table'] = 'table';
        if(!isset($options['menu_static'])) $options['menu_static'] = '';
        //if false menu items with insufficient access are NOT displayed
        if(!isset($options['show_disabled'])) $options['show_disabled'] = true;

        if(!isset($options['logout'])) $options['logout'] = '/login?mode=logout';
        if(!isset($options['logo_link'])) $options['logo_link'] = $options['http_root'].'/home';
        if(!isset($options['logo'])) $options['logo'] = SITE_MENU_LOGO;
        if(!isset($options['style'])) $options['style'] = SITE_MENU_STYLE;
        
        if($this->check_access) {
            $access_levels = $this->user->getAccessLevels();
            //first defined access level is assumed to be highest access level
            $god_level = $access_levels[0];
            $god_access = $this->user->checkUserAccess($god_level);
        } else {
            $god_access = false;
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
                        '</div>'.    
                    '<div id="navbar" class="navbar-collapse collapse">'.
                    '<ul class="nav navbar-nav">';
        
        //NB:Static menu items must be correctly specified inside navbar html
        $html .= $options['menu_static'];
        
        //get all menu items
        $this->addSql('ORDER',$this->order_by);
        $this->addSql('WHERE',$this->tree_cols['level'].' = 1 ');
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
                            $this->addSql('RESTRICT',$this->tree_cols['rank'].' > '.$item[$this->tree_cols['rank']].' AND '.
                                                  $this->tree_cols['rank'].' <= '.$item[$this->tree_cols['rank_end']]);
                           
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
        
        if($options['logout'] != '') {
            $html .= '<li><a href="'.$options['logout'].'">Logout</a></li>';
        }  
                     
        //finally add footer html
        $html .= '</ul></div></div></nav>';
                     
                     
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
