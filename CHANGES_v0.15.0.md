# GTA Laravel v0.15.0 - Final four-programme task release

Integrated the supplied final 200-task release, 50 tasks per programme and five tasks per Block 1-10. Added 200 release-specific PDF backups; all 131 prior PDFs remain available to old saved task schemas. Original task IDs, whole-task marking, role checks, revision locks and extra-evidence access are retained.

Merged the two narrative improvements into the current controller/view instead of installing an older overlay: paper test titles are multiline; test titles and tutor feedback no longer have fixed narrative character caps. Typed marks, dates, score ranges and transport/file limits remain. Native PDF exports flow long narratives across pages.

Reconciled two source details: Panel permits unassessed scores with an explanation in needs_practice, matching its original PDF; final Autocare ADAS instructions now correctly say the final PDF includes the released questions. Older saved schemas retain their own original instructions and rules. The ten known Panel placeholders still use the explicit guarded upgrade action. Other saved task records are never rewritten.

A repeatable development-only importer verifies the release member hashes and reproduces the active catalogue/task files exactly. The final receipt pins all 200 PDF hashes. Verification checks all 7,998 PDF text fields for multiline flags and absence of fixed character caps. PDF boxes do not expand; continuation pages remain necessary for visible printed overflow. No cross-viewer acceptance is claimed.

Executed consolidated checks and limits: TEST_RESULTS.json at v0.18.0. No intermediate source ZIP is represented as independently tested/deployed.
