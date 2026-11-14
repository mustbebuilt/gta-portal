# GTA Laravel v0.13.0 - account access and password recovery preparation

## Implemented

- Added Change password for a signed-in user’s own account. Current password, confirmation and new-password validation are required; posted user IDs/roles cannot target or grant another account. Successful changes require fresh sign-in.
- Added an additive users.auth_version column and per-request version binding. Existing sessions without a binding must sign in again. Old sessions cannot silently resume after a password change, reset, deactivation/reactivation or sensitive staff-account change.
- Added a Laravel database password-broker recovery flow, disabled by default. It can run only in local/testing with the array mail sink; SMTP/log mailers and production do not enable it. Only active accounts with an existing verified email qualify. Generic request responses, throttles, hashed expiring single-use tokens and trusted APP_URL link construction are tested.
- Added account_access_events without password, token or hash contents. Account changes rotate remember credentials, remove reset tokens and end outstanding CEO emergency sessions for the affected account. Audit failure rolls back password, version and token changes.
- Staff email changes clear verification and old/new reset tokens. The local fictional-password command uses the same invalidation service. No new verification, public registration, invitations or MFA flow was invented.
- Updated test actingAs to model the same security-version binding as a fresh real sign-in. Explicit stale/missing-session tests and actual two-browser HTTP checks verify the production middleware path; there is no test-only middleware bypass.
- HTTP proof now has a per-run temporary storage directory, so sessions, file cache/rate limits and evidence are isolated between runs. Actual HTTP verifies disabled recovery, CSRF protection, password change, second-session invalidation, new-password sign-in and persistence after restart.

## Evidence

389 tests / 4,023 assertions on SQLite; 63 actual HTTP checks; 29 connected module tables persist after restart; 269 PHP/compiled Blade syntax checks; 1 JavaScript check; 121 unchanged source PDFs and 2 original GTA assets. No new dependency.

## Boundaries

Recovery is preparation tested against an internal sink, not live email delivery. Production identity verification, invitations, MFA, sender/transport configuration and target hosting/engine/security acceptance remain separate. New local default passwords use at least 12 characters; review the final policy with the actual identity system. Formal plans, signatures and Panel reflection sources remain unfinished.
