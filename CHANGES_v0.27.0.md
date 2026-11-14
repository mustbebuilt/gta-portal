# v0.27.0 — current due review reminders

Review reminders now use checked actual meeting dates and sourced Active programme status. Current assigned tutor and mentor receive one in-portal prompt per source when due today or overdue. Seen does not complete a review or reset its date. Superseded sources and old assignments cannot be acknowledged. Existing evidence and history are retained.

- Manager read-only preview and explicit CSRF-protected recording on Progress reviews.
- Sourced Active programme status and independently checked meeting + 70 days required. Unknown status/history/owner/integrity problems are checks, with non-active programmes paused.
- One prompt for each current active main tutor/mentor and source. Independent recipient acknowledgement, idempotent seen history, stale/reassigned/corrupt source guards.
- Combined preview/default and explicit recording now include reviews alongside marking/Careers, with atomic audits/run counts. Existing schedule stays opt-in and off by default; no external messages.
- Additive reminder/event tables. No source/dependency change; all 531 PDFs, original records/files and historical packs retained.

See MARTIN_BATCH_v0.27.0.md and TEST_RESULTS.json. Electronic signing, complete historical compliance and actual target-environment/user acceptance remain separate.

Laravel Cloud handover added: read-only `gta:cloud-check [--database]`, value-free staging environment reference and CLOUD_START_HERE_MARTIN.md. This reports actual blockers without leaking secrets or changing records. Hosted web access, S3 dependency/configuration, first real administrator and actual target acceptance still require integration in Martin’s repository.

Final executed v0.27.0 evidence: 479 tests / 5,966 assertions; 96 actual HTTP checks; 41-table restart persistence; 324 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Cloud diagnostic expectedly blocked; no deployment performed. Read CLOUD_START_HERE_MARTIN.md for the installation priority.
