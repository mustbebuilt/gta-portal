> Current checkpoint v0.33.0 — two linked batches.

v0.32.0 adds exact-preview, protected evidence copy plans; v0.33.0 adds explicit once-only staging execution with exclusive destination creation, source SHA-256/size validation, destination readback, final verification and technical audit. Original files, application references and retained snapshots are not changed. No storage cutover or production execution is enabled. Read MARTIN_BATCH_v0.33.0.md and TEST_RESULTS.json for current instructions/evidence. One additive migration; no new dependency. Earlier entries describe historical scope.

# GTA Laravel staging development checkpoint v0.31.0

Laravel Cloud is the confirmed target. Read CLOUD_START_HERE_MARTIN.md first for actual setup blockers, value-free environment reference and the read-only gta:cloud-check command. Controlled opt-in staging access and empty-application initial-admin setup now work; actual repository/Cloud acceptance and private storage integration remain outstanding. This pack has not been deployed.

> **Martin / development AI: start with START_HERE_MARTIN.txt and MARTIN_AI_INTEGRATION_INSTRUCTIONS.md.** A printable GTA_Martin_AI_Integration_Guide.html is included. These instructions explain staged integration into your actual repository, private Cloud storage, environment guard changes, permission checks and release/rollback. The integration guide retains its historical v0.7.0 documentation-addendum baseline. This source pack now contains v0.31.0 with a read-only retained-upload inventory and optional private file verification; current test evidence is in TEST_RESULTS.json. This is not a finished live release.

This is a new, separate Laravel apprentice-management application. It is not Martin's existing application, the complete v1.2.8 portal, or a production deployment. It reuses the original GTA logo and agreed vehicle artwork. The older complete HTML simulation remains the design and requirements reference for later modules.

## v0.31.0 — private evidence inventory

A read-only migration inventory now covers registered private uploads and retained file metadata/links across eight portal source families. Default mode reads database references/configuration only. Explicit verification checks each logical disk/path once against SHA-256 and declared size, flags problems and re-scans for drift. It does not move, relink, delete or approve migration of evidence. Read MARTIN_BATCH_v0.31.0.md. No new migration/dependency; actual source/target mapping is still required before any file move.

## Historical v0.30.0 — native database compatibility

Native database compatibility is now checked against MariaDB 11.8.6 through PDO MySQL as well as SQLite. A real fresh-install index-length blocker is corrected, with a data-preserving forward normalisation for older SQLite checkpoints. Native audit-failure, persistence and initial-administrator concurrency tests are included. Actual Cloud/Oracle MySQL/schema acceptance remains separate. Read MARTIN_BATCH_v0.30.0.md. Apply the new forward index-normalisation migration with earlier migrations; inspect any previously failed partial native installation before retrying.

## Historical v0.29.0 — private S3 storage

Cloud-compatible private S3 evidence storage now has a locked runtime adapter, bucket-level permission handling, file-only existence checks and an explicit synthetic storage probe. Original disk references and records are preserved. Actual Cloud bucket visibility, resource configuration and deployment still need Martin’s environment. Read MARTIN_BATCH_v0.29.0.md. No new schema migration; restore the new lock with composer install. `gta:evidence-storage` is configuration-only; `--probe` is an explicit nonproduction synthetic write/read/delete check.

## Historical v0.28.0 — controlled hosted staging startup

Controlled hosted staging access and once-only first-administrator setup are implemented and tested locally. Explicit staging flags, valid application key, HTTPS origin/requests, secure encrypted shared sessions and shared cache are required. Production remains blocked. Existing records/accounts are never overwritten; actual Cloud resources and repository integration remain unverified. Read MARTIN_BATCH_v0.28.0.md. Apply the additive bootstrap-state migration; both staging/setup flags default false.

## Historical v0.27.0 — in-portal review reminders

Review reminders now use checked actual meeting dates and sourced Active programme status. Current assigned tutor and mentor receive one in-portal prompt per source when due today or overdue. Seen does not complete a review or reset its date. Superseded sources and old assignments cannot be acknowledged. Existing evidence and history are retained. Apply the additive reminder/event migration and read MARTIN_BATCH_v0.27.0.md. Combined marking/Careers/review scheduling stays off by default.

## Historical v0.26.0 — checked external review copies

Externally signed progress-review records, independent manager checks and actual-meeting-based ten-week tracking are implemented/tested locally. Pending copies do not reset dates. The latest active checked meeting sets the next reference at meeting + 70 days. Electronic signing, employer portal access, complete historical compliance and live deployment remain separate. Read MARTIN_BATCH_v0.26.0.md and CHANGES_v0.26.0.md. Apply the additive signed-review-record/event migration.

## Historical v0.23.0-v0.25.0 - reviewed imports and management packs

Read MARTIN_BATCH_v0.25.0.md and the three current CHANGES files. Manager-only CSV previews, independent checked application, immutable historical OTJ with overlap reconciliation/withdrawal, and fixed group-scoped operational management packs are implemented. Apply the additive import/history/pack tables migration. No dependency or task source changed; all 531 PDFs retained. Import does not establish historical completeness, funding eligibility, topic completion or whole-programme achievement. Formal signing/source decisions and actual Cloud/engine/identity acceptance remain separate.

## Historical v0.19.0-v0.22.0 - next four batches

Read MARTIN_BATCH_v0.22.0.md and the four current CHANGES files. QA2 sources are integrated without rewriting saved work; all 331 older PDFs are retained. The OTJ ledger compares loaded records with the current approved target and closed-block hours. SMART proposals require current source binding and explicit agreement. Careers prompts use approved Block 10/11 dates/current advisers. Combined in-portal scheduling is opt-in/off by default, with no external messages. Apply the additive prompt/run tables migration. Formal signatures, complete history, curriculum decisions and live acceptance remain separate.

## Historical v0.15.0-v0.18.0 - four consolidated batches

Read MARTIN_BATCH_v0.18.0.md and the four CHANGES files. New allocations use 200 final tasks/PDFs across the four routes. Individual operational calendars, source references, independent provider approval, guarded unset-deadline application and plan-linked frozen review preparation are implemented. Apply the additive delivery-plan/review-state migration. Old task source snapshots and all 131 prior PDFs are retained. Provider approval is separate from participant signatures, formal KSB achievement and funding decisions. Current executed evidence is in TEST_RESULTS.json; the whole live portal is not finished.

## Historical v0.14.0 - Panel reflections

Read MARTIN_BATCH_v0.14.0.md and CHANGES_v0.14.0.md. All ten supplied Panel reflections are embedded. Existing untouched placeholders can be upgraded through an explicit administrator action; work, evidence and marking history are preserved. Scores follow the supplied source, including explained unassessed areas. No new migration/dependency. Current executed evidence is in TEST_RESULTS.json.

## Historical v0.13.0 - account access

Read MARTIN_BATCH_v0.13.0.md before integrating this batch. Apply its additive auth-version/audit migration and require a fresh sign-in for existing sessions. Change password works for a signed-in user; recovery remains off by default and is limited to a local/testing array mail sink. Invitations, verification onboarding, MFA and live delivery remain separate. Current evidence: 389 tests / 4,023 assertions and 63 HTTP checks.

## Historical v0.12.0 - marking worklist and in-portal reminders

Read MARTIN_BATCH_v0.12.0.md and CHANGES_v0.12.0.md. Tutors now have a scoped marking worklist; administrators can record deduplicated in-portal reminders for work waiting more than seven days. Seeing a reminder does not approve the task. Apply the new additive reminder-table/index migration. The command defaults to preview; --record persists in-portal reminders. Email and scheduling remain separate. Current evidence: 366 tests / 3,891 assertions and 57 HTTP checks.

## Historical v0.11.0 - Panel and private evidence

Read MARTIN_BATCH_v0.11.0.md and CHANGES_v0.11.0.md. Panel now has 40 available task records plus 10 explicit Source required reflection slots. Evidence modules use a shared configured disk service, with per-file disk metadata and legacy reads retained. Apply the additive task_evidence.disk migration in the isolated copy. Current evidence: 346 tests / 3,783 assertions on SQLite, 105 named-disk repeat cases and 52 actual HTTP checks. The S3 adapter, actual Cloud storage and remaining business/source work are not included as completed.

## Historical v0.10.0 task batches

Read MARTIN_BATCH_v0.10.0.md and CHANGES_v0.10.0.md. Heavy Vehicle and Paint each now have 50 source-specific tasks, with 60 additional unchanged PDFs. Current evidence: 327 tests / 3,414 assertions on SQLite and 52 actual HTTP checks. Other programmes cannot apply the Autocare planning/RPL source. Formal plans, signatures, Panel and live integration remain separate.

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

SQLite makes the first local setup straightforward. The current v0.13.0 suite ran on SQLite. The previous v0.9.0 suite also ran against isolated MariaDB 11.8.6; that evidence does not validate the new batch on MariaDB. Oracle MySQL and Martin's specific hosting environment have not been tested. Do not point automated tests at a populated database: database-refresh tests are destructive to their chosen test database.

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

## Support duties and scope in v0.8.0

Administrators/CEO open **Support permissions**, choose a staff member, duty and scope, and save a reason. The base account role stays unchanged. No Inclusion Team duty, member and lead are separate from other portal roles. Assigned scope uses current profile/group-tutor links plus explicitly selected apprentices/groups. All-apprentice scope is explicit; administrators retain operational oversight. Raising a concern alone does not grant access to private team records.

The stored duty overrides the legacy inclusion role. Without an explicit record, existing inclusion members/leads keep their previous all-apprentice access. Inactive accounts remain blocked even if permissions are saved. Exactly one active Inclusion Team lead is allowed through both the permissions editor and staff directory; remove the old duty before assigning its replacement. Saved changes invalidate older staff-directory edits. Audit creation and permissions save are one transaction.

Scope covers support worklists, private concerns, ASPs, activities/evidence, survey receipts/checks and instruction drafting/approval. Members can draft instructions; the lead can release the exact draft. Approved tutor instructions retain their own recipient workflow. Administrators can classify concerns and approve ASPs, but instruction drafting/approval still needs an explicit team duty or the authenticated CEO emergency workflow. Careers, employee HR and Functional Skills subject scope are not expanded.

Run the new migration with the existing migration sequence in a separate local/staging copy. The database adds support_capabilities and support_permission_events; do not manually edit these tables to bypass the checks. Review explicit access assignments before importing any real data. No production deployment or authentication replacement is authorised by this checkpoint.

Heavy Vehicle, Paint and Panel task content is pending the source documents and programme/version binding. The current task catalogue remains Autocare-only; no content or achievement rules for other programmes have been invented.

## v0.9.0 connected-workflow batch

Read CHANGES_v0.9.0.md, QUEUE_COMPLETION_REVIEW.md and DATA_REQUIREMENTS_AND_GAPS.md. All original queue areas have been reviewed; dependent source/live work is not marked complete.

Run `composer install` from the supplied lock file, then the existing migration sequence in a separate local/staging copy. Three additive migrations create marker-change events, assignment-link events and per-apprentice block controls. No existing real records are backfilled as historical achievement or lifetime data. The empty fictional demo now explicitly uses the supplied ST0499 v1.3 catalogue binding.

New Autocare allocation enables sequence controls. Existing task sets keep their previous access until an administrator checks and enables controls. Later blocks require all five source tasks approved in each previous block; exceptions need an administrator reason. This does not sign off a KSB or the training plan. Already-submitted work remains markable.

Weekly repeat planning copies the current roster/plan into separate future sessions without attendance or hours. Check closures/holidays individually. Subsequent actual-register changes require a correction reason and retain prior marks. Confirmed minutes replace previous values and still pass the original overlap/eligibility checks.

Native PDFs use locked dompdf/dompdf 3.1.6 and four supporting packages (no previous package version updates). PHP, JavaScript and remote-resource execution are disabled in the renderer; user-entered text is escaped. Fonts/temp files use writable Laravel framework storage. Verify storage/runtime in Cloud staging. Original task PDFs remain unchanged; recorded-work PDFs are separate static exports, not new signed AcroForms. Private ASP PDFs use the last approved snapshot, not draft replacements. Review PDFs remain private drafts with no signatures or review-cycle completion. Operational PDF uses the exact report scope. No export grants employer access to private Careers/support/drafts.

Reproducible non-browser checks: `python3 tests/static_checks.py --php php`; the PHP tests remain `php vendor/bin/phpunit`; `tests/http_persistence.py --php php --output ...` uses a disposable SQLite database and actual loopback requests. Existing source, role and unknown-data restrictions remain. Browser/mobile/keyboard/physical-print acceptance and Cloud deployment are not verified.
