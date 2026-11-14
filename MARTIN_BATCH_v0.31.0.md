# Martin / development AI — v0.31.0 migration inventory

A read-only migration inventory now covers registered private uploads and retained file metadata/links across eight portal source families. Default mode reads database references/configuration only. Explicit verification checks each logical disk/path once against SHA-256 and declared size, flags problems and re-scans for drift. It does not move, relink, delete or approve migration of evidence.

## Run before planning any file move

Use a trusted authorised server/CLI context. There is no new browser route or wider portal-role access. No new migration/dependency; restore the current lock and review any pending earlier migrations/schema mapping first.

```bash
php artisan gta:evidence-inventory
php artisan gta:evidence-inventory --details
php artisan gta:evidence-inventory --verify-files --details
```

Default mode reads known database metadata and private-disk configuration only; it does not instantiate a storage client or read uploaded-file bytes. --verify-files explicitly permits private file HEAD/read operations, including network requests for an approved S3 disk. It never writes a file, row, cache entry or audit. Temporary database test fixtures perform their own controlled writes separately; production command logic is read-only.

The summary lists scanned rows/references/logical objects, legacy/pinned counts, declared bytes, metadata/schema/conflict counts, verified/missing/changed/unreadable counts and a source binding. Default output contains no file locators or personal content. --details exposes internal module/record/column/reference-ordinal locators, opaque object tokens and issue codes; it still excludes filenames, paths, document SHA values, purpose, identity references, support/HR notes and file contents. Treat real operator output as internal and resolve issues privately against the authorised database rather than pasting private records into chat.

Exit 0 means references_scanned, or verified_referenced_objects in verification mode. It is not migration approval. Exit 1 means needs_checks or inventory_failed. Missing/incompatible known tables/columns are explicit; an unrelated legacy portal schema cannot silently produce a successful empty inventory. Invalid JSON/shape, metadata/hash/size/location problems, missing task-evidence rows, wrong task ownership or hash disagreement do not get guessed away.

## Coverage and identity

The eight sources are task_evidence; learning_tasks.submissions evidence links; training_topics files/submissions/history; qualifications files/history; support_plans files/active-plan/reviews/history; staff_development_profiles.payload including retained entry history; extra_trainings file/history/approval/agreement/compensation; signed_review_records file/snapshot/history. Current status does not erase retained source references. A verified withdrawn document does not become current/approved again.

A logical object is the resolved configured disk NAME plus stored relative PATH. Duplicate snapshots/links at that same logical location are read once. Distinct aliases of the same physical bucket/root are not merged; reconcile them deliberately. Declared unique bytes are metadata totals for those logical locations, not actual disk usage, billing or backup size. Conflicting/unknown sizes are flagged rather than invented. Unregistered/orphan storage objects, other applications’ tables and committed task source PDFs are outside this upload-reference inventory. The 531 task PDFs remain repository/source-pack assets, not uploaded evidence.

Missing/null saved disks follow the deliberately configured GTA_LEGACY_EVIDENCE_DISK and are counted as legacy; explicit saved disks stay pinned. A new upload default does not change existing sources. Do not switch the legacy disk to a new empty Cloud bucket or remove old allowed aliases before file migration/rescue is verified.

## Verification behaviour

Each eligible unique logical object is read through PrivateEvidence, checked against declared SHA-256 and byte count, then the stream is closed. Invalid or conflicting declarations are not treated as valid files. Storage exceptions become unreadable counts/codes without printing credentials/URLs/exception text. Missing and mismatched objects need source reconciliation; this command never adopts new hashes, replaces files or repairs history.

No long database transaction surrounds file/network reads. After verification, a second source scan compares row versions/file references/link issues/schema and non-secret storage-identity configuration. Detected changes make the result needs_checks even if individual bytes matched at read time. Matching bindings do not prove a transactionally frozen or continuously unchanged system. For an actual migration, use a controlled backup/write window and independently verify the intended source/target; do not treat this report as a signed immutable manifest or automatic copy authorisation.

The files_read flag identifies requested file-verification mode; the status/counts show what was actually verified or could not be read. A complete empty source schema reports zero registered references, not an empty bucket/backup. File verification does not establish document/signature authenticity, assessment approval, funding eligibility, historic completeness or permissions for new sharing.

## Next controlled step

Use the inventory to identify missing/changed/unknown sources and rescue any earlier ephemeral files before redeployment. Keep snapshots, recorded metadata, saved schemas, IDs and access rules intact. This batch deliberately supplies no automatic copy/relink/delete workflow: an actual copy plan needs Martin’s source/target disk configuration and actual repository mapping, with verified bytes and retained rollback evidence. Never edit frozen metadata merely to make a storage check pass.

See TEST_RESULTS.json for executed dual-engine suites, actual HTTP/CLI row/file preservation and actual SDK wire fingerprint checks. Local fixtures are not actual Laravel Cloud bucket/database acceptance. No real learner data, live source environment or Cloud connection was used.

Final executed v0.31.0 evidence: SQLite: 530 tests / 6,243 assertions; MariaDB 11.8.6: 530 tests / 6,752 assertions; 98 HTTP/CLI checks per engine with 41-table/file-hash restart preservation; 12 actual SDK wire checks; 7 clean no-dev build checks; 341 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud/storage migration remains unverified and unauthorised by any inventory result.
