# Martin / development AI - v0.13.0 account batch

Start with START_HERE_MARTIN.txt, CHANGES_v0.13.0.md and TEST_RESULTS.json. This batch adds signed-in password changes, account-session invalidation and a local recovery test flow. It retains the earlier marking, task, privacy and storage work. It is not a production identity rollout.

## Upgrade consequence: existing sessions must sign in again

Review the additive 2026_11_13_100000_add_account_access_controls.php migration against your real users table and audit schema. It adds users.auth_version (default 1) and account_access_events. It preserves existing passwords, roles and verification values.

SessionController binds the current auth_version after successful sign-in. ActiveAccount reads the current active user and checks the bound version on every protected request. Sessions without a version are deliberately not adopted. Plan the maintenance/sign-in handoff, tell users to save work, and test cutover with fictional sessions first. Do not remove this check or fake a version to keep old sessions alive.

Integrate with the actual authentication/session/MFA stack rather than layering a competing account system over it. The default local PHP session driver, existing APP_KEY, auth user IDs and file keys are not replaced. Do not regenerate an existing APP_KEY. This change requires coherent middleware, login and credential-update code plus its migration.

## Interfaces

| Interface | Behaviour |
| --- | --- |
| GET/POST /account/password | Authenticated own-account password change; current password required; signs out afterwards |
| GET/POST /forgot-password | Generic recovery request; no account/token disclosure |
| GET /reset-password/{token}, POST /reset-password | Local sink-gated broker recovery; hashed, expiring, single-use tokens |
| App/Support/AccountAccess | Invalidation, token clearing, remember rotation, emergency-override closure and audit |
| Staff directory sensitive changes | Rotate auth version; changed email loses verification and resets old/new token state |
| gta:local-password | Existing local-only fictional command, now using the same invalidation/audit service |

The broker configuration remains config/auth.php: database token storage, 60-minute expiry and 60-second broker resend throttle. Reset request/change/reset routes also have attempt limits. Recovery request/reset processing has a minimum Timebox delay; a production timing-uniformity assessment has not been performed.

Local new-password rules require at least 12 characters, confirmation, and an upper bound compatible with the existing bcrypt setup. Review the final password policy with the actual identity provider; do not claim this is a complete production security policy. Existing passwords are not silently rewritten.

## Recovery stays off by default

```dotenv
GTA_PASSWORD_RECOVERY_ENABLED=false
```

Even setting this true only enables recovery when APP_ENV is local/testing AND the configured mailer is exactly array. It never activates SMTP, log-mail delivery or production recovery. Do not put a public/live environment into local mode to bypass the guard. Tests use fictional verified accounts and Notification fakes/internal array transport; no external messages were sent.

Only a currently active account with a previously verified email may request/reset. Case-only ambiguous matches are refused. Do not bulk-mark existing email addresses as verified merely to make recovery work. Reconcile real identities and your existing verified-address process first. No verification/onboarding invitation workflow or MFA was added here.

Links use trusted APP_URL rather than an incoming Host header. Set and review the actual HTTPS application URL when integrating. Raw tokens are not put in account audit events or validation old-input flash data. The array sink is not an inbox for real users, and no reset URL is exposed in the UI or administrator directory.

Before live recovery, implement the actual authorised delivery/verification/MFA policy, review broker/user-provider mappings and authentication lifecycle listeners, test throttles/CSRF/token replay/deactivation and ensure the same session invalidation is used by every credential-changing path. Do not just remove the sink gate and label production recovery complete.

## Audit and rollback

Password changes/resets and staff access changes rotate the security version and remember token, remove reset tokens, end that account’s outstanding CEO overrides and audit the actor/origin/event. Names, plaintext passwords, reset tokens and credential hashes are not copied into the new audit records. Audit failure tests prove rollback of credentials, version and token consumption.

Keep compatible login/middleware/version handling together when rolling back. Do not drop the audit/version schema as a generic rollback after real account events exist. Restore from the reviewed backup/cutover plan; a code-only rollback must not revive previously invalidated sessions.

## Executed evidence

389 tests / 4,023 assertions on SQLite, including 23 new account cases; 63 actual HTTP checks with 29-table restart proof. Two independently signed-in HTTP clients prove old-session revocation. Updated credentials/security versions survive restart. The HTTP helper isolates both storage and database per run. TestCase actingAs represents a fresh sign-in and sets the same version binding; stale/missing binding tests use the real middleware.

No target MySQL/MariaDB rerun, browser/phone/keyboard/physical-print acceptance, penetration test, external delivery or Cloud deployment was performed. Existing source/plan/signature/storage gaps remain in BATCH_QUEUE.json.

References checked 13 November 2026:
- https://laravel.com/framework/docs/passwords
- https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
