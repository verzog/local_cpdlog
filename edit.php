<?php
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
 * Lets a member log a CPD entry, or edit their draft or rejected entry.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\form\entry_form;
use local_cpdlog\local\entry_manager;
use local_cpdlog\persistent\entry;

require(__DIR__ . '/../../config.php');

require_login(null, false);
$context = context_system::instance();
require_capability('local/cpdlog:submit', $context);

$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/edit.php', ['id' => $id]);
$returnurl = new moodle_url('/local/cpdlog/index.php');

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$heading = $id ? get_string('editentry', 'local_cpdlog') : get_string('addentry', 'local_cpdlog');
$PAGE->set_title($heading);
$PAGE->set_heading(get_string('mylogbook', 'local_cpdlog'));
$PAGE->navbar->add(get_string('mylogbook', 'local_cpdlog'), $returnurl);
$PAGE->navbar->add($heading);

// Only the member's own draft or rejected entry in an open period can be edited.
$entry = null;
if ($id) {
    $entry = new entry($id);
    if (!entry_manager::can_edit($entry, (int) $USER->id)) {
        throw new moodle_exception('error:entrylocked', 'local_cpdlog', $returnurl);
    }
}

$form = new entry_form($url, ['userid' => (int) $USER->id, 'entry' => $entry]);
$data = $entry ? file_prepare_standard_editor($entry->to_record(), 'description', entry_form::editor_options()) : new stdClass();
$data = file_prepare_standard_filemanager(
    $data,
    'evidence',
    entry_manager::evidence_options(),
    $context,
    'local_cpdlog',
    entry_manager::EVIDENCE_AREA,
    $entry ? $entry->get('id') : null
);
$form->set_data($data);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $data->description = $data->description_editor['text'];
    $data->descriptionformat = $data->description_editor['format'];
    $entry = entry_manager::save_draft((int) $USER->id, $data, $entry);
    entry_manager::save_evidence($entry, (int) $USER->id, (int) $data->evidence_filemanager);

    $message = get_string('entrysaved', 'local_cpdlog');
    if (!empty($data->saveandsubmit)) {
        entry_manager::submit($entry, (int) $USER->id);
        $message = get_string('entrysubmitted', 'local_cpdlog');
    }
    // Two activities on one day are possible, so a likely duplicate is a warning, not an error.
    if (entry_manager::count_duplicates($entry)) {
        redirect(
            $returnurl,
            $message . ' ' . get_string('duplicatewarning', 'local_cpdlog'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$form->display();
echo $OUTPUT->footer();
