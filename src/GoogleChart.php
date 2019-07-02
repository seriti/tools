<?php
namespace Seriti\Tools;

use Exception;

use Seriti\Tools\Secure;

use Seriti\Tools\MessageHelpers;

//NB: this is a minmalist implementation of google visualisation charts for simple dashboard type charts. 
//It does not implement anywhere near the full functionality available and is not intended to
class GoogleChart 
{
    use MessageHelpers;

    protected $chart_functions = [];
    protected $google_load = [];
    protected $google_api_key = '';

    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    
    //can create single chart with construct or call individual setupXXXChart() for multiple charts on same page
    public function __construct($type = '',$div_id = '',$title = '',$data = [],$param = []) 
    {
        if(isset($param['google_api_key'])) $this->google_api_key = $param['google_api_key'];

        if($type !== '') {
            switch($type) {
                case 'pie' : $this->addPieChart($div_id,$title,$data,$param); break;
                case 'bar' : $this->addBarChart($div_id,$title,$data,$param); break;
                case 'line' : $this->addLineChart($div_id,$title,$data,$param); break;
                case 'area' : $this->addAreaChart($div_id,$title,$data,$param); break;
                case 'map' : $this->addMap($div_id,$title,$data,$param); break;
            }
        }
    }

    //sample data: $data = ['yin'=>50,'yang'=>50,'wtf'=>50];
    public function addPieChart($div_id,$title,$data = [],$param = [])
    {
        $type = 'pie';
        $chart_data = [];
        $chart_options = [];

        $param = $this->setupParameters($type,$data,$param);

        if(!$this->errors_found) {
            $chart_options = $this->setupChartOptions($type,$title,$data,$param);

            $chart_data = $this->setupChartData($type,$data,$param);
            
            $this->setupDrawFunction($type,$div_id,$chart_data,$chart_options);
        } 
    }

    //single series: $data = [['Jan',10],['Feb',20],['Mar',40]];
    //4 series: $data = [['Jan',10,20,30,40],['Feb',20,20,20,20],['Mar',40,30,20,10]];
    //$param['series'] = ['month','one','two','three','four'];
    public function addBarChart($div_id,$title,$data = [],$param = [])
    {
        $type = 'bar';
        $chart_data = [];
        $chart_options = [];
        
        $param = $this->setupParameters($type,$data,$param);

        if(!$this->errors_found) {
            $chart_options = $this->setupChartOptions($type,$title,$data,$param);

            $chart_data = $this->setupChartData($type,$data,$param);
            
            $this->setupDrawFunction($type,$div_id,$chart_data,$chart_options);
        }    
    }

    //sample single series: $data = [['Jan',10],['Feb',20],['Mar',40]];
    //sample 4 series: $data = [['Jan',10,20,30,40],['Feb',20,20,20,20],['Mar',40,30,20,10]];
    //$param['series'] = ['month','one','two','three','four'];
    public function addLineChart($div_id,$title,$data = [],$param = [])
    {
        $type = 'line';
        $chart_data = [];
        $chart_options = [];
                  
        $param = $this->setupParameters($type,$data,$param);

        if(!$this->errors_found) {
            $chart_options = $this->setupChartOptions($type,$title,$data,$param);

            $chart_data = $this->setupChartData($type,$data,$param);
            
            $this->setupDrawFunction($type,$div_id,$chart_data,$chart_options);
        }
    }

    //sample single series: $data = [['Jan',10],['Feb',20],['Mar',40]];
    //sample 4 series: $data = [['Jan',10,20,30,40],['Feb',20,20,20,20],['Mar',40,30,20,10]];
    //$param['series'] = ['month','one','two','three','four'];
    public function addAreaChart($div_id,$title,$data = [],$param = [])
    {
        $type = 'area';
        $chart_data = [];
        $chart_options = [];
                  
        $param = $this->setupParameters($type,$data,$param);

        if(!$this->errors_found) {
            $chart_options = $this->setupChartOptions($type,$title,$data,$param);

            $chart_data = $this->setupChartData($type,$data,$param);
            
            $this->setupDrawFunction($type,$div_id,$chart_data,$chart_options);
        }
    }

    //NB: no validation or modification of any data or options just constructs js function as is
    public function addCustomChart($type,$div_id,$chart_data = [],$chart_options = [])
    {
        $this->setupDrawFunction($type,$div_id,$chart_data,$chart_options);
    }

    public function addMap($div_id,$title,$data = [],$param = [])
    {
        $type = 'map';
        $chart_data = [];
        $chart_options = [];
        
        if($this->google_api_key === '') {
            $this->addError('Maps require a google api key! Please provide in constructor.');
        }

        $param = $this->setupParameters($type,$data,$param);

        if(!$this->errors_found) {
            $chart_options = $this->setupChartOptions($type,$title,$data,$param);

            $chart_data = $this->setupChartData($type,$data,$param);
            
            $this->setupDrawFunction($type,$div_id,$chart_data,$chart_options);
        }
    }

     

    //checks and sets any missing or required parameters
    protected function setupParameters($type,$data = [],$param = []) 
    {
        if($type === 'pie') {
            if(!isset($param['slice'])) $param['slice'] = 'Slice';
            if(!isset($param['value'])) $param['value'] = 'Value';
        }

        if($type === 'bar' or $type === 'line' or $type === 'area') {
            if(count($data) === 0) {
                $this->addError('No data specified');
            } else {
                if(!isset($param['series_count'])) $param['series_count'] = count($data[0]) -1;
                if($param['series_count'] < 1) $this->addError('Need at least one data series');
            }
            
            //setup series default names if none specified 
            if($param['series_count'] === 1) {
                if(!isset($param['series'])) $param['series'] = ['label','value'];
                if(!isset($param['legend'])) $param['legend'] = false;
            } else {
                if(!isset($param['series'])) {
                    $param['series'][] = 'label';
                    for($i = 1; $i <= $param['series_count']; $i++) $param['series'][] = 'Series-'.$i; 
                }  
                if(!isset($param['legend'])) $param['legend'] = true;        
            }
    
        } 

        return $param;
    }
    
    //returns array of google chart object options via json_encode() 
    protected function setupChartOptions($type,$title,$data = [],$param = []) 
    {
        $chart_options = [];

        $chart_options['title'] = $title;

        if(isset($param['x_axis'])) {
            //simple title or array of settings 
            if(!is_array($param['x_axis'])) {
                $chart_options['hAxis'] = ['title'=>$param['x_axis']];
            } else {
                $chart_options['hAxis'] = ['title'=>$param['x_axis']['title'],'titleTextStyle'=>['color'=>$param['x_axis']['color']]];
            }
        }

        if(isset($param['y_axis'])) {
            //simple title or array of settings 
            if(!is_array($param['y_axis'])) {
                $chart_options['vAxis'] = ['title'=>$param['y_axis']];
            } else {
                $chart_options['vAxis'] = ['title'=>$param['y_axis']['title'],'titleTextStyle'=>['color'=>$param['y_axis']['color']]];
            }
        }

        if(isset($param['width'])) $chart_options['width'] = $param['width'];
        if(isset($param['height'])) $chart_options['height'] = $param['height'];

        //can use ['red','blue','#RRGGBB]'...
        if(isset($param['colors'])) $chart_options['colors'] = $param['colors'];
        if(isset($param['legend'])) {
            if($param['legend'] === false or $param['legend'] === 'none') {
                $chart_options['legend'] = ['position'=>'none'];
            } elseif($param['legend'] === 'bottom') {
                $chart_options['legend'] = ['position'=>'bottom'];
            }   
        }    


        if($type === 'pie') {
            //$param['3D'] = true or false
            if(isset($param['3D'])) $chart_options['is3D'] = $param['3D'];
        } 
        
        if($type === 'bar') {
            if(isset($param['stacked']) and $param['stacked'] === true) $chart_options['isStacked'] = true;
        } 

        if($type === 'line') {
            if(isset($param['smoothed'])) $chart_options['curveType'] = 'function';
        }

        if($type === 'area') {
            
        } 

        if($type === 'map') {
            $chart_options['showTooltip'] = true;
            $chart_options['showInfoWindow'] = true;

            if(isset($param['tool_tip'])) $chart_options['showTooltip'] = $param['tool_tip'];
            if(isset($param['show_info'])) $chart_options['showInfoWindow'] = $param['show_info'];
        }   


        return $chart_options;
    }

    //returns array of google chart object data via json_encode()
    protected function setupChartData($type,$data = [],$param = []) 
    {
        $chart_data = [];

        if(count($data) === 0 ) {
            $this->addError('No data specified');
        } else {
            if($type === 'pie') {
                $chart_data[] = [$param['slice'],$param['value']];

                foreach($data as $key=>$value) {
                    if(!is_numeric($value)) {
                        $this->addError('Pie chart slice['.$key.'] has non numeric value['.$value.']');
                        $value = 0;
                    }

                    $chart_data[] = [$key,floatval($value)];
                }
            }

            if($type === 'bar' or $type === 'line' or $type === 'area') {
                $chart_data[] = $param['series'];

                //assign data and validate for numeric values
                if($param['series_count'] > 0) {
                    foreach($data as $values) {
                        $chart_values = [];
                        //assign axis label/value
                        $chart_values[] = $values[0];
                        for($i = 1; $i <= $param['series_count']; $i++) {
                            if(!is_numeric($values[$i])) {
                                $this->addError('Data series['.$param['series'][$i].'] at label['.$values[0].'] has non numeric value['.$values[$i].']');
                                $chart_values[] = 0;
                            } else {
                                $chart_values[] = floatval($values[$i]);
                            }   
                        }
                        $chart_data[] = $chart_values;
                    }
                }
            } 

            if($type === 'map') {
                $chart_data = $data;
            }

        }

        return $chart_data;

    }

    protected function setupLoader($type)
    {
        if($type === 'map') {
            $load = 'map';
        } else {
            $load = 'corechart';
        }    

        if(!isset($this->google_load['packages'])) $this->google_load['packages'] = [];

        if(!in_array($load,$this->google_load['packages'])) {
            $this->google_load['packages'][] = $load;
        }
    }
    //construct js function to draw a single chart
    protected function setupDrawFunction($type,$div_id,$chart_data,$chart_options)
    {
        $name = '';
        $js = '';
        $chart_object = '';

        $this->setupLoader($type);

        switch($type) {
            case 'pie': $chart_object = 'PieChart'; break;
            case 'bar': $chart_object = 'ColumnChart'; break;
            case 'line': $chart_object = 'LineChart'; break;
            case 'area': $chart_object = 'AreaChart'; break;
            case 'map': $chart_object = 'Map'; break;
        }

        $function = 'draw'.Secure::clean('basic',$div_id);  
        if(isset($this->chart_functions[$function])) {
            $this->addError('You have specified chart div id['.$div_id.'] more than once!');
        } else {
            $js_array = json_encode($chart_data);
            //if you ever need to have dates then set as "##Date(Y,M,D)##" and apply following
            //$js_array = str_replace('"##Date(','new Date(',$js_array);
            //$js_array = str_replace(')##"',')',$js_array);

            $js .= "function ".$function."() 
                    {
                        var data = google.visualization.arrayToDataTable(".$js_array.");
                        var options = ".json_encode($chart_options)."
                        var chart = new google.visualization.".$chart_object."(document.getElementById('".$div_id."'));

                        chart.draw(data, options);
                    }"; 

            $this->chart_functions[$function] = $js;
        }    
    }

    //create all js required to build all setup charts within provided html div_id's 
    public function getJavaScript() {
        $js = '';
        
        $js .= '<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>'."\r\n";

        if(in_array('map',$this->google_load['packages']) and $this->google_api_key !== '') {
            $js .= '<script async defer src="https://maps.googleapis.com/maps/api/js?key='.$this->google_api_key.'&callback=initMap" type="text/javascript"></script>';
        }
        
        $js .= '<script type="text/javascript">'."\r\n".    
               'google.charts.load(\'current\', '.json_encode($this->google_load).');'."\r\n";

        foreach($this->chart_functions as $name => $function) {
            $js .= $function."\r\n";
            $js .= 'google.charts.setOnLoadCallback('.$name.');'."\r\n";
        }    

        $js .= '</script>';
        
        return $js;    
    }    
}    

?>