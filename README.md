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
