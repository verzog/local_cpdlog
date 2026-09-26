# CPD logbook (local_cpdlog)

A continuing professional development (CPD) logbook for Skin Cancer College Australasia (SCCA)
members, inside Moodle. Members log CPD hours by category against courses they are enrolled in,
attach evidence, and submit entries for staff approval. Members see their own progress against
configurable targets; staff report across all members. CPD records held in iMIS are replicated
one way, by row origin, rather than bidirectionally synced.

This plugin is at an early stage of development. See `docs/decisions.md` for the agreed design
decisions and the build plan.

## Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually

The plugin can also be installed by putting the contents of this directory in

    {your/moodle/dirroot}/local/cpdlog

On Moodle 5.1 and later, `{your/moodle/dirroot}` is the `public/` directory of the Moodle
checkout. Afterwards, log in to your Moodle site as an admin and go to _Site administration >
Notifications_ to complete the installation, or run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## Logging CPD

Members open _My CPD logbook_ from their profile page (or `/local/cpdlog/index.php`) and log
activities against courses they are or were enrolled in, or have completed. An activity's date
must fall in an open reporting period, and its hours are limited by the _Maximum hours per entry_
setting. Entries are saved as drafts and then submitted for review; a submitted entry cannot be
changed by the member. Entries imported from iMIS are shown read-only.

The logbook opens with the member's progress for the current reporting period: one bar per
target that applies to them, counting approved hours only, with hours still waiting for review
shown beside them. Earlier and later periods can be picked from the _Reporting period_ menu.

Members can attach up to 5 evidence files (PDF, Word or image) to an entry. Where a category is
marked _Evidence required_, an entry cannot be submitted until it has at least one file. Evidence
is private: only the member, staff with `local/cpdlog:viewall` and approvers can download it, and
files are always downloaded rather than opened in the browser.

## Approving CPD

Approvers review submitted entries under _Site administration > Plugins > Local plugins > CPD
logbook > Approval queue_ (`/local/cpdlog/admin/review.php`), oldest submission first. Each entry
can be approved, or rejected with a reason the member sees; a rejected entry goes back to the
member to edit and resubmit. Up to 50 entries on a page can be ticked and approved together.
Approvers cannot review their own entries, or entries in a closed period.

An approval made in error can be reversed from the queue's _Approved entries_ tab, with a reason.
Reversal is final: the entry stays in the member's logbook as reversed and no longer counts.

Members are notified when an entry is approved, rejected or reversed, and approvers when an entry
is submitted. Notifications are sent as a popup and by email by default; each person can change this
in their notification preferences.

## Staff reports

Two Report Builder sources are provided under _Site administration > Reports > Report builder >
Custom reports_:

- **CPD entries**: one row per entry, with the member, date, category, course, hours and status.
- **CPD progress**: one row per member per target that applies to them, with the required,
  approved and pending hours, whether the target is met and the percentage reached. Members are
  included when they logged CPD in the period or belong to a cohort with targets in it.

Both can be filtered by cohort, by any custom user profile field, and by period, category, status
or whether a target is met, and exported like any custom report. The plugin adds two starting
reports, _CPD entries_ and _CPD progress by member_, visible to the _CPD staff_ audience (users
with `local/cpdlog:viewall`); staff with Report Builder editing rights can copy or change them.

## Privacy and data deletion

CPD records are retained compliance records and are never deleted automatically. The privacy
provider exports a member's CPD data on request, but deletion requests, expired-context clean-up
and account deletion leave CPD records in place. Reject or hand-process CPD deletion requests
under _Site administration > Users > Privacy and policies > Data requests_; staff delete CPD
records manually.

## Setting up approvers

CPD entries are approved by a designated site-level role. After installing:

1. Go to _Site administration > Users > Permissions > Define roles_ and add a role (for example
   "CPD approver") with context type _System_.
2. Allow `local/cpdlog:approve`. Add `local/cpdlog:viewall` if approvers should also see every
   member's logbook, and `local/cpdlog:manageperiods` for staff who maintain categories, periods
   and targets.
3. Assign the role to the approving staff under _Site administration > Users > Permissions >
   Assign system roles_.

Members need no role assignment: logging and viewing their own CPD is granted to the
authenticated user role by default.

## Categories, periods and targets

Staff with `local/cpdlog:manageperiods` maintain the CPD set-up under _Site administration >
Plugins > Local plugins > CPD logbook_:

- **Categories** group CPD activities. The plugin starts with the three RACGP activity types
  (Educational activities, Reviewing performance, Measuring outcomes), which can be renamed,
  reordered, disabled or added to. Categories are disabled rather than deleted, so entries are
  never orphaned.
- **Reporting periods** are whole days in the site timezone and cannot overlap. Closing a period
  locks its entries and targets; it can be reopened. A period can be deleted only while nothing
  refers to it.
- **Targets** set the hours required in a period, for all members or one cohort. Tick one
  category for a per-category minimum, several for a combined minimum, or none for a total.
  Everyone must meet the all-members targets; a member's cohort adds its own targets on top.
- **Cohort conflicts** list members who are in more than one cohort with targets in a period.
  Staff choose which cohort's targets to add for each, or none. Until then only the all-members
  targets apply to them.

## Requirements

| Moodle | PHP |
|---|---|
| 5.0 | 8.2–8.4 |
| 5.1 | 8.2–8.4 |
| 5.2 | 8.3–8.4 |
| 5.3 LTS | 8.3–8.4 |

## License

Copyright © Skin Cancer College Australasia. All rights reserved.

This is a proprietary plugin developed by Skin Cancer College Australasia for use with Moodle.
It is NOT free software and is NOT released under the GNU General Public License. Unauthorised
copying, distribution, modification, or use of this plugin, in whole or in part, via any medium,
is strictly prohibited without the prior written permission of Skin Cancer College Australasia.
The software is provided "as is", without warranty of any kind, express or implied.
