# Martin / development AI — v0.26.0 integration addendum

Externally signed progress-review records, independent manager checks and actual-meeting-based ten-week tracking are implemented/tested locally. Pending copies do not reset dates. The latest active checked meeting sets the next reference at meeting + 70 days. Electronic signing, employer portal access, complete historical compliance and live deployment remain separate.

## Integration and local acceptance

Use this alongside START_HERE_MARTIN.txt and the earlier addenda. Back up database/private files; integrate in an isolated branch and staging environment. Apply `2026_11_13_180000_create_signed_review_records.php` with all prior migrations. New tables are signed_review_records and signed_review_events. No dependency change or previous-data rewrite. Retain all 531 source PDFs and old source versions.

Use the configured PrivateEvidence private disk; every uploaded source pins its disk, path and SHA-256. New matchesHash reads the actual private file on confirmation/download. Test missing/tampered files, disk changes, denied users and storage continuity/backup on the real target; local tests use filesystem adapters. Existing environment/local-only guard remains. Actual MySQL/MariaDB, Cloud, S3 and concurrent production acceptance were not run.

## Operator flow

1. Prepare a review against the current provider-approved plan. Record its actual meeting date, participant names and complete fields; freeze Ready for signatures.
2. Download the unsigned frozen source. Obtain all three signatures/dates outside the portal and retain every page. The native export itself contains no signatures and remains unsigned after an external copy is checked.
3. Current assigned main tutor, mentor or manager opens Signed copies, checks the actual signed PDF and records matching signatory names/dates, source reference and identity/role-check reference. PDF MIME and extension required, maximum 15 MB. Signature dates must be on/after the actual meeting and not future. It remains pending.
4. A different current administrator/CEO opens and reads the actual file and independently attests matching frozen content, identities/roles and all three signatures/dates. Checkboxes start unchecked. Version, plan/hash, participant binding, duplicate-meeting and actual-byte checks must pass atomically with audit.
5. The latest active independently checked meeting supplies next tracked reference = actual meeting + 70 days. Upload/check/signature timestamps do not move this date. Older checked backfills do not displace a newer meeting. Missing complete history remains explicit; no cohort compliance percentage is inferred.
6. To correct, manager withdraws with a reason. Original file, snapshot and history remain; a past management pack stays fixed. Withdraw any pending/checked copy before reopening the parent frozen review. No generic source edit, replacement, delete or restore is provided.

The parent review keeps its frozen Ready for signatures state/content/version; checked external source state is separate. Current staff scoping applies: apprentice, employer, Functional Skills tutor and previously assigned staff do not gain signed-file access. Identity references/private file content are excluded from management packs; only source pointers/version/status/dates are captured.

## Boundaries requiring care

This is manual provider verification of external signing, not electronic signing, cryptographic signature validation, participant authentication, automatic recipient mapping or authorised employer sharing. Automated fixtures are fictional generated unsigned PDFs; tests prove workflow and persistence, not genuine signatures. Humans must inspect real sources honestly.

Recording/confirmation requires a known first-learning date and CURRENT applicable provider-approved plan plus frozen source binding. Older records under different plans/participants need controlled historical reconciliation. Do not resave or invent historic data merely to force a match. Plan/profile changes after confirmed recording do not silently erase a historical meeting; explicit withdrawal records corrections.

Cycle views use saved verification and snapshot integrity rather than streaming every stored PDF on each page. Missing/changed bytes fail download and confirmation; actual storage monitoring/backups still need acceptance. Any corrupt checked snapshot makes due tracking uncertain instead of silently choosing another date.

External email/reminder delivery, completed-review reminder scheduling, full historical migration/compliance, participant portal/e-signing, training-plan/HR signing and production rollout remain separate. Existing marking/Careers scheduling stays disabled by default. No real data, external messages or deployment used.

## Executed evidence

466 automated tests / 5,732 assertions; 93 actual loopback HTTP checks; 39-table restart persistence; 317 PHP/compiled Blade checks; 531 unchanged source PDFs. A fictional three-page unsigned review was rendered and its first/signature pages inspected.

See TEST_RESULTS.json and evidence/v0.26.0-* for exact checks. Browser/mobile/keyboard acceptance, long production load, MySQL/Cloud and genuine signed-source acceptance are not claimed.
