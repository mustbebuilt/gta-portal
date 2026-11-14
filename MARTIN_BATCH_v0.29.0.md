# Martin / development AI — v0.29.0 private storage integration

Cloud-compatible private S3 evidence storage now has a locked runtime adapter, bucket-level permission handling, file-only existence checks and an explicit synthetic storage probe. Original disk references and records are preserved. Actual Cloud bucket visibility, resource configuration and deployment still need Martin’s environment.

## Dependencies and source

No new database migration in this batch. Apply prior migrations when appropriate to the reviewed actual schema. Composer require/lock now add:

- league/flysystem-aws-s3-v3 3.35.3
- aws/aws-sdk-php 3.403.5
- aws/aws-crt-php v1.2.7
- mtdowling/jmespath.php 2.9.2
- symfony/filesystem v8.1.11

All existing package versions are unchanged. Use composer install from the supplied lock for the standalone source. When integrating into Martin’s actual repository, resolve the added requirement against his actual lock, review the diff and retain his other dependencies. Do not blindly replace a populated application or its vendor directory. The effective tested minimum remains PHP 8.4.1; local runtime is 8.4.27. Current platform/validation/audit evidence is included; advisory results apply only at the recorded check date.

## Actual private bucket configuration

Attach a PRIVATE Laravel Cloud bucket and use the Cloud-managed disk’s actual name in GTA_PRIVATE_EVIDENCE_DISK and GTA_PRIVATE_EVIDENCE_ALLOWED_DISKS. Cloud’s Laravel disk configuration supplies its connection; do not guess AWS credentials/endpoint names or override other services’ injected AWS variables. Confirm the bucket visibility and absence of public domains/URLs in the actual Cloud settings. Keep a deliberate legacy disk/allowlist for old files until a verified migration is performed.

Do not change GTA_LEGACY_EVIDENCE_DISK to a new empty bucket as a shortcut. Old metadata without disk references must still read the old location; pinned sources keep their saved disk. This batch does not move/rewrite source files or database references. Rescue/reconcile any earlier ephemeral uploads before redeploying a populated source environment. A later audited file-migration/reconciliation task is separate.

## SDK handling

PrivateEvidence resolves the approved disk, applies the SDK init middleware once per client, and removes ACL/grant parameters from PutObject/CreateMultipartUpload. This happens before request serialisation and is scoped to the private evidence client. Bucket-level privacy is configured externally; an omitted ACL does not itself make a public bucket private. No signed public URLs or public storage links are generated. Uploads/downloads stay behind existing controller permissions. File-only existence checks avoid directory listing requirements.

## Safe-by-default operator check

```bash
php artisan gta:evidence-storage
```

This reads configuration only, constructs no storage/network request and writes no file/row. It distinguishes an installed adapter from an actually configured S3 disk. It cannot verify bucket permissions or connectivity.

After actual staging bucket/configuration checks, explicitly run:

```bash
php artisan gta:evidence-storage --probe
```

The explicit probe is refused in production. It uses the current approved S3 disk, a random gta-storage-probes/<uuid>/ key and fictional small text bytes. It writes, reads/compares SHA-256, performs an UNSIGNED request to that object’s configured API endpoint without following redirects, then deletes and checks the file is absent. No learner records, audits, private documents or existing objects are changed. Only local/testing permits literal loopback HTTP endpoints; real staging probes require HTTPS. Default SDK certificate verification remains in use.

Exit 0 means these limited synthetic checks passed, not that all bucket aliases, visibility, backups or deployment continuity were certified. bucket_visibility_verified intentionally stays false: confirm the actual Cloud bucket/private/public-domain settings independently. Anonymous 200, redirects, errors or changed bytes fail. Cleanup runs on error. If deletion fails, the nonzero JSON reports cleanup_reference, relative to the configured disk root, for private operator cleanup. Do not ignore it; only that synthetic key is safe to remove. Exception URLs/credentials are not printed.

Repeat the probe after configuring/redeploying staging, and separately verify a retained uploaded source and scoped authorised/denied downloads after redeployment. Do not use a real apprentice’s file as a probe.

## Verification limits

New feature cases use the real installed adapter/SDK with controlled command results; tests/s3_wire.py also sends actual SigV4-signed HTTP requests through the installed SDK to a local filesystem-backed fixture, rejects ACL/grant headers and exercises read/privacy/cleanup failures. Fresh PHP processes retain pinned storage and hash checks. The fixture is not full R2/S3 and does not prove actual Cloud bucket visibility, real credentials, multipart provider limits, TLS endpoint configuration, replication, backup or target-load behaviour. No actual Cloud or MySQL/MariaDB environment was used.

The fresh local no-dev build script checks normal Composer install/package discovery and Laravel config/route/view caching without the development dependencies. It is not an actual Cloud build. See TEST_RESULTS.json and evidence/v0.29.0-* for executed results.

Final executed v0.29.0 evidence: 514 tests / 6,155 assertions; 96 loopback HTTP checks; 14 local HTTPS/CLI startup checks; 12 signed-S3 wire checks; 7 clean no-dev build checks; 333 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Strict Composer/platform checks passed; no known advisories reported at check time. Actual Cloud remains unverified.
