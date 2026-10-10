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
 * Sends CPD logbook notifications.
 *
 * @package    local_cpdlog
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cpdlog\local;

use local_cpdlog\persistent\entry;
use local_cpdlog\persistent\period;

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
     * Tells the member the outcome of a review: approved, rejected or reversed, with the reason.
     *
     * @param entry $entry The reviewed entry.
     */
    public static function entry_reviewed(entry $entry): void {
        $member = \core_user::get_user($entry->get('userid'), 'id, lang', MUST_EXIST);
        $reasonfield = $entry->get('status') === entry::STATUS_REVERSED ? 'reversalreason' : 'rejectionreason';
        $extra = ['reason' => (string) $entry->get($reasonfield)];
        self::send('entryoutcome', $member, 'message:entry' . $entry->get('status'), $entry, $extra);
    }

    /**
     * Reminds a member, before a reporting period closes, of the targets they have not yet met.
     *
     * @param \stdClass $member The member, with id, lang and firstname.
     * @param period $period The period.
     * @param \stdClass[] $unmet Their unmet targets, as returned by progress::get_progress().
     * @return bool Whether the message was sent.
     */
    public static function period_reminder(\stdClass $member, period $period, array $unmet): bool {
        $oldlang = force_current_language($member->lang ?? '');
        try {
            $values = (object) [
                'period' => format_string($period->get('name'), true, ['escape' => false]),
                'date' => userdate(
                    $period->get_lastday(),
                    get_string('strftimedatefull', 'local_cpdlog'),
                    \core_date::get_server_timezone(),
                    false
                ),
            ];
            $items = '';
            foreach ($unmet as $row) {
                $a = (object) [
                    'name' => s(format_string($row->target->get('name'), true, ['escape' => false])),
                    'approved' => format_float($row->approved, 2),
                    'required' => format_float($row->required, 2),
                    'pending' => format_float($row->pending, 2),
                ];
                $identifier = $row->pending > 0 ? 'message:reminder:targetpending' : 'message:reminder:target';
                $line = get_string($identifier, 'local_cpdlog', $a);
                $items .= \html_writer::tag('li', $line);
            }
            $subject = get_string('message:reminder:subject', 'local_cpdlog', $values);
            $body = get_string('message:reminder:body', 'local_cpdlog', (object) [
                'period' => s($values->period),
                'date' => s($values->date),
                'targets' => \html_writer::tag('ul', $items),
            ]);
            $urlname = get_string('mylogbook', 'local_cpdlog');
        } finally {
            force_current_language($oldlang);
        }

        $url = new \moodle_url('/local/cpdlog/index.php', ['periodid' => $period->get('id')]);
        $message = new \core\message\message();
        $message->component = 'local_cpdlog';
        $message->name = 'periodreminder';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = (int) $member->id;
        $message->subject = $subject;
        $message->fullmessage = html_to_text($body);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $body;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $urlname;
        return message_send($message) !== false;
    }

    /**
     * Tells every active approver which courses have completions waiting to be released.
     *
     * @param \stdClass[] $courses The courses, with id and fullname.
     * @param int[] $waiting How many completions wait, keyed by course id.
     * @return int How many approvers were told.
     */
    public static function completions_waiting(array $courses, array $waiting): int {
        $approvers = get_users_by_capability(
            \context_system::instance(),
            'local/cpdlog:approve',
            'u.id, u.lang, u.suspended, u.deleted'
        );
        $url = new \moodle_url('/local/cpdlog/admin/courses.php');
        $told = 0;
        foreach ($approvers as $approver) {
            if ($approver->suspended || $approver->deleted) {
                continue;
            }
            $oldlang = force_current_language($approver->lang ?? '');
            try {
                $items = '';
                foreach ($courses as $course) {
                    $items .= \html_writer::tag('li', get_string('message:completionswaiting:course', 'local_cpdlog', (object) [
                        'course' => s(format_string($course->fullname, true, ['escape' => false])),
                        'count' => $waiting[$course->id] ?? 0,
                    ]));
                }
                $subject = get_string('message:completionswaiting:subject', 'local_cpdlog', array_sum($waiting));
                $body = get_string('message:completionswaiting:body', 'local_cpdlog', \html_writer::tag('ul', $items));
                $urlname = get_string('cpdcourses', 'local_cpdlog');
            } finally {
                force_current_language($oldlang);
            }
            $message = new \core\message\message();
            $message->component = 'local_cpdlog';
            $message->name = 'completionswaiting';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = (int) $approver->id;
            $message->subject = $subject;
            $message->fullmessage = html_to_text($body);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $body;
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $urlname;
            if (message_send($message) !== false) {
                $told++;
            }
        }
        return $told;
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
                'course' => display::course($entry, false),
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
