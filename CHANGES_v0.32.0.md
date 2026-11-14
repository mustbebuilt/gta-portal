# v0.32.0 — protected evidence copy planning

- Metadata-only preview deduplicates known source objects and binds references, configured disk identities, hashes/sizes and the bounded copy limit.
- Exact proposal hash and controlled reference required to persist a plan in staging. Manifest and technical plan audit are saved atomically.
- Full file locators remain in the protected server database. Console output contains counts, plan ID, hashes and state, excluding names/paths/private notes.
- Each destination uses a plan-specific staging namespace. No file I/O, relink or cutover occurs while recording/inspecting a plan.

Delivered together with v0.33.0. Read MARTIN_BATCH_v0.33.0.md and current TEST_RESULTS.json for executed checks and limits.

Final executed v0.33.0 evidence: SQLite: 547 tests / 6,339 assertions; MariaDB 11.8.6: 547 tests / 6,865 assertions; 98 HTTP/CLI checks per engine with 41-table/file-hash restart preservation; 10 separate-process copy CLI checks; 14 actual SDK wire checks (45 requests); 7 clean no-dev build checks; 346 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud/source-target rehearsal, cutover and restore remain unverified.
