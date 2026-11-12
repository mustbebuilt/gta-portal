# GTA portal: instructions for Martin and his development AI

Documentation revision 1.0 · 12 November 2026 · Application checkpoint v0.7.0

## Martin: start here

Give your AI this file and the complete source ZIP, inside a separate working branch or test checkout of your actual portal repository. Tell it to begin with the copy-and-paste instruction below. It needs access to your actual code and an authorised test environment to integrate anything; uploading pictures or the ZIP to hosting alone will not do this.

The ZIP is a separate Laravel application with working, tested core features. It is not a plug-in, a database dump, a finished version of every feature in the earlier HTML simulation, or a drop-in replacement for your current portal. Keep the existing portal available while the integration is assessed. Do not enter real apprentice or staff information into the supplied demonstration.

The pack already contained local installation instructions in README.md. This addendum supplies the missing AI integration brief and a staged route towards a live release. It adds documentation only: no application code, original PDFs or dependencies have changed.

## Copy and paste this instruction into your AI

> You are integrating the GTA Laravel portal checkpoint into Martin's actual Laravel portal. Read MARTIN_AI_INTEGRATION_INSTRUCTIONS.md in full, then README.md, DEVELOPMENT_STATUS.txt, BATCH_QUEUE.json and TEST_RESULTS.json. Inspect my actual repository and its existing instructions, authentication, routes, database migrations, storage and hosting configuration before changing code. Reuse the developer/schema references already supplied. Work in an isolated branch and test database. Preserve the working functionality, appearance, role restrictions, original GTA artwork and task PDFs. Do not replace my application or database wholesale. First produce a concise integration map and identify collisions; then implement the compatible changes in tested stages. Resolve routine technical decisions yourself, and log missing business decisions without inventing values or compliance claims. Do not send messages, import real records, expose private uploads or deploy to production without Martin's explicit release approval. At completion, provide the reviewed diff, migration/data-mapping plan, executed test evidence, screenshots of staging, remaining gaps and a precise release/rollback runbook. A successful demo installation is not proof that live integration is complete.

## 1. Establish the actual starting point

1. Identify the deployed repository, branch and commit, installed PHP/Laravel versions and the actual database engine/version. Confirm the requested target: Laravel 13, PHP 8.4.1 or newer, Laravel Cloud. The bundled lock was tested with Laravel 13.42.0 and PHP 8.4.27; do not assume a different installation has those exact versions.
2. Read the repository's AGENTS.md and relevant local rules. Inventory existing models, policies, routes, UI components, migrations, background jobs and storage. Reuse the developer data/schema guide already provided; reconcile it against current migrations/schema. Do not ask Jon to supply the schema again simply because it is inconvenient to locate.
3. Validate the ZIP against SHA256SUMS.json before editing. Read BATCH_QUEUE.json as the boundary of what is and is not implemented. Identify genuine conflicts and business gaps separately.
4. Produce a mapping with these columns: pack feature; existing component/table; proposed change; identifier/relationship mapping; access rule; migration needed; tests; status. Record routes/table names already in use.
5. Choose one codebase with Martin. Prefer adapting compatible modules into his existing application. If a clean replacement is materially simpler, present the concrete consequences and obtain his decision before proceeding. Never make two competing live systems that write to incompatible databases.

## 2. Prove the pack locally first

Follow README.md in a new folder with a fresh, isolated local database. Use the included Composer lock and verify platform extensions. Restore dependencies with composer install if required; do not use composer update just to get past an error.

The source includes public/assets/portal.css and portal.js. This standalone checkpoint needs no Node/Vite build. If Martin's application already uses Vite or another asset pipeline, preserve and integrate that pipeline rather than deleting it.

Run the local setup and test commands in README.md. The gta:demo command supplies two fictional apprentice profiles and ten fictional accounts, and prints a newly generated local password. Never run that command against a live database. The local password command is not a production onboarding process.

Check adding, saving, reopening and editing an apprentice. Sign out/in and restart PHP to confirm persistence. Then exercise each linked module with the appropriate fictional account. Report the exact failure and request/route if anything fails; do not hide missing functions with dummy counters or successful-looking buttons.

## 3. Integrate the actual features, not the render images

Use the executable Blade, CSS, JavaScript, controllers, policies and source data as implementation material. Illustrative pictures are design references, not functioning pages. Copying a screenshot into a page will not implement its controls or reports.

| Area | Concrete source to inspect | Preserve when adapting |
| --- | --- | --- |
| Profiles and directories | ApprenticeController, DirectoryController, form requests, policies and linked models | Unique references, explicit staff/employer/programme/group links, validation, stale saves and audit |
| Appearance and navigation | resources/views/layout.blade.php; public/assets/portal.css and portal.js | Clear hierarchy, original GTA branding, light/dark control, responsive controls and role-specific menus |
| Dashboard and journey | DashboardController; resources/views/dashboard; JourneyEvents | Source-linked counts and dated events; roadmap illustration in the journey template; no invented overall achievement |
| Tasks and evidence | LearningTaskController; TaskCatalogue; resources/data; resources/task-pdfs | Whole-task submission/marking, locked versions, referrals, private files, paper knowledge results and source hashes |
| Maths and English | EnglishMathsController; topics and qualifications | Only assigned topics/hours; separate evidence acceptance, topic completion and qualification result; custom start/twelve-month target |
| Action Plan and Careers | SmartActionController; CareersController | SMART fields, owner/due/status, source links; private Careers and current adviser attribution |
| Registers and extra training | AttendanceController; AttendanceMath; ExtraTrainingController | Original codes/exclusions, actual delivered time, confirmation gates, overlaps and withdrawals |
| Support | SupportController; support plan/concern/instruction models | IM1–40 descriptions, multiple markers, private cases, active versus draft plan, released instructions and history |
| Reviews and training plans | ProgressReviewController; TrainingPlanController; TrainingPlanSource | Draft boundaries, source snapshots, actual fields, private assessment scope and unapplied proposals |
| Staff development | StaffDevelopmentController | Employee versus line-manager/CEO projection, private notes/files, actual review links and impact assessment |
| Reporting and case studies | RetentionReportController; CaseStudyController; MonthlyScorecardController | Completion versus achievement, unknown history, independent case approval and contextual monthly observations |
| Survey follow-up and help | SurveyReceiptController; HelpController | Receipt versus Inclusion check; no questionnaire answers/safeguarding details; optional generic-guide voice only |
| CEO emergency authority | ExecutiveAccessController; ExecutiveAccess | Explicit reason/current password, session binding, audit and end/logout; no validation bypass |

Use route:list and inspect the actual named routes rather than inventing endpoint names. Adapt existing components where possible and avoid duplicate forms/tabs. Replace demo labels deliberately, not with a global find-and-replace that changes evidence or history.

Preserve public/assets/gta-logo.jpg and vehicle-hero.png. The road illustration is implemented in resources/views/dashboard/journey.blade.php and its CSS; it is not dependent on a missing external roadmap photo. Keep the 41 original task PDFs unchanged and keep the separate portal-template/PDF hashes. The approved ADAS portal questions do not appear in the unchanged original PDFs.

## 4. Map the schema and existing records safely

Create explicit field, enum and relationship mappings against Martin's real schema. Matching table names do not prove equivalent meaning. Map source IDs to destination IDs explicitly; never assume numeric IDs or staff names refer to the same person in both systems.

Use new, reviewed, incremental migrations. Do not replay create-table migrations over conflicting existing tables. Preserve identifiers, saved source versions, approved snapshots, submission history, audit, file references, dates and unknown/null values. Group assignment must not silently replace a main tutor or widen access.

For existing records, write a repeatable import with a dry-run report, transaction boundaries, duplicate handling, validation and source-to-target reconciliation. Prove it first with fictional fixtures. Later real-data migration must use an authorised controlled environment, with minimal information, access logs and the agreed privacy/retention process. Do not put live records, credentials or HR documents into an AI prompt or code ZIP.

Before any real migration, take a database and private-file backup and prove restoration. Do not use migrate:fresh, db:wipe, broad deletes or test database-refresh routines against populated/live data. Do not regenerate an existing application's APP_KEY. Never reset real passwords to the demo password.

## 5. Preserve server-side permissions

Menus are navigation, not security. Enforce permissions on GET, POST, downloads, exports, jobs and source links. Recheck current assignments and versions when saving. Keep these distinctions:

- Administration/CEO have operational oversight; ordinary administrators do not automatically get every employee's private HR record. Assigned managers and CEO have the implemented HR authority.
- Tutors have their current linked apprentices. Maths/English tutors have their allocated subject scope, not general access to every apprentice profile.
- Apprentices see their own permitted records and cannot approve their work or confirmed qualification results.
- Careers belong to the apprentice, current adviser and authorised administrators. Employers and general tutors are excluded.
- Private inclusion/support records are separate from approved tutor instructions. Being a tutor does not automatically give private case access. Safeguarding case details stay outside this application.
- Mentor access currently covers assigned review drafts. It is not a grant to all apprentice information.
- Employer accounts are not linked to employer organisations in this checkpoint. The case-study author route does not authorise employer access to apprentice profiles, support, Careers or reviews.
- The existing code contains base role values and scoped access. Full configurable multi-area support duties remain pending; do not represent them as already implemented.
- CEO emergency instruction authority is explicit, session-bound and audited. It does not bypass authentication, inactive accounts, source validation, stale checks or independent case-study approval.

Test direct URLs and forged POSTs, not only whether a menu item is hidden. Reassignment/deactivation must remove the former user's relevant access without deleting history.

## 6. Deal with the two concrete deployment blockers

### A. Local-only middleware

app/Http/Middleware/LocalTestOnly.php currently returns 503 outside local/testing and adds security/cache headers. This is intentional. The supplied ZIP cannot simply be made live by changing APP_ENV.

After the required readiness work, Martin's AI must propose and test the scoped replacement/removal of that environment restriction for staging/production. Preserve appropriate no-store/private caching and security headers elsewhere. Keep genuine route authorisation and deny incomplete/unapproved functions. Do not set a public environment to local/testing as a workaround. Martin must review the diff before the guard is changed in a shared deployment.

### B. Explicit private local storage

The current code explicitly stores and downloads from the private local disk. Simply setting FILESYSTEM_DISK does not redirect those explicit calls.

Inspect EnglishMathsController, LearningTaskController, StaffDevelopmentController, SupportController, ExtraTrainingController and Support/PrivateEvidence. Introduce a consistent, configurable private evidence disk/service and update upload, read, existence, download, deletion and failed-transaction cleanup paths together. Tests must fake the configured disk. Check every caller; do not fix only uploads and leave downloads on local.

On Laravel Cloud, configure a private evidence bucket and check its actual attached disk configuration. Cloud buckets have bucket-wide visibility: private evidence and public artwork must not share a public bucket. Keep authorised download routes. Do not use a public storage link for evidence or substitute permanent public URLs. See the official storage reference below.

For any existing files, copy bytes, verify hashes, map keys and test restricted downloads before switching reads. Preserve originals until reconciliation/rollback is proven. Confirm persistence across an actual redeployment and, if used, multiple application instances. Configure scan/quarantine, type/size controls, retention, deletion and database/file backup as part of the release work.

## 7. Prepare a private Cloud staging environment

Use a separate branch, database, storage and credentials from production. Martin must confirm the actual database engine/version, domain, regions, secret handling, backups and authorised account-provisioning route. Keep secrets in the approved environment configuration, never source control or this handover.

Verify PHP extensions and use the reviewed lock file. Keep APP_DEBUG=false, the correct HTTPS APP_URL and secure cookies in a shared environment. Use the correct environment name; address the local-only middleware through the reviewed code change above. Set a new key only for a genuinely new application/environment, preserving existing keys where encrypted data already depends on them.

Configure durable sessions/cache and account lifecycle controls appropriate to the deployment. Ordinary staff creation currently sends no invitation. Add/reconcile secure invitations, recovery, privileged account provisioning, account deactivation and any required MFA/session controls; test them before real users join. A settings field or a queue worker is not itself an implemented reminder system.

The pack uses a no-send mail setting for local tests and has not implemented production reminder delivery. If messages are included in the chosen release, implement tested recipient scope, timing, deduplication, retry/audit and jobs first. Use a safe test destination/sink in staging. Do not contact real tutors, employers or apprentices during integration tests. Enable only the jobs and schedules actually implemented and reviewed.

Review build/deploy commands against the actual repository and current Cloud configuration. A typical Composer production install uses the lock, with dev dependencies excluded; tests run in a separate environment with dev dependencies. Run optimisation/migrations in the appropriate reviewed stages. A migrate --force command is appropriate only after the specific migrations, backup and release have been approved. Do not blindly paste a generic deployment script over Cloud's configured workflow.

The /up endpoint is a boot-health indicator, not proof that migrations, authorisation, private downloads or business workflows work. Exercise those separately. Follow the official documentation listed below; production setup has not been performed by this handover.

## 8. Run and record acceptance checks

Baseline evidence in TEST_RESULTS.json is dated 11 November 2026: 259 tests and 2,318 assertions on each of SQLite and MariaDB 11.8.6 through Laravel's mysql driver, plus 45 actual HTTP checks. These are the same suite on two engines, not 518 distinct scenarios. Oracle MySQL and Laravel Cloud were not verified. Do not relabel that baseline as a test of your integrated branch.

Rerun relevant tests after each adaptation and the complete suite before release. Use a disposable database on Martin's actual target engine. Run the suite with dev dependencies in CI/test, not against production. The supplied HTTP helper is Python/POSIX-based; do not claim it ran on Windows without testing it.

Record pass/fail evidence for:

1. Fresh install and non-destructive upgrade/import; source ID and history reconciliation.
2. Each permitted role: sign-in, current assignment scope, deactivation, logout and rejection of unauthorised direct requests/downloads.
3. Apprentice add/save/reopen/edit, stale edits, duplicate references and persistence after logout/restart/redeployment.
4. Task draft/submit/lock/refer/resubmit/approve, private uploads, paper results and all 41 unchanged original PDFs.
5. Assigned topic hours/zero-secure, evidence versus completion, separate qualification result and custom target.
6. SMART actions, actual registers, attendance exclusions/two-week flag, extra-training confirmations, overlap rejection and withdrawal.
7. Private Careers, support-plan active/draft distinctions, instruction approval/withdrawal and former-recipient denial.
8. Review/plan draft protections, source snapshot integrity, RPL private projection/approval invalidation and no automatic application.
9. Staff employee/manager privacy, private downloads, impact visibility, independent case approval and survey receipt/check separation.
10. Dashboard/report totals against original records, unknown/null handling, source routes and permitted exports.
11. Laptop/desktop and phone layouts: approximately 360/390/768/1366/1920 px widths; readable headings, usable navigation, aligned controls, no overlap/clipping, theme contrast and clear status wording. Check PDFs/printing and keyboard use. Capture fictional staging screenshots for Jon.
12. Production-readiness failures: wrong environment, unauthorised storage access, incomplete configuration, failed audit/file rollback, backup restoration and release rollback.

Do not weaken assertions, drop privacy tests, invent successful results or remove a gate to make a test pass. If a schema adapter changes an interface, replace the relevant test with an equivalent observable-behaviour check and explain the change.

## 9. Keep unfinished work explicitly unfinished

BATCH_QUEUE.json is authoritative for the checkpoint's remaining work. In particular, completed/signable ten-week reviews, full individual training-plan approval/application, block sequencing/sign-off, complete OTJ targets/history/pace, granular support permissions, some exports, reminder delivery, employer-account mapping and full management/KPI policy are not completed here.

Unresolved primary KSB allocation, Foundation/individual applicability, retained S9 internal allocation treatment, Blocks 11–12/gateway conflicts, full topic catalogue, historic retention data and final templates/thresholds need source reconciliation or decisions. Use the supplied repaired SOW and dated amendments. Do not replace them with remembered programme durations, example hours, invented templates, grades or industry-standard claims.

A review draft must not reset the completed-review cycle or appear signed. Accepting topic evidence must not award training completion or a qualification. Unknown history must not become zero or one. A case study or Inspection Overview must not become an Ofsted grade/readiness guarantee. Keep incomplete areas disabled or explicitly labelled within the agreed staging scope; do not present them as finished to real users.

## 10. Deliver the integration for review, then release

Provide Martin with: the branch/commit and reviewed diff; feature integration matrix; precise migration/import map; actual test commands/results; fictional staging URL/accounts through an appropriate private channel; laptop/phone screenshots; remaining implementation/source decisions; production secret/resource checklist without values; and a release/rollback runbook naming owners.

The runbook must specify the code release, approved database migrations, private-file state, backup/restore checkpoints, validation after cutover and recovery if data was written after cutover. A Git rollback alone does not reverse database changes or new uploaded files. Do not run destructive down migrations as a generic rollback. Rehearse recovery in staging.

Stop before production deployment or real-data import until Martin explicitly approves the concrete release. Do not interpret Jon forwarding the pack for testing as permission to overwrite the live portal. Jon should review the agreed design and the selected working scope in staging before launch.

## Official implementation references

Checked 12 November 2026; recheck current guidance when deploying:

- Laravel 13 deployment: https://laravel.com/framework/docs/13.x/deployment
- Laravel Cloud deployment workflow: https://laravel.com/cloud/deploy-a-laravel-app
- Laravel Cloud private/public object storage and attached disk configuration: https://laravel.com/cloud/docs/resources/object-storage

## Documentation-only verification

This addendum changes README.md and adds the handover files. Application code, migrations, dependencies, source reference data, previous TEST_RESULTS.json and all 41 PDFs are preserved byte-for-byte. Archive integrity and the revised payload manifest are checked. The application suite has not been rerun for this documentation-only change; the reported baseline remains 11 November 2026. No portal integration, Cloud deployment, real-data migration or person-directed message was performed.
