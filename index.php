<?php
require_once APP_PATH_DOCROOT . "ProjectGeneral/header.php"; 
$surveyDashboard = new BCCHR\SurveyDashboard\SurveyDashboard();
$surveyDashboard->getDashboard();
require_once APP_PATH_DOCROOT . "ProjectGeneral/footer.php";