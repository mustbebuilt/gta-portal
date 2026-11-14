# Martin / development AI: integrating the next four batches (v0.15.0-v0.18.0)

Read CHANGES_v0.15.0.md through CHANGES_v0.18.0.md and TEST_RESULTS.json. These are four implementation batches delivered as one tested local checkpoint. Do not copy an older final-pack controller over this version.

## Installation and migration

Use an isolated branch and the main integration guide; PHP 8.4.1+ / Laravel 13. No Composer dependency was added or changed. Apply the additive 2026_11_13_120000_create_individual_delivery_plans.php migration. It creates individual_delivery_plans and adds progress_review_drafts.state default Draft. Existing reviews are not marked complete, signed or approved. Earlier account/reminder/private-evidence migrations remain required, including v0.13 fresh sign-in rules. The local-only production guard stays in place.

Keep all old resource/task-pdfs files; 200 final-release filenames are added. Do not rewrite existing LearningTask.template, answers, evidence, submissions, status or dates. New normal allocations receive the final release. Existing untouched Panel source-required placeholders can still use the explicit guarded Load reflection sources action; other existing tasks remain frozen on their source version. The final pack is 50 tasks per programme, five in each Block 1-10, plus planning-only Blocks 11-12 elsewhere.

## Calendar and provider plan

Open Apprentice profile > Individual delivery plan and block calendar. Administrators/CEO prepare twelve block rows and controlled source references. After each save reopen/current page version is used for the next form. An independent administrator/CEO must approve: any recorded draft author is excluded as reviewer. Calendar dates/hours are entered explicitly. EPA assessment window hours must be zero; planned hours must total the explicit occupational target. No statutory minimum, funding eligibility or financial reduction is asserted by this arithmetic.

Approval pins an immutable snapshot, including current programme/profile identity and source hash. Draft amendments do not replace its active view. Changes to programme/version, first learning or employer invalidate applicability. On reconciliation, saving one draft section clears the other stale section, requiring both to be reviewed again. A source catalogue release does not silently rewrite an already approved plan. Referenced documents must actually be reviewed; storing an ID/reference is not evidence of their contents or verified signatures. RPL binding is valid only for current approved Autocare assessment; other standards need their own controlled assessment sources.

Task dates need the separate preview/apply action. It fills only unset Draft/Referred task dates, preserves existing deadlines and submitted/approved work, checks the row version, approved snapshot hash and exact task-version binding, and audits every date. Approval/withdrawal/application are atomic on audit failure. Approval withdrawal retains all old snapshots and already applied deadlines; those require a separate reviewed amendment if inappropriate.

## Review preparation

Save a review against the current approved provider plan. It captures the plan and next seventy-day block overlap at save time. Complete every section, using N/A when genuinely not applicable, and check previous actions, training, targets and preparation. Ready for signatures freezes that revision; ordinary draft save cannot overwrite it. Reopening needs a reason and preserves the frozen snapshot/history. A superseded or withdrawn plan requires a new review save before readiness. The current scope is checked inside the transaction.

This does not sign, send, complete or share a review. Employer identity/account mapping, participant signatures, completed history and review-cycle scheduling remain unfinished. The protected PDF remains clearly a draft/readiness record. Do not label Ready for signatures as a completed ten-week review.

## Validation and deployment boundary

Run PHPUnit, tests/http_persistence.py and tests/static_checks.py. The HTTP test uses a disposable fictional database/storage root and an independent CEO session; all thirty connected module tables are compared after PHP restart. Generated plan/review PDFs and long paper assessment title/feedback output were rendered/inspected locally. PDF metadata checks are not all-viewer/printed overflow acceptance.

No actual MySQL/Cloud deployment, employer signing, live mail/invitations/MFA, S3 adapter/bucket/backup or browser/mobile/keyboard acceptance has been performed. Martin must map actual schema/permissions and test staging/release/rollback before production. The source records controlled references and a provider operational decision; formal individual plan/KSB applicability, remaining source conflicts and financial/signature approval are not invented.
