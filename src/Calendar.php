<?php
namespace Seriti\Tools;

//https://github.com/donatj/SimpleCalendar

use Exception;

use Seriti\Tools\Secure;
use Seriti\Tools\Date;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;

//NB: this is a minmalist implementation of google visualisation charts for simple dashboard type charts. 
//It does not implement anywhere near the full functionality available and is not intended to
class Calendar 
{
    use IconsClassesLinks;
    use MessageHelpers;

    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    protected $events = [];
    protected $event_layout = 'LN';
    protected $units = [];
    //to identify same event over multiple days, and allows for multiple events on one day
    protected $event_no = 0;
    protected $start_date;
    protected $end_date;
    protected $today = 0;

    protected $calendar_class = ['calendar'=>'table  table-striped table-bordered table-hover table-condensed',
                                 'leading_day'=>'',
                                 'trailing_day'=>'',
                                 'today'=>'',
                                 'weekend'=>'',
                                 'holiday'=>''];

    protected $week_days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    //default is to start with Sunday first;
    private $offset = 0;
    
    public function __construct($type = '',$div_id = '',$title = '',$data = [],$param = []) 
    {
        $this->today = new \DateTime();
        
    }

    public function addUnit($unit_id,$name,$options = [])
    {
        $unit = [];
        $unit['id'] = $unit_id;
        $unit['name'] = $name;
        $unit['options'] = $options;

        $this->units[$unit_id] = $unit;
    }

    //added to every day between start and end,event is ignored if error
    public function addEvent($start_date,$end_date,$html,$options = [])
    {
        $error = '';
        $event = [];

        //set event_id if trequired for view processing
        if(!isset($options['event_id'])) $options['event_id'] = 0;
        //attach event to a specific unit
        if(!isset($options['unit_id'])) $options['unit_id'] = 0;

        $event['start'] = $this->parseDate($start_date);
        if(!$event['start']) $error .= 'Invalid event start';
            
        if($end_date == 0) {
            $event['end'] = $event['start'];
        } else {
            $event['end'] = $this->parseDate($end_date);
            if(!$event['end']) $error .= 'Invalid event end';
        }

        if($error !== '') {
            $this->addError($error);
        } else {    
            $this->event_no ++;
            $event['html'] = $html;
            $event['options'] = $options;
            if($options['event_id'] === 0) $event['id'] = $this->event_no; else $event['id'] = $options['event_id'];
            
            
            $temp = (new \DateTime())->setTimestamp($event['start']->getTimestamp());
            while($temp->getTimestamp() < ($event['end']->getTimestamp() + 1) ) {
                $date_key = date('Y-m-d',$temp->getTimestamp());
                $this->events[$date_key][$this->event_no] = $event;

                $temp->add(new \DateInterval('P1D'));
            }
        }    
    }

    public function clearEvents()
    {
        $this->events = [];
    }

    //specify which day of week to start on. ie: 'Monday' or 0-6  where 0 = Sunday
    public function setStartOfWeek($week_day) {
        if(is_int($week_day)) {
            $this->offset = $week_day % 7;
        } elseif(($offset = array_search($week_day,$this->week_days,true)) !== false ) {
            $this->offset = $offset;
        } 
    }

    
    public function show($format,$start_date,$end_date = 0,$options = [])
    {
        $html = '';
        $error = '';

        $this->start_date = $this->parseDate($start_date);
        if(!$this->start_date) $this->addError('Invalid calendar start date');

        if($end_date === 0) {
            $this->end_date = $this->start_date;
        } else {
            $this->end_date = $this->parseDate($end_date);
            if(!$this->end_date) $this->addError('Invalid calendar end date');
        }

        if($this->start_date->getTimestamp() > $this->end_date->getTimestamp()) $this->addError('Calendar start date after end date');

        $html .= $this->viewMessages();

        if(!$this->errors_found) {
            if($format === 'MONTH') $html = $this->showMonth($this->start_date,$options);
            if($format === 'DATE_UNIT') $html = $this->showDateUnits($this->start_date,$this->end_date,$options);
            if($format === 'UNIT_DATE') $html = $this->showUnitDates($this->start_date,$this->end_date,$options);

        }

        return $html;
    }

    //dates in rows, units in columns
    protected function showDateUnits($start_date,$end_date,$options = [])
    {
        $html = '';

        $day_options = [];
        $day_options['show_day'] = false;

        $html .= '<table class="'.$this->calendar_class['calendar'].'"><thead><tr>';

        //header row
        $html .= '<th>Date</th><th>Day</th>';
        foreach($this->units as $unit) $html .= '<th class="'.$this->calendar_class['item_header'].'">'.$unit['name'].'</th>';
        $html .= '</tr></thead><tbody><tr>';
       

        $temp = (new \DateTime())->setTimestamp($start_date->getTimestamp());
        while($temp->getTimestamp() < ($end_date->getTimestamp() + 1) ) {
            $date_key = date('Y-m-d',$temp->getTimestamp());

            $html.='<tr><td>'.Date::formatDate($date_key).'</td><td>'.$temp->format('l').'</td>';


            foreach($this->units as $unit_id=>$unit) {
                $html .= $this->viewDay($temp,$unit_id,$day_options);
            }
            
            $temp->add(new \DateInterval('P1D'));
        }

        return $html;
    } 

    //units in rows, dates in columns
    protected function showUnitDates($start_date,$end_date,$options = [])
    {
        $html = '';

        $day_options = [];
        $day_options['show_day'] = false;


        if(!isset($options['date_format'])) $options['date_format'] = 'abv';

        $dates = [];
        $temp = (new \DateTime())->setTimestamp($start_date->getTimestamp());
        while($temp->getTimestamp() < ($end_date->getTimestamp() + 1) ) {
            
            $time = $temp->getTimestamp();
            $date_key = date('Y-m-d',$time);
            
            $date = [];
            $date['object'] = (new \DateTimeImmutable())->setTimestamp($time);
            $date['header'] = substr($temp->format('l'),0,3).'<br/>'.$temp->format('M').'<br/>'.$temp->format('d');

            $dates[$date_key] = $date;
            
            $temp->add(new \DateInterval('P1D'));
        }


        $html .= '<table class="'.$this->calendar_class['calendar'].'"><thead><tr>';

        //header row
        $html .= '<th>&nbsp;</th>';
        foreach($dates as $key=>$date) $html .= '<th>'.$date['header'].'</th>';
        $html .= '</tr></thead><tbody><tr>';
       

        foreach($this->units as $unit_id => $unit) {
            $html .= '<tr><td>'.$unit['name'].'</td>';
            foreach($dates as $key=>$date) {
                $html .= $this->viewDay($date['object'],$unit_id,$day_options);
            }    
            $html .= '<tr>';
        }    

        return $html;
    }    

    //standard calendar month
    protected function showMonth($month_date,$options = [])
    {
        $html = '';

        $month   = getdate($month_date->getTimestamp());
       
        $week_days = $this->week_days;
        $week_days = $this->rotateWeekDays($week_days,$this->offset);

        $week_day_index = date('N', mktime(0, 0, 1, $month['mon'], 1, $month['year'])) - $this->offset;
        $days_in_month  = cal_days_in_month(CAL_GREGORIAN, $month['mon'], $month['year']);

        $html .= '<table class="'.$this->calendar_class['calendar'].'"><thead><tr>';
        foreach($week_days as $name) $html .= '<th>'.$name.'</th>';
        $html .= '</tr></thead><tbody><tr>';

        $week_day_index = ($week_day_index + 7) % 7;

        if( $week_day_index === 7 ) {
            $week_day_index = 0;
        } else {
            $html .= str_repeat('<td class="'.$this->calendar_class['leading_day'].'">&nbsp;</td>', $week_day_index);
        }

        $count = $week_day_index + 1;
        for( $i = 1; $i <= $days_in_month; $i++ ) {
            $date = (new \DateTimeImmutable())->setDate($month['year'], $month['mon'], $i);

            $html .= $this->viewDay($date);
            
            if( $count > 6 ) {
                $html   .= "</tr>\n" . ($i < $days_in_month ? '<tr>' : '');
                $count = 0;
            }
            $count++;
        }

        if( $count !== 1 ) {
            $html .= str_repeat('<td class="' . $this->calendar_class['trailing_day'] . '">&nbsp;</td>', 8 - $count) . '</tr>';
        }

        $html .= "\n</tbody></table>\n";

        return $html;

    }

    protected function viewDay($date,$unit_id = 0,$options = []) 
    {
        $html = '';
        $class = '';

        if(!isset($options['show_day'])) $options['show_day'] = true;

        $date_key = $date->format('Y-m-d');
        $day = $date->format('j');

        $today = false;
        if($this->today !== 0) {
            if($this->today->getTimestamp() === $date->getTimestamp()) {
                $today = true;
                $class .= $this->calendar_class['today'].' ';
            }    
        }

        if($class !== '') $class = 'class="'.trim($class).'" ';


        $html .= '<td '.$class.'>';
        if($options['show_day']) $html .= $day.'<br/>';
        $html .= $this->viewEvents($date_key,$unit_id);
        $html .= '</td>';

        return $html;
    }

    protected function viewEvents($date_key,$unit_id = 0) 
    {
        $html = '';

        if($this->event_layout === 'LIST') $html .= '<ul>';

        if(isset($this->events[$date_key])) {
            foreach($this->events[$date_key] as $event_no => $event) {
                if($unit_id === 0 or $event['options']['unit_id'] === $unit_id) {
                    if($this->event_layout === 'LIST') $html .= '<li>'.$event['html'].'</li>';  
                    if($this->event_layout === 'LN') $html .= $event['html'].'</br>'; 

                }
                
            }
            
        }    

        if($this->event_layout === 'LIST') $html .= '</ul>';

        return $html;
    }

    //$date must be \DateTime object
    private function displayDate($date,$format = 'Y-m-d') {
        $html = '';

        if($format === 'abv') {
            $html .= substr($date->format('l'),0,3).'<br/>'.$date->format('M').'<br/>'.$date->format('d');
        } else {
            $html .= $date->format($format);
        }    

        return $html;
    }

    private function parseDate( $date = null ) {
        if( $date instanceof \DateTimeInterface ) {
            return $date;
        }
        if( is_int($date) ) { //unix timestamp
            return (new \DateTimeImmutable())->setTimestamp($date);
        }
        if( is_string($date) ) {
            return new \DateTimeImmutable($date);
        }

        return false;
    }

    //rotates  weekdays by no_days offset
    private function rotateWeekDays($data,$no_days ) {
        $count = count($data);
        if($no_days < 0 ) {
           $no_days = $count + $no_days;
        }

        $no_days %= $count;
        for( $i = 0; $i <$no_days; $i++ ) {
            $data[] = array_shift($data);
        }

        return $data;
    }
    //create all js required if any
    public function getJavaScript() {
        $js = '';
        
        $js .= '<script type="text/javascript">'."\r\n".    
              
        $js .= '</script>';
        
        return $js;    
    }    
}    
