<?php
namespace Seriti\Tools;

use Exception;
use Seriti\Tools\Date;
use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_DOCS;
//NB: use setasign\FPDF will NOT work; NO NAMESPACING IN ACTUAL fpdf.php file
use \FPDF;

//NB: unless specified other wise the default units are mm and page size A4
class Pdf extends FPDF
{

    public $page_col = 0;
    public $page_title = '';
    public $show_header = true;
    public $custom_margins = true;
    public $font_face = 'Arial';

    public $bg_image = array('images/logo.jpeg',5,140,50,20,'YES'); //NB: YES flag turns off logo image display
    public $page_margin = array(30,20,20,30);//top,left,right,bottom!!
    public $h1_title = array(33,33,33,'B',10,'',8,20,'L','YES',33,33,33,'B',12,20,180);
    public $h2_title  =array(33,33,33,'B',10);
    public $h3_title = array(33,33,33,'B',8);
    public $text = array(33,33,33,'',8);
    public $table = array('00','00','00','',8,'CC','CC','CC');
    public $link = array(0,0,'FF','',8);
    public $footer_text = '';
    public $date_format = 'DD-MMM-YYYY';
    public $time_format = 'HH:MM';
    public $money = array(255,00,00,'()','R');
    public $page_numbering = true;
    public $header_format = array('row_height' =>5 );
    public $show_footer = true;
    public $footer_format = array('row_height' => 3,'font_size' => 8,'horiz_line' => false);
    public $footer = array(33,33,33,'I',8,'',5,0,'C');
    
    protected function getLayout($db,$id) 
    {
        $layout = 0;
        $sql = 'SELECT `sys_text` FROM `system` WHERE `system_id` = "'.$db->escapeSql($id).'" ';
        $layout = $db->readSqlValue($sql,0);
        return $layout; 
    }  
        
    public function setupLayout($param = array()) 
    {
        if(!isset($param['source'])) $param['source']='DB';

        if($param['source'] === 'DB') {
            if(!isset($param['db'])) throw new Exception('PDF_SETUP_ERROR: Database not defined') ;
            if(!isset($param['prefix'])) $param['prefix'] = 'PDF';
        }

        if(!isset($param['image_dir'])) $param['image_dir'] = BASE_UPLOAD.UPLOAD_DOCS; 
             
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_FONT');
        if($value_str!==0) $this->font_face=$value_str;
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_DATE');
        if($value_str!==0) $this->date_format=$value_str;
            
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_IMAGE');
        if($value_str!==0) {
            $this->bg_image=explode(',',$value_str);
            $this->bg_image[0]=$param['image_dir'].$this->bg_image[0];
        }  
        
        if($this->custom_margins) {
            $value_str=$this->getLayout($param['db'],$param['prefix'].'_MARGIN');
            if($value_str!==0) $this->page_margin=explode(',',$value_str);
        }  
        $this->SetMargins($this->page_margin[1],$this->page_margin[0],$this->page_margin[2]);
        $this->SetAutoPageBreak(true,$this->page_margin[3]);
                
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_H1');
        if($value_str!==0) $this->h1_title=explode(',',$value_str); 
        $this->h1_title[0]=hexdec($this->h1_title[0]);
        $this->h1_title[1]=hexdec($this->h1_title[1]);
        $this->h1_title[2]=hexdec($this->h1_title[2]);
        //[3]text style and [4]size
        if($this->h1_title[3]=='N') $this->h1_title[3]='';
        //[9]='YES' to show date, following is color
        $this->h1_title[10]=hexdec($this->h1_title[10]);
        $this->h1_title[11]=hexdec($this->h1_title[11]);
        $this->h1_title[12]=hexdec($this->h1_title[12]);
        //[13]date style and [14]size
        if($this->h1_title[13]=='N') $this->h1_title[13]='';
        //get current date for page header
        $this->h1_title['date']=Date::formatDate(date('Y-m-d'),'MYSQL',$this->date_format);
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_H2');
        if($value_str!==0) $this->h2_title=explode(',',$value_str);   
        $this->h2_title[0]=hexdec($this->h2_title[0]);
        $this->h2_title[1]=hexdec($this->h2_title[1]);
        $this->h2_title[2]=hexdec($this->h2_title[2]);
        if($this->h2_title[3]=='N') $this->h2_title[3]='';
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_H3');
        if($value_str!==0) $this->h3_title=explode(',',$value_str); 
        $this->h3_title[0]=hexdec($this->h3_title[0]);
        $this->h3_title[1]=hexdec($this->h3_title[1]);
        $this->h3_title[2]=hexdec($this->h3_title[2]);
        if($this->h3_title[3]=='N') $this->h3_title[3]='';
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_TEXT');
        if($value_str!==0) $this->text=explode(',',$value_str); 
        $this->text[0]=hexdec($this->text[0]);
        $this->text[1]=hexdec($this->text[1]);
        $this->text[2]=hexdec($this->text[2]);
        if($this->text[3]=='N') $this->text[3]='';
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_TABLE');
        if($value_str!==0) $this->table=explode(',',$value_str); 
        $this->table[0]=hexdec($this->table[0]);
        $this->table[1]=hexdec($this->table[1]);
        $this->table[2]=hexdec($this->table[2]);
        if($this->table[3]=='N') $this->table[3]='';
        //[4] is font size and requires no mods
        $this->table['th_fill']='#'.$this->table[5].$this->table[6].$this->table[7];
        
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_LINK');
        if($value_str!==0) $this->link=explode(',',$value_str); 
        $this->link[0]=hexdec($this->link[0]);
        $this->link[1]=hexdec($this->link[1]);
        $this->link[2]=hexdec($this->link[2]);
        if($this->link[3]=='N') $this->link[3]='';
        
        //$value_str=$this->getLayout($param['db'],$param['prefix'].'_FOOTER');
        //if($value_str!==0) $this->footer_text=explode(',',$value_str); 
        
        $value_str=$this->getLayout($param['db'],$param['prefix'].'_FOOT');
        if($value_str!==0) $this->footer=explode(',',$value_str);   
        $this->footer[0]=hexdec($this->footer[0]);
        $this->footer[1]=hexdec($this->footer[1]);
        $this->footer[2]=hexdec($this->footer[2]);
        if($this->footer[3]=='N') $this->footer[3]='';
    }

    public function changeFont($type = 'TEXT')
    {
        $bold = false;
        if(substr($type,-2) === '-B') {
            $bold = true;
            $type = substr($type,0,-2);
        } 
        
        switch ($type) {
            case 'H1': {
                $this->SetFont('',$this->h1_title[3],$this->h1_title[4]);
                $this->SetTextColor($this->h1_title[0],$this->h1_title[1],$this->h1_title[2]);
                break;
            }
            
            case 'DATE': {
                $this->SetFont('',$this->h1_title[13],$this->h1_title[14]);
                $this->SetTextColor($this->h1_title[10],$this->h1_title[11],$this->h1_title[12]);
                break;
            }
            
            case 'H2': {
                $this->SetFont('',$this->h2_title[3],$this->h2_title[4]);
                $this->SetTextColor($this->h2_title[0],$this->h2_title[1],$this->h2_title[2]);
                break;
            }
            
            case 'H3': {
                $this->SetFont('',$this->h3_title[3],$this->h3_title[4]);
                $this->SetTextColor($this->h3_title[0],$this->h3_title[1],$this->h3_title[2]);
                break;
            }
            
            case 'TEXT': {
                $this->SetFont('',$this->text[3],$this->text[4]);
                $this->SetTextColor($this->text[0],$this->text[1],$this->text[2]);
                break;
            }
            
            case 'TH': {
                $this->SetFont('',$this->table[3],$this->table[4]);
                $this->SetTextColor($this->table[0],$this->table[1],$this->table[2]);
                break;
            }
            
            case 'LINK': {
                $this->SetFont('',$this->link[3],$this->link[4]);
                $this->SetTextColor($this->link[0],$this->link[1],$this->link[2]);
                break;
            }
            
            case 'FOOTER': {
                $this->SetFont('',$this->footer[3],$this->footer[4]);
                $this->SetTextColor($this->footer[0],$this->footer[1],$this->footer[2]);
                break;
            }
        }
        
        //set to bold if required
        if($bold) $this->SetFont('','B');
                             
    }

    //Page header  (called automatically in FPDF but do nothing unless extended  like below)
    //NB: must be PUBLIC as defined public in FPDF 
    public function header() 
    {
        //Logo or full page background image
        //$this->Image('images/test_logo.jpg',10,8,15,15);
        //checkif want image/logo displayed at all
        if(!isset($this->bg_image[5]) or $this->bg_image[5] !== 'YES') {
            $this->Image($this->bg_image[0],$this->bg_image[2],$this->bg_image[1],$this->bg_image[3],$this->bg_image[4]);
        } 
        
        //font face for all text...should not be set anywhere else
        $this->SetFont($this->font_face);

        if($this->show_header) {
            $this->changeFont('H1');
            $this->SetXY($this->h1_title[7],$this->h1_title[6]);
            
            //ALL documents page header text if any set
            if($this->h1_title[5] != '')
            {
                $this->Cell(0,$this->header_format['row_height'],$this->h1_title[5],0,0,$this->h1_title[8],0);
                $this->Ln(7);
                $this->SetX($this->h1_title[7]);
             }
            
            //SPECIFIC document header text 
            //$this->Cell(0,7,$this->page_title,0,0,$this->h1_title[8],0);
            $this->MultiCell(0,$this->header_format['row_height'],$this->page_title,0,$this->h1_title[8],0);
            
            //date display
            if($this->h1_title[9] === 'YES')
            {
                $this->changeFont('DATE');
                $this->SetXY($this->w - $this->h1_title[16],$this->h1_title[15]);
                $this->Cell(0,0,$this->h1_title['date'],0,0,'L',0);
            }
            
            //set to start of page after top margin
            $this->SetY($this->page_margin[0]);
        }  
        
        
    }

    //Page footer (called automatically in FPDF but do nothing unless extended  like below)
    //NB: must be PUBLIC as defined public in FPDF 
    public function footer() 
    {
        if($this->show_footer) {
            $this->changeFont('FOOTER');
            
            if($this->footer[6] < $this->page_margin[3]) {
                $offset =- 1*$this->page_margin[3]+$this->footer[6];
            } else {
                $offset =- 1*$this->page_margin[3];
            }    
            $this->SetXY($this->footer[7],$offset);
            
            if($this->footer_format['horiz_line']) {
                $this->Cell(0,1,' ','B',1);
                $this->Ln($this->footer_format['row_height']);
            }
            
            //ALL documents page footer text if any set
            if($this->footer[5] != '') {
                $this->MultiCell(0,$this->footer_format['row_height'],$this->footer[5],0,$this->footer[8],0);
                $this->Ln($this->footer_format['row_height']);
            }
            
            //Page number
            if($this->page_numbering) $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
        }  
    }

    protected function prepareValueStr($str_type,$value,&$num_value,&$num_type)
    {
        $number = false;
        $money = false;
        $negative = '-';
        $spacer = ',';
        $symbol = '';
        $decimals = 2;
        $suffix = '';
        //for all unspecified types
        $value_str = $value;
        
        //first cast value into a string in case not one (NB for extracting currency symbol)
        $value = strval($value);
        if($value == '') return $value_str;
        
        
        if($str_type === 'DATE') {
            $value_str = Date::formatDate($value,'MYSQL',$this->date_format);
        }
         
        if($str_type === 'TIME') {
            $value_str = Date::formatTime($value,'MYSQL',$this->time_format);
        } 
            
        if(substr($str_type,0,3) === 'DBL')
        {
            $number = true;
            $value_str = $value;
            $num_type = 'DBL';
            if(strlen($str_type) > 3) $decimals = $str_type[3];
        }

        if(substr($str_type,0,3) === 'PCT')
        {
            $number = true;
            $value_str = $value;
            $suffix = '%';
            $num_type = 'PCT';
            if(strlen($str_type) > 3) $decimals = $str_type[3];
        }

        if(substr($str_type,0,4) === 'CASH')
        {
            //$negative='()';
            $negative = $this->money[3];
            $number = true;
            $num_type = 'CASH';
            if(strlen($str_type) > 4) $decimals = $str_type[4];
        
            //extract currency symbol if any
            settype($value,'string');
            $i = 0;
            while(!is_numeric($value[$i]) and $value[$i] !== '-' and $i < 8) $i++;
            if($i > 0) {
                $symbol = substr($value,0,$i);
                $value_str = substr($value,$i);
            } else {
                $value_str = $value; 
            }
        }
            
        if($number)
        {
            $num_value = floatval($value_str);
            $format_ok = false;
            $i = 0;
            while(!$format_ok and $i < 3) {
                $i++;
                if(round($num_value,$decimals) < 0.0) $neg = true; else $neg = false;
                $value_str = number_format(abs($num_value),$decimals);
                if($spacer !== ',') $value_str = str_replace(',',$spacer,$value_str);
                if($neg) {
                    if($negative == '()') {
                        $value_str = '('.$symbol.$value_str.')'; 
                    } else {
                        $value_str = $symbol.$negative.$value_str;
                    }
                } else {
                    $value_str=$symbol.$value_str;
                }
                if(strlen($value_str) > 12) {
                    if($i == 1){ $suffix = 'M'; $divide = 1000000; }
                    if($i == 2){ $suffix = 'B'; $divide = 1000; }
                    if($i == 3){ $suffix = 'T'; $divide = 1000; }
                    
                    $num_value = $num_value/$divide;
                    if($decimals < 2) $decimals = 2;
                } else {
                    $format_ok = true;
                } 
            }
            
        } else {
            $num_value = 0;
        }
        
        $value_str .= $suffix;
        
        return $value_str;
    }

    protected function drawCellContents($cell_type,$cell_width,$cell_height,$value,$align,&$num_value)
    {
        $reset_font = false;

        $value_str = $this->prepareValueStr($cell_type,$value,$num_value,$num_type);
        if($num_type === 'CASH' and $value_str[0] === '(')
        {
            $old_color = $this->TextColor;
            //$this->SetTextColor(255,0,0);
            $this->SetTextColor($this->money[0],$this->money[1],$this->money[2]);
            $reset_font = true; 
        }
        
        //$value_str.=$cell_type;
        
        $this->MultiCell($cell_width,$cell_height,$value_str,0,$align,0);
        
        if($reset_font) $this->SetTextColor(0,0,0);
    }

    public function mysqlDrawTable($data_set,$row_h,$col_w,$col_type,$align = 'L',$options = array())
    {
        //configure any option defaults
        $calc_totals = false;
        $reset_font = false;
        if(!isset($options['resize_cols'])) $options['resize_cols'] = false;
        //formating options
        $format_header = array('fill' => $this->table['th_fill'],'font' => 'TH','line_width' => 0.1,'line_color' => '#CCCCCC');
        $format_text = array('fill' => '#CCCCCC','font' => 'TEXT','line_width' => 0.1);
        
        if(isset($options['font_size'])) {
            $old_font_size = $this->FontSizePt;
            
            $format_text['font_size'] = $options['font_size'];
            $format_header['font_size'] = $options['font_size'];
        }

        if(isset($options['col_total'])) {
            $calc_totals = true;
            $col_total = $options['col_total'];
            $totals = array();
        }
                 
        //determine start position of table based on alignment
        $table_w = array_sum($col_w);
        $page_w = $this->w-($this->lMargin+$this->rMargin);
        $page_h = $this->h-($this->tMargin+$this->bMargin);

        //column widths need to be adjusted to fit page
        if($table_w > $page_w or $options['resize_cols']) {
            $calc = $page_w/$table_w;
            foreach ($col_w as $key => $value) $col_w[$key] = round($value*$calc);
            $table_w = array_sum($col_w); 
        }
        
        if(!is_int($align)) {
            switch ($align) {
                case 'C' : $align = round(($page_w-$table_w)/2); break;
                case 'L' : $align = 0;                           break;
                case 'R' : $align = round(($page_w-$table_w));  break;
            }
            if ($align<0) $align = 0;
        }

        $x_start = $this->lMargin+$align;
        $x_end = $x_start+$table_w;

        //draw table headers
        $header_inc = 0;
        $this->formatCell($format_header);
        
        //set X position based on calculated offset($align) from left margin
        //could probaly use $this->SetX(x_start) but do not have time to test
        if ($align != 0) $this->Cell($align);
        $y_inc = $row_h;
        $y_start = $this->GetY();
        
        $col_count = mysqli_num_fields($data_set);
        $row_count  =mysqli_num_rows($data_set);

        //write any grouping headers - max two layers catered for
        //NB: these will NOT be repeated on subsequent page feeds
        if(isset($options['col_group'])) {
            $x_temp = $x_start;
            $col_group = $options['col_group'];
            
            for($i = 0;$i < $col_count;$i++) {
                if($col_group[$i] != '' and $col_group[$i] != '-') {
                    $group_width = $col_w[$i];
                    $j = $i+1;
                    while($col_group[$j] == '-' and $j < $col_count) {
                        $group_width += $col_w[$j];
                        $j++;
                    }
                    $this->SetXY($x_temp,$y_start);
                    $this->Cell($group_width,$row_h,$col_group[$i],1,0,'C',1);
                } 
                $x_temp += $col_w[$i];
            }
            
            $this->ln($row_h);
            $y_start = $this->GetY();
            
            if(isset($options['col_group2'])) {
                $x_temp = $x_start;
                $col_group = $options['col_group2'];
                
                for($i = 0;$i < $col_count;$i++) {
                    if($col_group[$i] != '' and $col_group[$i] != '-') {
                        $group_width = $col_w[$i];
                        $j = $i+1;
                        while($col_group[$j] == '-' and $j < $col_count) {
                            $group_width += $col_w[$j];
                            $j++;
                        }
                        $this->SetXY($x_temp,$y_start);
                        $this->Cell($group_width,$row_h,$col_group[$i],1,0,'C',1);
                    } 
                    $x_temp += $col_w[$i];
                }
                
                $this->ln($row_h);
                $y_start = $this->GetY();
            }
        }
        
        //get main HEADER row maximum height
        $max = 0;
        for($i = 0; $i < $col_count; $i++) {
            $field = mysqli_fetch_field_direct($data_set,$i);
            $col_name = $field->name;
            $col_name = str_replace('_',' ',$col_name);
            $n = $this->countLines($col_w[$i],$col_name);
            $max = max($n,$max);
        }
        $header_inc = $max*$row_h;  
        
        //get FIRST row maximum height and set pointer back to first row
        $max = 0;
        $row = mysqli_fetch_array($data_set,MYSQLI_NUM);
        for($i = 0; $i < $col_count; $i++) {
            $n = $this->countLines($col_w[$i],$row[$i]);
            $max = max($n,$max);
        }
        $row_inc = $max*$row_h; 
        mysqli_data_seek($data_set,0);
        
        //check that sufficient space for header AND first row
        $page_space = ($this->h - $this->GetY() - $this->bMargin)-($header_inc + $row_inc);
        if($page_space < 10) {
            $this->AddPage();
            $y_start = $this->GetY();
        } 
        
         
        //finally draw border around each header cell in row and fill
        $x_temp = $x_start;
        for($i = 0; $i < $col_count; $i++) {
            $this->Rect($x_temp,$y_start,$col_w[$i],$header_inc,'DF');
            $x_temp = $x_temp+$col_w[$i];
        }
        
        //write header text 
        $this->SetXY($x_start,$y_start);
        for($i = 0; $i < $col_count; $i++) {
            $field = mysqli_fetch_field_direct($data_set,$i);
            $col_name = str_replace('_',' ',$field->name);
            $txt_align = 'C';
            $x_temp = $this->GetX();
            $y_temp = $this->GetY();
            $this->MultiCell($col_w[$i],$row_h,$col_name,0,$txt_align,0);
            $this->SetXY($x_temp+$col_w[$i],$y_temp);
        }
        
        //start next row
        $this->ln($header_inc);
            
        //draw table row contents with word wrap to fit column width
        $this->formatCell($format_text); 
        $row_no = 0;
        while($row = @mysqli_fetch_array($data_set,MYSQLI_NUM)) {
            $row_no++;
            
            if ($align != 0) $this->Cell($align);
            $y_inc = $row_h;

            for($i = 0; $i < count($row); $i++) {
                $y_start = $this->GetY();
                
                if (($col_type[$i] == 'email' or $col_type[$i] == 'www') and $row[$i] != '') {
                    $this->changeFont('LINK');
                }
                
                $txt_align = 'L';
                if ($col_type[$i] == 'R' or substr($col_type[$i],0,3) == 'DBL' or substr($col_type[$i],0,3) == 'PCT' 
                    or substr($col_type[$i],0,4) == 'CASH') $txt_align='R';
                
                $x_temp = $this->GetX();
                $y_temp = $this->GetY();
                $this->drawCellContents($col_type[$i],$col_w[$i],$row_h,$row[$i],$txt_align,$num_value);
                if($calc_totals) {
                    if(!isset($totals[$i])) $totals[$i] = 0;
                    if($col_total[$i] == 'T') $totals[$i] += $num_value; else $totals[$i] = 0; 
                }
                
                $cell_h = $this->GetY()-$y_temp;
                if ($cell_h > $y_inc) $y_inc = $cell_h;
                $this->SetXY($x_temp+$col_w[$i],$y_temp);

                if (($col_type[$i] == 'email' or $col_type[$i] == 'www') and $row[$i] != '') {
                     if ($col_type[$i] == 'email') $link_url = 'mailto:'.$row[$i];
                     if ($col_type[$i] == 'www')   $link_url = 'http://'.$row[$i];
                     $this->Link($x_temp,$y_temp,$col_w[$i],$cell_h,$link_url);
                     
                     $this->formatCell($format_text);
                }
            }

            //now that we know final row height...draw border around each cell in row
            $x_temp = $x_start;
            for($i = 0; $i < count($row); $i++) {
                $this->Rect($x_temp,$y_start,$col_w[$i],$y_inc,'D');
                $x_temp = $x_temp+$col_w[$i];
            }

            //start next row
            $this->ln($y_inc);
            
            //new page check based on space available and next row height
            $page_space = $this->h - $this->GetY() - $this->bMargin;
            if($row_no < $row_count) {
                $row = mysqli_fetch_array($data_set,MYSQLI_NUM);
                $max = 0;
                for($i = 0; $i < $col_count; $i++) {
                    $n = $this->countLines($col_w[$i],$row[$i]);
                    $max = max($n,$max);
                }
                $page_space =   $page_space-$max*$row_h;
                mysqli_data_seek($data_set,$row_no); 
            }
            
            if($page_space < 10 and $row_no < $row_count) {
                $this->AddPage();
                
                //redraw column titles
                $this->formatCell($format_header);
                $y_start = $this->GetY();
                if ($align != 0) $this->Cell($align);
                
                //draw border around each cell in header row and fill
                $x_temp = $x_start;
                for($i = 0; $i < $col_count; $i++) {
                    $this->Rect($x_temp,$y_start,$col_w[$i],$header_inc,'DF');
                    $x_temp = $x_temp+$col_w[$i];
                }
                
                //write header text
                $this->SetXY($x_start,$y_start);
                for($i = 0; $i < $col_count; $i++) {
                    $field = mysqli_fetch_field_direct($data_set,$i);
                    $col_name = str_replace('_',' ',$field->name);
                    $txt_align = 'C';
                    $x_temp = $this->GetX();
                    $y_temp = $this->GetY();
                    $this->MultiCell($col_w[$i],$row_h,$col_name,0,$txt_align,0);
                    $this->SetXY($x_temp+$col_w[$i],$y_temp);
                }
                
                //start next row and reset drawing parameters
                $this->ln($header_inc);
                $this->formatCell($format_text);
            }
        }
        
        //WRITE COLUMN TOTALS IF REQUIRED
        if($calc_totals) {
            $this->formatCell($format_header);
            
            $x_temp = $x_start;
            $y_temp = $this->GetY();
            for($i = 0; $i < $col_count; $i++) {
                //fill block and add border regardless if no content
                $this->Rect($x_temp,$y_temp,$col_w[$i],$row_h,'DF');
            
                if($col_total[$i] == 'T') {
                    $this->SetXY($x_temp,$y_temp);
                    $value_str = $this->prepareValueStr($col_type[$i],$totals[$i],$num_value,$num_type);
                    if($num_type == 'CASH' and $value_str[0] == '(') {
                        $old_color = $this->TextColor;
                        $this->SetTextColor(255,0,0);
                        $reset_font = true; 
                    }
                    
                    $this->MultiCell($col_w[$i],$row_h,$value_str,0,'R',0);
                    if($reset_font) $this->SetTextColor(0,0,0);
                } 
                $x_temp += $col_w[$i];
            }
            
            $this->ln($row_h);
        }
        
        //ADD XTRA ROW IF PASSED TRHROUGH
        if(isset($options['row_xtra'])) {
            $row_xtra = $options['row_xtra'];
            
            $this->formatCell($format_header);
        
            $x_temp = $x_start;
            $y_temp = $this->GetY();
            for($i = 0; $i < col_count; $i++) {
                //fill block and add border regardless if no content
                $this->Rect($x_temp,$y_temp,$col_w[$i],$row_h,'DF');
            
                if($row_xtra[$i] != '') {
                    $this->SetXY($x_temp,$y_temp);
                    $value_str = $this->prepareValueStr($col_type[$i],$row_xtra[$i],$num_value,$num_type);
                    if($num_type == 'CASH' and $value_str[0] == '(') {
                        $old_color = $this->TextColor;
                        $this->SetTextColor(255,0,0);
                        $reset_font = true; 
                    }
                    
                    $this->MultiCell($col_w[$i],$row_h,$value_str,0,'R',0);
                    if($reset_font) $this->SetTextColor(0,0,0);
                } 
                $x_temp += $col_w[$i];
            }
            
            $this->ln($row_h);
        }
        
        //reset drawing parameters to defaults
        $this->formatCell($format_text);
        if(isset($options['font_size'])) $this->SetFontSize($old_font_size);
    }

    public function arrayDrawTable($array,$row_h,$col_w,$col_type,$align = 'L',$options = array())
    {
        //configure any option defaults
        $calc_totals = false;
        if(!isset($options['resize_cols'])) $options['resize_cols'] = false;
        if(!isset($options['header_align'])) $options['header_align'] = 'C';
        if(!isset($options['header_show'])) $options['header_show'] = true;
        
        //formating options
        $format_header = array('fill'=>$this->table['th_fill'],'font'=>'TH','line_width'=>0.1,'line_color'=>'#CCCCCC');
        $format_text = array('fill'=>'#CCCCCC','font'=>'TEXT','line_width'=>0.1);
        if(isset($options['font_size'])) {
            $old_font_size = $this->FontSizePt;
            $format_text['font_size'] = $options['font_size'];
            $format_header['font_size'] = $options['font_size'];
        }
        //use to overwrite any format settings.
        if(isset($options['format_header'])) $format_header = array_merge($format_header,$options['format_header']);
        if(isset($options['format_text'])) $format_text = array_merge($format_text,$options['format_text']);
        //reset default money format options
        if(isset($options['money'])) $this->money = $options['money'];  
        
        //determine start position of table based on alignment
        $table_w = array_sum($col_w);
        $page_w = $this->w-($this->lMargin+$this->rMargin);
        
        //column wdths need to be adjusted to fit page
        if($table_w > $page_w or $options['resize_cols']) {
            $calc = $page_w/$table_w;
            foreach ($col_w as $key => $value) $col_w[$key] = round($value*$calc);
            $table_w = array_sum($col_w); 
        }

        if (!is_int($align)) {
            switch ($align) {
                case 'C' : $align = round(($page_w-$table_w)/2); break;
                case 'L' : $align = 0;                           break;
                case 'R' : $align = round(($page_w-$table_w));  break;
            }
            if ($align < 0) $align = 0;
        }
        
        //start end coordinates
        $y_start = $this->GetY();
        $x_start = $this->lMargin+$align;
        $x_end  = $x_start+$table_w;
        
        //get array dimensions
        $col_count = count($col_type);
        $row_count = count($array[0]);
        
        //draw table headers
        $header_inc = 0;
        $this->formatCell($format_header);

        //get main HEADER and first row maximum height
        $max1 = 0;
        $max2 = 0;
        for($i = 0; $i < $col_count; $i++) {
            $n = $this->countLines($col_w[$i],$array[$i][0]);
            $max1 = max($n,$max1);
            if(isset($array[$i][1])) {
                $n = $this->countLines($col_w[$i],$array[$i][1]);
                $max2 = max($n,$max2);
            }  
        }
        $header_inc = $max1*$row_h; 
        $row_inc = $max2*$row_h;  
        
        //check that sufficient space for header AND first row
        $page_space = ($this->h - $this->GetY() - $this->bMargin)-($header_inc + $row_inc);
        if($page_space < 10) {
            $this->AddPage();
            $y_start = $this->GetY();
        } 
        
        if($options['header_show']) {
            //finally draw border around each header cell in row and fill
            $x_temp = $x_start;
            for($i = 0; $i < $col_count; $i++) {
                $this->Rect($x_temp,$y_start,$col_w[$i],$header_inc,'DF');
                $x_temp = $x_temp+$col_w[$i];
            }
            
            //and lastly write header text
            if($align != 0) $this->Cell($align);
            for($i = 0; $i < $col_count; $i++) {
                $txt_align = $options['header_align'];
                $x_temp = $this->GetX();
                $y_temp = $this->GetY();
                $this->MultiCell($col_w[$i],$row_h,$array[$i][0],0,$txt_align,0);
                $this->SetXY($x_temp+$col_w[$i],$y_temp);
            }
            
            //start first row
            $this->ln($header_inc);
        } 
        
            
        //draw table row contents with word wrap to fit column width
        $this->formatCell($format_text);
        $fill = 0;
        for($r = 1; $r < $row_count; $r++) {
            if($align != 0) $this->Cell($align);
            $y_inc = $row_h;

            if($array[0][$r] === 'CUSTOM_ROW') {
                //currently only BLANK option supported, not HEADER
                $y_start = $this->GetY();
            } else {  
                for($i = 0; $i < $col_count; $i++) {
                    $y_start = $this->GetY();
                    
                    if (($col_type[$i] == 'email' or $col_type[$i] == 'www') and $array[$i][$r] != '') {
                         $this->changeFont('LINK');
                    }
                    
                    $txt_align = 'L';
                    if ($col_type[$i] == 'R' or substr($col_type[$i],0,3) == 'DBL' or substr($col_type[$i],0,3) == 'PCT' 
                            or substr($col_type[$i],0,4) == 'CASH') $txt_align = 'R';
                    
                    $x_temp = $this->GetX();
                    $y_temp = $this->GetY();
                    $this->drawCellContents($col_type[$i],$col_w[$i],$row_h,$array[$i][$r],$txt_align,$num_value);
                    if($calc_totals) {
                        if($col_total[$i] == 'T') $totals[$i] += $num_value; else $totals[$i] = 0; 
                    }
                    
                    $cell_h = $this->GetY()-$y_temp;
                    if ($cell_h > $y_inc) $y_inc = $cell_h;
                    $this->SetXY($x_temp+$col_w[$i],$y_temp);
                    
                    if (($col_type[$i] == 'email' or $col_type[$i] == 'www') and $array[$i][$r] != '') {
                         if ($col_type[$i] == 'email') $link_url = 'mailto:'.$array[$i][$r];
                         if ($col_type[$i] == 'www')   $link_url = 'http://'.$array[$i][$r];
                         $this->Link($x_temp,$y_temp,$col_w[$i],$cell_h,$link_url);
                         //reset font
                         $this->formatCell($format_text);
                    }
                }
            }  

            //now that we know final row height...draw border around each cell in row
            $x_temp = $x_start;
            for($i = 0; $i < $col_count; $i++) {
                $this->Rect($x_temp,$y_start,$col_w[$i],$y_inc,'D');
                $x_temp = $x_temp+$col_w[$i];
            }

            //start next row
            $this->ln($y_inc);
            
            //new page check based on space available and next row height
            $page_space = $this->h - $this->GetY() - $this->bMargin;
            if(($r+1) < $row_count) {
                $max = 0;
                for($i = 0; $i < $col_count; $i++) {
                    $n = $this->countLines($col_w[$i],$array[$i][$r+1]);
                    $max = max($n,$max);
                }
                $page_space = $page_space-$max*$row_h;
            }
            
            if($page_space < 10 and ($r+1) < $row_count) {
                $this->AddPage();
                
                //redraw column titles
                $this->formatCell($format_header);
                $y_start = $this->GetY();
                if ($align != 0) $this->Cell($align);
                
                //draw border and fill
                $x_temp = $x_start;
                for($i = 0; $i < $col_count; $i++) {
                    $this->Rect($x_temp,$y_start,$col_w[$i],$header_inc,'DF');
                    $x_temp = $x_temp+$col_w[$i];
                }
                
                //write header text
                if($align != 0) $this->Cell($align);
                for($i = 0; $i < $col_count; $i++) {
                    $txt_align = $options['header_align'];
                    $x_temp = $this->GetX();
                    $y_temp = $this->GetY();
                    $this->MultiCell($col_w[$i],$row_h,$array[$i][0],0,$txt_align,0);
                    $this->SetXY($x_temp+$col_w[$i],$y_temp);
                }
                
                //start next row and reset drawing parameters
                $this->ln($header_inc);
                $this->formatCell($format_text);
            }
        }

        //reset drawing parameters to defaults
        $this->formatCell($format_text);
        if(isset($options['font_size'])) $this->SetFontSize($old_font_size);
    }

    //same as above but with column/page_split option
    public function arrayDrawTable2($array,$row_h,$col_w,$col_type,$align = 'L',$options = array())
    {
        if(!isset($options['resize_cols'])) $options['resize_cols']=false;
        
        if(!isset($options['page_split'])) $options['page_split']=2;
        $options['split_gap'] = 2;

        //if more than one col then resize col widths
        if($options['page_split'] > 1) $options['resize_cols']=true;
                
        //formating options
        $format_header = array('fill'=>$this->table['th_fill'],'font' => 'TH','line_width' => 0.1,'line_color' => '#CCCCCC');
        $format_text = array('fill' => '#CCCCCC','font' => 'TEXT','line_width' => 0.1);
        if(isset($options['font_size'])) {
            $old_font_size = $this->FontSizePt;
            $format_text['font_size'] = $options['font_size'];
            $format_header['font_size'] = $options['font_size'];
        }
        
        //determine start position of table based on alignment
        $table_w = array_sum($col_w);
        $page_w = $this->w-($this->lMargin+$this->rMargin);
        
        if($options['page_split'] > 1) $page_w = $page_w/$options['page_split'] - $options['split_gap'];

        //column wdths need to be adjusted to fit page
        if($table_w > $page_w or $options['resize_cols']) {
            $calc = $page_w/$table_w;
            foreach ($col_w as $key=>$value) $col_w[$key] = round($value*$calc);
            $table_w = array_sum($col_w); 
        }

        if(!is_int($align)) {
            switch($align) {
                case 'C' : $align = round(($page_w-$table_w)/2); break;
                case 'L' : $align = 0;                           break;
                case 'R' : $align = ound(($page_w-$table_w));  break;
            }
            if ($align < 0) $align = 0;
        }
        
        //start end coordinates
        $y_start = $this->GetY();
        $x_start = $this->lMargin+$align;
        $x_end = $x_start+$table_w;
        
        if($options['page_split'] > 1 and $this->page_col > 0) $x_start += $page_w*$this->page_col+$options['split_gap'];
        
        //get array dimensions
        $col_count = count($col_type);
        $row_count = count($array[0]);
        
        //draw table headers
        $header_inc = 0;
        $this->formatCell($format_header);

        //get main HEADER and first row maximum height
        $max1 = 0;
        $max2 = 0;
        for($i = 0; $i < $col_count; $i++) {
            $n = $this->countLines($col_w[$i],$array[$i][0]);
            $max1 = max($n,$max1);
            $n = $this->countLines($col_w[$i],$array[$i][1]);
            $max2 = max($n,$max2);
        }
        $header_inc = $max1*$row_h; 
        $row_inc = $max2*$row_h;  
        
        //check that sufficient space for header AND first row
        $page_space = ($this->h - $this->GetY() - $this->bMargin)-($header_inc + $row_inc);
        if($page_space < 10) {
            if($options['page_split'] > 1 and $this->page_col == 0) {
                $this->page_col++;
                $x_start = $this->lMargin+$align;
                $x_start += $page_w*$this->page_col+$options['split_gap']; 
                $x_end = $x_start+$table_w;
                $y_start = $this->tMargin;
                $this->SetXY($x_start,$y_start);  
            } else {
                $this->AddPage();
                $x_start = $this->lMargin+$align;
                $y_start = $this->GetY();
            }   
        } 
         
        //finally draw border around each header cell in row and fill
        $x_temp = $x_start;
        for($i = 0; $i < $col_count; $i++) {
            $this->Rect($x_temp,$y_start,$col_w[$i],$header_inc,'DF');
            $x_temp = $x_temp+$col_w[$i];
        }
        $this->SetX($x_start);
        
        
        //and lastly write header text
        if($align != 0) $this->Cell($align);
        for($i = 0; $i < $col_count;$i++) {
            $txt_align = 'C';
            $x_temp = $this->GetX();
            $y_temp = $this->GetY();
            $this->MultiCell($col_w[$i],$row_h,$array[$i][0],0,$txt_align,0);
            $this->SetXY($x_temp+$col_w[$i],$y_temp);
        }
        
        //start first row
        $this->ln($header_inc);
        $this->SetX($x_start);
            
        //draw table row contents with word wrap to fit column width
        $this->formatCell($format_text);
        $fill = 0;
        for($r = 1; $r < $row_count; $r++) {
            if($align != 0) $this->Cell($align);
            $y_inc = $row_h;
            $y_row = $this->GetY();
            
            for($i = 0; $i < $col_count; $i++) {
                $txt_align = 'L';

                if($col_type[$i] == 'R') $txt_align = 'R';

                if(($col_type[$i] == 'email' or $col_type[$i] == 'www') and $array[$i][$r] != '') {
                    $this->changeFont('LINK'); 
                }

                $x_temp = $this->GetX();
                $y_temp = $this->GetY();
                $this->MultiCell($col_w[$i],$row_h,$array[$i][$r],0,$txt_align,0);
                $cell_h = $this->GetY()-$y_temp;
                if($cell_h > $y_inc) $y_inc = $cell_h;
                $this->SetXY($x_temp+$col_w[$i],$y_temp);

                if(($col_type[$i] == 'email' or $col_type[$i] == 'www') and $array[$i][$r] != '') {
                     if($col_type[$i] == 'email') $link_url = 'mailto:'.$array[$i][$r];
                     if($col_type[$i] == 'www')   $link_url = 'http://'.$array[$i][$r];
                     $this->Link($x_temp,$y_temp,$col_w[$i],$cell_h,$link_url);
                     //reset font
                     $this->formatCell($format_text);
                }
            }

            //now that we know final row height...draw border around each cell in row
            $x_temp = $x_start;
            for($i = 0; $i < $col_count; $i++) {
                $this->Rect($x_temp,$y_row,$col_w[$i],$y_inc,'D');
                $x_temp = $x_temp+$col_w[$i];
            }

            //start next row
            $this->ln($y_inc);
            $this->SetX($x_start);
            
            //new page check based on space available and next row height
            $page_space = $this->h - $this->GetY() - $this->bMargin;
            if(($r+1) < $row_count) {
                $max = 0;
                for($i = 0; $i < $col_count; $i++) {
                    $n = $this->countLines($col_w[$i],$array[$i][$r+1]);
                    $max = max($n,$max);
                }
                $page_space = $page_space-$max*$row_h;
            }
            
            //pagination and column switching code
            if($page_space < 10 and ($r+1) < $row_count) {
                if($options['page_split'] > 1) {
                    $this->page_col++;
                    if($this->page_col < $options['page_split']) {
                        $y_start = $this->tMargin;
                        $x_start = $x_start+$table_w+$options['split_gap'];
                        $x_end  = $x_start+$table_w;
                        $this->SetXY($x_start,$y_start);
                    } else {
                        $this->AddPage();
                        $x_start = $this->lMargin+$align;
                        $y_start = $this->GetY();
                        $this->page_col = 0;
                    } 
                } else {  
                    $this->AddPage();
                    $x_start = $this->lMargin+$align;
                    $y_start = $this->GetY();
                }  
                
                //redraw column titles
                $this->formatCell($format_header);
                if ($align != 0) $this->Cell($align);
                
                //draw border and fill
                $x_temp = $x_start;
                for($i = 0; $i < $col_count; $i++) {
                    $this->Rect($x_temp,$y_start,$col_w[$i],$header_inc,'DF');
                    $x_temp = $x_temp+$col_w[$i];
                }
                $this->SetX($x_start);
                
                //write header text
                if($align != 0) $this->Cell($align);
                for($i = 0; $i < $col_count; $i++) {
                    $txt_align = 'C';
                    $x_temp = $this->GetX();
                    $y_temp = $this->GetY();
                    $this->MultiCell($col_w[$i],$row_h,$array[$i][0],0,$txt_align,0);
                    $this->SetXY($x_temp+$col_w[$i],$y_temp);
                }
                
                //start next row and reset drawing parameters
                $this->ln($header_inc);
                $this->SetX($x_start);
                
                $this->formatCell($format_text);
            }
        }

        //reset drawing parameters to defaults
        $this->formatCell($format_text);
        if(isset($options['font_size'])) $this->SetFontSize($old_font_size);
    }

    public function arrayDrawTableAdv($pos_x,$pos_y,$cell_txt,$cell_format,$row_h,$col_w,$options = array())
    {
        //calculate table parameters
        $table_w = array_sum($col_w);
        $col_count = count($col_w);
        $row_count = count($cell_txt[0]);
        
        //check options and set defaults
        if(!isset($options['col_align'])) $col_align = array_fill(0,$col_count,'L'); else $col_align=$options['col_align'];
        if(!isset($options['col_type'])) $col_type = array_fill(0,$col_count,''); else $col_type=$options['col_type'];
            
        //get HEADER row maximum height
        $max = 0;
        for($i = 0; $i < $col_count; $i++) {
            $this->formatCell($cell_format[$i][0]);
            $n = $this->countLines($col_w[$i],$cell_txt[$i][0]);
            $max = max($n,$max);
        }
        $header_inc = $max*$row_h;  
        
        //get FIRST row maximum height
        $max = 0;
        for($i = 0; $i < $col_count; $i++) {
            $this->formatCell($cell_format[$i][1]);
            $n = $this->countLines($col_w[$i],$cell_txt[$i][1]);
            $max = max($n,$max);
        }
        $row_inc = $max*$row_h; 
        
        //check that sufficient space for header AND first row
        $page_space = ($this->h - $this->GetY() - $this->bMargin)-($header_inc + $row_inc);
        if($page_space < 10) {
            $this->AddPage();
            $pos_y = $this->GetY();
        } 
            
        //now draw header borders and background fill
        $this->SetDrawColor(220);
        $this->SetLineWidth(.3);
        $x_temp = $pos_x;
        for($i = 0; $i < $col_count; $i++) {
            $this->formatCell($cell_format[$i][0]);
            $this->Rect($x_temp,$pos_y,$col_w[$i],$header_inc,'DF');
            $x_temp = $x_temp+$col_w[$i];
        }
        
        //finally write header text over background fill 
        $x_temp = $pos_x;
        $y_temp = $pos_y;
        for($i = 0; $i < $col_count; $i++) {
            $col_name = $cell_txt[$i][0];
            $txt_align = $col_align[$i];
            $this->SetXY($x_temp,$y_temp);
            $this->formatCell($cell_format[$i][0]);
            $this->MultiCell($col_w[$i],$row_h,$col_name,0,$txt_align,0);
            $x_temp += $col_w[$i];
        }

        
        //NOW DRAW ARRAY ROWS
        $old_format = array();
        $fill = 0;
        $y_temp = $pos_y+$header_inc;
        for($r = 1; $r < $row_count; $r++) {
            $row_inc = $row_h;
            $x_temp = $pos_x;
            for($i = 0; $i < $col_count; $i++) {
                if(($col_type[$i] == 'email' or $col_type[$i] == 'www') and $cell_txt[$i][$r] != '') {
                    $this->changeFont('LINK'); 
                }
                $txt_align = $col_align[$i];
                $this->SetXY($x_temp,$y_temp);
                //check if cell format has changed from previous cell and update if necessary
                if(count(array_diff_assoc($cell_format[$i][$r],$old_format))>0) $this->formatCell($cell_format[$i][$r]);
                $old_format = $cell_format[$i][$r];
                //write contents of cell
                $this->MultiCell($col_w[$i],$row_h,$cell_txt[$i][$r],0,$txt_align,1);
                //get cell height and set max row height
                $cell_h = $this->GetY()-$y_temp;
                if($cell_h > $row_inc) $row_inc = $cell_h;
                //increment x pos to next col
                $x_temp += $col_w[$i];
                //make entire cell a link if required
                if(($col_type[$i] == 'email' or $col_type[$i] == 'www') and $cell_txt[$i][$r] != '') {
                     if($col_type[$i] == 'email') $link_url = 'mailto:'.$cell_txt[$i][$r];
                     if($col_type[$i] == 'www')   $link_url = 'http://'.$cell_txt[$i][$r];
                     $this->Link($x_temp,$y_temp,$col_w[$i],$cell_h,$link_url);
                     $this->changeFont('TEXT');
                }
            }

            //now that we know final row height...draw border around each cell in row
            $this->SetDrawColor(220);
            $this->SetLineWidth(.1);
            $x_temp = $pos_x;
            for($i = 0; $i < $col_count; $i++) {
                $this->Rect($x_temp,$y_temp,$col_w[$i],$row_inc,'D');
                $x_temp += $col_w[$i];
            }

            //set next row y coordinate
            $y_temp += $row_inc;
                    
            //new page check 
            $page_space = $this->h - $this->GetY() - $this->bMargin;
            if(($r+1) < $row_count) {
                $max = 0;
                for($i = 0; $i < $col_count; $i++) {
                    $n = $this->countLines($col_w[$i],$cell_txt[$i][$r+1]);
                    $max = max($n,$max);
                }
                $page_space = $page_space-$max*$row_h;  
            }
            if($page_space < 10 and $r < $row_count) {
                $this->AddPage();
                $old_format = array();
                            
                //draw header borders and background fill
                $this->SetDrawColor(220);
                $this->SetLineWidth(.3);
                $x_temp = $pos_x;
                $y_temp = $this->GetY();
                for($i = 0; $i < $col_count; $i++) {
                    $this->formatCell($cell_format[$i][0]);
                    $this->Rect($x_temp,$y_temp,$col_w[$i],$header_inc,'DF');
                    $x_temp = $x_temp+$col_w[$i];
                }
                
                //write header text over background fill 
                $x_temp = $pos_x;
                for($i = 0; $i < $col_count; $i++) {
                    $col_name = $cell_txt[$i][0];
                    $txt_align = $col_align[$i];
                    $this->SetXY($x_temp,$y_temp);
                    $this->formatCell($cell_format[$i][0]);
                    $this->MultiCell($col_w[$i],$row_h,$col_name,0,$txt_align,0);
                    $x_temp += $col_w[$i];
                }
                
                $y_temp += $header_inc;
            }
            
        }
        
    }

    public function checkForPageBreak($space_limit)
    {
        $page_space = $this->h - $this->GetY() - $this->bMargin;
        if($page_space < $space_limit) $this->AddPage();
    }

    public function drawTextBlock($pos_x,$pos_y,$width,$height,$title,$text,$options = array())
    {
        if(!isset($options['row_h'])) $options['row_h'] = 6;
        if(!isset($options['frame'])) $options['frame'] = true;
        if(!isset($options['title_align'])) $options['title_align'] = 'C';
        if(!isset($options['title_font'])) $options['title_font'] = 'TH';
        if(!isset($options['title_fill'])) $options['title_fill'] = '#CCCCCC';
        if(!isset($options['text_font'])) $options['text_font'] = 'TEXT';
        
        $title_height = 0;
        
        //draw title
        if($title != '') {
            $title_height = $options['row_h'];
            $this->changeFont($options['title_font']);
            $red = hexdec(substr($options['title_fill'],1,2));
            $green = hexdec(substr($options['title_fill'],3,2));
            $blue = hexdec(substr($options['title_fill'],5,2));
            $this->SetFillColor($red,$green,$blue);
            
            $this->SetXY($pos_x,$pos_y);
            $this->Cell($width,$title_height,$title,1,0,$options['title_align'],1);
        }  
        
        //setup block contents
        $this->SetXY($pos_x,$pos_y+$title_height);
        $this->SetFont('','');
        $this->changeFont($options['text_font']);
        //truncate text to fit dimensions before drawing
        $max_lines = floor($height/$options['row_h']);
        $text = $this->truncateText($width,$max_lines,$text);
        $this->MultiCell($width,$options['row_h'],$text,0,'L',0);   
        
        //draw frame    
        if($options['frame']) $this->Rect($pos_x,$pos_y,$width,$height,'D');
    } 

    protected function formatCell($format)
    {
        if(isset($format['font'])) $this->changeFont($format['font']);
        if(isset($format['fill'])) {
            $red = hexdec(substr($format['fill'],1,2));
            $green = hexdec(substr($format['fill'],3,2));
            $blue = hexdec(substr($format['fill'],5,2));
            $this->SetFillColor($red,$green,$blue);
        }  
        if(isset($format['color'])) {
            $red = hexdec(substr($format['color'],1,2));
            $green = hexdec(substr($format['color'],3,2));
            $blue = hexdec(substr($format['color'],5,2));
            $this->SetTextColor($red,$green,$blue);
        }
        if(isset($format['line_color'])) {
            $red=hexdec(substr($format['line_color'],1,2));
            $green=hexdec(substr($format['line_color'],3,2));
            $blue=hexdec(substr($format['line_color'],5,2));
            $this->SetDrawColor($red,$green,$blue);
        }
            
        if(isset($format['line_width'])) $this->SetLineWidth($format['line_width']);
        
        if(isset($format['font_size'])) $this->SetFontSize($format['font_size']);
    }

    //determine number of lines in a multicell with width and text
    //courtesy of http://www.fpdf.de/downloads/addons/3/
    protected function countLines($w, $txt)
    {
            //Computes the number of lines a MultiCell of width w will take
            $cw=&$this->CurrentFont['cw'];
            if($w==0)
                    $w=$this->w-$this->rMargin-$this->x;
            $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
            $s=str_replace("\r", '', $txt);
            $nb=strlen($s);
            if($nb>0 and $s[$nb-1]=="\n")
                    $nb--;
            $sep=-1;
            $i=0;
            $j=0;
            $l=0;
            $nl=1;
            while($i<$nb)
            {
                    $c=$s[$i];
                    if($c=="\n")
                    {
                            $i++;
                            $sep=-1;
                            $j=$i;
                            $l=0;
                            $nl++;
                            continue;
                    }
                    if($c==' ')
                            $sep=$i;
                    $l+=$cw[$c];
                    if($l>$wmax)
                    {
                            if($sep==-1)
                            {
                                    if($i==$j)
                                            $i++;
                            }
                            else
                                    $i=$sep+1;
                            $sep=-1;
                            $j=$i;
                            $l=0;
                            $nl++;
                    }
                    else
                            $i++;
            }
            return $nl;
    }


    function truncateText($width,$max_lines,$text)
    {
            $w=$width;
            $text_out='';
            
            if($max_lines==0) return $text_out;
            
            //Computes the number of lines a MultiCell of width w will take
            $cw=&$this->CurrentFont['cw'];
            if($w==0)
                    $w=$this->w-$this->rMargin-$this->x;
            $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
            $s=str_replace("\r", '', $text);
            $nb=strlen($s);
            if($nb>0 and $s[$nb-1]=="\n")
                    $nb--;
            $sep=-1;
            $i=0;
            $j=0;
            $l=0;
            $nl=1;
            while($i<$nb)
            {
                    if($nl>=$max_lines)
                    {
                        $text_out=substr($s,0,$i);
                        return $text_out;
                    }
                    
                    $c=$s[$i];
                    if($c=="\n")
                    {
                            $i++;
                            $sep=-1;
                            $j=$i;
                            $l=0;
                            $nl++;
                            continue;
                    }
                    if($c==' ')
                            $sep=$i;
                    $l+=$cw[$c];
                    if($l>$wmax)
                    {
                            if($sep==-1)
                            {
                                    if($i==$j)
                                            $i++;
                            }
                            else
                                    $i=$sep+1;
                            $sep=-1;
                            $j=$i;
                            $l=0;
                            $nl++;
                    }
                    else
                            $i++;
            }
            
            
            if($nl<=$max_lines) $text_out=$s;
            return $text_out;
    }

    public function checkLinesRemaining($row_h)
    {
        $page_space = $this->h - $this->GetY() - $this->bMargin;
        $no_lines = floor($page_space/$row_h);
        return $no_lines;
    } 
}
