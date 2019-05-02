<?php
namespace Seriti\Tools;

use Exception;

//class intended as a pseudo namespace for a group of functions to be referenced as "Html::function_name()"
class Html 
{
    public static function mysqlDumpHtml($data_set,$options=array()) 
    {
        if(isset($options['class'])) {
            $class = 'class="'.$options['class'].'"';
        } else {
            $class = 'class="table  table-striped table-bordered table-hover table-condensed"';  
        }  
        
        $html = '<table '.$class.'>';
        $col_count = mysqli_num_fields($data_set);

        //column headers
        $html .= '<tr>';
        for ($i = 0; $i < $col_count; $i++) {
            $field = mysqli_fetch_field_direct($data_set,$i);
            $html .= '<th>'.str_replace('_',' ',$field->name).'</th>';
        }  
        $html.='</tr>';

        //now add rows
        while($row = mysqli_fetch_row($data_set)) {
            $html .= '<tr>';
            foreach($row as $i => $value) $html .= '<td>'.$value.'</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }
    
    //assumes array() is an array of row arrays created using Mysql:readSqlArray
    public static function arrayDumpHtml($array,$options = array()) 
    {
        $html = '';
        
        if(isset($options['class'])) {
            $class = 'class="'.$options['class'].'"';
        } else {
            $class = 'class="table table-striped table-bordered table-hover table-condensed"';  
        }  
        
        if(count($array)) {
            $html .= '<table '.$class.'>';
            
            //populate all row arrays with key used as header
            $row = reset($array);
            $html .= '<thead>';
            foreach($row as $key => $value) $html .= '<th>'.str_replace('_',' ',$key).'</th>';
            $html .= '</thead>';
            
            $row_no = 0;
            foreach($array as $row) {
                $row_no++;
                $html .= '<tr>';
                foreach($row as $key => $value) $html .= '<td>'.$value.'</td>';
                $html .= '</tr>';
            }  
            
            $html .= '</table>';
        }  
        return $html;
    }
    
    //this is for a 2 dimensional array [col][row] configured similar to an excel spreadsheet
    public static function arrayDumpHtml2($array,$options = array()) 
    {
        $html = '';
        
        if(isset($options['class'])) {
            $class = 'class="'.$options['class'].'"';
        } else {
            $class = 'class="table table-striped table-bordered table-hover table-condensed"';  
        }  
        
        if(count($array)) {
            $col_count = count($array);
            $row_count = count($array[0]);
            
            $html .= '<table '.$class.'>';
            //header/first row
            $html .= '<tr>';
            $r = 0; //first row in array is header
            for ($i = 0; $i < $col_count; $i++) {
                $col_name = str_replace('_',' ',$array[$i][$r]);
                $html .= '<th>'.$col_name.'</th>';
            }
            $html.='</tr>';

            //remaining rows
            for($r = 1; $r < $row_count; $r++) {
                $html .= '<tr>';
                for($c = 0; $c < $col_count; $c++) {
                    $html .= '<td>'.$array[$c][$r].'</td>';
                }  
                $html .= '</tr>';
            } 
            $html .= '</table>';
        }  

        return $html;
    }
    
    
    //equivalent pdf function in Pdf class
    public static function mysqlDrawTable($data_set,$row_h,$col_w,$col_type,$align='L',$options=array(),&$output) 
    {
        $calc_totals = false;
        $csv = false;
                
        if(!isset($options['csv_output'])) $options['csv_output'] = 'NO';
        //if(!isset($options['spacing'])) $options['spacing'] = '2';
        //if(!isset($options['padding'])) $options['padding'] = '2';
        if(!isset($options['width'])) $options['width']='100%';
        if(isset($options['col_total'])) {
            $calc_totals=true;
            $col_total=$options['col_total'];
            $totals=array_fill(0,strlen($col_total),0);
        }
        if($options['csv_output']=='YES') {
            $csv=true;
            $csv_row='';
            $csv_data='';
        } 
        if(isset($options['class'])) {
            $class='class="'.$options['class'].'"';
        } else {
            $class='class="table  table-striped table-bordered table-hover table-condensed"';  
        }  
            
        $col_count = mysqli_num_fields($data_set);
        $row_count = mysqli_num_rows($data_set);
        
        $data = '<table width="'.$options['width'].'" '.$class.'>';
        
        //width settings
        $set_width = false;
        if(is_numeric($options['width'])) {
            $set_width = true;
            $table_w = array_sum($col_w);
            $calc = $options['width']/$table_w;
            foreach ($col_w as $key => $value) $col_w[$key] = round($value*$calc);
            $table_w = array_sum($col_w);
        } 
        
        //column headers grouping
        if(isset($options['col_group'])) {
            $data .= '<tr>';
            $col_group = $options['col_group'];
            
            for($i = 0;$i < $col_count;$i++) {
                if($col_group[$i] == '') $data .= '<td>&nbsp;</td>';
                
                if($col_group[$i] != '' and $col_group[$i] != '-') {
                    $col_span = 1;
                    $j = $i+1;
                    while($col_group[$j] == '-' and $j < $col_count) {
                        $col_span++; 
                        $j++;
                    }
                    $data .= '<td align="center" class="thead" bgcolor="#CCCCCC" colspan="'.$col_span.'">'.$col_group[$i].'</td>';
                } 
            }
            $data .= '</tr>';
            
            if(isset($options['col_group2'])) {
                $data .= '<tr>';
                $col_group = $options['col_group2'];
                
                for($i = 0;$i < $col_count;$i++) {
                    if($col_group[$i] == '') $data .= '<td>&nbsp;</td>';
                    
                    if($col_group[$i] != '' and $col_group[$i] != '-') {
                        $col_span = 1;
                        $j = $i+1;
                        while($col_group[$j] == '-' and $j < $col_count) {
                            $col_span++; 
                            $j++;
                        }
                        $data  .= '<td align="center" class="thead" bgcolor="#CCCCCC" colspan="'.$col_span.'">'.$col_group[$i].'</td>';
                    } 
                }
                $data .= '</tr>';
            }
        }
        
        //column headers individual
        $data .= '<tr>';
        for ($i = 0; $i < $col_count; $i++) {
            $field = mysqli_fetch_field_direct($data_set,$i);
            $col_name = str_replace('_',' ',$field->name);
            $data .= '<td class="thead" bgcolor="#CCCCCC">'.$col_name.'</td>';
            
            if($csv) $csv_row .= Csv::csvPrep($col_name).',';
        }
        $data .= '</tr>';
        
        if($csv) {
            $csv_row = substr($csv_row,0,-1); //strips last comma!
            Csv::csvAddRow($csv_row,$csv_data);
            $csv_row = '';
        } 

        //now add rows
        $row_no = 0;
        while($row = mysqli_fetch_row($data_set)) {
            $row_no++;
            if(fmod($row_no,2) > 0) {
                $data .= '<tr class="trow_alt" bgcolor="#FFFFFF">';
            } else {
                $data .= '<tr class="trow" bgcolor="#EEEEEE">';
            }

            foreach($row as $i => $value) {
                $h_align = '';
                if ($col_type[$i] == 'R' or substr($col_type[$i],0,3) == 'DBL' or substr($col_type[$i],0,3) == 'PCT' 
                    or substr($col_type[$i],0,4) == 'CASH') $h_align = ' style="text-align:right;" ';
                        
                $data .= '<td ';
                if($set_width) $data .= ' width="'.$col_w[$i].'" ';   
                $data .= $h_align.'>'.self::drawTableCell($col_type[$i],$value,$num_value).'</td>';
                
                if($calc_totals)  {
                    if($col_total[$i] == 'T') $totals[$i] += $num_value; else $totals[$i] = 0; 
                }
                
                if($csv) $csv_row .= Csv::csvPrep($value).',';
            }
            $data .= '</tr>';
            if($csv) {
                $csv_row = substr($csv_row,0,-1); //strips last comma!
                Csv::csvAddRow($csv_row,$csv_data);
                $csv_row = '';
            } 
        }
        
        //WRITE COLUMN TOTALS IF REQUIRED
        if($calc_totals) {
            $data.='<tr class="thead" bgcolor="#CCCCCC">';
             for($i=0; $i<$col_count; $i++)
            {
                $h_align=' align="right" ';
                $data.='<td ';
                if($set_width) $data.=' width="'.$col_w[$i].'" ';
                $data.=$h_align.'>';
                if($col_total[$i]=='T')
                {
                    $tmp_type=str_replace('CASH','DBL',$col_type[$i]);//currency symbols unknown!!
                    $data.=self::drawTableCell($tmp_type,$totals[$i],$num_value);
                } else {
                    $data.='&nbsp;';
                } 
                $data.='</td>'; 
            }
            $data.='</tr>';
        }
        
        //ADD XTRA ROW IF PASSED THROUGH
        if(isset($options['row_xtra']))
        {
            $row_xtra=$options['row_xtra'];

            $data.='<tr class="thead" bgcolor="#CCCCCC">';
            foreach($row_xtra as $i=>$value)
            {
                $h_align='';
                if ($col_type[$i]=='R' or substr($col_type[$i],0,3)=='DBL' or substr($col_type[$i],0,3)=='PCT' 
                        or substr($col_type[$i],0,4)=='CASH') $h_align=' align="right" ';
                        
                $data.='<td ';
                if($set_width) $data.=' width="'.$col_w[$i].'" ';
                $data.=$h_align.'>';
                if($value=='') $data.='&nbsp;'; else $data.=self::drawTableCell($col_type[$i],$value,$num_value);
                $data.='</td>';
            }
            $data.='</tr>';
        }

        
        if($csv) $output['csv_data']=$csv_data;
        
        $data.='</table>';
        return $data;
    }
    
    //equivalent pdf function in Pdf class
    public static function arrayDrawTable($array,$row_h,$col_w,$col_type,$align='L',$options=array(),&$output=array())  
    {
        $html = '';
                
        if(!isset($options['csv_output'])) $options['csv_output'] = 'NO';
        if(!isset($options['active_row'])) $options['active_row'] = 0;
        if(!isset($options['active_class'])) $options['active_class'] = 'trow_active';
        if(!isset($options['spacing'])) $options['spacing'] = '2';
        if(!isset($options['padding'])) $options['padding'] = '2';
        if(!isset($options['width'])) $options['width'] = '100%';
        //use to set <colgroup></colgroup> styling elements for columns
        if(!isset($options['colgroup'])) $options['colgroup'] = '';

        if($options['csv_output'] == 'YES') {
            $csv = true;
            $csv_row = '';
            $csv_data = '';
        } else {
            $csv = false;
        } 
        if(isset($options['class'])) {
            $class = 'class="'.$options['class'].'"';
        } else {
            $class = 'class="table table-striped table-bordered table-hover table-condensed"';  
        }
        
                        
        //get array dimensions
        $col_count = count($col_type);
        $row_count = count($array[0]);
        
        $html = '<table width="'.$options['width'].'" cellspacing="'.$options['spacing'].'" cellpadding="'.$options['padding'].'" '.$class.'>';

        if($options['colgroup'] !== '') $html .= $options['colgroup'];
        
        //width settings
        $set_width = false;
        if(is_numeric($options['width'])) {
            $set_width = true;
            $table_w = array_sum($col_w);
            $calc = $options['width']/$table_w;
            foreach ($col_w as $key => $value) $col_w[$key] = round($value*$calc);
            $table_w = array_sum($col_w);
        } 
                
        //construct standard rows
        $header = '<tr>';
        $blank = '<tr>';
        $r = 0; //first row in array is header
        for ($i = 0; $i < $col_count; $i++) {
            $col_name = str_replace('_',' ',$array[$i][$r]);
            $header .= '<th class="thead" bgcolor="#CCCCCC">'.$col_name.'</th>';
            $blank .= '<td>&nbsp;</td>';
            if($csv) $csv_row .= Csv::csvPrep($col_name).',';
        }
        $header .= '</tr>';
        $blank .= '</tr>';
        $html .= $header;
        
        
        if($csv) {
            $csv_row = substr($csv_row,0,-1); //strips last comma!
            Csv::csvAddRow($csv_row,$csv_data);
            $csv_row = '';
        } 
            
        //add remaining rows to table 
        for($r = 1; $r < $row_count; $r++) {
            if($array[0][$r] === 'CUSTOM_ROW') {
                if($array[1][$r] === 'HEADER') $html .= $header;
                if($array[1][$r] === 'BLANK') $html .= $blank;
            } else {  
                if(fmod($r,2) > 0) {
                    $class = 'trow_alt';
                } else {
                    $class = 'trow';
                }
                $class = '';
                if($options['active_row'] and $r == $options['active_row']) $class = $options['active_class'];
                $html .= '<tr class="'.$class.'" >';
                
                for($i = 0; $i < $col_count; $i++) {
                    $value = $array[$i][$r]; //[Col][Row] like Excel
                    
                    $h_align = '';
                    if ($col_type[$i] == 'R' or substr($col_type[$i],0,3) == 'DBL' or 
                        substr($col_type[$i],0,3) == 'PCT' or substr($col_type[$i],0,4) == 'CASH') {
                        $h_align = ' style="text-align:right;" ';
                    }

                    $html .= '<td ';
                    if($set_width) $html .= ' width="'.$col_w[$i].'" ';   
                    $html .=$h_align.'>'.self::drawTableCell($col_type[$i],$value,$num_value).'</td>';
                                    
                    if($csv) $csv_row .= seriti_csv::csv_prep($value).',';
                }
                $html .= '</tr>';
                if($csv) {
                    $csv_row = substr($csv_row,0,-1); //strips last comma!
                    Csv::csvAddRow($csv_row,$csv_data);
                    $csv_row = '';
                }
            }  
        }
        
        $html .= '</table>';
        
        if($csv) $output['csv_data'] = $csv_data;
        
        return $html;
    }
    
    //used by mysql_draw_table() and array_draw_table()
    static function drawTableCell($cell_type,$value,&$num_value) 
    {
        if($value === '') return $value;
        
        $number = false;
        $money = false;
        $decimals = 2;
        $suffix = '';
        
        $options['negative'] = '(RED)';
        $options['1000_spacer'] = ',';
        $options['curr_symbol'] = '';
                
        if(substr($cell_type,0,3) === 'DBL') {
            $number = true;
            if(strlen($cell_type) > 3) $decimals = $cell_type[3];
        }

        if(substr($cell_type,0,3) === 'PCT') {
            $number = true; 
            $suffix = '%';
            if(strlen($cell_type) > 3) $decimals = $cell_type[3];
        }

        if(substr($cell_type,0,4) === 'CASH') {
            $number = true;
            $money = true;
            if(strlen($cell_type) > 4) $decimals = $cell_type[4];
                    
            //extract currency symbol if any
            settype($value,'string');
            $i = 0;
            while(!is_numeric($value[$i]) and $value[$i] !== '-' and $i < 8) $i++;
            if($i>0) {
                $options['curr_symbol'] = substr($value,0,$i);
                $value = substr($value,$i);
            } 
        }
        
        if(substr($cell_type,0,4) == 'BOOL' and is_numeric($value)) {
            if($value != 0) $value = 'True'; else $value='False';
        }  
            
        if($number) {
            $num_value = $value;
            $value = self::formatCellNum($value,$decimals,$options);
        } else {
            $num_value = 0;
        }
        
        $value .= $suffix;
        
        return $value;
    }
    
    //used by mysql_draw_table_cell()
    static function formatCellNum($num,$dec,$options = array()) 
    {
        //return $num;
        
        if(!isset($options['negative'])) {
            $options['negative'] = '(RED)';
            $options['1000_spacer'] = ',';
            $options['curr_symbol'] = '';
        }
        
        if(round($num,$dec)<0.0) $neg = true; else $neg = false;
        
        $num = abs($num);
        $str = number_format($num,$dec);
        if($neg  and $options['negative'] == '(RED)')
        {
            $str = '<font color="#FF0000">('.$options['curr_symbol'].$str.')</font>';
        } else {
            $str = $options['curr_symbol'].$str;
        }
            
        return $str;
    }
    
    static function recordDumpHtml($record,$options = array()) 
    {
        $html = ''; 
        $header = '<tr>';
        $body = '<tr>';
        
        if(!isset($options['layout'])) $options['layout'] = 'ROW';
        
        $html .= '<table class="table  table-striped table-bordered table-hover table-condensed">';
                
        if($options['layout'] === 'ROW') {
            foreach($record as $key => $value) {
                $header .= '<th>'.ucfirst(str_replace('_',' ',$key)).'</th>';
                $body .= '<td>'.$value.'</td>';
            }  
            $html .= '<tr>'.$header.'</tr><tr>'.$body.'</tr>';
        }
        
        if($options['layout'] === 'COLUMN') {
            $html .= '<tr><th>Description</th><th>Value</th></tr>';
            foreach($record as $key => $value) {
                $html .= '<tr>'.
                             '<td>'.ucfirst(str_replace('_',' ',$key)).'</td><td>'.$value.'</td>'.
                             '</tr>';
            }  
        }
        
        $html .= '</table>';  
        return $html;
    } 
    
    //NB: this requires Parsedown class
    public static function markdownToHtml($markdown_text) {
        $html = '';
        
        $Parsedown = new \Parsedown();
        $html = $Parsedown->text($markdown_text);
        
        return $html;
    }   
}
?>
