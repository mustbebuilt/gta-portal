# GTA Laravel v0.12.0 - marking worklist and in-portal reminders

## Implemented

- Added Marking worklist navigation for active tutors, administrators and CEO accounts. Tutors see only current main-tutor assignments; management sees the whole awaiting-marking queue.
- Added filters for overdue work, all awaiting work, submission date checks and tutor assignment checks, plus scoped name/reference search and 25-row pagination. The queue opens the existing whole-task marking page; it does not expose answer bodies or evidence contents.
- Preserved the existing threshold of more than seven calendar days. Missing/future dates remain checks. Reminder creation also checks the current active main tutor and matching dated submission/template source.
- Added an administrator action to record in-portal reminders, and gta:marking-reminders (preview by default; --record to persist). Each current submission/recipient is recorded once. No scheduler or email sending was introduced.
- Added recipient-only Mark reminder seen. This does not change the task, its deadlines, assessment state or progress. Reassignment/deactivation, approval and a new submitted version invalidate access to stale reminders. Task deadline changes do not create duplicate reminders.
- Added two tables and a queue index in one additive migration. Administrator and recipient events record their real actor; command events use a null system actor rather than pretending to be a tutor. Audit failures roll back reminder creation/acknowledgement.
- Added role-scoped Help guidance and 20 new feature cases. Expanded the actual HTTP helper to test worklist access, CSRF protection, deduplication and acknowledgement, and persist both reminder tables across a PHP restart.

## Executed checks

366 tests / 3,891 assertions on SQLite; 57 actual local HTTP checks with 28-table restart persistence; 261 PHP/compiled Blade syntax checks; 1 JavaScript syntax check. All 121 source PDFs and both GTA artwork files remain unchanged. No new dependencies.

The dated HTTP reminder fixture is explicitly fictional and disposable. It is not a production backdating feature. No real users, messages or deployment were involved. Current browser/phone/keyboard/physical-print, MySQL/MariaDB and Cloud acceptance remain unverified. Previous v0.11.0 evidence is preserved separately.

## Still separate

Email delivery/retries/cadence, automatic scheduling, production account lifecycle and live recipient rollout. Formal plans/KSB sources, completed signatures, Panel's ten reflection sources and actual Cloud/private-file migration also remain outstanding.
