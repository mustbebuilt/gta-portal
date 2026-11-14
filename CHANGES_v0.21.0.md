# GTA Laravel v0.21.0 - Source-linked SMART action proposals

Added an assigned-tutor/manager proposal worklist showing referred tasks, current tutor feedback and known unfinished task deadlines in the next seventy days. It opens an existing action instead of duplicating it. Other dated work stays in its original task.

A correction proposal prefills the original referred task/reference, linked-task completion method and sensible draft text. A task deadline must exist first. Viewing/preparing creates no action. Support, realistic workload and agreement remain explicit user confirmations; they are not preselected.

The save transaction rechecks current assignment, task ownership/status, the exact task/decision/version binding and duplicate linked actions under locks. Changed sources are rejected. A confirmed proposal creates one linked SMART action retaining the source deadline and approval; it does not create evidence, submit work or approve the task. Proposal source binding is retained in action history. Audit failure rolls back the new action.

These four batches were tested together at v0.22.0. See TEST_RESULTS.json for executed checks and limits; no intermediate standalone release is claimed.
