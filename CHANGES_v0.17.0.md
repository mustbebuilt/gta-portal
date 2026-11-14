# GTA Laravel v0.17.0 - Independent provider delivery-plan approval

Added controlled individual plan, primary KSB mapping, qualification requirements and employer release reference fields, plus an explicit occupational target and planning basis. No reference is supplied by a task approval percentage. Approval requires twelve complete dated blocks, matching planned-hour totals, recorded first learning/employer, explicit reviewer checks and an independent administrator/CEO who did not prepare the draft.

Approval pins the exact draft, programme/profile identity, source catalogue hash, actor/time and controlled references. A current approved Autocare RPL assessment can be referenced by its binding; absent, incomplete, withdrawn or changed approval cannot be applied. Other programmes cannot borrow the Autocare assessment. No automatic RPL price/hours/date calculation or funding approval is introduced. The existing source reconciliation issues remain.

Draft amendments keep the current approved snapshot until replacement approval or reasoned withdrawal. Programme/profile changes invalidate applicability; source/profile reconciliation clears the other stale draft section before approval can proceed. Historical snapshots remain. Withdrawal is available even after a programme source becomes unavailable. Repeated approval of an unchanged draft is rejected.

A separate version/source-bound deadline preview fills only unset Draft/Referred task dates: Task 1 and reflection at block end; Tasks 2-4 at quarter/half/three-quarter points. Existing dates and submitted/approved work are preserved. Every applied date increments the task version and is audited. Stale previews are rejected; audit failure rolls back all changes. Approved-plan PDF reads the approved snapshot, never unapproved amendments.

This is provider operational approval. It is not a signed apprenticeship agreement, verified primary KSB achievement, funding eligibility decision or approval of a financial reduction. Reviewers must inspect the referenced controlled documents and the individual target basis.

Executed consolidated checks and limits: TEST_RESULTS.json at v0.18.0. No intermediate source ZIP is represented as independently tested/deployed.
