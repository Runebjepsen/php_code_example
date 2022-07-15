<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Carbon\Carbon;
use DateTime;
use DatePeriod;
use DateInterval;

use App\Models\Project;

use App\Models\MongoDB\ScanSchedule;
use App\Models\MongoDB\Schedule;

class GenerateScanSchedule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $schedule;
    protected $project;
    protected $globalUserInput;
    protected $localUserInput;

    public $tries = 1;
    public $timeout = 600;
    public $retryAfter = 120;
    
    /**
     * Create a new job instance.
     *
     * @param Schedule $Schedule
     * @param Project $project
     * @return void
     */
    public function __construct(Schedule $Schedule, Project $project, array $globalUserInput, array $localUserInput) {

        $this->schedule = $Schedule;
        $this->project = $project;
        $this->globalUserInput = $globalUserInput;
        $this->localUserInput = $localUserInput;
    }
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle() {
        
        ini_set('max_execution_time', 600); // 10 minutes
        $currentSchedule = $this->schedule;
        $project = $this->project;
        $globalUserInput = $this->globalUserInput;
        $scanExists = ScanSchedule::where('schedule_id', $currentSchedule->id)->exists();
        
        $tasks = $currentSchedule['tasks']['data']; //get all tasks
        $links =  $currentSchedule['links']['links']; //get all links
        
        $localUserInput = []; 
        
        $task = $this->transformDataContent($tasks, $links, $localUserInput, $globalUserInput);
        $schedule = $this->calculateSchedule($task);
        
        if($localUserInput){
            $schedule = $this->addNonEssentialTask($task, $schedule, $localUserInput);
        }

        // Contains a new element (date) which are used to show the scan schedule 
        $dates = []; 
        
        // Transforms the scan schedules into the correct format
        $scans = $this->convertToDBschema($schedule, $dates, $task, $links);

        $config = $currentSchedule['config'];
        $config['duration_unit'] = 'day';
        $config['custom_duration_unit'] = 'd';
        $worktime = $currentSchedule['worktime'];

        // Populates workhours -> dates with content from workhour -> hours, if it has no content
        for ($i = 0; $i < 7; $i++) {

            if (!$worktime['dates'][$i]) {

                $worktime['dates'][$i] = $worktime['hours'];
            }
        }    

        // Save the scanSchedule to mongodb

        if ($scanExists) {

            $scanSchedule = ScanSchedule::where('schedule_id', $currentSchedule->id)->firstOrFail();
            $createdAt = $scanSchedule->created_at;

        } else {

            $scanSchedule = new ScanSchedule;
            $createdAt = Carbon::now()->format('Y-m-d H:i:s');            
        }

        $scanSchedule->schedule_id = $currentSchedule['id'];
        $scanSchedule->data = $scans;
        $scanSchedule->config = $config;
        $scanSchedule->resources = $currentSchedule['resources'];
        $scanSchedule->workhours = $worktime;
        $scanSchedule->date = $dates;
        $scanSchedule->created_at = $createdAt;
        $scanSchedule->updated_at = Carbon::now()->format('Y-m-d H:i:s');
        $scanSchedule->save();                 
    }

    /**
     * if a task needs to be scanned multible times, 
     * then this function finds the most suitable scan timeframe, and adds the task to it 
     *
     * @param Array $task
     * @param Array $schedule
     * @return Array
     */
    private function addNonEssentialTask($tasks, $schedule, $userInputs){
        
        $largestId = 0; // Is used to ensure scanSchedule ID is not the same as the task ID (otherwise, we would have conflicts)
        $selectedTasks = [];
        
        // Find the largest id. This number is used to ensure that the right id is used
        for($i = 0; $i < count($tasks); $i++) {

            for($n = 0; $n < count($userInputs); $n++) {

                if($tasks[$i]['id'] == $userInputs[$n]['TaskID']) {

                    $selectedTasks[] = ['TaskId' => $i, 'UserInputId' => $n];
                }

                if (intval($tasks[$i]['id']) > $largestId) {

                    $largestId = intval($tasks[$i]['id']);
                }
            }
        }

        $largestId++;
        for($i = 0; $i < count($selectedTasks); $i++) {
            
            $existingscan = []; // scans which already exist
            $banned = []; // Task which already have been added to the scan 
            $task = $tasks[$selectedTasks[$i]['TaskId']]; // The current task in the loop
            $userInput = $userInputs[$selectedTasks[$i]['UserInputId']]; // The current selected task from userinput            
            $extraScan = $userInput['ExtraScan'] - count($userInput['ScanLocation']); // Amount of task which can be placed in any location     
            $endDate = $task['duration'];           

            for($n = 0; $n < count($schedule); $n++) { // Find all existing scan within the timespan
                        
                if ($schedule[$n]['timespan'][0] >= $task['end_date']) {   

                    break;
                }
                if($schedule[$n]['timespan'][0] < $task['end_date'] && $schedule[$n]['timespan'][0] > $task['start_date']) {    

                    $existingscan[] = [$n, $schedule[$n]['timespan']];
                }
            }

            for($n = 0; $n < count($userInput['ScanLocation']); $n++) { 

                $distance = round(($endDate / 100) * $userInput['ScanDistance'][$n]); // Days from selected scan day, where a scan may a occur
                $currentDate =  round(($endDate / 100) * $userInput['ScanLocation'][$n]); // The day, where the scan should occur, if possible  
                $start = date('Y-m-d', strtotime(strval($currentDate - $distance) . ' day',strtotime($task['start_date']))); // The first possible day for a scan
                $end = date('Y-m-d', strtotime(strval($currentDate + $distance) . ' day',strtotime($task['start_date']))); // The Last possible day for a scan
                $added = 0; // If an already existing scan have been seleced.   

                for($t = 0; $t < count($existingscan); $t++) { // Find out if an existing scan is within the wanted timespan

                    if($existingscan[$t][1][0] <= $end && $existingscan[$t][1][0] >= $start) {

                        if (end($existingscan[$t][1]) < $end) {

                            $end = end($existingscan[$t][1]); 
                        }     

                        if ($existingscan[$t][1][0] > $start) { 

                            $start = $existingscan[$t][1][0];
                        }

                        $added = $t;
                        break; 
                    }                    
                }

                if(!$added) { // If no scan have been selected, then create a new scan on the wanted day

                    $schedule[] = ['tasks' => [$largestId . ':' . $task['id']],'text' => [$task['text']],'timespan' => [date('Y-m-d', strtotime(strval($currentDate) . ' day',strtotime($task['start_date'])))]];
                    $largestId++;
                    $banned[] = date('Y-m-d', strtotime(strval($currentDate) . ' day',strtotime($task['start_date'])));

                } else { // Add the task to the scan and change the scans timespan, so it doesn't conflicts with the task 

                    $period = new DatePeriod(
                        new DateTime($start),
                        new DateInterval('P1D'),
                        new DateTime($end)
                    );
    
                    $dates = [];

                    foreach ($period as $key => $value) {

                        $dates[] = $value->format('Y-m-d');
                        $banned[] = $value->format('Y-m-d');                        
                    }

                    $dates[] = $end;
                    $banned[] = $end;
                    $schedule[$existingscan[$added][0]]['tasks'][] = $largestId . ':' . $task['id']; 
                    $largestId++;
                    $schedule[$existingscan[$added][0]]['text'][] = $task['text'];
                    $schedule[$existingscan[$added][0]]['timespan'] = $dates;
                }        
            }

            if($extraScan) { // If there are tasks, that can placed in any location 
                
                $average =  ceil($endDate / 2);
                $scanDates = [$average]; // All exising scans within the selecet timespan           
                
                // Find the best days for the wanted scans
                $this->defaultScansRecursive($average, $endDate + 1, $scanDates, $extraScan - ($extraScan % 2));

                if(count($scanDates) > $extraScan) { // if the average are not included

                    array_shift($scanDates);
                }

                sort($scanDates);

                $noConfictScans = []; // Days which can be used for creating a new scan

                for($n = 0; $n < count($scanDates); $n++) {

                    $scanDates[$n] = date('Y-m-d', strtotime(strval($scanDates[$n]) . ' day',strtotime($task['start_date'])));

                    if(in_array($scanDates[$n],$banned)) {

                        continue;
                    }

                    // Checks if there is an exising scan on the wanted day, if yes, then add the task to it
                    for($t = 0; $t < count($existingscan); $t++) { 

                        if (end($existingscan[$t][1]) >= $scanDates[$n] && $existingscan[$t][1][0] <= $scanDates[$n]) {

                            $banned[] = $scanDates[$n];
                            $extraScan--;
                            $schedule[$existingscan[$t][0]]['tasks'][] = $largestId . ':' . $task['id']; 
                            $largestId++;
                            $schedule[$existingscan[$t][0]]['text'][] = $task['text'];
                            $schedule[$existingscan[$t][0]]['timespan'] = [$scanDates[$n]];
                            continue;
                        }                      
                    }

                    if(!in_array($scanDates[$n],$banned)) { // If there are no exising scan on the wanted day

                        $noConfictScans[] = $scanDates[$n];
                        continue;
                    } 
                }

                for($n = 0; $n < count($noConfictScans); $n++) { // Create scans for all the tasks, where there are no exising scan 

                    $schedule[] = ['tasks' => [$largestId . ':' . $task['id']],'text' => [$task['text']],'timespan' => [$noConfictScans[$n]]];
                    $largestId++;
                    $banned[] = $noConfictScans[$n];
                    $extraScan--;
                }

                if($extraScan) { // If there are still task left, then add them where there is room for them

                    $dates = [];

                    $period = new DatePeriod(
                        new DateTime($task['start_date']),
                        new DateInterval('P1D'),
                        new DateTime(date('Y-m-d', strtotime('1 day',strtotime($task['end_date']))))
                    );

                    // Find every day in the timespan, and checks if there are room for the task
                    for($n = 0; $n < $extraScan; $n++) { 

                        foreach ($period as $key => $value) {

                            if (!in_array($value->format('Y-m-d'), $banned)) {

                                $schedule[] = ['tasks' => [$largestId . ':' . $task['id']],'text' => [$task['text']],'timespan' => [$value->format('Y-m-d')]];
                                $largestId++;
                                $banned[] = $value->format('Y-m-d');
                                $extraScan--;    
                                break;
                            }
                        }
                    }
                }
            }
            
            $schedule = $this->sortMultiByASCTimespan($schedule, 'timespan');
        }           
        return $schedule;
    }

    /**
     * Prepares the Gantt schedule for processing by removing unnecessary data and modifies start_date & duration based on
     * $globalInput, $userInput
     *
     * @param Schedule $dataSet
     * @param Array $globalInput
     * @param Array $userInput
     * @return Array
     */
    private function transformDataContent($tasks, $links, $userInput, $globalInput) {
        
        $secondsInADay = (24*60*60); // The number of seconds in a day
        $rawData = []; // Return this - contains the transformed data
        $parent = [];// Task which are categories to other task, should be ignored.

        // Find the tasks which are defined as parents
        foreach($tasks as $task){

            if(!in_array($task['parent'], $parent))
            {
                $parent[] = $task['parent'];
                continue;
            }
        }

        // Transforms the dataset into the corret format and include all the necessary elements to it
        $parentPassed = 0;

        for ($i = 0; $i < count($tasks); $i++) { // each task

            // If parent, skip
            if(in_array($tasks[$i]['id'], $parent)){
                $parentPassed++;
                continue;
            }

            $tasks[$i]['start_date'] = date("Y-m-d", strtotime($tasks[$i]['start_date']));              
            
            // Get start_date and create end_date based on duration             
            $startDate = date("Y-m-d", strtotime($tasks[$i]['start_date']) + ($secondsInADay * $globalInput['RealTimeStart']));            

            $endDate = strtotime($tasks[$i]['start_date']) + ($secondsInADay * $globalInput['RealTimeEnd']); 

            $duration = array_key_exists('duration_days', $tasks[$i]['custom_data'])
                ? $tasks[$i]['custom_data']['duration_days'] - $globalInput['RealTimeStart'] + $globalInput['RealTimeEnd']
                : $tasks[$i]['duration'] - $globalInput['RealTimeStart'] + $globalInput['RealTimeEnd'];

            $endDate += $secondsInADay * $duration;  
            $endDate = date("Y-m-d", $endDate); 
            $overlap = $globalInput['TaskOverlap'] + 0.001;

            if($userInput){
                // We want to find out how high a percentage of overlap is allowed for this task (returns 0.01% to 1.00%)                
                if ($this->inArrayMulti($tasks[$i]['id'], $userInput, 'TaskID'))
                {
                    $startDate = date("Y-m-d",
                        strtotime($startDate) +
                        ($secondsInADay * $userInput[$this->searchForId($tasks[$i]['id'], $userInput, 'TaskID')]['RealTimeStart']));

                    $endDate = date("Y-m-d",
                        strtotime($endDate) +
                        ($secondsInADay * $userInput[$this->searchForId($tasks[$i]['id'], $userInput, 'TaskID')]['RealTimeEnd']));

                    $overlap = $userInput[$this->searchForId($tasks[$i]['id'], $userInput, 'TaskID')]['TaskOverlap'];
                    $duration -= $userInput[$this->searchForId($tasks[$i]['id'], $userInput, 'TaskID')]['RealTimeStart'];
                    $duration += $userInput[$this->searchForId($tasks[$i]['id'], $userInput, 'TaskID')]['RealTimeEnd'];  
                } 
            }

            //add data schema to the tasks array
            array_push($rawData, array(
                'id'=> $tasks[$i]['id'],
                'text' => $tasks[$i]['text'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'dependencies' => array(),
                'scheduleTime' => array(),
                'overlap' => strval($overlap),
                'duration' => $duration
            ));

            // Find and save all the links to the current task
            for ($n = 0; $n < count($links); $n++) { // each link

                if($links[$n]['source'] == $tasks[$i]['id']) {

                    $rawData[$i - $parentPassed]['dependencies'][] = $links[$n]['target'];
                    $id = $this->searchForId($links[$n]['target'], $tasks, 'id'); 
                    $changeStartDay = $globalInput['RealTimeStart'];
                    if($userInput){
                        // We want to find out how high a percentage of overlap is allowed for this task (returns 0.01% to 1.00%)                
                        if ($this->inArrayMulti($tasks[$id]['id'], $userInput, 'TaskID'))
                        {
                            $changeStartDay = $userInput[$this->searchForId($tasks[$id]['id'], $userInput, 'TaskID')]['RealTimeStart'];
                        } 
                    }
                    $rawData[$i - $parentPassed]['scheduleTime'][] = date("Y-m-d", strtotime($tasks[$id]['start_date']) + ($secondsInADay * $changeStartDay));
                }                
            }            
        }
        return $this->sortMultiByASC($rawData, 'end_date');
    }

    /**
     * Takes the transformed data and generates a scan schedule as output.
     *
     * @param $tasks
     * @return Array
     */
    private function calculateSchedule($tasks) {
           
        $dividedTasks = ['inChain' => [], 'lastInChain' => []]; // Tasks with and without dependencies 
        $banned = []; // Task which already have been added to Schedule
        $schedule = []; // The end result
        $secondsInADay = (24*60*60); // The number of seconds in a day     
        
        // Divides the tasks into two and finds all the days where a scan can occur
        for ($i = 0; $i < count($tasks); $i++) {

            if ($tasks[$i]['scheduleTime'] == null) { // If there is no dependencies to this task, then add it, and skip 
                
                $dividedTasks['lastInChain'][] = $tasks[$i];
                continue;
            }           

            $startScan = $tasks[$i]['end_date']; // End date of task
            $endScan = $tasks[$i]['scheduleTime'][0]; // First start date of dependency
            $DId = 0; // Dependency id

            // Find the shortest time period between the task and its denpendencies  
            for ($n = 0; $n < count($tasks[$i]['scheduleTime']); $n++) {

                if ($endScan > $tasks[$i]['scheduleTime'][$n]) { 

                    $DId = $n;
                    $endScan = $tasks[$i]['scheduleTime'][$n];
                }
            }

            $dependency = $tasks[$this->searchForId($tasks[$i]['dependencies'][$DId], $tasks, 'id')];

            // Caculate the attribute "covered" into days and compare it with the distance between start and end date
            $overlap = round($dependency['duration'] * $dependency['overlap']);            
            
            // // Take a common User error into account, where a dependency starts before the task ends
            if($startScan > $endScan) {
                $difference = round((strtotime($startScan) - strtotime($endScan)) / $secondsInADay);
                                
                $endScan = $startScan;

                if ($overlap >= $difference) { // Subtract the calculated days from "covered"

                    $tasks[$i]['overlap'] -= ($difference / $overlap);
                } else {

                    $tasks[$i]['overlap'] = 0;
                }
            }

            if($tasks[$i]['overlap'] > 0) {

                $endScan = date("Y-m-d", strtotime($endScan) + $overlap * $secondsInADay);
            }            
            $timespan = [];

            do{ // Add all dates where a scan can occur
                $timespan[] = $startScan;
                $startScan = date("Y-m-d", strtotime($startScan) + $secondsInADay); 
            } while($startScan < $endScan);

            array_push($dividedTasks['inChain'],array(
                'task' => $tasks[$i]['id'],
                'text' => $tasks[$i]['text'],
                'timespan' => $timespan
            ));
        }

        // Transform all task (with dependencies) into scan schedules
        for ($i = 0; $i < count($dividedTasks['inChain']); $i++)
        {
            
            if(in_array($i,$banned)) { // If current id has been banned, skip it

                continue;
            }

            $compareTask = []; //contains all combinations of other tasks with the current task 

            // Finds all the other task, which can share the same date with the current task
            for($n = 0; $n < count($dividedTasks['inChain']); $n++) {

                if(in_array($n,$banned) || $i == $n) { // If current id has been banned, skip it

                    continue;
                }

                // Find all the possible scan dates for this task and compare them with the main task scan dates
                foreach($dividedTasks['inChain'][$n]['timespan'] as $date) { 

                    if(in_array($date, $dividedTasks['inChain'][$i]['timespan'])) {

                        $added = false;
                        // Loops through compareTask, if the current task fits into the dates, then add the task to it
                        for($t = 0; $t < count($compareTask); $t++) {

                            if($date == $compareTask[$t]['timespan'][0]) {

                                $compareTask[$t]['tasks'][] = $n;
                                $compareTask[$t]['text'][] = $dividedTasks['inChain'][$n]['text'];
                                $added = true;
                            }
                        }

                        // If task doesn't fit into compareTask, then add a new element
                        if(!$added) {

                            array_push($compareTask, [
                                'tasks' => [$n],
                                'text' => [$dividedTasks['inChain'][$n]['text']],
                                'timespan' => [$date]
                            ]);
                        }
                    }
                }
            }

            // If this task can't be combined with any other task, then create a stand alone instance.             
            if ($compareTask == null) {

                array_push($schedule,[
                    'tasks' => [$i],
                    'text' => [$dividedTasks['inChain'][$i]['text']],
                    'timespan' => $dividedTasks['inChain'][$i]['timespan']
                ]);
            }
            else { // Else find the best combination of tasks

                $bestDate = 0;

                // Looks for combination with the highest amount of tasks
                for ($n = 0; $n < count($compareTask); $n++) {

                    if (count($compareTask[$bestDate]['tasks']) < count($compareTask[$n]['tasks'])) {

                        $bestDate = $n;
                    }
                }

                $start = array_search($compareTask[$bestDate]['timespan'][0], $dividedTasks['inChain'][$i]['timespan']);                
                $DId = $i;
                $count = 0;

                for ($n = $start; $n < count($dividedTasks['inChain'][$i]['timespan']); $n++) {

                    $count++;
                }

                // Loops through all the tasks in this combination, and finds the task with the shortest timespan
                foreach($compareTask[$bestDate]['tasks'] as $task) {

                    $newstart = array_search($compareTask[$bestDate]['timespan'][0], $dividedTasks['inChain'][$task]['timespan']);
                    $newCount = 0;

                    for ($n = $start; $n < count($dividedTasks['inChain'][$task]['timespan']); $n++) {

                        $newCount++;
                    }

                    if($count > $newCount) {

                        $count = $newCount;
                        $DId = $task;
                        $start = $newstart;
                    }
                }

                $compareTask[$bestDate]['tasks'][] = $i;
                $compareTask[$bestDate]['text'][] = $dividedTasks['inChain'][$i]['text'];

                // Add all the dates which belongs to the task with the shortest timespan & check if the dates in this timespan are shared between the tasks
                for ($n = $newstart + 1; $n < count($dividedTasks['inChain'][$DId]['timespan']); $n++) {

                    $dateExist = true;

                    if ($compareTask[$bestDate]['timespan'][0] == $dividedTasks['inChain'][$DId]['timespan'][$n]) {

                        continue;
                    }

                    foreach($compareTask[$bestDate]['tasks'] as $task) {

                        if(!in_array($dividedTasks['inChain'][$DId]['timespan'][$n], $dividedTasks['inChain'][$task]['timespan'])) {

                          $dateExist = false;  
                        }
                    } 

                    if($dateExist) {

                        $compareTask[$bestDate]['timespan'][] = $dividedTasks['inChain'][$DId]['timespan'][$n];   
                    }                
                }

                // Ban all the task in this combination, to avoid duplicates of tasks in scans    
                for ($n = 0; $n < count($compareTask[$bestDate]['tasks']); $n++) {

                    $banned[] = $compareTask[$bestDate]['tasks'][$n];
                }

                $schedule[] = $compareTask[$bestDate];
            }            
        }

        // Transforms the reference to a task into the task id insted 
        for($i = 0; $i < count($schedule); $i++) {

            for($n = 0; $n < count($schedule[$i]['tasks']); $n++) {

                $schedule[$i]['tasks'][$n] = $dividedTasks['inChain'][$schedule[$i]['tasks'][$n]]['task'];
            }
        }

        // If the last task (without dependencies) is not finished before the last of the tasks (with dependencies), then create a new scan
        if(end($dividedTasks['lastInChain'])['end_date'] > end($schedule)['timespan'][0]) {

            array_push($schedule, [
                'tasks' => [],
                'timespan' => [end($dividedTasks['lastInChain'])['end_date']]
            ]);
        }

        // Add the tasks (without dependencies) to the schedules where they match (with start date for scan)
        foreach ($dividedTasks['lastInChain'] as $lastTask) {

            for($i = 0; $i < count($schedule); $i++) {

                if($lastTask['end_date'] <= $schedule[$i]['timespan'][0]) {

                    $schedule[$i]['tasks'][] = $lastTask['id'];
                    $schedule[$i]['text'][] = $lastTask['text'];
                    break;
                }
            }
        }

        return $schedule;
    }

    /**
     * runs trough a collection of dates, and find the best possible days for scan
     *
     * @param Int $average
     * @param Int $end
     * @param Array $scanDates
     * @param Int $scanAmount
     * @param Boolean $leftFirst
     * @param Int $start
     * @return Array
     */
    public function defaultScansRecursive($average, $end, &$scanDates, $scanAmount, $leftFirst = true, $start = 0) {
        if($leftFirst) { // If the recursive function went left of the average, then set right as highest priority

            $left = ceil($scanAmount / 2);
            $right = floor($scanAmount / 2);
        } else { // If the recursive function went right of the average, then set left as highest priority

            $right = ceil($scanAmount / 2);
            $left = floor($scanAmount / 2);
        }       

        if($average - $start - 1> 2 && $left > 0) { // pick next average and run this function again

            $left--;
            $scanDates[] = round(($average + $start) / 2);

            $this->defaultScansRecursive(end($scanDates), $average, $scanDates, $left, true, $start);                  
        }
        if($end - $average - 1> 2 && $right > 0) { // pick next average and run this function again

            $right--;
            $scanDates[] = round(($average + $end) / 2);
            
            $this->defaultScansRecursive(end($scanDates), $end, $scanDates, $right, false, $average);  
        }        
    }

    /**
     * Converts the scan schedule data into a dhtmlx Gantt schedule format.
     *
     * @param $tasks
     * @return Array
     */
    private function convertToDBschema($schedule, &$dates, $tasks, $links) {

        $index = -1; // Arr-index (is -1 because it has to be part of each iteration, but has to be 1 in beginning of second loop)
        $largestId = 0; // Is used to ensure scanSchedule ID is not the same as the task ID (otherwise, we would have conflicts)

        // Finds the largest id. This number is used to ensure that the right id is used
        foreach($schedule as $element) {

            foreach($element['tasks'] as $task) {

                if (intval($task) > $largestId) {

                    $largestId = intval($task);
                }
            }
        }

        $largestId++;

        $scans = [
            'data' => [],
            'links' => $links
        ];
        
        $newId = count($schedule) + $largestId;

        // Adds the schedule as its own entity, this entity is used as a parent to the tasks in this schedule
        for ($i = 0; $i < count($schedule); $i++) {

            $duration = count($schedule[$i]['timespan']);
            $startDate = $schedule[$i]['timespan'][0];
            $endDate = end($schedule[$i]['timespan']) . ' 23:59';

            array_push($scans['data'], [
                'id' => strval($i + $largestId),
                'text' => 'Scan ' . ($i + 1),
                'start_date' => $startDate,
                'duration' => $duration,
                'progress' => 0,
                'open' => true,
                'parent' => '0',
                'end_date' => $endDate,
                'custom_data' => [
                    'completeness_progress' => 0,
                    'non_essential_progress' => '0',
                    'scan_task' => false,
                    'notes' => ''
                ]
            ]);

            $index++;

            $dates[$schedule[$i]['timespan'][0]] = [
                'duration' => $duration,
                'is_completed' => false,
                'areas' => 0.0,
                'notes' => '',
                'elements' => [],
                'tasks' => []
            ];

            // Adds each task from the current schedule as its own entity.
            for ($n = 0; $n < count($schedule[$i]['tasks']); $n++) {
                
                if(strpos($schedule[$i]['tasks'][$n], ':')) {

                    $taskID = strtok($schedule[$i]['tasks'][$n], ':');  
                    $oldID = substr($schedule[$i]['tasks'][$n], strpos($schedule[$i]['tasks'][$n], ":") + 1);  
                    $task = $tasks[$this->searchForId($oldID,$tasks,'id')];
                    $non_essential_progress = $oldID;

                } else {

                    $taskID = $schedule[$i]['tasks'][$n];
                    $non_essential_progress = '0';
                    $task = $tasks[$this->searchForId($taskID,$tasks,'id')];                    
                }

                $text = $schedule[$i]['text'][$n];
                $startDate = $task['start_date'];
                $endDate = $task['end_date'];
                $duration = (strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24);

                array_push($scans['data'], [
                    'id' => $taskID,
                    'text' => $text,
                    'start_date' =>  $startDate,
                    'duration' => $duration,
                    'progress' => 0,
                    'open' => true,
                    'parent' => strval($i + $largestId),
                    'end_date' => $endDate,
                    'custom_data' => [
                        'completeness_progress' => 0,
                        'non_essential_progress' => $non_essential_progress,
                        'scan_task' => false,
                        'note' => 'this is a note'
                    ]
                ]);

                $index++;
                $dates[$schedule[$i]['timespan'][0]]['tasks'][] = $schedule[$i]['tasks'][$n];                       
            }
        }

        return $scans;
    }

    /**
     * Shows how to go trough a model, not used in program
     *
     */
    private function goThroughModel(){
        // Checks if the current task is part of geometry and has a layer
        if ($model != null) {

            foreach ($model['elements'] as $id => $value) {
   
                if (array_key_exists($schedule[$i]['tasks'][$n], $value['tasks'])) {
                    
                    $scans['data'][$index]['custom_data']['geometry'] = true;

                    if ($value['tasks'][intval($schedule[$i]['tasks'][$n])]['Layer'] != null) {

                        $scans['data'][$index]['custom_data']['layer'] = true;
                    }

                    if (!in_array($id, $dates[$schedule[$i]['timespan'][0]]['elements'])) {

                        $dates[$schedule[$i]['timespan'][0]]['elements'][] = $id;
                    }                                                
                }
            }       
        }
    }

    /**
     * runs through an multidimensional array and searches for a selected value 
     *
     * @param String $needle
     * @param Array $haystack
     * @param String $key
     * @return Boolean
     */
    private function inArrayMulti($needle, $haystack, $key) {

        // Searches a multidimensional array for a reference based on a defined attribute.
        foreach ($haystack as $item) {
            Log::channel('single')->info('WHOA MAMA');
            Log::channel('single')->info($haystack);
            if (($needle == $item[$key])) {

                return true;
            }
        }

        return false;

    }

    /**
     * Sorts an multidimensional array
     *
     * @param Array $task
     * @param String $value
     * @return Array
     */
    public function sortMultiByASC($task, $value) {

        // Sorts a multidimensional array, based on a defined attribute.
        $element = [];

        foreach ($task as $key => $row) {
          
            $element[$key] = $row[$value];
        }

        array_multisort($element, SORT_ASC, $task);

        return $task;
    }

    /**
     * Sorts an multidimensional array with an timespan
     *
     * @param Array $task
     * @param String $value
     * @return Array
     */
    private function sortMultiByASCTimespan($task, $value) {
        // Sorts a multidimensional array, based on a defined attribute.
         $element = [];

         foreach($task as $key => $row) {

             $element[$key] = $row[$value][0];
         }

         array_multisort($element, $task);

         return $task;
    }

    /**
     * runs through an multidimensional array and find the key for the selected attribute
     *
     * @param String $id
     * @param Array $array
     * @param String $attribute
     * @return String 
     */
    public function searchForId($id, $array, $attribute) {

        // Searches a multidimensional array for a reference based on a defined attribute.
        foreach ($array as $key => $val)
        {
            if ($val[$attribute] == $id)
            {
                return $key;
            }
        }
        return null;
    }
        /**
     * might be useful if a schedule don't have an .custom_data.duration_days
     *
     * @param Array $scheduleId
     */
    public function addCustomDuration($scheduleId)
    {        
        $convertTodays = 24*60*60; //h:24 m:60 s:60
        $schedule = Schedule::with('tasks')->findOrFail($scheduleId);
        $tasks = Task::where('schedule_id', $scheduleId)->firstOrFail();
        $days = array_slice($schedule['worktime']['dates'], 0, 7, true);
        $hoursInDay = 1;
        if ($schedule["config"]['duration_unit'] != 'day')
        {
            $hoursInDay = 9;
            // use this when the duration_unit is correct
            //$hoursInDay = ($schedule['worktime']['hours'][count($schedule['worktime']['hours'])- 1] - $schedule['worktime']['hours'][0]);
        } 
        $count = 0;
        foreach($tasks['data'] as $item)
        {      
            $count++;
       
            $duration = round(intval($item['duration'])) / $hoursInDay;

            $weekDay = intval(date('w', strtotime($item['start_date']))); 

            $customDuration = $duration; 

            for($n = 0; $n < $duration; $n++)
            {                   
                if(!$days[$weekDay])
                {
                    $customDuration++;
                    $n--;                        
                }
                $weekDay >= 6 ? $weekDay = 0 : $weekDay++;            
            }

            Task::where('schedule_id', $scheduleId)->update(array('data.'. array_search($item, $tasks['data']) .'.custom_data.duration_days' => intval(ceil($customDuration))));            
        }       
    }      
    /**
     * Catch error if job fails.
     *
     * @return ProcessFailedException
     */
    public function failed(ProcessFailedException $exception)
    {
        dd($exception);
    }
}
