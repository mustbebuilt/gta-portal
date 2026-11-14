# Martin / development AI — v0.28.0 startup integration

Controlled hosted staging access and once-only first-administrator setup are implemented and tested locally. Explicit staging flags, valid application key, HTTPS origin/requests, secure encrypted shared sessions and shared cache are required. Production remains blocked. Existing records/accounts are never overwritten; actual Cloud resources and repository integration remain unverified.

Read CLOUD_START_HERE_MARTIN.md first. Apply `2026_11_13_200000_create_portal_bootstrap_state.php` after earlier migrations in the reviewed integration branch/staging schema. The singleton id=1 row and account_access_events are required by setup. No earlier source/data/dependency changes.

## Hosted gate

Keep the middleware registered. APP_ENV must be staging; GTA_ENABLE_STAGING_PORTAL must be true. APP_DEBUG=false, a valid key for the configured cipher, a canonical HTTPS APP_URL origin, secure/encrypted/HTTP-only session cookies, database/Redis sessions and database/Redis cache are required. Each web request must be recognised as HTTPS and match APP_URL host/port exactly. Local/testing access is unchanged. Production and arbitrary environment names remain blocked even if flags are true.

Use the actual Cloud HTTPS/proxy behaviour and verify it. Source proxy settings are not broadened. The test-only TLS proxy trusts 127.0.0.1 in a temporary config, not in source. Do not trust arbitrary incoming forwarded headers or change APP_ENV to local to bypass checks. /up remains process health only.

This gate checks configuration and request origin; it does not certify database schema/connectivity, an S3 adapter/bucket, identity, private storage continuity or readiness for real records. Satisfy gta:cloud-check and actual staging acceptance separately.

## One-time administrator

GTA_ALLOW_INITIAL_ADMIN defaults false. Enable it only for a confirmed empty isolated staging application and rebuild/redeploy cached configuration. Run gta:initial-admin to preview. To create, use --create, --name and --email, then hidden password/confirmation prompts in a private interactive terminal. If that executor cannot forward hidden input, use --password-stdin --no-interaction with private standard input JSON containing exactly password and password_confirmation. The JSON is bounded at 2,048 bytes; extra/nested/invalid input is rejected. Never put passwords in arguments, shell history, command dashboard text, logs or chat.

Creation requires zero users, apprentices, employers, programmes and groups, plus an unused bootstrap marker. The singleton row is locked in the transaction. It creates one active admin with a hashed password, leaves email_verified_at null, and writes an initial_admin_created/staging_bootstrap audit. It does not make a CEO, send mail, assign apprentices, use a demo password or replace an existing account. Failed audits roll back both account and consumed marker. Repeated attempts refuse even if the setup flag remains on. Disable GTA_ALLOW_INITIAL_ADMIN after setup and redeploy the cached configuration.

If the application already contains users or domain records, use its existing administrator/onboarding flow. This is not password recovery or an account import. Real identity verification, invitations, MFA and mail remain separate.

## Executed scope

The PHPUnit suite exercises gate profiles, origin/HTTPS checks, private headers, pending dependency status, one-time state, existing records, password validation, rollback, secret handling and actual HTTPS CSRF sign-in within the feature harness. tests/cloud_startup.py separately runs a disposable local PHP server behind a verified-certificate TLS proxy with database sessions/cache, actual private-stdin setup, failed login rate limiting, save/reopen/restart, configuration disable and production rejection. It uses SQLite and fictional identities, not Laravel Cloud/MySQL/S3.

Actual cross-instance MySQL row-lock behaviour, database mapping, Cloud cache/scheduler, private-bucket visibility/read/write/redeployment, real first account and browser acceptance are not certified. The S3 adapter is still absent from the supplied unchanged lock; resolve it in the actual repository. No real messages, real data or deployment were used.

Final executed v0.28.0 evidence: 504 tests / 6,083 assertions; 96 loopback HTTP checks; 14 local TLS/CLI startup checks; 41-table restart persistence; 331 PHP/compiled Blade checks; 531 unchanged source PDF hashes. Actual Cloud remains unverified; see CLOUD_START_HERE_MARTIN.md.
