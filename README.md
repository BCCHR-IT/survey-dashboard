# Survey Dashboard
This module displays survey statistics for individual REDCap projects. This module has been tested with classical, longitudinal, and multi-armed projects, but not repeating events. 

## Usage
A user can view statistics for each survey in a project, via dropdown selection.

The dashboard is divided into the following:
- A survey completion breakdown pie chart, displaying incomplete, unverified and completed survey statuses
- A survey completion count timeline, displaying surveys completed across time and their corresponding invitation send times (when available)
- A duration histogram displaying time between survey invitation and survey completion (only calculated if both values are present).

## Limitations
- The Survey Dashboard uses invitation send time as the start time for surveys. For projects with Public Surveys, no survey invitations are sent, and thus, portions of the Dashboard will not work (namely, the survey timeline will not display the invitations sent across time, nor will it display the duration histogram).
- The Survey Dashboard uses invitation send time as the start time for surveys. For projects with a Survey Queue or using the Autocontinue feature, the survey invitation time is based on the first survey invitation send time.