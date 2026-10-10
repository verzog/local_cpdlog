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
 * Moodle App script for the CPD entry form, run with "this" as the screen.
 *
 * Uploads the evidence files when they changed, saves the entry through local_cpdlog_save_entry, and
 * returns to the logbook, which reloads. The server checks everything again.
 *
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

var that = this;
var data = this.CONTENT_OTHERDATA;
var strings = data.strings;
var originaldescription = data.entry.description;
var originalfiles = fileNames(data.files);

/**
 * Lists file names, to tell whether the evidence changed.
 *
 * @param {Array} files Files from the attachments component.
 * @return {string}
 */
function fileNames(files) {
    return files.map(function(file) {
        // Files already on the server have a URL; new ones picked on the device do not.
        return (file.fileurl ? '' : 'new:') + (file.filename || file.name);
    }).sort().join('/');
}

/**
 * Shows a loading message. Older app versions only have the DOM utilities.
 *
 * @param {string} text The message.
 * @return {Promise}
 */
function showLoading(text) {
    if (that.CoreLoadingsService) {
        return that.CoreLoadingsService.show(text);
    }
    return Promise.resolve(that.CoreDomUtilsProvider.showModalLoading(text));
}

/**
 * Shows an error.
 *
 * @param {*} error The error or message.
 * @return {Promise}
 */
function showError(error) {
    if (that.CoreAlertsService) {
        return that.CoreAlertsService.showError(error);
    }
    return that.CoreDomUtilsProvider.showErrorModal(error);
}

/**
 * Shows a short message.
 *
 * @param {string} message The message.
 */
function showToast(message) {
    if (that.CoreToastsService) {
        that.CoreToastsService.show({message: message});
    } else {
        that.CoreDomUtilsProvider.showToast(message);
    }
}

/**
 * Saves the entry as a draft, and submits it when asked.
 *
 * @param {boolean} submit Whether to submit it for review.
 * @return {Promise}
 */
this.saveEntry = async function(submit) {
    if (!that.CoreNetwork.isOnline()) {
        showError(strings.offline);
        return;
    }
    var entry = data.entry;
    var site = that.CoreSitesProvider.getCurrentSite();
    var loading = await showLoading(strings.saving);
    try {
        // -1 keeps the files already on the entry; 0 removes them all.
        var evidence = -1;
        if (fileNames(data.files) !== originalfiles) {
            evidence = data.files.length ?
                await that.CoreFileUploaderProvider.uploadOrReuploadFiles(data.files, 'local_cpdlog', entry.id || 0) : 0;
        }
        var params = {
            id: entry.id || 0,
            categoryid: entry.categoryid || 0,
            courseid: entry.courseid || 0,
            activityname: entry.activityname || '',
            provider: entry.provider || '',
            activitydate: entry.activitydate || '',
            hours: entry.hours || 0,
            evidence: evidence,
            submit: submit ? 1 : 0,
        };
        // A description left alone keeps its formatting from the website.
        if ((entry.description || '') !== (originaldescription || '')) {
            params.description = entry.description || '';
        }
        var result = await site.write('local_cpdlog_save_entry', params);
        that.CoreEventsProvider.trigger('local_cpdlog_entry_saved', {entryid: result.entryid}, site.getId());
        showToast(result.message);
        that.CoreNavigatorService.back();
    } catch (error) {
        showError(error);
    } finally {
        loading.dismiss();
    }
};
