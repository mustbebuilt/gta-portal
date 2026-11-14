# GTA Laravel v0.22.0 - Approved-calendar Careers prompts and opt-in in-portal scheduling

Added current-plan Block 10/11 prompts when each approved block start has been reached. Prompts use the current active Careers adviser and are bound to apprentice, adviser, block/date and approved plan hash. Missing/invalid advisers are checks for administrators. Later draft dates do not replace the approved trigger; withdrawal/profile/source applicability changes hide invalid prompts.

Administrators can explicitly record due in-portal prompts. The assigned active adviser can mark a still-current prompt seen. Reassignment and plan changes invalidate stale acknowledgements; repeats are deduplicated and the audit is atomic. Seen does not create a Careers discussion, agreed action or completed advice record. Employer/tutor access to private Careers remains restricted.

Added gta:portal-reminders: default preview is read-only; --record writes existing marking reminders and Careers prompts plus one combined run audit in a single transaction. A failed combined run audit rolls back both prompt types. No email, SMS or other external message is sent.

An opt-in daily 09:00 schedule in the application timezone has overlap protection. GTA_SCHEDULE_PORTAL_REMINDERS is false by default. Infrastructure is not installed/activated here. The management marking page shows configured on/off and the last recorded combined run, without claiming a configured flag proves actual execution. Actual multi-server cache locks, scheduler infrastructure and production acceptance remain Martin staging work.

These four batches were tested together at v0.22.0. See TEST_RESULTS.json for executed checks and limits; no intermediate standalone release is claimed.

Consolidated executed evidence: 433 tests / 5,324 assertions; 82 actual HTTP checks; 33-table restart persistence; 293 PHP/compiled Blade checks; 531 source PDF hashes.
