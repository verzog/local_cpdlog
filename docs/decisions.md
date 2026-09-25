# Design decisions

Decisions agreed on 23/09/2026 when reviewing the scoping document ("CPD logbook plugin —
scoping", 24/09/2026). Where this file and the scoping document differ, this file wins.

## Decisions

1. **Targets are fully configurable.** The scoping document assumed per-category targets per
   triennium. The Medical Board standard since 2023 is annual, with a combined minimum across
   Reviewing Performance and Measuring Outcomes. Targets therefore support a minimum per
   category, a minimum across a group of categories, and an overall total, with periods of any
   length (annual or triennial).
2. **Approval is by a designated site-level role.** `local/cpdlog:approve` stays at system
   context; SCCA assigns it to a named approver role. Course-specific approvers are out of scope.
3. **Cohort target conflicts go to a staff conflict list.** When a member belongs to more than
   one cohort with targets for the same period, the conflict is listed for staff, who choose
   which cohort's targets apply to that member, or none. The choice is recorded. Until it is
   made, only the all-members targets apply.
   - **Cohort targets add on to the all-members targets** (agreed 24/09/2026). Everyone is
     measured against the all-members targets; a member's cohort adds its own targets rather
     than replacing them. A choice only counts while the conflict remains and the chosen cohort
     is still one of the member's. Otherwise it is ignored: the member reappears on the conflict
     list, or, once down to a single cohort with targets, gets that cohort's targets even if
     staff had chosen none.
4. **Reversal is a status.** A final `reversed` status is added. Reversing an approved entry
   keeps the original visible and fires `entry_reversed`; only staff can reverse.
5. **Rejected is a stored status.** Rejection sets `rejected` with a reason, so rejections can be
   reported. When the member edits a rejected entry it returns to `draft`.
6. **Past enrolments.** Course validity accepts current, suspended or expired enrolments via
   `is_enrolled()`, and falls back to course completion records for fully unenrolled members.
7. **Schema additions.** `evidencerequired` on categories; a course name snapshot on each entry
   so entries stay readable if the course is deleted; a unique index on (`source`,
   `externalref`) so an iMIS row cannot be imported twice.
8. **Later tables arrive by upgrade.** The reminder log, sync retry state and events cache are
   added in their own phases through `db/upgrade.php`. The upcoming-events feature is
   configurable (on/off, with its source isolated behind its own class) so it keeps working, or
   can be switched off, if SCCA moves away from iMIS.
9. **Date storage.** Period end dates are stored as the start of the following day (exclusive)
   and compared with `<`, so the last day is inclusive and DST in Australia/Sydney cannot shift
   a boundary. Periods may not overlap.
10. **Evidence files live in the system context** (filearea `evidence`, itemid = entry id), not
    the member's user context, so deleting an account does not silently delete compliance
    evidence. Retention is handled by the privacy provider.
11. **CI runs on pull requests and `main` only** (no schedule) while the repo is private.

12. **CPD records are never deleted automatically; deletion is manual** (agreed 24/09/2026). The
    privacy provider exports a member's CPD data but its delete functions deliberately do nothing,
    so approved deletion requests, expired-context clean-up and account deletion leave entries,
    evidence and approval history in place. Staff should reject or hand-process CPD deletion
    requests in the data privacy tool. Phase 5 adds a staff tool to delete a member's CPD records,
    behind its own capability (`local/cpdlog:deletedata`, RISK_DATALOSS, no default roles), with a
    typed confirmation, an audit event, a queued task and a settings kill-switch.
13. **Entries are logged against a course.** As the scoping document says, members log CPD
    against courses they are or were enrolled in, or have completed; only iMIS-origin rows have no
    course. The course name is snapshotted on the entry.

14. **Evidence files** are stored in the system context (file area `evidence`, item id = entry id,
    per decision 10): up to 5 PDF, Word or image files per entry, within the site's upload size
    limit. They are served only by `local_cpdlog_pluginfile()`, which loads the entry and allows its
    owner or holders of `local/cpdlog:viewall` or `local/cpdlog:approve` (approvers need the
    evidence to review), answers "not found" otherwise, and always forces a download. Evidence
    changes only while the member can edit the entry, and deleting a draft deletes its files.
15. **Approval rules** (agreed 25/09/2026). Approvers review submitted entries in an approval
    queue, oldest submission first.
    - Rejecting and reversing both require a reason, which the member sees in their logbook and
      in the notification.
    - Approvers can never review or reverse their own entries; another approver must.
    - Entries in a closed period cannot be reviewed or reversed until the period is reopened.
    - Approved entries are listed on the queue's second tab, newest approval first, where an
      approver can reverse one. Reversal is final (decision 4): the entry stays in the member's
      logbook as reversed, keeps its original approval on record, and cannot be edited or
      approved again.
    - Approvers can tick up to 50 entries on a page and approve them together, after a
      confirmation. Rejection is one entry at a time, because each needs its own reason.
    - Notifications (popup and email by default, adjustable in each person's notification
      preferences): members hear when an entry is approved, rejected or reversed, and every
      approver except the member hears when an entry is submitted.
    - If two approvers act on the same entry at once, only the first action counts.

## Schema notes

- `local_cpdlog_target` holds the required hours; `local_cpdlog_target_cat` links the categories
  that count towards it. One linked category is a per-category minimum, several make a combined
  minimum, and none makes an overall total (decision 1).
- `local_cpdlog_cohortchoice` records staff choices for cohort conflicts, one per member per
  period; a null `cohortid` means the all-members targets apply (decision 3).
- The scoping document's `approvedby` / `approvedtime` are `reviewedby` / `timereviewed`, because
  they record whoever last approved *or* rejected the entry. Reversal has its own
  `reversedby` / `timereversed` / `reversalreason` (decision 4).
- `externalref` is nullable; the unique (`source`, `externalref`) index allows many rows without a
  reference while blocking a duplicate iMIS row.
- The maximum hours per entry defaults to 40 as a placeholder until SCCA confirms a value.

## Admin pages (phase 1, part 3)

- Categories, periods and targets are edited through `core\persistent` classes, so validation
  (unique short names, non-overlapping periods, positive hours) is enforced for every caller, not
  only the forms.
- Staff pick a period's last day; it is stored as the start of the following day in the site
  timezone, using calendar arithmetic (`local_cpdlog\local\dates`). Tests cover the 23- and
  25-hour days at Sydney DST changes.
- Target categories are tick boxes rather than an autocomplete, so the form works without
  JavaScript.
- Seed data is the three RACGP categories only. No periods or targets are seeded until SCCA
  confirms its requirements.
- Cohort conflict resolution (decision 3) is in `local_cpdlog\local\target_resolver`,
  which also answers which targets apply to a member; reporting (phase 4) reuses it.

## Licensing note

The plugin is Mode A (proprietary, SCCA). If it is ever open-sourced it becomes Mode B: that
needs SCCA's written permission, GPLv3+ headers and `@license` tags on every file, and removal
of the licence overrides in `.phpcs.xml`.

## Build plan

| Phase | Delivers | Status |
|---|---|---|
| 1. Foundation | Skeleton, CI, schema, capabilities, settings, admin pages, cohort conflicts | Done |
| 2. Capture | Entry form, validation, draft and submit, privacy, evidence upload | Done |
| 3. Approval | Staff queue, approve / reject / reverse, events, message providers | Done |
| 4. Reporting | Member progress page; Report Builder source for staff | |
| 5. Privacy and hardening | Full privacy provider, PHPUnit and Behat coverage | |
| 6. iMIS push | On hold until the iMIS write path is proven in sccadev | |
| 7. iMIS pull | Read-only import and conflict detection | |
| 8. Calendar and events | Period dates in the calendar; configurable upcoming-events cache | |

## Still open

- Hour targets and category names SCCA actually uses (needed for seed data).
- Retention position on approved entries under the APPs (delete or anonymise).
- Whether existing CPD history in iMIS must be visible on day one (would bring phase 7 forward).
- Whether an iMIS event feed exists or an IQA must be built.
