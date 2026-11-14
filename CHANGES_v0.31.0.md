# v0.31.0 — private evidence migration inventory

A read-only migration inventory now covers registered private uploads and retained file metadata/links across eight portal source families. Default mode reads database references/configuration only. Explicit verification checks each logical disk/path once against SHA-256 and declared size, flags problems and re-scans for drift. It does not move, relink, delete or approve migration of evidence.

- Added `gta:evidence-inventory`, with optional --verify-files and --details. Summary output excludes filenames, paths, file hashes, private notes and document contents; optional details add internal record locators/issue codes only.
- Covers task evidence, learning-task submission links, training topics/current and submitted evidence, qualifications, support-plan files/snapshots, staff-development payload/history, extra training and signed-review records/snapshots. Withdrawn/older retained references stay in scope.
- Repeated references to the same configured disk/path are grouped. Different hash/size declarations, missing/unapproved locations, malformed metadata/schema, wrong task owner, missing evidence rows and historical hash disagreement become checks.
- Explicit verification streams bytes through the existing private storage resolver and compares SHA-256 AND size. Missing/changed/unreadable files are not repaired. Legacy references keep their configured old disk; changing new-upload defaults does not repoint them.
- No database transaction is held while reading files. A second metadata/configuration scan flags detected source drift. This is a live inventory, not an atomic backup, completeness certificate, signature decision or migration permission.
- No directory/bucket listing or unregistered-file discovery. Disk aliases of the same physical location are not silently merged. No files/records/audits are written or relinked by the inventory.

No new migration/dependency. All prior source records and 531 source PDFs retained. Read MARTIN_BATCH_v0.31.0.md and TEST_RESULTS.json; actual Cloud mapping/storage and controlled file migration remain outstanding.

Final executed v0.31.0 evidence: SQLite: 530 tests / 6,243 assertions; MariaDB 11.8.6: 530 tests / 6,752 assertions; 98 HTTP/CLI checks per engine with 41-table/file-hash restart preservation; 12 actual SDK wire checks; 7 clean no-dev build checks; 341 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud/storage migration remains unverified and unauthorised by any inventory result.
