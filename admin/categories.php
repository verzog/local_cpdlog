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
 * Lists CPD categories and handles enable, disable and reorder actions.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_cpdlog\persistent\category;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Checks login and local/cpdlog:manageperiods at system context.
admin_externalpage_setup('local_cpdlog_categories');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/cpdlog/admin/categories.php');

if ($action !== '') {
    require_sesskey();
    $category = new category($id);
    switch ($action) {
        case 'enable':
        case 'disable':
            $category->set('enabled', $action === 'enable');
            $category->update();
            break;
        case 'moveup':
        case 'movedown':
            category::move($id, $action === 'moveup');
            break;
        default:
            throw new moodle_exception('invalidaction', 'error');
    }
    redirect($url);
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('shortname', 'local_cpdlog'),
    get_string('evidencerequired', 'local_cpdlog'),
    get_string('status'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable local-cpdlog-categories';

$categories = category::get_records([], 'sortorder');
$last = count($categories) - 1;
foreach (array_values($categories) as $index => $category) {
    $id = $category->get('id');
    $actionurl = fn(string $action) => new moodle_url($url, ['action' => $action, 'id' => $id, 'sesskey' => sesskey()]);

    $actions = [$OUTPUT->action_icon(
        new moodle_url('/local/cpdlog/admin/category.php', ['id' => $id]),
        new pix_icon('t/edit', get_string('edit'))
    )];
    if ($index > 0) {
        $actions[] = $OUTPUT->action_icon($actionurl('moveup'), new pix_icon('t/up', get_string('moveup')));
    }
    if ($index < $last) {
        $actions[] = $OUTPUT->action_icon($actionurl('movedown'), new pix_icon('t/down', get_string('movedown')));
    }
    if ($category->get('enabled')) {
        $actions[] = $OUTPUT->action_icon($actionurl('disable'), new pix_icon('t/hide', get_string('disable')));
    } else {
        $actions[] = $OUTPUT->action_icon($actionurl('enable'), new pix_icon('t/show', get_string('enable')));
    }

    $table->data[] = [
        format_string($category->get('name')),
        s($category->get('shortname')),
        $category->get('evidencerequired') ? get_string('yes') : get_string('no'),
        $category->get('enabled') ? get_string('enabled', 'local_cpdlog') : get_string('disabled', 'local_cpdlog'),
        implode(' ', $actions),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('categories', 'local_cpdlog'));
echo html_writer::tag('p', get_string('categories_desc', 'local_cpdlog'));
echo $OUTPUT->single_button(
    new moodle_url('/local/cpdlog/admin/category.php'),
    get_string('addcategory', 'local_cpdlog'),
    'get'
);
echo html_writer::table($table);
echo $OUTPUT->footer();
