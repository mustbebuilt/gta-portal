# Martin / development AI - v0.11.0 integration addendum

Start with START_HERE_MARTIN.txt, CHANGES_v0.11.0.md and TEST_RESULTS.json, then the retained integration guide. This checkpoint retains v0.10.0 Heavy Vehicle/Paint work and adds Panel's available task workflow plus configurable private evidence. It is not a live deployment.

## Panel

| Binding | Source PDFs | Usable task records | Missing-source slots |
| --- | --- | --- | --- |
| ST0403 v1.3 (v1.3 accepted) | 20 unchanged practical PDFs | 40 | 10 learning reflections |

Confirm the learner's actual programme link before assignment. Paper knowledge results and the generic workplace job card are separate from the 20 practical PDFs. Task 5 is deliberately Source required until its controlled reflection source is available. Do not substitute Paint/Autocare reflections or turn a placeholder into an approval. Four approvals cannot release the next block; a reasoned administrator exception may allow later practical work without approving the missing task.

The Panel PDFs' printed example weeks/durations and KSB references are source text only. No individual calendar, OTJ target, KSB applicability or gateway sign-off is inferred. Submission, evidence, referrals, marking and recorded-work exports use existing permissions and version history.

## Private evidence settings

All evidence operations use App/Support/PrivateEvidence. Local defaults remain:

```dotenv
GTA_PRIVATE_EVIDENCE_DISK=local
GTA_LEGACY_EVIDENCE_DISK=local
GTA_PRIVATE_EVIDENCE_ALLOWED_DISKS=local
```

For a reviewed private target disk named private_evidence, set the new-upload disk to that exact configured name and allow both local and private_evidence. Keep the legacy disk as the original file location. Keep every disk referenced by saved file metadata configured and allowed. These variables select existing reviewed disks; they do not create a bucket, set credentials or prove bucket visibility.

New task_evidence rows pin disk in a new nullable column. Other modules pin disk in new JSON file entries. Null/missing disk means the legacy disk, not the current upload disk. Do not bulk-relabel existing files or change the legacy setting without reconciling their actual bytes. No files are automatically copied by this checkpoint.

Integrate the additive migration 2026_11_12_223000_add_disk_to_task_evidence.php against Martin's actual schema; do not replay conflicting create-table migrations. The migration preserves existing evidence rows and leaves disk null. Preserve current APP_KEY, records, hashes, source versions and private file keys.

Run:

```text
php artisan gta:evidence-check
php vendor/bin/phpunit
```

To repeat the named-disk substitute tests on Linux/POSIX, set GTA_TEST_PRIVATE_DISK=1 and run the selected workflows listed in TEST_RESULTS.json. This is a local adapter test, not proof of Cloud persistence. The full suite and HTTP helper must run against disposable databases. Tests now fake the configured evidence disk.

## Actual Cloud integration still required

Use a separate private bucket for evidence and keep public artwork separate. Cloud bucket visibility governs access; do not rely on a per-file visibility option. The service streams through authorised application routes, not public URLs. It refuses the public disk, public visibility/base URLs and public local roots. The local disk's standalone serving is disabled.

The unchanged source lock does not include league/flysystem-aws-s3-v3. If absent from Martin's real app, add the supported S3 adapter in his isolated branch, review the dependency/lock diff and rerun target-engine tests. Do not make an unrelated broad dependency update. gta:evidence-check reports the missing adapter for an S3 target and never mistakes a settings check for a bucket test.

Attach/configure the actual private bucket in Cloud, keep credentials in environment configuration, check the resulting named disk, and verify upload, authorised download, role denial, hash persistence, failed-audit cleanup and redeployment persistence. Actual bucket access, encryption/retention settings, scanning/quarantine and backup/restore remain environment work, not executed claims in this pack.

If existing local files need moving, make a dry-run inventory, copy without deleting originals, verify hashes, reconcile each metadata record and test restricted reads before switching it. Retain the previous location for reconciliation/rollback. Do not drop the disk column or revert to code that hardcodes local after remote files have been written; a safe rollback must preserve the disk metadata and compatible reader as well as the bytes.

Official references checked 12 November 2026:
- https://laravel.com/framework/docs/13.x/filesystem
- https://laravel.com/cloud/docs/resources/object-storage

## Current evidence and remaining scope

346 tests / 3,783 assertions on SQLite; 105 related cases repeated on a named private local disk; 52 HTTP/restart checks. No real S3/Cloud call, target MySQL/MariaDB rerun, browser/phone acceptance or production release. Source PDFs and artwork remain unchanged. Individual plans/KSB sources, ten Panel reflections, completed/signed reviews, production accounts/mail and live integration remain unfinished.
