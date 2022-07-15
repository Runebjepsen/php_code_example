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
     * Catch error if job fails.
     *
     * @return ProcessFailedException
     */
    public function failed(ProcessFailedException $exception)
    {
        dd($exception);
    }
}
