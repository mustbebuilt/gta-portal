> Current checkpoint v0.33.0 — two linked batches.

v0.32.0 adds exact-preview, protected evidence copy plans; v0.33.0 adds explicit once-only staging execution with exclusive destination creation, source SHA-256/size validation, destination readback, final verification and technical audit. Original files, application references and retained snapshots are not changed. No storage cutover or production execution is enabled. Read MARTIN_BATCH_v0.33.0.md and TEST_RESULTS.json for current instructions/evidence. One additive migration; no new dependency. Earlier entries describe historical scope.

# GTA Laravel v0.31.0 — evidence inventory

A read-only migration inventory now covers registered private uploads and retained file metadata/links across eight portal source families. Default mode reads database references/configuration only. Explicit verification checks each logical disk/path once against SHA-256 and declared size, flags problems and re-scans for drift. It does not move, relink, delete or approve migration of evidence. Earlier entries/counts retain historical scope.

# GTA Laravel v0.30.0 — native database task

Native database compatibility is now checked against MariaDB 11.8.6 through PDO MySQL as well as SQLite. A real fresh-install index-length blocker is corrected, with a data-preserving forward normalisation for older SQLite checkpoints. Native audit-failure, persistence and initial-administrator concurrency tests are included. Actual Cloud/Oracle MySQL/schema acceptance remains separate. Earlier notes/counts below retain historical scope.

# GTA Laravel v0.29.0 — private storage task

Cloud-compatible private S3 evidence storage now has a locked runtime adapter, bucket-level permission handling, file-only existence checks and an explicit synthetic storage probe. Original disk references and records are preserved. Actual Cloud bucket visibility, resource configuration and deployment still need Martin’s environment. Earlier instructions/counts below are historical.

# GTA Laravel v0.28.0 — Cloud startup task

Controlled hosted staging access and once-only first-administrator setup are implemented and tested locally. Explicit staging flags, valid application key, HTTPS origin/requests, secure encrypted shared sessions and shared cache are required. Production remains blocked. Existing records/accounts are never overwritten; actual Cloud resources and repository integration remain unverified. Earlier notes/counts below are historical.

# GTA Laravel v0.27.0 — review reminder queue update

Review reminders now use checked actual meeting dates and sourced Active programme status. Current assigned tutor and mentor receive one in-portal prompt per source when due today or overdue. Seen does not complete a review or reset its date. Superseded sources and old assignments cannot be acknowledged. Existing evidence and history are retained. Automatic generation stays disabled by default. Earlier notes/counts below are historical.

# GTA Laravel v0.26.0 — next-batch queue review

Externally signed progress-review records, independent manager checks and actual-meeting-based ten-week tracking are implemented/tested locally. Pending copies do not reset dates. The latest active checked meeting sets the next reference at meeting + 70 days. Electronic signing, employer portal access, complete historical compliance and live deployment remain separate. Current evidence: TEST_RESULTS.json. Earlier entries/counts below are historical.

# GTA Laravel v0.25.0 - three-batch queue review

Reviewed qualification result imports, reviewed historical OTJ imports/reconciliation and fixed operational management packs are implemented/tested locally. Actual external source mapping/evidence/completeness, signing, source/gateway decisions and production acceptance remain separate. Current evidence: TEST_RESULTS.json; earlier entries below are historical.

# GTA Laravel v0.22.0 - four-batch queue review

QA2 source integration, a plan-linked recorded OTJ ledger, guarded SMART source proposals and approved-calendar Careers/in-portal scheduling are implemented/tested locally. Historic completeness, formal signing/source/gateway decisions, external delivery and live hosting remain separate. Scheduling is opt-in/off by default. Current evidence: TEST_RESULTS.json. Earlier queue entries below are historical.

# GTA Laravel v0.18.0 - four-batch queue review

Final task-pack integration, individual operational calendars, independent provider plan approval and plan-linked frozen review preparation are implemented and tested together. Formal KSB applicability, participant signing, review completion/scheduling, Blocks 11-12 criterion content and live acceptance remain separate. Earlier queue entries below are historical; see TEST_RESULTS.json for current evidence.

# GTA Laravel v0.14.0 - queue review

Panel reflections are supplied and integrated for Blocks 1-10. Existing untouched placeholders can be upgraded explicitly without replacing saved work/history. No new dependency/migration. Formal plans/KSB applicability, verified signatures, Blocks 11-12 and live hosting remain unfinished. Current evidence is in TEST_RESULTS.json; earlier entries below are historical.

# GTA Laravel v0.13.0 - queue review

Own-account password changes, session revocation and local sink recovery preparation are implemented. Recovery remains disabled by default; real onboarding/MFA/delivery and hosting are not completed. Existing sessions must reauthenticate when the coherent migration/login/middleware update is installed. Current evidence and remaining scope are in TEST_RESULTS.json and BATCH_QUEUE.json. Earlier entries below are historical.

# GTA Laravel v0.12.0 - queue review

Marking worklist and in-portal reminders are implemented and tested. Queue access follows current main-tutor assignment; reminder acknowledgement does not approve work. Email/scheduling, formal plans/signatures, Panel reflection sources and live Cloud work remain unfinished. Current evidence is in TEST_RESULTS.json; earlier sections below are historical.

# GTA Laravel v0.11.0 - queue review

Panel practical workflow and private evidence configuration have been implemented and tested locally. Panel has 40 usable records and 10 blocked Source required reflections. Actual Cloud storage, optional adapter installation, file migration, formal plans/KSB sources and signatures remain unfinished. BATCH_QUEUE.json and TEST_RESULTS.json carry current scope; earlier sections below are historical.

# GTA Laravel v0.10.0 - queue review

Heavy Vehicle and Paint task integration batches completed locally. Each supplies 50 portal tasks and 30 unchanged PDFs. Formal programme plan/KSB mapping, signatures and live integration remain unfinished. See MARTIN_BATCH_v0.10.0.md and BATCH_QUEUE.json.

## Historical v0.9.0 queue review

All 21 queue entries have been reviewed. This is not a claim that the live portal or the entire original simulation has been completed. Working local code and dependent/blocked work are separated below.

## Completed local development in this batch

- Autocare sequence releases with administrator exceptions, stale guards and reasoned history; legacy task sets retain access until explicitly enabled.
- Programme/version allocation guard and protection against silently transferring existing Autocare tasks to a different linked programme.
- Atomic weekly repeat planning, separate saved rosters, overlap prevention, reasoned register corrections and retained prior marks.
- Marker before/after history and source/actor/time, including concern classification; no reset of unchanged marker timestamps.
- Inclusive recorded concern days with overlap union, missing/invalid date checks and explicit incomplete historical coverage.
- Minimal assignment-ID event history and distinct recorded employer/main-tutor counts; no inferred lifetime totals.
- Staff scope summary, HR3.8 appraisal headings/capability self/manager assessments, existing HR3.9 source headings preserved.
- Review source action outcome snapshot and known next-ten-week dates; drafts still do not complete a review or reset its clock.
- Block 10 on-screen careers check from source sequence release, scoped to current advisers; no email or automatic advice completion.
- Expanded operational report, current-tutor waiting-work ratio/date checks, source attendance and separate qualification records.
- Native access-controlled PDFs for recorded task work, operational report, private review draft and approved ASP snapshot.
- Responsive spacing/control polish, updated help and reproducible non-rendering static verification script.

## Remaining requirements and blockers

| Area | Exact remaining dependency |
| --- | --- |
| Individual training-plan / KSB / RPL approval | Confirm individual applicability, primary KSB allocation, controlled Foundation source and the S9 internal basis. Existing 663-hour source and supplied scheme are unchanged; memory recollections are not substituted. |
| Blocks 11–12 / final gateway | Resolve recorded IMI source conflicts (S9 form/log inconsistency, minimum-duration/20% wording, inconsistent version headers) and approve criterion-level content. The supplied guidance/agreement/supplement are already recorded; do not request them again. |
| Heavy Vehicle, Paint, Panel tasks | Provide or explicitly identify the controlled packs for this portal import and their standard/version bindings. Autocare is not reused as their content. |
| Full OTJ progress / review completion | Supply/check the approved individual training-plan calendar/target, whole-plan applicability and historical records. Three-way signatures/controlled shared review copies also require the agreed employer identity/recipient mapping. Do not treat current task/subtotal figures as complete. |
| Management / inspection policy and evidence | Confirm final management report contents and additional KPI thresholds, verified provider/cohort outcome/destination/governance assurance sources. Existing overdue policy is retained; historical appraisal targets are not adopted as industry standards. |
| Survey answers / links / HR sign-off | Blank induction/wellbeing and HR3.8/HR3.9 sources have been located. Supply reviewed responder links rather than design URLs; internal sensitive-answer storage/consent and signed HR workflow remain separate decisions. Additional observation criteria are not guessed. |
| Production accounts, delivery and Cloud | Martin must review the actual repository/schema migration, employer-account mapping, account recovery/MFA policy, mail/queue/scheduler, private storage, backups and release/rollback. Local-only guard remains; no external messages/deployment performed. |
| Visual acceptance | Browser/phone/keyboard/physical print acceptance remains unverified under the project constraint. Native PDF sample inspection is a separate limited check. |

## Complete queue status

| Original batch | Current status | Remaining work |
| --- | --- | --- |
| Core profiles and linked directories | working_tested | Live schema migration and production deployment pending |
| Autocare task workspaces | working_tested_core | Sequence controls/exceptions and native recorded-work PDF now work. New allocation enables sequence control; legacy task sets require explicit activation. Source programme/version guard prevents other programmes receiving Autocare tasks or silently relabelling assigned task history. Formal KSB sign-off still depends on individual applicability/primary mapping. Blocks 11–12 and Heavy Vehicle/Paint/Panel tasks require the controlled source content. Original PDFs remain unchanged; generated recorded-work PDFs do not overwrite their AcroForms. |
| Maths/English training and qualifications | working_tested_core | Full authoritative topic catalogue reconciliation and external result import remain. Private certificate/evidence uploads now work without setting achievement. |
| SMART Action Plan | working_tested_core | Review drafts now capture original action outcomes/dates and known next-ten-week task deadlines without creating duplicate targets. Automatic target selection requiring an approved individual block calendar and remaining source-linked action-type policy stays pending. No targets are invented or automatically agreed. |
| Sessions and registers | working_tested_core | Weekly repeated plans, overlap-safe atomic creation, immutable roster snapshots, reasoned corrections with prior marks and cancellation history now work. Full historical OTJ ledger and approved individual targets/pace remain source-dependent. A separate formal reopen/approval window has not been guessed. |
| Private Careers | working_tested_core | Current-adviser worklist now includes an on-screen Block 10 source-sequence release check. It is not a planned-start deadline, delivered-advice assertion or email. Exact calendar trigger needs the approved individual block calendar; external delivery needs reviewed mail/queue configuration. |
| Support and retention cases | working_tested_core | Scoped duties remain. Marker additions/removals and classification-created markers now retain before/after codes, actor, time and optional reason/source, atomically. Unchanged marker timestamps are retained. Historical paper marker data is not reconstructed. |
| Private Apprentice Support Plans | working_tested_core | Scoped private evidence/activity and native approved-plan PDF now work. Export uses the last approved snapshot and excludes draft replacements. Consent-controlled employer sharing requires agreed recipient mapping/consent workflow and is not enabled. |
| Approved tutor instructions | working_tested_core | Separate support-member drafting and support-lead approval now work inside the current apprentice scope, with one active team lead. Ordinary administrator oversight does not itself grant instruction drafting/approval; explicit CEO emergency access is retained. User acceptance and production security review remain. |
| Training plans, RPL and curriculum/KSB coverage | working_tested_preparation_and_assessment | Portal-first draft projection, proposal inputs and source-bound individual RPL assessment/review now work. Formal plan approval/application, primary KSB allocation, S9 internal basis reconciliation, Foundation/individual applicability, revised block calendar, signatures and final gateway source checks remain. |
| Ten-week progress reviews | working_tested_drafts | Drafts now include fixed source action outcomes and known next-period task dates; protected native draft PDFs include captured blocks, subjects, Maths/English and separate qualification results. They remain drafts: whole-plan applicability, completion/history scheduling, three signatures and controlled shared copies depend on source/identity/plan decisions and are not represented as complete. |
| Monthly apprentice scorecard | working_tested_core | Calendar-month main-tutor record, five conduct-informed areas, Not observed, source register link and review snapshot. Descriptors are explicitly proposed GTA configuration, not approved industry benchmarks. No grade/ranking. User acceptance pending. |
| Retention and early-leaver analysis | working_tested_core | Recorded acknowledged concern durations union overlapping inclusive calendar dates, preserving unknown/date checks. Minimal assignment-ID history and distinct recorded linked employers/main tutors now work; historical counts remain unknown. Source attendance is connected. Full historic import, verified destinations and approved comparison/KPI policy remain pending. |
| Dashboards, journey and management/inspection reports | working_tested_core | Operational report now connects attendance/confirmed subtotal, training topics, separate qualifications, actions, outcome context and current-tutor waiting-work percentages, with native PDF. Inspection source wording/links corrected; approved case studies retained. Full management contents/KPI policy, verified provider outcomes and governance/controlled assurance evidence remain pending. No Ofsted grade/readiness score is calculated. |
| Case studies | working_tested_core | All non-apprentice authors can draft their own anonymised cases. Independent administrator/CEO approves an exact submitted version. Amendments remove the case from current inspection evidence until reapproved. External publication consent/authority not implied. |
| Staff development | working_tested_core | Scoped staff summary and supplied HR3.8 v1.3 appraisal discussion/capability fields now work beside existing HR3.9 v1.1 tutor headings. Self/manager assessments stay separate. Legacy Grade 2/85% wording is not adopted as current KPI policy. Signed HR workflow and any additional controlled observation criteria remain pending confirmation. |
| Induction/wellbeing surveys | working_tested_receipt_tracking | Previously supplied questionnaire PDFs were located; their documents are not being requested again. Current code records private receipts/checks, not questionnaire answers. External responder URLs/configuration are still required; document design/edit URLs must not be treated as respondent links. Any internal sensitive-answer storage needs a separate approved privacy/access decision. |
| Help and optional voice explanations | working_tested_core | Role-scoped core guides now also cover plan preparation, extra training and CEO emergency access. Actual device voice/browser availability and visual acceptance remain unverified. |
| Reminder delivery and account administration | not_ported | Local password command works; production invitations/recovery/MFA, mail/queue/schedules and employer-account mapping pending |
| Polishing and acceptance | partial | Shared responsive controls, spacing, source labels, report meters and source-safe guidance polished. Native PDF task sample inspected using non-browser PDF rendering. No browser/phone/keyboard/print user acceptance or full-device QA performed. |
| Laravel Cloud release | blocked_live_environment | Martin must supply/review actual Cloud/database/storage/mail/backup environment and release process; local guard intentionally remains |

## Data and quality

Fictional editable seed remains two apprentices and ten accounts. The large disposable scope test remains 1,000 fictional apprentices. No real apprentice/staff records or safeguarding details have been imported. No new external messages, automations or deployment. Continue functionality and polishing without sacrificing quality.
