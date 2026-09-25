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
 * Messages come from the no-reply user, are sent after the change is committed, and are written in
 * the recipient's language. Each person chooses how they receive them in their notification preferences.
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
        $approvers = get_users_by_capability(\context_system::instance(), 'local/cpdlog:approve', 'u.id, u.lang, u.suspended');
        foreach ($approvers as $approver) {
            if ((int) $approver->id === $memberid || $approver->suspended) {
                continue;
            }
            self::send('entrysubmitted', $approver, 'message:entrysubmitted', $entry, ['member' => $membername]);
        }
    }

    /**
     * Tells the member the outcome of a review: approved or rejected, with the reason.
     *
     * @param entry $entry The reviewed entry.
     */
    public static function entry_reviewed(entry $entry): void {
        $member = \core_user::get_user($entry->get('userid'), 'id, lang', MUST_EXIST);
        $extra = ['reason' => (string) $entry->get('rejectionreason')];
        self::send('entryoutcome', $member, 'message:entry' . $entry->get('status'), $entry, $extra);
    }

    /**
     * Sends one notification, written in the recipient's language.
     *
     * @param string $provider The message provider in db/messages.php: entryoutcome or entrysubmitted.
     * @param \stdClass $recipient The recipient, with id and lang.
     * @param string $identifier The string identifier prefix; ':subject' and ':body' are appended.
     * @param entry $entry The entry the message is about.
     * @param string[] $extra Further plain-text values for the strings, such as member or reason.
     */
    private static function send(string $provider, \stdClass $recipient, string $identifier, entry $entry, array $extra): void {
        $oldlang = force_current_language($recipient->lang ?? '');
        try {
            $dateformat = get_string('strftimedatefull', 'local_cpdlog');
            $values = [
                'date' => userdate($entry->get('activitydate'), $dateformat, \core_date::get_server_timezone(), false),
                'course' => format_string((string) $entry->get('coursename'), true, ['escape' => false]),
                'hours' => format_float($entry->get('hours'), 2),
            ] + $extra;
            // The subject is plain text; the body is HTML, so its values are escaped.
            $html = array_map(fn($value) => nl2br(s($value)), $values);
            $subject = get_string($identifier . ':subject', 'local_cpdlog', (object) $values);
            $body = get_string($identifier . ':body', 'local_cpdlog', (object) $html);
            if ($provider === 'entrysubmitted') {
                $url = new \moodle_url('/local/cpdlog/admin/review.php');
                $urlname = get_string('approvalqueue', 'local_cpdlog');
            } else {
                $url = new \moodle_url('/local/cpdlog/index.php');
                $urlname = get_string('mylogbook', 'local_cpdlog');
            }
        } finally {
            force_current_language($oldlang);
        }

        $message = new \core\message\message();
        $message->component = 'local_cpdlog';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = (int) $recipient->id;
        $message->subject = $subject;
        $message->fullmessage = html_to_text($body);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $body;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $urlname;
        message_send($message);
    }
}
