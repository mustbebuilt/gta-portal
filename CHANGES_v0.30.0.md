# v0.30.0 — native database compatibility

Native database compatibility is now checked against MariaDB 11.8.6 through PDO MySQL as well as SQLite. A real fresh-install index-length blocker is corrected, with a data-preserving forward normalisation for older SQLite checkpoints. Native audit-failure, persistence and initial-administrator concurrency tests are included. Actual Cloud/Oracle MySQL/schema acceptance remains separate.

- Fresh signed-review tables use explicit short index name review_parent_withdrawn_idx. The old automatic composite name exceeded the server identifier limit.
- Additive `2026_11_13_210000_normalize_signed_review_index.php` renames the older SQLite index where present, without rewriting source rows. Fresh installs already use the short name; repeat application is harmless. Normalisation is forward-only: rollback never recreates an invalid long name.
- Test audit-failure fixtures now use native SQLite RAISE or MySQL/MariaDB SIGNAL errors. Native tests get a fresh private schema per database test so implicit DDL commits do not invalidate artificial enclosing transactions. No failure cases are skipped.
- Default framework tests refuse anything except testing/in-memory SQLite. Native resets require a private matching local runner marker/token, random test schema/user and loopback endpoint. Runner owns its temporary daemon/data and removes them at exit; no existing/Cloud database is accepted.
- Actual HTTP persistence now runs against the same isolated native engine using an independent Python connector. DATE comparisons assert original-source preservation rather than a SQLite-specific timestamp representation. Failure reports retain completed check names, and fixture-file cleanup stays inside its temporary storage.
- A case-sensitive legacy email fixture tests ambiguous-account recovery without changing the application’s normal unique-email schema/rules.
- Two PHP workers released from a common barrier verify native once-only administrator creation and retained account/audit/marker counts.

No dependency versions or task PDFs changed. Read MARTIN_BATCH_v0.30.0.md and TEST_RESULTS.json. MariaDB testing is not actual Oracle MySQL or Laravel Cloud acceptance.

Final executed v0.30.0 evidence: SQLite: 518 tests / 6,173 assertions; MariaDB 11.8.6: 518 tests / 6,670 assertions; 96 actual HTTP checks on each engine; 41-table restart persistence on each; native two-process first-admin race passed; 7 clean no-dev build checks; 339 PHP/compiled Blade checks; 531 unchanged PDF hashes. Exact managed Cloud/Oracle MySQL remains unverified.
