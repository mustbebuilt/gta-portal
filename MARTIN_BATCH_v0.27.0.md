# Martin / development AI — v0.27.0 review reminder batch

Review reminders now use checked actual meeting dates and sourced Active programme status. Current assigned tutor and mentor receive one in-portal prompt per source when due today or overdue. Seen does not complete a review or reset its date. Superseded sources and old assignments cannot be acknowledged. Existing evidence and history are retained.

## Apply and verify

Read this addendum with START_HERE_MARTIN.txt, prior batch notes and TEST_RESULTS.json. Integrate on an isolated branch/staging database, with backups and rollback planning. Apply `2026_11_13_190000_create_review_reminders.php` after prior migrations. It adds review_reminders and review_reminder_events; no earlier records or dependencies are rewritten. Retain private files and all 531 source PDFs.

The local-only environment guard remains. Actual Cloud/MySQL/MariaDB, shared cache/scheduler and private object storage acceptance remain separate. Scheduler infrastructure was not provisioned or activated. No external messages or real-data import were used.

## Simple operator flow

- Open Progress reviews. Managers see global preview counts, programme/history/owner/source checks and the explicit Record current due reminders button. GET/preview writes nothing; POST requires CSRF and current manager authority.
- A reliable date comes only from the latest active independently checked external signed meeting. Next reference is actual meeting + 70 days; uploading, drafting, freezing or acknowledging cannot move it.
- Automatic prompts additionally require the recorded programme outcome/context to be Active with a source reference. Unknown/missing/unsourced status becomes a check. Break in learning, Completed, Withdrawn and Transferred pause reminder generation; the original dates/history remain available. This operational reminder exclusion is not a decision that follow-up/support or historical evidence is unnecessary.
- Current active main tutor and mentor each receive their own source-bound reminder. Missing, inactive or wrong-role assignments become checks. Other subjects/roles do not receive the source or gain access. Tutors/mentors can only mark their own current reminders seen. Managers cannot impersonate a recipient acknowledgement.
- Seen is an acknowledgement only. The due reference stays until a newer checked actual meeting changes it. Repeated page submissions/commands do not add daily copies or repeated seen audits.
- Newer checked meetings, withdrawal, source corruption, status pause or reassignment make obsolete acknowledgement unavailable. Records/events are retained. If an unchanged older meeting becomes current again, its existing reminder/seen history is reused rather than duplicated. A new current owner gets a separately bound prompt.

The retained reminder source contains source IDs/version/hash, checked time, actual meeting/due dates, recipient role/ID and a hash of the relevant outcome status/source fields. It excludes signed PDFs, identity references, private Careers/support/HR notes and outcome source text. Changes to unrelated outcome factors do not themselves issue another reminder. A corrupted reminder is flagged, never overwritten or acknowledged. There is no generic delete/restore workflow.

## Scheduling and audits

`gta:portal-reminders` still defaults to preview. `gta:portal-reminders --record` now records marking, Careers AND review prompts as one atomic run with counts under `marking`, `careers`, `reviews`. Older portal_reminder_runs rows retain their original narrower JSON scope; no historical runs are backfilled. A run/audit failure rolls back every new prompt and event. Separate manual review generation writes its per-reminder events, not a combined command run.

The existing daily 09:00 application-timezone schedule remains guarded by GTA_SCHEDULE_PORTAL_REMINDERS, false by default, and an overlap lock. If a deployment already enables this combined setting, upgrading extends its scope to review reminders; review that change in staging. A true setting does not prove the server scheduler/shared cache runs correctly. No email/SMS is sent, no live email recipient mapping is invented, and no schedule was activated here.

## Evidence and limits

See TEST_RESULTS.json for the final executed suite, actual HTTP and static checks. New cases cover due-day boundaries, pending source/history, Active-status eligibility, paused/ended/unknown status, both recipients, duplicate/replayed actions, reassignment, supersession, withdrawal/fallback, corrupt sources, denied roles, audit rollback and opt-in combined scheduling. HTTP uses fictional source documents and actual loopback requests; it does not establish genuine signatures or browser acceptance.

Manual external signature checks remain a provider responsibility. Electronic signing, participant authentication, employer portal/sharing, full history and whole-cohort compliance, different-plan reconciliation, broader KPI/curriculum/gateway/funding decisions and live rollout remain separate. Reminder eligibility does not claim those are complete. Full PDF byte reads are performed on signed-copy check/download, not every reminder-list scan; target storage integrity/backup monitoring still needs acceptance.

Final executed v0.27.0 evidence: 479 tests / 5,966 assertions; 96 actual HTTP checks; 41-table restart persistence; 324 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Cloud diagnostic expectedly blocked; no deployment performed. Read CLOUD_START_HERE_MARTIN.md for the installation priority.
