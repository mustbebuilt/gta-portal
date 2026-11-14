# v0.29.0 — private S3 evidence and storage acceptance preparation

Cloud-compatible private S3 evidence storage now has a locked runtime adapter, bucket-level permission handling, file-only existence checks and an explicit synthetic storage probe. Original disk references and records are preserved. Actual Cloud bucket visibility, resource configuration and deployment still need Martin’s environment.

- Added league/flysystem-aws-s3-v3 3.35.3 and four new transitive dependencies. No existing dependency versions changed. Composer lock and installed runtime sources are included.
- PrivateEvidence now prepares the actual SDK client for bucket-level privacy by removing object ACL/grant parameters before serialisation. Public URLs/visibility/grant configuration is rejected. Current named disk and each retained file’s pinned disk remain separate; no legacy source files are moved/repointed.
- Evidence existence uses file-only HEAD checks rather than directory/bucket listings. Hash verification, protected streaming downloads, saved file metadata and failed-audit cleanup are retained.
- `gta:evidence-storage` defaults to configuration-only/no network/no writes. Explicit `--probe` on local/testing/staging writes random synthetic bytes, hash-reads them, checks unsigned access at the configured API endpoint and deletes/verifies the temporary object. Failures return nonzero and redact exception/credential details. Failed deletion reports only the synthetic relative key.
- Anonymous API denial does not certify bucket visibility or other public aliases. The probe never creates learner/database records or moves existing evidence. Production probe is refused.
- Added SDK-backed feature and actual signed HTTP fixture checks, including ACL-free requests, privacy/readback/cleanup failures, fresh-process disk pinning and protected task-controller scope.

Read MARTIN_BATCH_v0.29.0.md and CLOUD_START_HERE_MARTIN.md. Staging gate/administrator setup remain off by default; production remains blocked. All 531 source PDFs and earlier records are retained.

Final executed v0.29.0 evidence: 514 tests / 6,155 assertions; 96 loopback HTTP checks; 14 local HTTPS/CLI startup checks; 12 signed-S3 wire checks; 7 clean no-dev build checks; 333 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Strict Composer/platform checks passed; no known advisories reported at check time. Actual Cloud remains unverified.
