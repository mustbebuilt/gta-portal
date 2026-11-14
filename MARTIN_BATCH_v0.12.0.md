# Martin / development AI - v0.12.0 marking batch

Read START_HERE_MARTIN.txt, CHANGES_v0.12.0.md and TEST_RESULTS.json first, then the retained integration guide and earlier addenda. This batch implements marking worklists and in-portal reminders. It does not send email or complete the remaining live integration.

## What to integrate

| Area | Files / interface |
| --- | --- |
| Worklist | MarkingQueueController; resources/views/marking/index.blade.php; /marking |
| Scope and source binding | App/Support/MarkingReminders |
| Stored reminders | App/Models/MarkingReminder |
| Schema | 2026_11_13_090000_create_marking_reminders.php |
| Entry points | Administrator POST /marking/reminders; recipient POST /marking/reminders/{id}/seen |
| Console | php artisan gta:marking-reminders (preview); append --record to persist in-portal records |
| Tests | tests/Feature/MarkingRemindersTest.php; expanded tests/http_persistence.py |

Adapt the two new tables and the learning_tasks status/submitted_at index incrementally against the real schema. Do not replay conflicting create-table migrations. Keep v0.11.0 private evidence, programme task snapshots and original PDFs intact. There are no dependency changes.

## Business behaviour

Only active current main tutors receive these task reminders. A group link, employer link, support duty or CEO emergency session does not grant extra queue access. Administration/CEO can record reminders across the current queue; ordinary tutors cannot generate them for colleagues or acknowledge another tutor's reminder.

Overdue remains more than seven calendar days after a valid submission date. A missing/future date is a check, not zero waiting time. Recording a reminder also requires a matching saved pending submission version, timestamp and template hash, plus an active current tutor. Missing source/owner cases remain visible for checking; no recipient is guessed.

Deduplication binds task, submitted source/version and current recipient. Changing a deadline does not create a new reminder. A new submission or a different current tutor can receive a distinct record. Previous records remain in restricted history but no longer grant access after reassignment. Acknowledgement is idempotent and never approves the task or removes it from outstanding work.

The administrator action and --record command are explicit. The command defaults to a read-only count preview, and its output contains counts rather than learner names or answers. No mail transport, queue job or automatic schedule is enabled. Do not present an in-portal reminder as a delivered email. Email cadence, recipients and production delivery must be implemented and tested separately.

## Validation and staging

366 tests / 3,891 assertions on SQLite; 57 actual HTTP checks; both reminder tables join the existing 26 tables in the restart proof. The overdue HTTP case uses a clearly dated fictional fixture in a disposable database. Actual HTTP then checks staff access, missing-CSRF rejection, single reminder creation, duplicate suppression and recipient acknowledgement without task approval.

Run php vendor/bin/phpunit, python3 tests/static_checks.py --php php and python3 tests/http_persistence.py --php php --output http-results.json in the isolated test copy. The HTTP helper is POSIX-tested, not Windows-certified. Repeat target-engine, visual/phone/keyboard and actual hosting checks in Martin's staging environment.

Review rollback with the new reminder/history records retained. Do not use destructive down migrations as a generic rollback after real records exist. Keep the local-only guard until the separate readiness/release process is complete. No production release or real-data import has been performed here.

Previous Cloud adapter/storage, plan/KSB/signature and Panel-source gaps remain in BATCH_QUEUE.json. The old HTML guide remains the unchanged v0.7.0 logic baseline; current addenda supersede its historical feature lists and counts.
