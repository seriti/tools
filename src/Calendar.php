<?php
namespace Seriti\Tools;

//https://github.com/donatj/SimpleCalendar

use Exception;

use Seriti\Tools\Secure;
use Seriti\Tools\Date;

use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;

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

    //for time based daily appointments
    protected $appointments = [];
    protected $appointment_no = 0;
    protected $appointment_interval = 15;
    protected $appointment_layout = 'LN';

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

    //NB: time must be in MySql HH:MM:SS format, SS are ignored
    public function addAppointment($date,$from_time,$to_time,$html,$options = [])
    {
        $error = '';
        $appointment = [];

        //set event_id if trequired for view processing
        if(!isset($options['event_id'])) $options['event_id'] = 0;
        //attach appointment to a specific unit
        if(!isset($options['unit_id'])) $options['unit_id'] = 0;

        $temp = $this->parseDate($date);
        $date_key = date('Y-m-d',$temp->getTimestamp());

        $appointment['start'] = Date::calcMinutes('00:00',$from_time);
        if(!$appointment['start']) $error .= 'Invalid appointment start time['.$from_time.']';
            
        $appointment['end'] = Date::calcMinutes('00:00',$to_time);
        if(!$appointment['end']) $error .= 'Invalid appointment end time['.$to_time.']';
       
        if($error !== '') {
            $this->addError($error);
        } else {    
            $this->appointment_no ++;
            $appointment['html'] = $html;
            $appointment['options'] = $options;
            
            $this->appointments[$date_key][$this->appointment_no] = $appointment;
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

            //show time slots within a date
            if($format === 'TIME_DATE') $html = $this->showTimeDates($this->start_date,$this->end_date,$options);
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

    //time in rows, dates in columns
    protected function showTimeDates($start_date,$end_date,$options = [])
    {
        $html = '';

        $time_options = [];
        $time_options['show_time'] = false;

        //time interval in minutes
        if(isset($options['interval'])) $this->appointment_interval = $options['interval'];
        if(!isset($options['start'])) $options['start'] = 360; //'06:00';
        if(!isset($options['end'])) $options['end'] = 1200; //'20:00';

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
        $html .= '<th>Time</th>';
        foreach($dates as $key=>$date) $html .= '<th>'.$date['header'].'</th>';
        $html .= '</tr></thead><tbody><tr>';
       
        $time = $options['start'];
        while($time < $options['end']) {
            $time_str = Date::incrementTime('00:00',$time);

            $html .= '<tr><td>'.$time_str.'</td>';
            foreach($dates as $date_key=>$date) {
                $html .= '<td>'.$this->viewAppointments($date_key,$time,$time_options).'</td>';
            }    
            $html .= '<tr>';

            $time = $time + $this->appointment_interval;
        }

        foreach($this->units as $unit_id => $unit) {
            
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

    protected function viewAppointments($date_key,$time,$options = [])
    {
        $html = '';

        if($this->appointment_layout === 'LIST') $html .= '<ul>';

        if(isset($this->appointments[$date_key])) {
            foreach($this->appointments[$date_key] as $no => $app) {
                $show = false;
                $time_start = $time - $this->appointment_interval;
                $time_end = $time + $this->appointment_interval;
                if($time_end > $app['start'] and $time_start < $app['end']) {
                    if($app['start'] >= $time and $app['start'] <= $time_end) {
                        $show_html = $no.': '.$app['html'];
                    } else {
                        $show_html = $no.'...';
                    }
                    $show = true;
                }    

                //if($app['start'] - $time < $this->appointment_interval) $show = true;
                //if($time + $this->appointment_interval <= $app['end'] ) $show = true;

                if($show) {
                    if($this->appointment_layout === 'LIST') $html .= '<li>'.$app['html'].'</li>';  
                    if($this->appointment_layout === 'LN') $html .= $show_html.'</br>'; 
                }
            }
            
        } 

        if($this->appointment_layout === 'LIST') $html .= '<ul>';
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
