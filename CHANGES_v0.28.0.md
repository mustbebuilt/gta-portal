# v0.28.0 — hosted staging startup and first administrator

Controlled hosted staging access and once-only first-administrator setup are implemented and tested locally. Explicit staging flags, valid application key, HTTPS origin/requests, secure encrypted shared sessions and shared cache are required. Production remains blocked. Existing records/accounts are never overwritten; actual Cloud resources and repository integration remain unverified.

- The retained LocalTestOnly middleware now delegates to StagingAccess. Local/testing behaviour is retained; a correctly configured explicitly enabled staging origin can serve HTTPS. HTTP, wrong host/port, disabled/incomplete profiles and production stay closed. Private response headers are retained.
- Staging pages/login show a fictional-data staging notice and account guidance.
- `gta:initial-admin` defaults to read-only preview. `--create` requires a separate explicit setup flag and an empty application. Passwords use hidden terminal prompts or bounded private JSON standard input, never password arguments/output.
- Additive singleton bootstrap-state migration serialises first-account attempts. The new active admin, consumed marker and account audit commit together. Existing users or core domain data, repeated setup, bad inputs and failed audit reject account creation. Email verification/CEO status are not inferred.
- Cloud diagnostic recognises the new staging gate and validates key/cipher compatibility. No deployment, new dependency or actual S3/MySQL access is claimed.
- A local HTTPS startup test verifies private-input CLI setup, actual CSRF/login/shared session/cache behaviour, saved fictional record, restart persistence, disabling the gate and production rejection.

Read CLOUD_START_HERE_MARTIN.md and MARTIN_BATCH_v0.28.0.md; exact executed evidence is in TEST_RESULTS.json. All 531 PDFs and previous work are retained.

Final executed v0.28.0 evidence: 504 tests / 6,083 assertions; 96 loopback HTTP checks; 14 local TLS/CLI startup checks; 41-table restart persistence; 331 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud remains unverified; see CLOUD_START_HERE_MARTIN.md.
