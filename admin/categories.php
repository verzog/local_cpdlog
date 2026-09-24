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
 * Lists CPD categories and handles enable, disable and reorder actions.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
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
