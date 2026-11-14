# Martin / development AI: v0.23.0-v0.25.0

Read the three current CHANGES files and TEST_RESULTS.json. This is a local development checkpoint, not a production release. No Composer dependency or source task PDF was changed. Preserve all earlier migration/authentication/private-evidence/source safeguards.

## Installation

Apply additive migration 2026_11_13_160000_create_reviewed_imports_and_management_packs.php: data_import_batches, data_import_events, historical_otj_records (indexed by apprentice/date) and management_packs. Existing qualifications, attendance, saved work and source PDFs are not rewritten by migration. Earlier additive migrations remain required, including fresh-sign-in version binding. Do not run migrate:fresh against populated data.

## Qualification results

Open Reports > Reviewed data imports > Import qualification results. The lookup table provides current qualification ID/version, learner reference, subject and level. Download the blank CSV and preserve exact headers. Record actual result-source references; do not substitute a task/topic percentage for an award.

The parser accepts at most 200 rows, 1 MB UTF-8. Headers are qualification_id,version,status,result,achievement_date,evidence_reference. Incoming status is Achieved or Awaiting confirmation. Only existing Working towards/Awaiting confirmation aims can be bulk updated. Functional Skills uses Achieved without grade bands; other actual results remain literal, including a legitimate zero-valued result. Dates must be real and not future; an achieved result cannot predate the known qualification start. Existing achievements/exemptions require individual amendment. No new aim, changed title/level/start/target, topic completion or financial update is inferred.

The upload retains a preview, source byte hash and normalised rows, not a raw file in public storage. Keep the original controlled result statement/evidence. The preparer cannot apply the batch. A different current active administrator/CEO must check every learner/aim/result/reference and apply the exact preview within 24 hours. Version/source drift, duplicate aims, incomplete confirmations or failed audit aborts the entire transaction. Per-qualification history includes the original before state, batch/source hash and checked reference. Repeated application does not replay results.

## Historical OTJ

Use the separate historical-otj template: apprentice_id,training_date,start_time,end_time,excluded_minutes,topic,evidence_reference. The current-source lookup provides learner IDs and programme/start details. Explicit loaded programme/version and first learning must be known. Intervals are same-day, actually ended and after recorded first learning, with positive net minutes. Split unsupported overnight sources only according to real evidence; do not change times to force validation.

Overlap checks cover rows within the batch, noncancelled registers, nonwithdrawn extra training (including pending confirmation) and existing active imports. Exact repeat fingerprints are also rejected. Independent review must check actual provider approval/eligibility evidence, occupational learning (excluding Maths/English, breaks and assessment-only time) and duplicates. A checkbox/source reference does not itself prove evidence quality or decide funding eligibility.

Application adds immutable reviewed source rows, never attendance marks. Existing rows/hours are not overwritten. Later overlaps or programme/start-source drift become explicit reconciliation checks and imported minutes are excluded, rather than counted twice. The original record remains. Reasoned withdrawal from the manager's OTJ ledger preserves source/history and old report/review snapshots. Replacement/correction must be deliberately reconciled against retained sources; do not delete history to force a new import. Historical completeness is never set true by this workflow. There is no generic restore/in-place edit endpoint: exact imported fingerprints remain reserved after withdrawal. Erroneous withdrawal or a corrected replacement needs a controlled source reconciliation, not altered labels/times to bypass a duplicate.

The cumulative ledger and connected attendance/report subtotals show historical minutes separately. Attendance percentages still use original registers only. Do not post the combined subtotal back as another training source.

## Operational management packs

Open Reports > Management pack preview and fixed copies. Review group scope/current source checks and capture with a meaningful title. The captured snapshot, actor name/ID, time and source pointers remain fixed; subsequent data corrections do not refresh it. PDF reads that saved snapshot. Create a new pack for a later position; no update/delete endpoint is provided.

Current summaries cover task approval/backlog, attendance, recorded OTJ and current plan target, separate training/qualification results, actions, draft/readiness reviews and recorded outcome context. Missing sources remain checks. No complete review cycle, national achievement/retention rate, inspection grade, efficiency score or new threshold is calculated. Private Careers, support/safeguarding and staff HR are excluded. The pack is not all governance/financial/assurance evidence. Only current active administrator/CEO accounts can capture/read/download it.

## Validation and remaining acceptance

Run PHPUnit, tests/http_persistence.py and tests/static_checks.py. The HTTP proof uploads both CSVs, rejects preparer application/CSRF, uses an independent CEO check, preserves topics/custom starts, creates historical hours without attendance, captures a pack/PDF and verifies withdrawal leaves that snapshot intact. All 37 connected module tables are compared after restart. JSON binding tests simulate object-key reordering; actual MySQL/MariaDB is still untested. A native one-record management PDF sample was rendered/inspected separately from browser acceptance.

No real learner data, actual PICS/awarding-body connection, historic completeness verification, browser/mobile/keyboard/all-viewer print acceptance, target-engine/Cloud deployment, live mail/MFA/storage acceptance or release has been performed. Participant signing/completed-review scheduling, controlled curriculum/KSB/Foundation/S9 and gateway decisions remain separate. Automatic in-portal scheduling remains disabled by default. Review actual identity, data mapping, source evidence, backups, permissions and rollback in staging before production.
