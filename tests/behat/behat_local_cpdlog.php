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
 * Behat steps for the CPD logbook.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Behat steps for the CPD logbook.
 */
class behat_local_cpdlog extends behat_base
{
    /**
     * Skips the scenario unless the optional image blog plugin (local_imageblog) is installed.
     *
     * @Given /^the image blog plugin is installed$/
     * @throws SkippedException
     */
    public function the_image_blog_plugin_is_installed(): void {
        if (!\local_cpdlog\local\imageblog_sync::is_installed()) {
            throw new SkippedException('local_imageblog is not installed.');
        }
    }
}
