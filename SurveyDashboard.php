<?php 

namespace BCCHR\SurveyDashboard;

use REDCap;
use Project;

class SurveyDashboard extends \ExternalModules\AbstractExternalModule
{
    function  __construct()
    {
        parent::__construct();
    }

    public function getDashboard() 
    {
        /* Survey dashboard statistics */
        /* 
            Author:  Naveen Karduri, Nelson Chan, Ashley Lee
            BC children's Hospital Research Institute
        */

        $Proj = new Project();
        $pid = $this->getProjectId();

        ?>
        <?php
        // Generate the dropdown list of "Survey - Event" combo
        // Based on Code copied from REDCap Class - SurveyScheduler::getInvitationLogSurveyList()
        $surveyEventOptions = array(); $surveyFormName = [];
        // Loop through each event and output each where this form is designated
        foreach ($Proj->eventsForms as $this_event_id=>$these_forms) {
            // Loop through forms
            foreach ($these_forms as $form_name) {
                // Ignore if not a survey
                if (!isset($Proj->forms[$form_name]['survey_id'])) continue;
                // Get survey_id
                $this_survey_id = $Proj->forms[$form_name]['survey_id'];
                // If longitudinal, add event name
                $event_name = ($longitudinal) ? " - ".$Proj->eventInfo[$this_event_id]['name_ext'] : "";
                // If survey title is blank (because using a logo instead), then insert the instrument name
                $survey_title = ($Proj->surveys[$this_survey_id]['title'] == "") ? $Proj->forms[$form_name]['menu'] : $Proj->surveys[$this_survey_id]['title'];
                // Truncate survey title if too long
                if (strlen($survey_title.$event_name) > 90) {
                    $survey_title = substr($survey_title, 0, 87-strlen($event_name)) . "...";
                }
                // Add this survey/event as drop-down option
                $surveyEventOptions["$this_survey_id-$this_event_id"] = "\"$survey_title\"$event_name";
                $surveyFormName["$this_survey_id"] = $Proj->surveys[$this_survey_id]['form_name'];
            }
        }

        // Get first element of the dropdown
        reset($surveyEventOptions); 
        $default = key($surveyEventOptions);
        $survey = $_GET["survey"];   // survey

        $event = $_GET["event"];     // event
        
        if (!empty($survey) && !empty($event) && isset($surveyEventOptions["$survey-$event"])) {
            $default = "$survey-$event";
        } 
        else {
            $default_arr = explode("-", $default);
            $survey = $default_arr[0];
            $event = $default_arr[1];
        }
        ?>
        <p style="max-width:810px;margin:5px 0 15px;">The <b>Survey Dashboard</b> displays survey statistics for individual REDCap projects. The dashboard is divided in three sections:</p>
        <ul>
	        <li>A survey completion breakdown pie chart, displaying incomplete, unverified and completed survey statuses </li>
	        <li>A survey completion count timeline, displaying surveys completed across time and their corresponding invitation send times (when available) </li>
	        <li>A duration histogram displaying time between survey invitation and survey completion (only calculated if both values are present). </li>
	    </ul>
        <?php

        $surveyQuery = "select * from redcap_surveys where project_id = '$pid' ";

        $sResult = $this->query($surveyQuery);

        $row_count = mysqli_num_rows($sResult);

        if($row_count == NULL || $row_count == 0) { ?>
        <div class="row">
            <div class="col-sm-12 error small">
                <h1><b>Error</b>: This project does <b><u>NOT</u></b> have any survey setup.</h1> <br/>
            </div>
        </div>
        <?php } ?>

        <div class="row">
            <div class="col-sm-8 survey-dropdown">
                <div>
                    <select class="form-control" name="survey_event" id="survey_event" onchange="SurveyDashboard.surveySelect(this.value)" >
                        <option value ="">-- Select the Survey --</option>
                        <?php
                            foreach($surveyEventOptions as $value => $label) {
                                $label = htmlspecialchars($label); 
                                print '<option '.(($value == $default)?'selected ':'').'value="'. $value .'">'. $label .'</option>';
                            }
                        ?>
                    </select>
                </div>
                <span>&nbsp;*</span>
            </div>
        </div>

        <div class="loadertxt">Loading....please wait</div>
        <div class="loader"></div>
        <br>
        <?php
        $record_id = REDCap::getRecordIdField();	 

        $data = REDCap::getData('json', null, array($record_id,$surveyFormName[$survey]."_complete",$surveyFormName[$survey]."_timestamp"), $event, null, true,true,true);

        $data_array = json_decode($data,true);

        $total_participants = count($data_array);

        $data_set = [];
        ## Get survey time stamp field
        ## Get form status field

        $total_complete_status = 0;
        $survey_complete_status = 0;
        $total_incomplete_status = 0;
        $total_unverified_status = 0;
        $total_partial_status = 0;

        for ($i=0; $i<$total_participants;$i++) {
            if ($data_array[$i][$surveyFormName[$survey].'_complete'] == 2) {	
                $total_complete_status++;
                // It is possible that incomplete or partial status will generate a timestamp. Only do the timestamp logic on records with _complete=2
                date_default_timezone_set('UTC');  // Set the timezone to UTC as Highchart will offset according to local timezone
                if (($utc_date = strtotime(substr($data_array[$i][$surveyFormName[$survey].'_timestamp'],0,10))) !== false) {
                    $survey_complete_status++;
                    $data_set[$data_array[$i][$record_id]] = ["complete" => $utc_date, "duration" => strtotime($data_array[$i][$surveyFormName[$survey].'_timestamp'])];
                }
            }

            if ($data_array[$i][$surveyFormName[$survey].'_complete'] == 0) { 	
                date_default_timezone_set('UTC'); 
                if ($data_array[$i][$surveyFormName[$survey].'_timestamp'] == "[not completed]" || ($utc_date = strtotime(substr($data_array[$i][$surveyFormName[$survey].'_timestamp'],0,10))) !== false ) {
                    $total_partial_status++;
                } else { 
                    $total_incomplete_status++;
                }
            }

            if ($data_array[$i][$surveyFormName[$survey].'_complete'] == 1) {	
                $total_unverified_status++;
            }
        }

        $dataentry_complete_status = $total_complete_status - $survey_complete_status;

        $invite_time = [];
        $max_invite[0] = 0;

        //check if this survey is listed as a public survey  or not.
        $survey_public  = "SELECT survey_id, hash FROM redcap_surveys_participants WHERE legacy_hash IS NULL AND participant_email IS NULL AND participant_identifier IS NULL AND survey_id = '$survey'";
        $survey_public_result  = $this->query($survey_public);				  
        $row_count_survey_public_result = mysqli_num_rows($survey_public_result);
        if($row_count_survey_public_result != NULL && $row_count_survey_public_result != 0)
        {
            while ($row = db_fetch_assoc($survey_public_result)) {	
                $survey  = $row['survey_id']; 
            }
            print '<p class="yellow" style="margin:20px 0;">
                <strong>Warning - Public Surveys</strong><br>The Survey Dashboard uses invitation send time as the start time for surveys. 
                For projects with Public Surveys, no survey invitations are sent, and thus, portions of the Dashboard will not work (namely, 
                the survey timeline will not display the invitations sent across time, nor will it display the duration histogram).
                </p>';
        }

        //check if this survey is in survey queue list - if it yes - then pull the parent's survey invitation time 
        $survey_queue  = "SELECT condition_surveycomplete_survey_id FROM redcap_surveys_queue where survey_id = '$survey'"; 
        $survey_queue_result  = $this->query($survey_queue);				  
        $row_count_survey_queue_result = mysqli_num_rows($survey_queue_result);
        if($row_count_survey_queue_result != NULL && $row_count_survey_queue_result != 0)
        {
            while ($row = db_fetch_assoc($survey_queue_result)) {	
                $survey  = $row['condition_surveycomplete_survey_id'];  // Parent survey id value
                //check if this survey as parent survey_id - do loop 
                for($i=0; $i<$row_count_survey_queue_result ;$i++)
                {
                    $survey_queue  = "SELECT condition_surveycomplete_survey_id FROM redcap_surveys_queue where survey_id = '$survey'"; 
                    $survey_queue_result  = $this->query($survey_queue);				  
                    $row_count_survey_queue_result = mysqli_num_rows($survey_queue_result);
                    if($row_count_survey_queue_result != NULL && $row_count_survey_queue_result != 0)
                    {
                        while ($row = db_fetch_assoc($survey_queue_result)) {	
                            $survey  = $row['condition_surveycomplete_survey_id'];  // Parent survey id value
                        }		
                    }	
                }
                if(empty($survey) || $survey == NULL) // Get the first conditional survey id
                {	
                    $survey_queue_first = "SELECT * FROM redcap_surveys_queue where survey_id IN ( SELECT survey_id FROM redcap_surveys where project_id = $pid) LIMIT 0, 1";
                    $survey_queue_first_result = $this->query($survey_queue_first);
                    while ($row = db_fetch_assoc($survey_queue_first_result)) {	
                        $survey  = $row['condition_surveycomplete_survey_id'];
                    }
                }
            }
            print '<p class="yellow" style="margin:20px 0;">
                <strong>Warning - Survey Queue, Autocontinue</strong><br> The Survey Dashboard uses invitation send time as the start time for surveys. 
                For projects with a Survey Queue or using the Autocontinue feature, the survey invitation time is based on the first survey invitation send time.
                </p>';
        }

        $query2 = "SELECT rser.participant_id, min(rse.email_sent) as sent_time, rsp.event_id, rsp.hash, rsr.record " . 
                    "FROM redcap_surveys s, redcap_surveys_emails rse, redcap_surveys_participants rsp, redcap_surveys_emails_recipients rser
                LEFT JOIN redcap_surveys_response rsr ON rsr.participant_id = rser.participant_id " . 
                "WHERE s.survey_id = rse.survey_id AND rse.survey_id = rsp.survey_id AND rse.email_id = rser.email_id AND rsp.participant_id = rser.participant_id " . 
                    "AND s.survey_id = '$survey' AND rsp.event_id = '$event' " . 
                "GROUP BY rsp.hash ORDER BY sent_time; ";

        $result2 = $this->query($query2);

        $row_count2 = mysqli_num_rows($result2);

        if($row_count2 != NULL && $row_count2 != 0)
        {
            date_default_timezone_set('UTC');  // Set the timezone to UTC as Highchart will offset according to local timezone
            while ($row = db_fetch_assoc($result2)) {
                if (($utc_date = strtotime(substr($row["sent_time"],0,10))) !== false) {
                    if (empty($row["record"])) {
                        $data_set["sdashboard_temp_pid".$row["participant_id"].$row["sent_time"]]["invite"] = $utc_date;
                    } elseif (isset($data_set[$row["record"]])) {
                        $data_set[$row["record"]]["invite"] = $utc_date;
                        $data_set[$row["record"]]["duration"] = $data_set[$row["record"]]["duration"] - strtotime($row["sent_time"]);
                    } else {
                        $data_set[$row["record"]]["invite"] = $utc_date;
                    } 
                }
            }
        }   

        foreach ($data_set as $key => $arr) {
            if (!isset($arr["invite"])) {
                unset($data_set[$key]["duration"]);
            }
        }

        date_default_timezone_set('UTC');
        $prod_time = strtotime(substr($Proj->project["production_time"],0,10));

        ##  Plot into Graph 
        ?>
        <br><br>
        <div class="row">
            <div class="col-sm-4">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <div class="row">
                        <div class="col-12 text-right">
                            <div class="huge"><?php print $total_participants; ?></div>
                            <div>Total Participants</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($survey_complete_status != 0 || $dataentry_complete_status != 0) {?>
                <div class="panel panel-green">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-right">
                                <div class="huge"><?php print $survey_complete_status; if ($dataentry_complete_status != 0) { print ' <small>(+'.$dataentry_complete_status.')</small>'; } ?></div>
                                <div>Completed via Survey<?php if ($dataentry_complete_status != 0) { ?> <small>(+Data Entry)</small><?php } ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <?php if ($total_incomplete_status != 0) {?>
                <div class="panel panel-red">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-right">
                                <div class="huge"><?php print $total_incomplete_status; ?></div>
                                <div>Incomplete</div>
                            </div>
                        </div>
                    </div>			   
                </div>
            <?php } ?>	
            <?php if ($total_unverified_status != 0) {?>
                <div class="panel panel-yellow">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-right">
                            <div class="huge"><?php print $total_unverified_status; ?></div>
                            <div>Unverified</div>                           
                        </div>
                        </div>
                    </div>	
                </div>
            <?php } ?>	
            <?php if ($total_partial_status != 0) {?>
                <div class="panel panel-orange">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-right">
                            <div class="huge"><?php print $total_partial_status; ?></div>
                            <div>Partial</div>                           
                        </div>
                        </div>
                    </div>	
                </div>
            <?php } ?>
            </div>
            <div class="col-sm-8">
                <div id="conso-project-bar-chart"></div>
            </div>
        </div>
        <br/><br/><hr/><br/>
        <br/>
        <div class="row">
            <div class="col-sm-8 text-left section-title"><span>Time-based Statistics</span></div>
        </div>
        <br/>
        <div class="row">
            <div class="col-sm-12 text-left">
            <div class="col-sm-12 panel panel-grey time-filter"> 
                <div class="dropdown-label">Show Time Statistics for Surveys with Completion Date: </div>
                <div class="col-sm-4 dropdown-div">
                    <select id="period" class="form-control" id="stat_range" onchange="SurveyDashboard.filter()">
                        <option value="" selected>Since Project Creation</option>
                        <?php if($prod_time) { ?>
                        <option value="PD">Since Move to Production</option> 
                        <?php } ?>
                        <option value="1y">Within Last 1 year</option>
                        <option value="90d">Within Last 90 days</option>
                        <option value="60d">Within Last 60 days</option>
                        <option value="30d">Within Last 30 days</option>
                        <option value="14d">Within Last 14 days</option>
                        <option value="7d">Within Last 7 days</option>
                    </select>
                </div>
            </div>
            </div>
        </div>
        <br/>
        <div class="row">
            <div class="col-12">
                <div id="conso-project-timeline-chart"></div>
            </div>
        </div>
        <br/><br/><br/>
        <div class="row">
            <div class="col-sm-4">
                <div class="panel panel-grey">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-left">
                            <div>Min Duration<sup>&#8225;</sup> from Survey Invitation to Completion</div>        
                            <div class="large" id="duration-min">N/A</div>                   
                        </div>
                        </div>
                    </div>	
                </div>
            </div>
            <div class="col-sm-4">
                <div class="panel panel-grey">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-center">
                            <div>Average Duration<sup>&#8225;</sup> from Survey Invitation to Completion</div>        
                            <div class="large" id="duration-avg">N/A</div>                   
                        </div>
                        </div>
                    </div>	
                </div>
            </div>
            <div class="col-sm-4">
                <div class="panel panel-grey">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-12 text-right">
                            <div>Max Duration<sup>&#8225;</sup> from Survey Invitation to Completion</div>        
                            <div class="large" id="duration-max">N/A</div>                   
                        </div>
                        </div>
                    </div>	
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <div id="conso-project-duration-chart"></div>
            </div>
        </div>
        <br/><br/><br/>
        <div class="row">
            <div class="col-sm-12">
                * Repeating instruments are currently <b>NOT</b> supported. Statistics shown might not reflect the complete data set if "Repeatable instruments" option is enabled for this project 
                <br/>
                &#8224; Only the <b>FIRST</b> invitation is considered if multiple invitations (of one single survey) are sent to the same subject 
                <br/>
                &#8225; Duration of a Survey is the total time elapsed from <b>when the FIRST invitation is sent out</b> to <b>when the survey is COMPLETED</b>  
            </div>
        </div>
        <br/><br/>
        <script src="<?php print $this->getUrl("highcharts.js"); ?>"></script>
        <script src="<?php print $this->getUrl("exporting.js"); ?>"></script>
        <script src="<?php print $this->getUrl("offline-exporting.js"); ?>"></script>
        <script src="<?php print $this->getUrl("histogram-bellcurve.js"); ?>"></script>
        <link rel="stylesheet" type="text/css" href="<?php print $this->getUrl("sb-admin.css"); ?>">           
        <script>
            var SurveyDashboard = {
                master_set: <?php print json_encode(array_values($data_set)) ?>,
                data_set: <?php print json_encode(array_values($data_set)) ?>,
                complete_set: [], 
                invite_set: [],
                duration_set: [],
                prod_time: <?php print ($prod_time)?$prod_time:"null" ?>,
                timelineChart: null, 
                durationChart: null,
                surveySelect: function (value) {
                    value = value.split("-");
                    $(".loadertxt").show();
                    $(".loader").show();
                    window.location.href = "<?php print $this->getUrl("index.php"); ?>" + "&survey=" + value[0] + "&event=" + value[1];
                },
                filter: function () {
                    var start, end;
                    var prod_time = <?php print ($prod_time)?$prod_time:"null" ?>;

                    switch ($('#period').val()) {
                        case 'PD':        
                            start = prod_time;
                            break;
                        case '1y':
                            start = (new Date().getTime()/1000|0) - 31536000;
                            break;
                        case '90d':
                            start = (new Date().getTime()/1000|0) - 7776000;
                            break;
                        case '60d':
                            start = (new Date().getTime()/1000|0) - 5184000;
                            break;
                        case '30d':
                            start = (new Date().getTime()/1000|0) - 2592000;
                            break;
                        case '14d':
                            start = (new Date().getTime()/1000|0) - 1209600;
                            break;
                        case '7d':
                            start = (new Date().getTime()/1000|0) - 604800;
                            break;
                    }

                    if (start && !(start && end)) {
                        this.data_set = [];
                        for (var i = 0; i < this.master_set.length; i++) {
                            if (this.master_set[i]["complete"] >= start) {
                                this.data_set.push(this.master_set[i]);
                            }
                        }
                    } 
                    else if (!start && !end) { 
                        this.data_set = this.master_set;
                    }

                    this.update();
                },
                update: function () {
                    var cp = [], inv = [], dur = [];
                    var completeHigh, inviteHigh, cpHighCount = 0, invHighCount = 0;
                    var durTotal = 0, durMax = 0, durMin = 0;

                    this.complete_set = []; this.invite_set = []; this.duration_set = [];

                    for (var i = 0; i < this.data_set.length; i++) {
                        if (this.data_set[i]["complete"]) {
                            var completeTS = this.data_set[i]["complete"];
                            if (cp[completeTS]) {
                                cp[completeTS]++;
                            } 
                            else {
                                cp[completeTS] = 1;
                            }

                            if (cp[completeTS] > cpHighCount) {
                                completeHigh = completeTS;
                                cpHighCount = cp[completeTS];
                            }
                            
                            if (this.data_set[i]["duration"]) {
                                var durPt = this.data_set[i]["duration"];
                                this.duration_set.push(durPt);

                                if (durPt > durMax) {
                                    durMax = durPt;
                                }

                                if (durMin == 0 || durPt < durMin) {
                                    durMin = durPt;
                                }
                                durTotal += durPt;

                                console.log(durTotal);
                            }
                        }
                        if (this.data_set[i]["invite"]) {
                            var inviteTS = this.data_set[i]["invite"];
                            if (inv[inviteTS]) {
                                inv[inviteTS]++;
                            }
                            else {
                                inv[inviteTS] = 1;
                            }

                            if (inv[inviteTS] > invHighCount) {
                                inviteHigh = inviteTS;
                                invHighCount = inv[inviteTS];
                            }
                        } 
                    }

                    var cp_keys = Object.keys(cp);
                    for (var i = 0; i < cp_keys.length; i++) {
                        /*	Add dummy datapoint of 0 in between actual datapoints to keep line default to 0
                        *	if the difference between 2 actual datapoints is more than 1 day, add one dummy after the first actual datapoint;
                        *	and if the difference is more than 2 days, add one more dummy before the second actual datapoint
                        */
                        if (i == 0) {
                            this.complete_set.push([(cp_keys[i]-86400)*1000, 0]);
                        }
                        else if (cp_keys[i] - cp_keys[i-1] > 86400){  
                            this.complete_set.push([(+cp_keys[i-1]+86400)*1000, 0]);  // Need to add the "+" in front of the variable to force js to treat it as number
                            if (cp_keys[i] - cp_keys[i-1] > 172800) {  
                                this.complete_set.push([(cp_keys[i]-86400)*1000, 0]);
                            }
                        }
                        this.complete_set.push([cp_keys[i]*1000, cp[cp_keys[i]]]);
                    }

                    var inv_keys = Object.keys(inv);
                    for (var i = 0; i < inv_keys.length; i++) {
                        if (i == 0) {
                            this.invite_set.push([(inv_keys[i]-86400)*1000, 0]);
                        }
                        else if (inv_keys[i] - inv_keys[i-1] > 86400){
                            this.invite_set.push([(+inv_keys[i-1]+86400)*1000, 0]);  // Need to add the "+" in front of the variable to force js to treat it as number
                            if (inv_keys[i] - inv_keys[i-1] > 172800) { 
                                this.invite_set.push([(inv_keys[i]-86400)*1000, 0]);
                            }
                        }
                        this.invite_set.push([inv_keys[i]*1000, inv[inv_keys[i]]]);
                    } 

                    if (this.timelineChart) {
                        this.timelineChart.update({
                            series: [{
                                name: 'Survey Completed',
                                data: this.complete_set,
                            }, {
                                name: 'Invitation Sent',
                                data: this.invite_set,
                            }]
                        });

                        if (completeHigh) {
                            $('#complete-high-count').hide().html(cpHighCount).fadeIn(400);
                            $('#complete-high').hide().html('('+new Date(completeHigh*1000).toISOString().slice(0,10).replace(/-/g,"/")+')').fadeIn(400);
                        } 
                        else {
                            $('#complete-high-count').hide().html('N/A').fadeIn("slow");
                            $('#complete-high').hide().html('').fadeIn("slow");
                        } 

                        if (inviteHigh) {
                            $('#invite-high-count').hide().html(invHighCount).fadeIn(400);
                            $('#invite-high').hide().html('('+new Date(inviteHigh*1000).toISOString().slice(0,10).replace(/-/g,"/")+')').fadeIn(400);
                        } 
                        else {
                            $('#invite-high-count').hide().html('N/A').fadeIn("slow");
                            $('#invite-high').hide().html('').fadeIn("slow");
                        }
                    }

                    if (this.durationChart) {
                        if (this.duration_set.length > 1) {
                            this.durationChart.update({
                                series: [{}, {
                                    id: 's_dur',
                                    data: this.duration_set
                                }]
                            }); 
                        }
                        else {
                            this.durationChart.update({
                                series: [{}, {
                                    id: 's_dur',
                                    data: []
                                }]
                            }); 
                        }

                        $('.highcharts-scatter-series').hide();

                        if (this.duration_set.length > 0) {
                            $('#duration-avg').hide().html(this.secToStr(Math.round( durTotal/this.duration_set.length ))).fadeIn(400);
                            $('#duration-max').hide().html(this.secToStr(durMax)).fadeIn(400);
                            $('#duration-min').hide().html(this.secToStr(durMin)).fadeIn(400);
                        } 
                        else {
                            $('#duration-avg').hide().html('N/A').fadeIn(400);
                            $('#duration-max').hide().html('N/A').fadeIn(400);
                            $('#duration-min').hide().html('N/A').fadeIn(400);
                        }
                    }
                },
                secToStr: function (s)  {
                    var r_str = (s/86400 > 1) ? Math.floor(s/86400) + 'd ' : '';
                    var r_hr = Math.floor(s/3600) % 24;
                    r_str += (r_hr > 0) ? r_hr + 'hr ' : '';
                    var r_min = Math.floor(s/60) % 60;
                    r_str += (r_min > 0) ? r_min + 'm ' : '';
                    r_str += (s%60 < 10) ? '0' + s%60 + 's' : s%60 + 's';
                    return r_str;
                }
            }

            $(".loadertxt").hide();
            $(".loader").hide();

            $(function () {
                $('#conso-project-bar-chart').highcharts({
                    credits: {
                        enabled: false
                    },
                    exporting:{
                        buttons: {
                            contextButton: {
                                menuItems: ['printChart', 'separator', 'downloadPNG', 'downloadSVG']
                            }
                        },
                        fallbackToExportServer: false  // export server disabled
                    },
                    chart: {
                        plotBackgroundColor: null,
                        plotBorderWidth: null,
                        plotShadow: false
                    },
                    title: {
                        text: '<?php print "Survey Response Overview"; ?>'
                    },
                    
                    tooltip: {
                        pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
                    },
                    plotOptions: {
                        pie: {
                            allowPointSelect: true,
                            cursor: 'pointer',
                            dataLabels: {
                                enabled: true,
                                format: '<b>{point.name}</b>: {point.percentage:.1f} %',
                                style: {
                                    color: (Highcharts.theme && Highcharts.theme.contrastTextColor) || 'black'
                                }
                            }
                        }
                    },
                    series: [{
                        type: 'pie',
                        name: 'Browser share',
                        data: [
                            { name: 'Complete',  y: <?php print $total_complete_status; ?>,  color: '#098800' },
                            { name: 'Incomplete', y: <?php print $total_incomplete_status; ?>, color: '#d9534f' },
                            { name: 'Unverified',    y: <?php print $total_unverified_status; ?>,    color: '#ffe000' },
                            { name: 'Partial',    y: <?php print $total_partial_status; ?>,    color: '#ff9020' },
                        ]
                    }]
                });

                SurveyDashboard.timelineChart = Highcharts.chart('conso-project-timeline-chart', {
                    credits: {
                        enabled: false
                    },
                    exporting:{
                        buttons: {
                            contextButton: {
                                menuItems: ['printChart', 'separator', 'downloadPNG', 'downloadSVG']
                            }
                        },
                        fallbackToExportServer: false  // export server disabled
                    },
                    chart: {
                        type: 'line'
                    },
                    title: {
                        text: '<?php print "Survey Invitation and Completion Counts<sup>†</sup>"; ?>',
                        align: 'left',
                        margin: 30,
                        x: 5,
                        useHTML: true
                    },
                    xAxis: {
                        type: 'datetime',
                        title: {
                            text: 'Date'
                        },
                        dateTimeLabelFormats: {
                            millisecond: '%e. %b',
                            second: '%e. %b',
                            minute: '%e. %b',
                            hour: '%e. %b',
                            day: '%e. %b',
                        },
                        labels: {
                            rotation: -45,
                            align: 'right'
                        }
                    },
                    yAxis: {
                        title: {
                            text: 'Count'
                        },
                        min: 0,
                        allowDecimals: false
                    },
                    tooltip: {
                        headerFormat: '<b>{series.name}</b><br>',
                        pointFormat: '{point.x:%Y/%m/%d}: {point.y}'
                    },
                    plotOptions: {},
                    series: [{
                        name: 'Survey Completed',
                        data: []
                    },{
                        name: 'Invitation Sent',
                        data: []
                    }
                    ]
                });

                SurveyDashboard.durationChart = Highcharts.chart('conso-project-duration-chart', {
                    credits: {
                        enabled: false
                    },
                    exporting:{
                        buttons: {
                            contextButton: {
                                menuItems: ['printChart', 'separator', 'downloadPNG', 'downloadSVG']
                            }
                        },
                        fallbackToExportServer: false  // export server disabled
                    },
                    title: {
                        text: '<?php print "Distribution of Duration from Survey Invitation to Completion"; ?>',
                        align: 'left'
                    },
                    xAxis: [{
                        title: {
                            text: null
                        },
                        labels: {
                            enable: false
                        }
                    },{
                        title: {
                            text: 'Duration'
                        },
                        labels: {
                            useHTML: true,
                            rotation: -45,
                            align: 'right',
                            formatter: function() {
                                return SurveyDashboard.secToStr(this.value);
                            }
                        }
                    }],
                    yAxis: [{
                        title: {
                            text: null
                        },
                        labels: {
                            enable: false
                        }
                    },{ 
                        title: {
                            text: 'Count'
                        },
                        minTickInterval: 1
                    }],
                    tooltip: {
                        formatter: function () {
                            return '<span style="font-size:10px">' + SurveyDashboard.secToStr(Math.round(this.point.x)) +' - ' 
                            + SurveyDashboard.secToStr(Math.round(this.point.x2)) + '</span><br/><span style="color:' + this.color 
                            + '">●</span> Count: <b>' + this.y + '</b><br/>';
                        }
                    },
                    legend: {
                        enabled: false
                    },
                    series: [{
                        name: 'Histogram of Survey Duration',
                        type: 'histogram',
                        xAxis: 1,
                        yAxis: 1,
                        baseSeries: 's_dur',
                        zIndex: -1
                    },{
                        type: 'scatter',
                        id: 's_dur',
                        visible: false
                    }]
                });
                
                $(".highcharts-credits").hide();
                $('.highcharts-scatter-series').hide();
                SurveyDashboard.update();
            });
        </script>
        <?php
    }

}