<?php
namespace Seriti\Tools;

use Seriti\Tools\BASE_URL;

trait  IconsClassesLinks 
{
        
    protected $icons=array('true'=>'<img src="'.BASE_URL.'images/tick.png">',
                           'false'=>'<img src="'.BASE_URL.'images/cross.png">',
                           'edit'=>'<img src="'.BASE_URL.'images/edit.png" border="0" title="Edit">',
                           'delete'=>'<img src="'.BASE_URL.'images/erase.png" border="0" title="Delete">',
                           'view'=>'<img src="'.BASE_URL.'images/view.png" border="0" title="View">',
                           'files'=>'<img src="'.BASE_URL.'images/folder.png" border="0" title="Files">manage',
                           'images'=>'<img src="'.BASE_URL.'images/folder.png" border="0" title="Images">manage',
                           'download'=>'<img src="'.BASE_URL.'images/disk.png" border="0" title="Download">',
                           'required'=>'<span class="star">*</span>',
                           'excel'=>'<img src="'.BASE_URL.'images/excel_icon.gif" border="0" title="Download excel/csv file of data">',
                           'sort_up'=>'<img src="'.BASE_URL.'images/arrow_u.gif" title="sort ascending">',
                           'sort_dn'=>'<img src="'.BASE_URL.'images/arrow_d.gif" title="sort ascending">',
                           'expand'=>'<img src="'.BASE_URL.'images/plus.gif" border="0" title="Expand All">',
                           'collapse'=>'<img src="'.BASE_URL.'images/minus.gif" border="0" title="Collapse All">',
                           'plus'=>'<span class="glyphicon glyphicon-plus"></span>',
                           'minus'=>'<span class="glyphicon glyphicon-minus"></span>',
                           'import'=>'<span class="glyphicon glyphicon-import"></span>',
                           'setup'=>'<span class="glyphicon glyphicon-wrench"></span>' );
        
    //configured for bootstrap defaults                     
    protected $classes=array('button'=>'btn btn-primary',
                             'file_browse'=>'form-control btn btn-primary ',
                             'table'=>'table  table-striped table-bordered table-hover table-condensed',
                             'search'=>'form-control input-small',
                             'action'=>'form-control input-medium input-inline',
                             'error'=>'alert alert-danger',
                             'message'=>'alert alert-success',
                             'breadcrumb'=>'breadcrumb',
                             'info'=>'alert alert-info',
                             'table_edit'=>'col-sm-12 col-md-8 col-lg-6',
                             'col_label'=>'col-sm-6 col-lg-4 edit_label',
                             'col_value'=>'col-sm-12 col-lg-8 edit_value',
                             //'col_label'=>'col-sm-3 col-lg-2 edit_label',
                             //'col_value'=>'col-sm-6 col-lg-4 edit_value',
                             'col_submit'=>'col-sm-offset-6 col-lg-offset-4 col-sm-12 col-lg-8',
                             //'col_submit'=>'col-sm-offset-3 col-lg-offset-2 col-sm-6 col-lg-4',
                             'edit'=>'form-control edit_input',
                             'edit_small'=>'form-control input-small',
                             'date'=>'form-control input-small bootstrap_date',
                             'time'=>'form-control input-small',
                             'task_list'=>'nav nav-pills nav-stacked',
                             'report_list'=>'nav nav-pills nav-stacked',
                             'start_link'=>'btn btn-primary',
                             'browse_link'=>'',
                             'reset_link'=>'',
                             'file_list'=>'margin_t10'); 

    protected  $js_links=array('back'=>'<a href="javascript: history.go(-1)"> [&laquo; go back]</a>',
                               'close'=>'<a href="Javascript:onClick=window.close()">[close window]</a>',
                               'close_update'=>'<a href="Javascript:onClick=update_calling_page(\'ORIGINAL\')">[close window]</a>');    
}
