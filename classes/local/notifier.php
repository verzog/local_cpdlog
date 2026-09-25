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
 * Sends CPD logbook notifications.
 *
 * @package    local_cpdlog
 * @copyright  © Skin Cancer College Australasia
 * @license    Proprietary — Skin Cancer College Australasia, all rights reserved
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;

/**
 * Sends CPD logbook notifications.
 *
 * Messages come from the no-reply user and are sent after the change is committed. Each person
 * chooses how they receive them in their notification preferences.
 */
final class notifier
{
    /**
     * Tells every active approver, except the member, that an entry was submitted for review.
     *
     * @param entry $entry The submitted entry.
     */
    public static function entry_submitted(entry $entry): void {
        $memberid = (int) $entry->get('userid');
        $membername = fullname(\core_user::get_user($memberid, '*', MUST_EXIST));
        $a = self::describe($entry);
        $a->member = s($membername);
        $url = new \moodle_url('/local/cpdlog/admin/review.php');

        $approvers = get_users_by_capability(\context_system::instance(), 'local/cpdlog:approve', 'u.id, u.suspended');
        foreach ($approvers as $approver) {
            if ((int) $approver->id === $memberid || $approver->suspended) {
                continue;
            }
            self::send(
                'entrysubmitted',
                (int) $approver->id,
                get_string('message:entrysubmitted:subject', 'local_cpdlog', $membername),
                get_string('message:entrysubmitted:body', 'local_cpdlog', $a),
                $url,
                get_string('approvalqueue', 'local_cpdlog')
            );
        }
    }

    /**
     * Tells the member the outcome of a review: approved or rejected, with the reason.
     *
     * @param entry $entry The reviewed entry.
     */
    public static function entry_reviewed(entry $entry): void {
        $status = $entry->get('status');
        $a = self::describe($entry);
        $a->reason = nl2br(s((string) $entry->get('rejectionreason')));
        self::send(
            'entryoutcome',
            (int) $entry->get('userid'),
            get_string('message:entry' . $status . ':subject', 'local_cpdlog', $a),
            get_string('message:entry' . $status . ':body', 'local_cpdlog', $a),
            new \moodle_url('/local/cpdlog/index.php'),
            get_string('mylogbook', 'local_cpdlog')
        );
    }

    /**
     * Returns the entry details used in messages, escaped for HTML.
     *
     * @param entry $entry The entry.
     * @return \stdClass date, course and hours.
     */
    private static function describe(entry $entry): \stdClass {
        $dateformat = get_string('strftimedatefull', 'local_cpdlog');
        return (object) [
            'date' => userdate($entry->get('activitydate'), $dateformat, \core_date::get_server_timezone(), false),
            'course' => format_string((string) $entry->get('coursename')),
            'hours' => format_float($entry->get('hours'), 2),
        ];
    }

    /**
     * Sends one notification.
     *
     * @param string $provider The message provider in db/messages.php.
     * @param int $userid The recipient.
     * @param string $subject The subject.
     * @param string $html The message body as HTML.
     * @param \moodle_url $url The page the message links to.
     * @param string $urlname The link text.
     */
    private static function send(
        string $provider,
        int $userid,
        string $subject,
        string $html,
        \moodle_url $url,
        string $urlname
    ): void {
        $message = new \core\message\message();
        $message->component = 'local_cpdlog';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $userid;
        $message->subject = $subject;
        $message->fullmessage = html_to_text($html);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $urlname;
        message_send($message);
    }
}
