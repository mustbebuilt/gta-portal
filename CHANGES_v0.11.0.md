# GTA Laravel v0.11.0 - Panel tasks and private evidence configuration

## Panel task workflow

Imported 20 unchanged Panel practical PDFs into the existing task workflow, with their own field names, printed technical labels, seven knowledge questions, assessor records and source hashes. The configured catalogue binding is ST0403 v1.3. The 20 practical tasks, ten paper-result records and ten shared workplace job cards provide 40 usable records across Blocks 1-10.

The source pack contains no Panel learning reflections. Ten Task 5 slots are explicitly Source required, not invented questionnaires or completed tasks. They cannot receive work, evidence, approval or a recorded-work PDF. Four task approvals do not complete a block. An administrator can record a reasoned exception to release a later block while the missing source is resolved. Formal KSB/plan sign-off stays separate.

## Configurable private evidence

Every evidence upload/download/existence/failed-audit cleanup caller now uses the shared PrivateEvidence service. New metadata pins the file's disk; old metadata without a disk continues to use the legacy disk. Changing the current upload disk never moves older files or searches another disk for missing bytes.

Added config/evidence.php, three GTA_PRIVATE/GTA_LEGACY environment settings and a nullable task_evidence.disk column. Qualification, support, staff, topic and extra-training JSON file metadata gets a disk key for new files only. Existing source snapshots/history are retained.

Public disk names, public visibility/base URLs and public local roots are rejected. Local evidence uses private visibility; S3/Cloud bucket visibility must be reviewed in staging. S3 writes add no per-object visibility override. The read-only gta:evidence-check command checks disk settings and optional S3-adapter availability without printing secrets or contacting the bucket. The S3 adapter and a live Cloud connection are not included or tested.

## Validation

346 tests / 3,783 assertions on SQLite; 105 related cases / 1,669 assertions repeated with a non-default private local disk; 52 actual HTTP/restart checks. The repeated cases are a subset, not 105 additional application scenarios. Separate final configuration tests also pass. Native long-answer exports retain all 1,200 repeated lines and their final marker. All 121 source PDFs and two GTA artwork files retain their hashes. See TEST_RESULTS.json for scope and exact evidence.

Added tests for Panel missing-source gates, all Panel task pages/PDFs, draft/referral/resubmission/approval, permissions, disk switching, legacy file/JSON reads, wrong-disk refusal, public/missing configuration, failed-audit cleanup, configuration diagnostics and additive migration preservation. Existing file-workflow tests now fake/assert the configured disk.

No live migration, Cloud deployment, real records or external messages. Formal plans/KSB mapping, missing Panel reflections, completed/signed reviews and production identity/delivery remain separate.
