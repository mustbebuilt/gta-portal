# v0.33.0 — verified exclusive staging copies

v0.32.0 adds exact-preview, protected evidence copy plans; v0.33.0 adds explicit once-only staging execution with exclusive destination creation, source SHA-256/size validation, destination readback, final verification and technical audit. Original files, application references and retained snapshots are not changed. No storage cutover or production execution is enabled.

- Copy remains disabled by default. Requires APP_ENV=staging, GTA_ENABLE_EVIDENCE_COPY=true, exact plan hash/version and unchanged source references/configuration.
- Transactional once-only claim records Running before byte transfer. Each object has a durable intent before writing. There is no long SQL transaction over external I/O.
- Source bytes are validated in a bounded temporary spool before writing. Local destinations use exclusive creation and 0600 files; S3 uses conditional PutObject If-None-Match: * with no ACL/grant headers.
- Existing destinations are refused, including identical bytes. Immediate and final hash/size verification plus a final metadata/configuration binding check precede the verified-copy state.
- Failed/partial/interrupted copies are retained for private reconciliation. No automatic retry, adoption, deletion or source/link change.
- One additive migration adds protected copy plans and console-origin events. No new dependency; all 531 source PDFs retained unchanged.

Read MARTIN_BATCH_v0.33.0.md. Verified staging copies do not approve application cutover or certify a complete backup.

Final executed v0.33.0 evidence: SQLite: 547 tests / 6,339 assertions; MariaDB 11.8.6: 547 tests / 6,865 assertions; 98 HTTP/CLI checks per engine with 41-table/file-hash restart preservation; 10 separate-process copy CLI checks; 14 actual SDK wire checks (45 requests); 7 clean no-dev build checks; 346 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud/source-target rehearsal, cutover and restore remain unverified.
