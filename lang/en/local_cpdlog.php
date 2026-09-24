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

$string['addcategory'] = 'Add category';
$string['addperiod'] = 'Add period';
$string['addtarget'] = 'Add target';
$string['allcategories'] = 'All categories';
$string['allmembers'] = 'All members';
$string['backtoperiods'] = 'Back to periods';
$string['categories'] = 'Categories';
$string['categories_desc'] = 'Categories group CPD activities. Disable a category to stop new entries using it; existing entries keep it.';
$string['close'] = 'Close';
$string['confirmcloseperiod'] = 'Close the period "{$a}"? Members will no longer be able to add or change entries in it, and its targets will be locked.';
$string['confirmdeleteperiod'] = 'Delete the period "{$a}"? This cannot be undone.';
$string['confirmdeletetarget'] = 'Delete the target "{$a}"? This cannot be undone.';
$string['confirmreopenperiod'] = 'Reopen the period "{$a}"? Members will be able to add and change entries in it again.';
$string['cpdlog:approve'] = 'Approve, reject or reverse CPD entries';
$string['cpdlog:manageperiods'] = 'Manage CPD categories, periods and targets';
$string['cpdlog:submit'] = 'Log and submit own CPD entries';
$string['cpdlog:sync'] = 'Run or retry iMIS replication of CPD entries';
$string['cpdlog:viewall'] = 'View every member\'s CPD logbook';
$string['cpdlog:viewown'] = 'View own CPD logbook and progress';
$string['defaultcategory:educationalactivities'] = 'Educational activities';
$string['defaultcategory:measuringoutcomes'] = 'Measuring outcomes';
$string['defaultcategory:reviewingperformance'] = 'Reviewing performance';
$string['disabled'] = 'Disabled';
$string['editcategory'] = 'Edit category';
$string['editperiod'] = 'Edit period';
$string['edittarget'] = 'Edit target';
$string['enabled'] = 'Enabled';
$string['error:periodclosed'] = 'This period is closed. Reopen it to make changes.';
$string['error:periodenddate'] = 'The last day must be on or after the first day.';
$string['error:periodinuse'] = 'This period has entries, targets or cohort choices, so it cannot be deleted.';
$string['error:periodoverlap'] = 'This period overlaps another period. Periods cannot overlap.';
$string['error:shortnametaken'] = 'Another category already uses this short name.';
$string['error:targethours'] = 'Required hours must be between 0.01 and 999999.99, with at most two decimal places.';
$string['evidencerequired'] = 'Evidence required';
$string['evidencerequired_help'] = 'If ticked, members must attach at least one evidence file before they can submit an entry in this category.';
$string['firstday'] = 'First day';
$string['lastday'] = 'Last day';
$string['lastday_help'] = 'The last day included in the period. Activities on this day count towards the period.';
$string['maxhoursperentry'] = 'Maximum hours per entry';
$string['maxhoursperentry_desc'] = 'The most hours a member can claim in a single CPD entry. Entries above this are rejected when they are saved.';
$string['periodclosedtargets'] = 'This period is closed, so its targets cannot be changed. Reopen the period to change them.';
$string['periods'] = 'Reporting periods';
$string['periods_desc'] = 'Each CPD activity belongs to the period its date falls in. Periods cannot overlap. Close a period to lock its entries and targets.';
$string['pluginname'] = 'CPD logbook';
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
$string['reopen'] = 'Reopen';
$string['requiredhours'] = 'Required hours';
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
