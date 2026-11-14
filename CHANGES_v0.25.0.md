# GTA Laravel v0.25.0 - Fixed operational management reporting packs

Added manager-only current pack previews with group scope, source checks, apprentice summaries and direct links to current records needing follow-up. An explicit capture saves a fixed title/snapshot/actor/time and integrity hash. Subsequent source changes and actor renaming do not rewrite the saved position. A protected PDF reads the fixed snapshot; no message is sent.

The pack combines task/backlog counts, attendance, recorded OTJ (including reviewed historical imports), current provider-plan linkage, assigned training topics, separate qualification results, action counts, draft/readiness review counts and recorded programme outcome context. Qualification/task/review versions and OTJ source pointers are retained for traceability. Missing profile dates/programme/tutor/plan/qualification sources and unconfirmed/reconciliation work remain named checks, without an invented compliance percentage, grade, efficiency score or KPI threshold.

Private Careers discussions, support/safeguarding details, evidence files and staff HR are excluded. Managers can capture a selected group or current overall operational scope; ordinary tutors/apprentices cannot read saved global packs. Source snapshot tampering returns a conflict. This is an operational evidence pack, not the complete governance/inspection/funding report, historical cohort-rate calculation, verified review cycle or national achievement-rate return.

These three batches were tested together at v0.25.0. Executed consolidated evidence and limitations are in TEST_RESULTS.json; no independently tested intermediate release is implied.

Consolidated executed evidence: 457 tests / 5,559 assertions; 89 actual HTTP checks; 37-table restart persistence; 311 PHP/compiled Blade checks; 531 unchanged source PDF hashes.
