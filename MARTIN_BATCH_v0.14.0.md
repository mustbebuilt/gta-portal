# Integrating v0.14.0: Panel reflections

Use the complete source in an isolated branch and follow the main integration guide. This is a local development checkpoint. No production release has been performed.

1. Keep the coherent existing migrations and account-version middleware. This batch adds no database migration or Composer dependency.
2. Copy the updated Panel catalogue, task list, source receipt, 10 new original PDFs and legacy placeholder hash map with the importer, controller, routes, view and tests. Do not reallocate existing apprentice task sets or globally replace their saved templates.
3. For a newly allocated ST0403 v1.3 programme, all 50 task records start available as drafts. The paper test questions remain outside the supplied pack; Task 1 records actual tutor results only. Task 2 uses the existing shared job card.
4. For existing allocations, an administrator opens Training blocks and tasks, confirms source applicability, then selects Load reflection sources. POST /apprentices/{id}/learning/reflection-sources requires CSRF and confirmed=1. The server resolves the current linked active programme/version and locks apprentice, programme, tasks and controls.
5. Only pristine known source-pending rows are replaced: version 1, Source required, Panel saved catalogue, known reference/hash, matching block and Task 5, no answers/submissions/submission date/files/task events. IDs and agreed deadlines stay intact. Any skipped record needs individual investigation; do not remove work/history to force an upgrade.
6. The task becomes Draft/version 2. Audit records old/new/PDF hashes and actor/time in existing learning events/control history. A failed audit rolls back the whole action; repeated requests are idempotent. Block-control version advances, so stale sequence forms cannot overwrite its new history.
7. The Panel source allows unassessed scores to be blank with an explanation. The practice/support answer is required if any score is blank; entered scores must be 1-10. The server cannot judge explanation quality: tutors must check each omitted area before deciding. Do not imply blank or high scores establish competence.
8. Apprentice fields and tutor review fields are separated server-side. Typed mentor/trainer names do not constitute verified participant signatures. Whole-task tutor decisions and sequencing retain the existing workflow.

Verification: run the full PHPUnit suite, tests/http_persistence.py and tests/static_checks.py using PHP 8.4.1+. The HTTP proof reconstructs one legacy placeholder solely in a disposable fictional database, then exercises the protected real upgrade endpoint and persistence after restart. Programme integration tests cover skipped work/history/files, stale versions, permissions, programme mismatch, repeated upgrades and audit rollback.

Development-only reproduction: tools/import_programme_tasks.py panel PRACTICAL.zip --impact-archive IMPACT.zip requires pypdf and PyMuPDF. Runtime does not. Original pack bytes remain unchanged and receipts pin their hashes. Confirm formal plan/KSB applicability independently; supplied source week labels do not set actual dates.

Before live integration, Martin must reconcile his actual schema/permissions, test the target engine and Cloud storage, review the UI on browser/phone and approve staging/release/rollback. Recovery remains disabled by default and only supports the local mail sink when enabled. No real users or external messages were used.
