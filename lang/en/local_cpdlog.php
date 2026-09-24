<?php
// Copyright (c) Skin Cancer College Australasia.
// All rights reserved.
//
// This file is part of a proprietary plugin developed by Skin Cancer
// College Australasia for use with Moodle. It is NOT free software and is
// NOT released under the GNU General Public License.
//
// Unauthorised copying, distribution, modification, or use of this file,
// in whole or in part, via any medium, is strictly prohibited without the
// prior written permission of Skin Cancer College Australasia. The software
// is provided "as is", without warranty of any kind, express or implied.


/**
 * English language strings for the CPD logbook plugin.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

$string['activitydate'] = 'Activity date';
$string['activitydate_help'] = 'The day the activity took place. It must fall within an open reporting period.';
$string['addcategory'] = 'Add category';
$string['addentry'] = 'Log CPD activity';
$string['addperiod'] = 'Add period';
$string['addtarget'] = 'Add target';
$string['allcategories'] = 'All categories';
$string['allmembers'] = 'All members';
$string['backtoperiods'] = 'Back to periods';
$string['backtotargets'] = 'Back to targets';
$string['categories'] = 'Categories';
$string['categories_desc'] = 'Categories group CPD activities. Disable a category to stop new entries using it; existing entries keep it.';
$string['choicefor'] = 'Cohort targets to add for {$a}';
$string['choicenone'] = 'No cohort targets (all-members targets only)';
$string['choicenotmade'] = 'Not chosen yet';
$string['choicestale'] = 'Earlier choice no longer applies';
$string['close'] = 'Close';
$string['cohortconflicts'] = 'Cohort conflicts ({$a} unresolved)';
$string['confirmcloseperiod'] = 'Close the period "{$a}"? Members will no longer be able to add or change entries in it, and its targets will be locked.';
$string['confirmdeleteentry'] = 'Delete this draft entry? This cannot be undone.';
$string['confirmdeleteperiod'] = 'Delete the period "{$a}"? This cannot be undone.';
$string['confirmdeletetarget'] = 'Delete the target "{$a}"? This cannot be undone.';
$string['confirmreopenperiod'] = 'Reopen the period "{$a}"? Members will be able to add and change entries in it again.';
$string['confirmsubmitentry'] = 'Submit this entry for review? You will not be able to change it while it is being reviewed.';
$string['conflictcohorts'] = 'Cohorts with targets';
$string['conflicts_desc'] = 'Everyone is measured against the all-members targets, and a member\'s cohort adds its own targets. These members are in more than one cohort with targets in this period. Until you choose which cohort\'s targets to add, only the all-members targets apply to them.';
$string['conflictsfor'] = 'Cohort conflicts for {$a}';
$string['cpdlog:approve'] = 'Approve, reject or reverse CPD entries';
$string['cpdlog:manageperiods'] = 'Manage CPD categories, periods and targets';
$string['cpdlog:submit'] = 'Log and submit own CPD entries';
$string['cpdlog:sync'] = 'Run or retry iMIS replication of CPD entries';
$string['cpdlog:viewall'] = 'View every member\'s CPD logbook';
$string['cpdlog:viewown'] = 'View own CPD logbook and progress';
$string['currentchoice'] = 'Current choice';
$string['defaultcategory:educationalactivities'] = 'Educational activities';
$string['defaultcategory:measuringoutcomes'] = 'Measuring outcomes';
$string['defaultcategory:reviewingperformance'] = 'Reviewing performance';
$string['disabled'] = 'Disabled';
$string['duplicatewarning'] = 'You already have an entry for this course on this date. If this is a separate activity, no action is needed.';
$string['editcategory'] = 'Edit category';
$string['editentry'] = 'Edit CPD activity';
$string['editperiod'] = 'Edit period';
$string['edittarget'] = 'Edit target';
$string['enabled'] = 'Enabled';
$string['entrycourse'] = 'Course';
$string['entrycourse_help'] = 'The course this activity relates to. You can choose courses you are or were enrolled in, or have completed.';
$string['entrydeleted'] = 'Draft entry deleted.';
$string['entrysaved'] = 'Draft saved.';
$string['entrystatus:approved'] = 'Approved';
$string['entrystatus:draft'] = 'Draft';
$string['entrystatus:rejected'] = 'Rejected';
$string['entrystatus:reversed'] = 'Reversed';
$string['entrystatus:submitted'] = 'Submitted for review';
$string['entrysubmitted'] = 'Entry submitted for review.';
$string['error:choosecohort'] = 'Choose which cohort targets to add before saving.';
$string['error:entrycategory'] = 'Choose an available category.';
$string['error:entrycourse'] = 'Choose a course you are or were enrolled in, or have completed.';
$string['error:entryhours'] = 'Hours must be at least 0.01, with at most two decimal places.';
$string['error:entryhoursmax'] = 'An entry can claim at most {$a} hours.';
$string['error:entryinvalid'] = 'The entry could not be saved: {$a}';
$string['error:entrylocked'] = 'This entry cannot be changed. Only your own draft or rejected entries in an open period can be changed.';
$string['error:entrynoperiod'] = 'No reporting period covers this date.';
$string['error:entryperiodclosed'] = 'The reporting period "{$a}" is closed, so activities on this date cannot be logged.';
$string['error:evidencerequired'] = 'This category needs at least one evidence file before the entry can be submitted. Save it as a draft and add evidence first.';
$string['error:periodclosed'] = 'This period is closed. Reopen it to make changes.';
$string['error:periodenddate'] = 'The last day must be on or after the first day.';
$string['error:periodinuse'] = 'This period has entries, targets or cohort choices, so it cannot be deleted.';
$string['error:periodoverlap'] = 'This period overlaps another period. Periods cannot overlap.';
$string['error:shortnametaken'] = 'Another category already uses this short name.';
$string['error:targethours'] = 'Required hours must be between 0.01 and 999999.99, with at most two decimal places.';
$string['event:entrycreated'] = 'CPD entry created';
$string['event:entrydeleted'] = 'CPD entry deleted';
$string['event:entrysubmitted'] = 'CPD entry submitted';
$string['event:entryupdated'] = 'CPD entry updated';
$string['evidenceneeded'] = 'Add evidence before submitting.';
$string['evidencerequired'] = 'Evidence required';
$string['evidencerequired_help'] = 'If ticked, members must attach at least one evidence file before they can submit an entry in this category.';
$string['firstday'] = 'First day';
$string['fromimis'] = 'From iMIS';
$string['hours'] = 'Hours';
$string['hours_help'] = 'The hours of CPD this activity is worth, up to two decimal places, for example 1.5.';
$string['lastday'] = 'Last day';
$string['lastday_help'] = 'The last day included in the period. Activities on this day count towards the period.';
$string['maxhoursperentry'] = 'Maximum hours per entry';
$string['maxhoursperentry_desc'] = 'The most hours a member can claim in a single CPD entry. Entries above this are rejected when they are saved.';
$string['member'] = 'Member';
$string['mylogbook'] = 'My CPD logbook';
$string['noconflicts'] = 'No members are in more than one cohort with targets in this period.';
$string['noentries'] = 'You have not logged any CPD activities yet.';
$string['periodclosedconflicts'] = 'This period is closed, so choices cannot be changed. Reopen the period to change them.';
$string['periodclosedtargets'] = 'This period is closed, so its targets cannot be changed. Reopen the period to change them.';
$string['periods'] = 'Reporting periods';
$string['periods_desc'] = 'Each CPD activity belongs to the period its date falls in. Periods cannot overlap. Close a period to lock its entries and targets.';
$string['pluginname'] = 'CPD logbook';
$string['privacy:cohortchoices'] = 'Cohort target choices';
$string['privacy:entries'] = 'CPD entries';
$string['privacy:metadata:local_cpdlog_category'] = 'CPD categories. Records the staff member who last changed each category.';
$string['privacy:metadata:local_cpdlog_cohortchoice'] = 'Staff choices of which cohort\'s targets apply to a member who is in several cohorts with conflicting targets.';
$string['privacy:metadata:local_cpdlog_cohortchoice:chosenby'] = 'The staff member who made the choice.';
$string['privacy:metadata:local_cpdlog_cohortchoice:cohortid'] = 'The cohort whose targets apply, or none for the all-members targets.';
$string['privacy:metadata:local_cpdlog_cohortchoice:periodid'] = 'The reporting period the choice applies to.';
$string['privacy:metadata:local_cpdlog_cohortchoice:timecreated'] = 'When the choice was made.';
$string['privacy:metadata:local_cpdlog_cohortchoice:timemodified'] = 'When the choice was last changed.';
$string['privacy:metadata:local_cpdlog_cohortchoice:userid'] = 'The member the choice applies to.';
$string['privacy:metadata:local_cpdlog_entry'] = 'CPD activities logged by or for a member.';
$string['privacy:metadata:local_cpdlog_entry:activitydate'] = 'The date of the activity.';
$string['privacy:metadata:local_cpdlog_entry:categoryid'] = 'The CPD category of the activity.';
$string['privacy:metadata:local_cpdlog_entry:courseid'] = 'The course the activity was logged against.';
$string['privacy:metadata:local_cpdlog_entry:coursename'] = 'The name of the course when the activity was logged.';
$string['privacy:metadata:local_cpdlog_entry:description'] = 'The member\'s description of the activity.';
$string['privacy:metadata:local_cpdlog_entry:externalref'] = 'The identifier of the matching CPD record in iMIS.';
$string['privacy:metadata:local_cpdlog_entry:hours'] = 'The hours claimed.';
$string['privacy:metadata:local_cpdlog_entry:periodid'] = 'The reporting period the activity falls in.';
$string['privacy:metadata:local_cpdlog_entry:rejectionreason'] = 'The reason given when the entry was rejected.';
$string['privacy:metadata:local_cpdlog_entry:reversalreason'] = 'The reason given when an approved entry was reversed.';
$string['privacy:metadata:local_cpdlog_entry:reversedby'] = 'The staff member who reversed the entry.';
$string['privacy:metadata:local_cpdlog_entry:reviewedby'] = 'The staff member who approved or rejected the entry.';
$string['privacy:metadata:local_cpdlog_entry:source'] = 'Whether the entry was created in Moodle or imported from iMIS.';
$string['privacy:metadata:local_cpdlog_entry:status'] = 'Whether the entry is a draft, submitted, approved, rejected or reversed.';
$string['privacy:metadata:local_cpdlog_entry:syncstatus'] = 'Whether the entry has been replicated to iMIS, is waiting, failed or conflicts.';
$string['privacy:metadata:local_cpdlog_entry:timecreated'] = 'When the entry was created.';
$string['privacy:metadata:local_cpdlog_entry:timemodified'] = 'When the entry was last changed.';
$string['privacy:metadata:local_cpdlog_entry:timereversed'] = 'When the entry was reversed.';
$string['privacy:metadata:local_cpdlog_entry:timereviewed'] = 'When the entry was approved or rejected.';
$string['privacy:metadata:local_cpdlog_entry:timesubmitted'] = 'When the entry was submitted for approval.';
$string['privacy:metadata:local_cpdlog_entry:userid'] = 'The member the entry belongs to.';
$string['privacy:metadata:local_cpdlog_period'] = 'CPD reporting periods. Records the staff member who last changed each period.';
$string['privacy:metadata:local_cpdlog_target'] = 'CPD hour targets. Records the staff member who last changed each target.';
$string['privacy:metadata:timemodified'] = 'When the record was last changed.';
$string['privacy:metadata:usermodified'] = 'The staff member who last changed the record.';
$string['privacy:staffactions'] = 'Actions as staff';
$string['rejectionreasonis'] = 'Reason for rejection: {$a}';
$string['reopen'] = 'Reopen';
$string['requiredhours'] = 'Required hours';
$string['resolved'] = 'Resolved';
$string['saveandsubmit'] = 'Save and submit for review';
$string['savedraft'] = 'Save draft';
$string['settings'] = 'Settings';
$string['shortname'] = 'Short name';
$string['shortname_help'] = 'A unique code for the category, used in reports and when matching CPD records from iMIS. Letters, numbers, hyphens and underscores only.';
$string['statusclosed'] = 'Closed';
$string['statusopen'] = 'Open';
$string['strftimedatefull'] = '%d/%m/%Y';
$string['targetcategories'] = 'Categories counted';
$string['targetcategories_help'] = 'The categories whose hours count towards this target. Tick one category for a minimum in that category, several for a combined minimum, or none for a total across all categories.';
$string['targetcohort'] = 'Cohort';
$string['targetcohort_help'] = 'Members this target applies to. Choose "All members" unless the target is only for one cohort.';
$string['targets'] = 'Targets';
$string['targets_desc'] = 'Targets set the CPD hours members must complete in this period.';
$string['targetsfor'] = 'Targets for {$a}';
$string['unresolved'] = 'Unresolved';
$string['unresolvedconflicts'] = 'Unresolved conflicts';
