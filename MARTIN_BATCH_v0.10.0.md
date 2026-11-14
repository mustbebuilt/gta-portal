# Martin / development AI - v0.10.0 integration addendum

Read START_HERE_MARTIN.txt, the retained MARTIN_AI_INTEGRATION_INSTRUCTIONS.md and CHANGES_v0.10.0.md. The supplied HTML integration guide is the unchanged v0.7.0 baseline; its implementation approach remains applicable. Current status/tests override historical feature lists and test counts.

## Programme task selection

| Portal catalogue | Linked programme | Version | New source PDFs | Portal tasks |
| --- | --- | --- | --- | --- |
| Heavy Vehicle Level 3 | ST0068 | 1.5 (v1.5 also accepted) | 30 | 50 |
| Vehicle Damage Paint Level 3 | ST0448 | 1.2 (v1.2 also accepted) | 30 | 50 |

These are explicit GTA catalogue bindings. They do not infer the version for an existing learner or prove individual applicability. Use the supplied programme link, check it deliberately, and select Assign tasks. The legacy unmapped local Autocare fallback is retained for compatibility; do not use it to assign other programmes.

Tasks 3/4 and learning reflection use their own programme PDF packs. Task 1 records the actual paper assessment result (no new question paper/pass threshold). Task 2 uses the existing generic workplace job card, with mentor verification still required before approval. Do not clone Autocare's technical questions into another programme.

The two packs contain 30 PDFs each; they are not missing 20 PDFs. Paper results and the shared job card account for Tasks 1/2. Panel's located pack contains 20 practical PDFs and no separate Panel reflection pack; Panel is not enabled in this release.

## Adaptation scope

No new migrations or dependency changes are needed relative to v0.9.0. Adapt the changed catalogue/controller/view/test files and add the programme JSON/PDF files. Preserve all older task template snapshots, learner answers, evidence and submission/decision history. Allocation refuses duplicates. Block-release checks use the saved catalogue and require all five matching source tasks per preceding block. Explicit reasoned exceptions remain available.

PDF files are stored under programme-prefixed filenames to avoid collisions; source receipts preserve original filenames, field names/page mappings and SHA-256 hashes. The importer under tools/ is a development utility requiring PyMuPDF and pypdf, not a production runtime dependency. No PDF source is edited during import.

Individual plan/RPL/KSB sources are still Autocare-only. Other programmes have a pending-source page and cannot apply the Autocare planning/RPL source. Source documents' example weeks/durations are preserved as source text and never assigned as personal deadlines or OTJ targets.

Free-text task answers have no application character cap. Normal server request/database/resource limits still apply. Use the native recorded-work PDF for verified complete saved-answer output. Browser-print mirroring is implemented and syntax checked; browser/phone/keyboard/physical print acceptance remains for staging.

## Repeat validation

Run composer install from the unchanged lock only if dependencies need restoring; then php vendor/bin/phpunit. Run python3 tests/static_checks.py --php php and python3 tests/http_persistence.py --php php --output http-results.json in a disposable local checkout. The HTTP helper is POSIX-tested, not Windows-certified.

Current executed evidence: 327 tests / 3,414 assertions on SQLite and 52 actual HTTP checks. MariaDB/MySQL target-engine tests must be repeated in Martin's test environment; v0.9.0 MariaDB results are historical. No production credentials or populated databases are included.

The attached guide's isolation, permissions, private-storage, migration and release/rollback approach remains in force. This pack has not been integrated into Martin's live repository or deployed. Continue with Panel, programme planning/KSB sources, completed/signed reviews and production accounts/delivery/storage as separately recorded work. Do not disable the local guard to make a demo look ready for release.
