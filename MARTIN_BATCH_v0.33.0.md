# Martin / development AI — two batches v0.32.0 and v0.33.0

v0.32.0 adds exact-preview, protected evidence copy plans; v0.33.0 adds explicit once-only staging execution with exclusive destination creation, source SHA-256/size validation, destination readback, final verification and technical audit. Original files, application references and retained snapshots are not changed. No storage cutover or production execution is enabled.

## Integrate before using the commands

Use the real repository's reviewed integration branch and a separate fictional staging environment. Preserve the existing APP_KEY, users, schema, IDs, snapshots, historical uploads and disk pins. This pack is executable Laravel code, not an installed update to Martin's portal. Actual repository/schema mapping and Cloud acceptance remain outstanding. Do not run migrate:fresh on a real environment.

Review the additive migration 2026_11_13_230000_create_evidence_copy_plans.php, which creates evidence_copy_plans and evidence_copy_events. It uses a short explicit foreign key name for native engine compatibility. Restore the existing lock with composer install; no dependency changes in these batches. Review pending earlier migrations before applying php artisan migrate --force to the intended staging database. Back up database and private objects first. Rolling SQL back cannot undo external object writes, and dropping these tables would destroy reconciliation/audit information.

This is an authorised technical server/CLI operation. No browser route, new portal-role access, human signature, business approval or impersonated actor is supplied. Events say origin=console. A controlled reference records the operator's stated basis; it does not independently authenticate a human approver. Keep the tables, database backups, spool/temporary directory and logs private with access limited to the authorised operations team. PHP's temporary spool can spill beyond 2 MiB; confirm the host's temp directory is private/durable enough for this controlled staging operation. No private source name/path is printed in normal command output, though operators can access manifests in the protected database.

## Configure distinct approved private aliases

Keep GTA_LEGACY_EVIDENCE_DISK pointing to the actual original legacy location. Configure the real source and target in filesystems.disks and the evidence.private_disks allowlist; both must pass PrivateEvidence validation. Do not change the upload default or remove earlier aliases as part of this copy. Explicit S3 endpoints must use HTTPS for execution. Local targets must be private, outside public directories; do not allow concurrent untrusted filesystem writers to their roots. Server/bucket ACLs and access policies need separate acceptance. An alias name alone does not prove that the two physical locations differ; the inventory deduplicates logical disk/path pairs, not bucket/root aliases.

Plans bind non-secret storage identity (driver, root/prefix, bucket, endpoint, region, URL/visibility), all known reference metadata and recorded versions. Credential rotation is not part of that identity binding. Unknown/unregistered bucket objects and other applications' records are outside the inventory. Missing or incompatible source schema, invalid/conflicting metadata, no eligible objects or an object over the configured limit refuse planning. The default 20 MiB cap matches portal uploads; larger legacy objects need a separately reviewed workflow, not an assumed partial success.

## Preview and record — v0.32.0

Use your actual configured aliases in place of SOURCE_ALIAS and TARGET_ALIAS. These are command examples, not completed real environment actions.

```bash
php artisan gta:evidence-inventory --verify-files --details
php artisan gta:evidence-copy-plan --source=SOURCE_ALIAS --target=TARGET_ALIAS
php artisan gta:evidence-copy-plan --source=SOURCE_ALIAS --target=TARGET_ALIAS --record --expected-proposal=PREVIEW_HASH --reference="CONTROLLED_CHANGE_REFERENCE"
php artisan gta:evidence-copy PLAN_ID
```

Plan preview reads database/configuration metadata without uploaded-byte/network access. Recording requires staging, the exact current proposal hash and a nonblank reference up to 1000 characters. It creates a UUID plan and atomic technical audit only. Inspection does not read file bytes. Source paths, SHA-256 and byte counts are stored privately in the server manifest. Destinations are gta-staged-copies/{plan UUID}/{opaque object token}.bin — source filenames are not reused. Record the returned manifest_hash and version in the private change record.

## Explicit copy — v0.33.0

Execution is disabled by default. Only enable GTA_ENABLE_EVIDENCE_COPY=true in the authorised staging environment after the source/target configuration and controlled write window are ready; refresh that environment's config cache deliberately. The flag does not switch any application storage reference. Disable it again after the rehearsal.

```bash
php artisan gta:evidence-copy PLAN_ID --copy --expected-version=PLAN_VERSION --expected-hash=MANIFEST_HASH
php artisan gta:evidence-copy PLAN_ID
php artisan gta:evidence-inventory --verify-files --details
```

An unchanged Planned row with the exact version/hash is claimed transactionally once. It becomes Running with an incremented version and copy_started event; no SQL transaction is held across transfer. Each source is streamed into a bounded temporary spool and matched against its original SHA-256/size BEFORE creating its destination. No arbitrary caller-supplied source/destination can bypass the plan's structural checks.

The local implementation uses exclusive xb creation, private permissions and rejects existing symlink destination parents. S3 uses the installed SDK's PutObject with IfNoneMatch='*', no object ACL/grant header. An existing key is a failure even when bytes match; it is never silently adopted or overwritten. After each copy the destination is read back and checked. At the end, all source and destination objects are checked again, then current reference/configuration bindings are checked. Only a complete successful run becomes Verified staging copies. The result verified_copies count records successful immediate readbacks; a later final-check failure can still make the overall state Needs checks, so never use that count alone as approval. This is finite sequential verification, not atomic freezing, a whole-bucket completeness check or a guarantee against changes after the last read. Use an independently controlled source/write window and actual remote acceptance.

## Failure and restart rules

The first failed object stops the run. Earlier successful copies and any partial/changed destination remain; result is Needs checks when final audit succeeds. A pre-write object_intended event plus the protected manifest locates possible outputs if a process stops. A claim/start audit failure rolls back before external writes. A finish audit/database failure leaves Running and known staging keys for reconciliation; SQL cannot roll back file or object writes. Never reset a Running/Needs checks row to Planned to force a retry. Inspect actual source/target bytes and ordered events privately, preserve incident evidence and prepare a fresh reviewed plan/namespace only after resolving the cause. No resume, cleanup, automatic deletion, source-hash adoption, overwrite or cutover is implemented. Keep originals and partial staging objects until an explicit retention/reconciliation decision.

Default inspection can report a Needs checks or Running state with exit zero if the stored manifest itself is intact; inspect state/result before operational decisions. Altered manifest inspection and any failed explicit copy return nonzero. Failed command output may report that staging keys remain; it never promises an external rollback. A normal summary omits filenames/paths/private notes and does not echo the controlled reference.

## What this does not finish

No saved application reference, legacy/default disk, task submission, review snapshot, access policy or source byte is relinked/deleted. Verified staging copies are not yet used by portal downloads. Copy-plan verification does not authenticate document signatures, approve assessment/funding decisions, authorise migration/cutover or establish an independently witnessed business approval. There is no automated cutover/relink or rollback-switch command in these batches.

The next real integration step is to run this workflow against Martin's actual configured private source/target in staging, reconcile real legacy/unregistered files and validate upload/download after redeployment. A separately reviewed reference-cutover design, rollback/restore proof, browser acceptance and actual managed database/bucket checks are still required. Do not remove the source based solely on this local test report.

Actual local evidence is in TEST_RESULTS.json. tests/evidence_copy_flow.py runs real console processes against fictional disposable SQLite and private directories. tests/s3_wire.py verifies signed requests using the pinned SDK against a local conditional-write fixture, not actual R2/Cloud. Feature tests run on SQLite and local MariaDB, including audit failures, stale inputs, partial copies, changed source/destination, symlink refusal and replay. Existing HTTP/CLI tests still verify portal source rows/files across process restart.

Conditional write references: https://developers.cloudflare.com/r2/api/s3/conditional-operations/ and https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes.html . The actual provider configuration still needs acceptance; a check-then-write fallback is not permitted.

Final executed v0.33.0 evidence: SQLite: 547 tests / 6,339 assertions; MariaDB 11.8.6: 547 tests / 6,865 assertions; 98 HTTP/CLI checks per engine with 41-table/file-hash restart preservation; 10 separate-process copy CLI checks; 14 actual SDK wire checks (45 requests); 7 clean no-dev build checks; 346 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud/source-target rehearsal, cutover and restore remain unverified.
