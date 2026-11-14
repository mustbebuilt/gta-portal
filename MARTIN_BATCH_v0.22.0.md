# Martin / development AI: v0.19.0-v0.22.0 integration

Read the four CHANGES files and TEST_RESULTS.json. This is a separate tested local checkpoint, not a live release. Preserve the original main integration guide and all earlier migration/account/private-evidence requirements.

## Migration and source installation

Apply additive migration 2026_11_13_140000_create_careers_prompt_tables.php. It creates careers_prompts, careers_prompt_events and portal_reminder_runs. No Composer dependency was added. Previous delivery-plan/review-state migration remains required, as do v0.13 authentication-version controls.

Copy QA2 active catalogue/task files, final-task-pack-receipt.json, retained final-task-pack-v1-receipt.json, task-pack-qa2-audit.json and all 200 new immutable qa2 PDFs. Keep every previous source PDF: total 531. Never mass-replace existing LearningTask.template, answers, submissions, evidence, dates or approvals. Normal new allocation uses QA2; the separate untouched Panel placeholder upgrade remains guarded. The old v1 importer is historical; tools/import_audited_tasks.py is the current QA2 reproduction tool. Do not install an older controller overlay.

Paint SOW document version/cohort applicability remains a curriculum decision, distinct from standard ST0448 v1.2. Autocare Foundation, primary mapping, S9 and other recorded source conflicts remain unresolved. Relevant warnings are now visible on allocation/delivery-plan screens. They are not silently cleared by task availability, tests or provider operational approval.

## OTJ ledger

/apprentices/{id}/otj-ledger and its report.pdf use training access scope. They show ended registers, occurred extra training, exclusions and pending checks, cumulative loaded minutes and the current approved target. Planned hours due are closed blocks only: end date before today. No daily spread, complete history, achievement, funding eligibility or automatic RAG is inferred. Read original source corrections; never add the combined subtotal as another training entry. Review draft saves capture the current summary and leave earlier snapshots unchanged. Validate historic imports/completeness separately.

## Action proposals

/apprentices/{id}/actions/proposals is for current assigned tutors and managers. GET /actions/create?source_task_id=... prepares a referred task proposal with an agreed source deadline. It writes nothing. The posted source_task_id/source_binding must match the freshly locked source and the linked task. Explicit support, realistic workload and agreement remain required. Duplicate task actions are rejected; existing ones are linked. Referred status, source deadline, marking and evidence remain on the original task. A completed/changed source requires reopening the current proposal. Do not infer agreement from the prefilled text.

## Careers prompts and scheduling

Approved Block 10/11 start dates drive the new due checks, independently of the older sequence-release check. Current active adviser assignment and plan hash are part of each source key. Admin recording is explicit; recipient acknowledgement checks assignment/active role/current plan again. No discussion, delivered advice or SMART target is created by recording/seeing a prompt. Private Careers is still hidden from ordinary tutors/employers.

Run `php artisan gta:portal-reminders` for preview. Use `--record` only when intentional: it atomically records deduplicated marking/Careers prompts and a combined run audit. Repeated runs retain existing prompt keys and add run history; they do not resend external messages. The existing marking-only command remains supported.

GTA_SCHEDULE_PORTAL_REMINDERS=false is the default. An enabled flag permits the daily 09:00 application-timezone scheduled event, with overlap protection; it does not provision or prove an actual scheduler. Review Laravel Cloud scheduler configuration, shared cache/mutex behaviour and real run history in staging before opting in. The management marking page shows the configured flag and last combined run. No actual scheduler has been activated, and no email/SMS transport or live identity/MFA has been configured by this batch.

## Verification and boundaries

Run the full PHPUnit suite, tests/http_persistence.py and tests/static_checks.py. The HTTP proof uses a disposable fictional database/storage root and compares all 33 connected module tables after restart. It exercises the OTJ PDF, explicit proposal creation, calendar prompts, adviser acknowledgement and command preview/record. Source PDF checks and generated PDF sample evidence are included.

Current validation is PHP 8.4.27/Laravel 13.42.0 on SQLite and loopback HTTP. Browser/mobile/keyboard/all-viewer printing, MySQL/MariaDB, Cloud, scheduler/cache infrastructure, real historic completeness and release/rollback need acceptance. Formal participant signatures, review completion/scheduling, KSB/source decisions, gateway criteria, external email/accounts and production remain unfinished. Provider operational approval, prompt Seen and Ready for signatures must not be presented as full completion.
