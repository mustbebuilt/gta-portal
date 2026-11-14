# GTA Laravel v0.18.0 - Plan-linked review preparation and frozen revisions

Review drafts now capture the applicable approved delivery-plan hash/reference, occupational target and twelve-block calendar. Blocks overlapping the next seventy days are shown separately from delivered training. Captured snapshots do not change when later plans or draft calendars change.

Added Ready for signatures and reasoned reopening. Readiness requires every saved review section (N/A where appropriate), checked previous actions/training/targets/preparation and linkage to the current approved plan. Missing, withdrawn or superseded plan approvals block readiness. Versions and current assignment scope are checked inside locks. Ready revisions cannot be overwritten through draft save; reopening retains the full frozen revision and reason.

Readiness/reopening events and snapshots are transactional; failed audit preserves the prior state. PDFs show saved status and plan linkage. A warning identifies a saved review whose plan differs from the current approval. No duplicate SMART targets, sent messages, participant signatures, completion record or reset review deadline is created.

Actual participant signing, employer account mapping/controlled sharing, completed-review scheduling and gateway criteria remain separate work. This batch completes the safe plan-to-review preparation connection, not the entire signed-review lifecycle.

Executed consolidated checks and limits: TEST_RESULTS.json at v0.18.0. No intermediate source ZIP is represented as independently tested/deployed.

Consolidated executed evidence: 420 tests / 5,147 assertions; 75 actual HTTP checks; 30-table restart persistence; 277 PHP/compiled Blade checks; 331 retained source PDF hashes.
