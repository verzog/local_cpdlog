# CPD logbook (local_cpdlog)

A continuing professional development (CPD) logbook for professional members, inside Moodle.
Members log CPD hours by category against courses they are enrolled in, attach evidence, and
submit entries for staff approval. Members see their own progress against
configurable targets; staff approve entries and report across all members.

The logbook, evidence, approvals, progress, staff reports and data deletion are complete. One-way
replication of CPD records with iMIS is planned but not built yet. See `docs/decisions.md` for the
agreed design decisions and the build plan.

## Contents

- [Requirements](#requirements)
- [Installing](#installing)
- [Setting up the plugin](#setting-up-the-plugin)
- [Capabilities](#capabilities)
- [Using the logbook (members)](#using-the-logbook-members)
- [Approving CPD (approvers)](#approving-cpd-approvers)
- [Staff reports](#staff-reports)
- [Calendar and reminders](#calendar-and-reminders)
- [CPD from course completions](#cpd-from-course-completions)
- [CPD from the image blog](#cpd-from-the-image-blog)
- [Privacy and data deletion](#privacy-and-data-deletion)
- [Development and testing](#development-and-testing)
- [License](#license)

## Requirements

| Moodle | PHP |
|---|---|
| 5.0 | 8.2–8.4 |
| 5.1 | 8.2–8.4 |
| 5.2 | 8.3–8.4 |
| 5.3 LTS | 8.3–8.4 |

Moodle's cron must run regularly (every minute is recommended): approval notifications and
data deletions are carried out by background tasks.

## Installing

### Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

### Installing manually

The plugin can also be installed by putting the contents of this directory in

    {your/moodle/dirroot}/local/cpdlog

On Moodle 5.1 and later, `{your/moodle/dirroot}` is the `public/` directory of the Moodle
checkout. Afterwards, log in to your Moodle site as an admin and go to _Site administration >
Notifications_ to complete the installation, or run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

### Upgrading

Replace the plugin files with the new version (keeping the same folder), then visit _Site
administration > Notifications_ or run `php admin/cli/upgrade.php`. Merging code into the
repository does not change a live site until the files are deployed and the upgrade has run.

## Setting up the plugin

Everything below is under _Site administration > Plugins > Local plugins > CPD logbook_ unless
stated otherwise. Do these once after installing, in this order.

### 1. Check the settings

Open _Settings_:

- **Maximum hours per entry** (default 40): the most hours one entry can claim.
- **Create CPD entries from course completions** (default on) and **Default category for course
  CPD** (default Educational activities): see
  [CPD from course completions](#cpd-from-course-completions).
- **Send CPD reminders** (default on) and **Reminder days** (default 60,14): remind members who
  are behind on their targets before a reporting period closes. See
  [Calendar and reminders](#calendar-and-reminders).
- **Allow CPD data deletion** (default off): switches on the deletion tool described in
  [Privacy and data deletion](#privacy-and-data-deletion). Leave it off until it is needed.
- **Image blog** (only shown when the image blog plugin, `local_imageblog`, is installed): whether
  to copy its CPD hours into the logbook (default on) and which category to file them under
  (default Educational activities). See [CPD from the image blog](#cpd-from-the-image-blog).

### 2. Give staff their roles

Members need no role assignment: logging and viewing their own CPD is granted to the
authenticated user role by default. Site managers hold every staff capability except deletion.

For other staff, create a site-level role for each job:

1. Go to _Site administration > Users > Permissions > Define roles_ and add a role (for example
   "CPD approver") with context type _System_.
2. Allow the capabilities the job needs (see [Capabilities](#capabilities)). A typical set-up:
   - **CPD approver:** `local/cpdlog:approve`, plus `local/cpdlog:viewall` to see the staff reports.
   - **CPD administrator:** `local/cpdlog:manageperiods` and `local/cpdlog:viewall`.
   - **CPD data officer:** `local/cpdlog:deletedata`, given only to the few staff who handle
     deletion requests.
3. Assign the role to the staff under _Site administration > Users > Permissions > Assign system
   roles_.

### 3. Review the categories

Open _Categories_. Categories group CPD activities. The plugin starts with the three RACGP
activity types (Educational activities, Reviewing performance, Measuring outcomes), which can be
renamed, reordered, disabled or added to. Tick _Evidence required_ on a category to stop entries
in it being submitted without at least one evidence file. Categories are disabled rather than
deleted, so entries are never orphaned.

Tick _Allows external activities_ on a category to let members log activities in it that were
not Moodle courses, such as conferences or workshops. They name the activity and its provider
instead of choosing a course. External entries always need at least one evidence file before they
can be submitted, and approvers review them like any other entry.

### 4. Add reporting periods

Open _Reporting periods_ and add one for each CPD year or cycle, for example _2026_ from
01/01/2026 to 31/12/2026. Periods are whole days in the site timezone and cannot overlap. Members
can only log activities dated inside an open period.

Closing a period locks its entries and targets; it can be reopened. A period can be deleted only
while nothing refers to it. Each period's first and last days appear in the Moodle calendar
automatically (see [Calendar and reminders](#calendar-and-reminders)).

### 5. Set targets for each period

From _Reporting periods_, open a period's _Targets_. A target sets the hours required in that
period, for all members or for one cohort:

- Tick one category for a per-category minimum, several for a combined minimum, or none for a
  total across all categories.
- Everyone must meet the all-members targets; a member's cohort adds its own targets on top.

Cohorts are managed in Moodle under _Site administration > Users > Accounts > Cohorts_.

### 6. Resolve cohort conflicts

From _Reporting periods_, open a period's _Cohort conflicts_ (shown with the number still
unresolved). It lists members who are in more than one cohort with targets in that period. For
each, choose which cohort's targets to add, or none. Until a choice is made, only the all-members
targets apply to them. Check this page again whenever cohort membership changes.

### 7. Check notifications

Members are notified when an entry is approved, rejected or reversed, when a reporting period is
about to close and they are behind on their targets, and approvers when an entry is submitted.
They are sent as a popup and by email by default. Site defaults are under _Site administration >
General > Messaging > Notification settings_; each person can change their own in their
notification preferences.

### 8. Share the staff reports

The plugin adds two starting reports, _CPD entries_ and _CPD progress by member_, under _Site
administration > Reports > Report builder > Custom reports_. They are shown to the _CPD staff_
audience (users with `local/cpdlog:viewall`). See [Staff reports](#staff-reports).

## Capabilities

All capabilities are granted at site (system) level.

| Capability | What it allows | Given by default to |
|---|---|---|
| `local/cpdlog:viewown` | View own CPD logbook and progress | Authenticated user |
| `local/cpdlog:submit` | Log and submit own CPD entries | Authenticated user |
| `local/cpdlog:approve` | Approve, reject or reverse CPD entries | Manager |
| `local/cpdlog:viewall` | See all members' CPD in staff reports; download evidence | Manager |
| `local/cpdlog:manageperiods` | Manage categories, periods, targets and cohort conflicts | Manager |
| `local/cpdlog:sync` | Run or retry iMIS replication (for the planned iMIS link) | Manager |
| `local/cpdlog:deletedata` | Permanently delete a member's CPD data | Nobody |

## Using the logbook (members)

Members open _My CPD logbook_ from their profile page (or `/local/cpdlog/index.php`).

### Progress

The logbook opens with the member's progress for the current reporting period: one bar per target
that applies to them, counting approved hours only, with hours still waiting for review shown
beside them. Earlier and later periods can be picked from the _Reporting period_ menu.

### Logging an activity

1. Choose _Log CPD activity_.
2. Pick the course (one the member is or was enrolled in, or has completed), the category, the
   date and the hours, and describe the activity. The date must fall in an open reporting period,
   and the hours are limited by the _Maximum hours per entry_ setting.
   For an activity outside Moodle, choose _External activity (not a Moodle course)_ and give the
   activity name and provider. Only categories that allow external activities accept these.
3. Attach evidence if needed: up to 5 files (PDF, Word or image). Categories marked _Evidence
   required_ need at least one file before the entry can be submitted.
4. Save. The entry is kept as a draft that the member can change or delete.
5. Submit the entry for review. A submitted entry cannot be changed by the member.

Evidence is private: only the member, staff with `local/cpdlog:viewall` and approvers can
download it, and files are always downloaded rather than opened in the browser.

### What happens next

- **Approved:** the hours count towards the member's targets.
- **Rejected:** the entry goes back to the member with the approver's reason, to edit and
  resubmit.
- **Reversed:** an approval made in error was withdrawn, with a reason. The entry stays in the
  logbook as reversed and no longer counts.

Entries copied from the image blog are shown read-only, marked _From the image blog_. Entries
imported from iMIS will be shown the same way once the iMIS link is built.

## Approving CPD (approvers)

Approvers review submitted entries under _Approval queue_ (`/local/cpdlog/admin/review.php`),
oldest submission first. Each entry shows the member, activity, hours, description and evidence.

- **Approve** an entry, or **reject** it with a reason the member sees.
- **Approve several:** tick up to 50 entries on a page and approve them together.
- **Reverse** an approval made in error from the _Approved entries_ tab, with a reason. Reversal
  is final.

Approvers cannot review their own entries, or entries in a closed period. If two approvers act on
the same entry at once, only the first action counts; the second approver is asked to try again
and then sees that the entry has already been reviewed.

## Staff reports

Two Report Builder sources are provided under _Site administration > Reports > Report builder >
Custom reports_:

- **CPD entries**: one row per entry, with the member, date, category, course, hours and status.
- **CPD progress**: one row per member per target that applies to them, with the required,
  approved and pending hours, whether the target is met and the percentage reached. Members are
  included when they logged CPD in the period or belong to a cohort with targets in it.

Both can be filtered by cohort, by any custom user profile field, and by period, category, status
or whether a target is met, and exported like any custom report. Staff with Report Builder editing
rights can copy the starting reports or build new ones from these sources.

## CPD from course completions

Moodle courses can add CPD to members' logbooks automatically when they are completed:

1. In a course's settings, under _CPD logbook_, a manager sets **CPD hours** and, optionally,
   **CPD category**. These fields are locked, so only managers can change them. Courses with no
   category use _Settings > Default category for course CPD_.
2. When a member completes the course (by Moodle course completion), a CPD entry is created for
   those hours, dated on the completion date, and sent to the approval queue. Approvers are
   notified and check it like any other entry.
3. The hourly task _Create CPD entries for course completions_ also adds past completions and any
   that were missed, without notifying approvers, so turning this on does not flood the queue
   with notifications.

Each completion creates at most one entry, even if the member later deletes it. Completions dated
outside every reporting period wait until a period covers them. Completions made before staff
deleted a member's CPD data are not added back.

_CPD courses_ (under the CPD logbook settings, for staff with `local/cpdlog:viewall`) lists every
course that awards CPD, with its hours, category and how many entries it has created. Untick
_Settings > Create CPD entries from course completions_ to switch it off.

## Calendar and reminders

**Calendar.** Every reporting period puts two site events in the Moodle calendar: _CPD reporting
period … opens_ on its first day and _CPD reporting period … closes_ on its last day. They follow
the period: renaming it or changing its dates moves them, and deleting it removes them. Nobody can
edit or delete them in the calendar itself. They show to everyone who can view a CPD logbook.

**Reminders.** The daily task _Send CPD reminders before a reporting period closes_ reminds
members who have not yet met all their targets in an open period:

- **When:** the set number of days before the period's last day, from _Settings > Reminder days_
  (default 60 and 14 days). If reminders start late, only the latest one due is sent, not every
  one at once.
- **Who:** members as the staff reports count them, that is anyone who logged CPD in the period
  or belongs to a cohort with targets in it, and only those with a target not yet met.
  Suspended and deleted accounts are skipped. Each member gets each reminder at most once.
- **What:** a notification (popup and email unless the member changes their preferences) naming
  the period, its closing date, and each unmet target with the hours approved so far and any
  waiting for review, with a link to the logbook.
- **Switching off:** untick _Settings > Send CPD reminders_.

A log of the reminders sent is kept so none is sent twice. It is part of the member's data for
privacy requests and, unlike CPD entries, is removed when the member asks for their data to be
deleted or when staff delete their CPD data.

## CPD from the image blog

When the image blog plugin (`local_imageblog`) is installed, the CPD hours members earn on its
clinical cases are copied into their logbooks every 15 minutes by the scheduled task _Copy CPD
hours from the image blog_:

- **What is copied:** hours for submitting a diagnosis, the best-diagnosis bonus and reading a
  revealed outcome, one entry per case and reason.
- **How it appears:** as an approved entry, so it counts towards targets straight away. It has no
  course (the course column reads _Image blog_), its description names the case and the reason,
  and it is read-only.
- **Category:** set under _Settings > Category for image blog CPD_ (default Educational
  activities). Changing it affects entries copied from then on. Only enabled categories are used;
  if the chosen one is disabled, entries go to Educational activities.
- **Past awards:** the first run copies every award made before the logbook was connected.
- **Dates:** an entry is dated when the hours were awarded. An award dated outside every
  reporting period is copied once a period covering its date is added.
- **Changes:** the image blog stays in charge. If it changes an award's hours, the entry follows;
  if it withdraws an award, the entry is reversed and stops counting; if the award comes back, so
  does the entry, dated when it came back.
- **Switching off:** untick _Copy CPD hours from the image blog_. Entries already copied stay.

## Privacy and data deletion

CPD records are retained compliance records and are never deleted automatically. The privacy
provider exports a member's CPD data on request, but deletion requests, expired-context clean-up
and account deletion leave CPD records in place. Reject or hand-process CPD deletion requests
under _Site administration > Users > Privacy and policies > Data requests_, and delete a member's
CPD data with the deletion tool:

1. A site administrator switches on _Allow CPD data deletion_ in the CPD logbook settings, and
   gives the `local/cpdlog:deletedata` capability (held by no role by default) to the staff who
   handle deletion requests.
2. Under _Delete member CPD data_, find the member by exact username or email address.
   - If the member's Moodle account has already been deleted, tick _Look for a deleted account_
     and enter their original username, original email address or user ID. Moodle keeps a deleted
     account's CPD data, so it can still be removed here.
   - Only an identifier that matches exactly one account is accepted, so the wrong member cannot
     be picked. If several deleted accounts match, use the user ID.
3. Check what will be removed, then confirm by typing the member's username (or, for a deleted
   account, its user ID).
4. The deletion runs in the background within a few minutes. It removes all the member's entries,
   evidence files and cohort choices, and clears their name from records where they acted as staff.
   It cannot be undone; export the member's data first with the data privacy tool if a copy is
   needed.

Each deletion is listed on the same page and logged as a _Member CPD data deleted_ event. Switching
the setting off again stops new deletions and cancels any that are queued; switching it back on
does not restart them. Image blog hours the member had when the deletion ran are not copied back;
hours they earn afterwards are.

## Development and testing

The plugin follows the Moodle coding style and is checked on every push by GitHub Actions
(`.github/workflows/moodle-ci.yml`) with
[moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) across Moodle 5.0 to 5.3: PHP lint,
code style, PHPDoc, Mustache, PHPUnit and Behat.

To run the tests locally in a Moodle checkout with the plugin installed:

    $ php admin/tool/phpunit/cli/init.php
    $ vendor/bin/phpunit --testsuite local_cpdlog_testsuite
    $ php admin/tool/behat/cli/init.php
    $ vendor/bin/behat --config {behat_dataroot}/behatrun/behat/behat.yml --tags=@local_cpdlog

## License

Copyright © 2026 Vernon Spain.

This program is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
General Public License for more details.

You should have received a copy of the GNU General Public License along with this program (see
`COPYING.txt`). If not, see <https://www.gnu.org/licenses/>.
