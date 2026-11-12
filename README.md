# GTA Laravel local test foundation v0.7.0

> **Martin / development AI: start with START_HERE_MARTIN.txt and MARTIN_AI_INTEGRATION_INSTRUCTIONS.md.** A printable GTA_Martin_AI_Integration_Guide.html is included. These instructions explain staged integration into your actual repository, private Cloud storage, environment guard changes, permission checks and release/rollback. Documentation addendum 1.0, 12 November 2026; application code remains v0.7.0 and the recorded system tests are unchanged. This is not a finished live release.

This is a new, separate Laravel apprentice-management application. It is not Martin's existing application, the complete v1.2.8 portal, or a production deployment. It reuses the original GTA logo and agreed vehicle artwork. The older complete HTML simulation remains the design and requirements reference for later modules.

## What works

See `DEVELOPMENT_STATUS.txt` and `BATCH_QUEUE.json` for module-by-module coverage. The source task, Maths/English, SMART action, attendance, Careers and support workflows are now connected to saved database records. Their unported subfeatures are explicitly listed; this is not the complete v1.2.8 portal.


- Sign in and sign out using locally generated fictional accounts.
- Administrators and CEO accounts can add, search, open and edit core apprentice details.
- Tutors can read only their currently assigned apprentices. Apprentice accounts can read only their linked record. Other roles are denied in this module.
- Unique learner references, date/email validation, record-version checks against stale edits and atomic audit events.
- Saved records persist in a database across sign-out and PHP restarts.
- Administrators and CEO can add, search, reopen, edit and deactivate employers, programmes, groups and staff records. Programmes use a code/version pair. Protected administrator/CEO accounts cannot be granted or edited through the staff directory.
- Apprentice profiles have explicit linked employer, programme, group, main tutor, mentor, careers adviser and subject-scoped Maths/English tutor selections. Existing free-text values remain intact until deliberately mapped; no name matching or guessed migration.
- Group membership does not automatically change the individual main tutor, programme or access permissions. Existing inactive assignments remain visible and are not silently dropped.
- Responsive Blade templates, original GTA assets and light/dark controls. Browser appearance has not been verified.

## Start a local copy on Windows

Requirements for the bundled dependency lock: PHP 8.4.1 or newer with the extensions required by Composer, including PDO SQLite, and Composer. The executed test runtime was PHP 8.4.27 and Laravel 13.42.0. PHP is not bundled in this ZIP. Installed PHP dependencies and composer.lock are included. No Node/Vite build is needed for this module.

1. Extract this ZIP to a new folder. Do not extract over Martin's application.
2. Open PowerShell in the extracted application folder, where `artisan` is located.
3. Run these commands one at a time:

```powershell
php -v
composer check-platform-reqs
Copy-Item .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan gta:demo
php artisan serve --host=127.0.0.1 --port=8000
```

`gta:demo` creates two fictional apprentices, ten fictional accounts and linked demonstration directories in an empty local database. It displays a newly generated test password. Keep that password locally. The administrator email is `admin@example.test`; the tutor email is `tutor@example.test`; the apprentice email is `apprentice@example.test`. Additional demo accounts are ceo@example.test, careers@example.test, maths@example.test, english@example.test, inclusion-lead@example.test, inclusion@example.test and mentor@example.test. They use the generated local test password. The mentor opens the assigned review-draft worklist; other specialist accounts open their current worklists. Review completion and signatures remain pending. The setup command refuses to overwrite existing records. It sends no messages.

Open `http://127.0.0.1:8000` on the same computer. Add a fictional apprentice, save, return to the list and reopen it. Edit it, save, sign out and sign back in. Stop the server using Ctrl+C, restart it and check the same record again.

Copy `.env.example` only for a fresh setup; do not overwrite an existing local `.env` or its APP_KEY after you start using it. Keep populated databases and `.env` files out of emailed code packages. If dependencies are absent or need restoring, run `composer install` using the included lock file.

## Additional connected workflows in v0.4.0

Source-linked dashboards and the visual journey now open actual saved records. Review preparation retains original fields and fixed source snapshots without claiming completion, whole-plan coverage or signatures. Staff development uses employee and assigned-manager projections; ordinary administrators do not automatically see other HR records. Case studies require independent exact-version approval before inspection use. Survey receipt/check tracking excludes questionnaire answers. Retention context and monthly observed snapshots are separate from achievement and ratings. Role-scoped help offers optional device read-aloud of generic guide text only. The approved ADAS task release and Block 9 guidance are preserved without replacing older saved task schemas.

## Workflow completion in v0.5.0

Careers discussion amendments retain the original adviser and previous terms. To do actions can be revised and advisers have an original-source worklist. Teaching instruction amendments retain the existing approved tutor copy until a separate approval replaces it; withdrawal removes the copy, with history retained. Staff development adds source-linked impact evaluation and reasoned rescheduling/cancellation. Qualification and support evidence files stay private and do not set results or OTJ. Delivered support records link the active approved plan and keep unknown duration separate from zero.

## Plan preparation and emergency authority in v0.6.0

Training-plan preparation reads the original profile, assigned topics, qualification records and curriculum references. Administration/CEO can save a four-field draft proposal and individually assess the retained 64-entry source KSB catalogue. RPL assessment approval, return and withdrawal bind to its assessed source version. Programme totals remain unknown until all source decisions are valid; internal allocation calculations never apply dates, price or individual targets. Private assessment references are excluded from tutor/apprentice projections. Formal plan application, signatures and approval remain pending source applicability and primary allocation.

CEO emergency access now requires a reason, current password and explicit confirmation for that login session. It enables otherwise restricted teaching-instruction actions and records use atomically. Ending it or signing out removes the override. It does not bypass authentication, inactive accounts, source checks or stale guards. No other role can enable or reuse it.

## Extra training and connected record subtotals in v0.7.0

Assigned tutors/administrators can record actual additional training with same-day times, exclusions, topic, provider, new occupational learning and evidence. Outside-hours training remains pending until own apprentice agreement (or a properly referenced recorded agreement), evidence of actual employer compensation covering the training minutes and an explicit tutor eligibility check. Promised future compensation is not credited. Overlap guards work in both directions between normal registers and approved extra training. Withdrawn records remain in history and leave confirmed totals. Optional evidence is private.

Attendance now separates normal-session and extra-training hours and combines their confirmed loaded-record subtotals. Extra training never creates an expected attendance session. Whole historical ledger coverage, an approved individual target and overall OTJ progress remain unconnected; no compliance result is inferred. Guides include plan preparation, extra training and explicit CEO emergency access.

## Repeat the tests

```powershell
php artisan test --compact
```

The ordinary test suite uses an in-memory SQLite database and does not use the local demonstration database. The supplied `tests/http_persistence.py` performs a separate disposable file-backed SQLite and actual loopback HTTP proof on the tested Linux environment. It stops and restarts PHP and compares the complete persisted row. It requires Python 3 and uses POSIX process groups; Windows execution of that helper is not verified. Manual save/reopen checks above apply on Windows.

```text
python3 tests/http_persistence.py --php php --output http-test-results.json
```

## Database and deployment boundaries

SQLite makes the first local setup straightforward. Migrations and the complete test suite were also executed using Laravel's mysql driver against an isolated MariaDB 11.8.6 instance. Oracle MySQL and Martin's specific hosting environment have not been tested. Do not point automated tests at a populated database: database-refresh tests are destructive to their chosen test database.

The application rejects web operation outside the local/testing environment. This is an intentional checkpoint guard, not production security certification. Changing APP_ENV is not a production release procedure. No real apprentice data should be entered at this stage.

Martin has confirmed Laravel 13, PHP 8.4.1+ and Laravel Cloud hosting (replacing IONOS). Before a shared/live release, agree one codebase with him and confirm the Cloud environment, actual database engine/version, storage, backup/restore, account provisioning, mail and release procedure. No Cloud configuration or deployment was performed. His source/database have not been changed or migrated. This new schema is not a drop-in replacement for his schema.

## Remaining work and local testing limits

Read `BATCH_QUEUE.json` and `DEVELOPMENT_STATUS.txt`; they distinguish implemented core functions from unported subfeatures and full modules. No original simulation feature should be assumed implemented merely because a similarly named page exists.

Use only fictional records. The setup supplies exactly two apprentice profiles. On the first profile, open Training blocks and tasks, explicitly confirm catalogue applicability and assign the Autocare source tasks. Dates remain unset until chosen. Use the apprentice account to save/submit work and the assigned tutor to record decisions. For Maths/English, choose each topic and its assessed planned hours; do not copy catalogue/example hours. Qualification results are separate. For support, raise a concern, classify it, then link the coded concern to a private plan draft. Approval is a separate exact-version step.

The Inclusion Lead account can approve teaching instructions. Their private notes never appear in the tutor instruction view. Careers records are private to the current assigned adviser, apprentice and administrators; employers and general tutors have no access. No live messages are sent.

A staff directory record still does not issue a production invitation. For a fictional local account only, run `php artisan gta:local-password staff@example.test` to generate a random test password. This command refuses non-local environments and does not grant a role or activate an account. Production invitations/recovery/MFA and session hardening remain pending.

Private task/topic uploads use the local private disk. Do not link this disk publicly. Laravel Cloud object storage/permissions, file scanning, quotas, backup/restore and operational security need review before production. The application still rejects web operation outside local/testing.

## Handover

The package includes the Laravel source, Blade templates, migrations, original approved assets, locked PHP dependencies and executable tests. `TEST_RESULTS.json` records the executed results. Every packaged file is listed in `SHA256SUMS.json`. Local credentials, populated databases, session data and logs are excluded.

## Changes from v0.1.0 and upgrade

Run `php artisan migrate` on a backed-up local v0.1.0 copy to add the directory tables and nullable links. Do not run `migrate:fresh` on a populated copy. The upgrade test verifies that the prior apprentice fields survive unchanged and new links remain unknown/null. The demo setup is only for a fresh empty local database. No migration from Martin's live schema is claimed.

This checkpoint activates the connected modules listed in DEVELOPMENT_STATUS.txt, with remaining subfeatures recorded in BATCH_QUEUE.json. The full v1.2.8 design simulation remains the reference for training, support, reviews, Careers and reports. No qualification achievement or training/compliance score is inferred from a directory assignment.
