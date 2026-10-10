// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Moodle App script for the CPD logbook screen, run with "this" as the screen.
 *
 * Reloads the logbook when an entry is saved on the entry form, and stops listening when the screen
 * closes.
 *
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

var that = this;
var observer = this.CoreEventsProvider.on('local_cpdlog_entry_saved', function() {
    that.refreshContent(false);
}, this.CoreSitesProvider.getCurrentSiteId());

/**
 * Stops listening for saved entries when the screen closes.
 */
this.ngOnDestroy = function() {
    observer.off();
};
