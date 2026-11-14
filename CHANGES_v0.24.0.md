# GTA Laravel v0.24.0 - Reviewed historical OTJ imports and reconciliation

Added explicit historical occupational training CSV imports with apprentice/programme identity, date, start/end times, excluded minutes, topic and evidence reference. Actual first learning and an explicit loaded programme/version are required. Overnight/future/unfinished, nonpositive, duplicate and overlapping intervals are rejected. Checks include existing noncancelled registers, nonwithdrawn extra training and earlier imports, even where another source still awaits confirmation.

Independent checking requires recorded provider eligibility/evidence and duplicate reconciliation. Application creates immutable source entries plus audit in one transaction; it adds no attendance marks, SMART targets or qualification achievement. Imports do not declare the historical dataset complete or make a new financial/funding eligibility decision.

The ledger and connected subtotals include independently reviewed imported minutes separately. Later overlap or programme/start-source changes mark an import for reconciliation and exclude its minutes, preventing silent double counting. A reasoned/versioned withdrawal retains the original source, minutes and event history. Failed withdrawal/import audit rolls back. Earlier review and management snapshots remain unchanged.

Real source mapping, evidence quality, completeness, actual target/eligibility assurance and controlled corrections still require provider review. A generic CSV interface is not a live PICS/awarding-body connector.

These three batches were tested together at v0.25.0. Executed consolidated evidence and limitations are in TEST_RESULTS.json; no independently tested intermediate release is implied.

No generic restore/in-place editor is provided; exact imported fingerprints remain reserved after withdrawal. Recovery/replacement needs controlled source reconciliation.
