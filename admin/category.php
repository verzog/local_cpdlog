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
 * Adds or edits a CPD category.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\form\category_form;
use local_cpdlog\persistent\category;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/category.php', ['id' => $id]);

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_categories', '', null, $url);

$returnurl = new moodle_url('/local/cpdlog/admin/categories.php');
$category = $id ? new category($id) : null;
$form = new category_form($url, ['persistent' => $category]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    if (!$category) {
        $category = new category();
        $data->sortorder = category::next_sortorder();
    }
    $category->from_record($data);
    $category->save();
    redirect($returnurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$heading = $category ? get_string('editcategory', 'local_cpdlog') : get_string('addcategory', 'local_cpdlog');
$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$form->display();
echo $OUTPUT->footer();
