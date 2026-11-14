# Martin — get the GTA portal running on Laravel Cloud

Target confirmed by Jon on 13 November 2026. Current checkpoint: v0.33.0.

The ZIP contains executable Laravel controllers, Blade forms, database migrations, private file workflows and tests. It is a separate application, not an installed update to your current repository. The local workflow and installed S3 adapter are tested; your Cloud application has not been accessed. Start with a separate Cloud staging environment and fictional records. Keep the existing portal/database available.

## Current two batches: plans and verified staging copies

Read MARTIN_BATCH_v0.33.0.md and TEST_RESULTS.json first. v0.32.0 protects an exact-preview copy manifest; v0.33.0 supplies once-only, exclusive, verified staging copies. Review the one additive plans/events migration. GTA_ENABLE_EVIDENCE_COPY stays false by default; execution requires staging and exact plan hash/version. Existing application references/defaults and originals remain unchanged, even after a successful rehearsal. Failed/partial outputs are retained for private reconciliation; no automatic retry, deletion, relink or cutover is provided. Earlier inventory-only instructions below describe the v0.31.0 scope.

## What we need to finish the integration

Send the repository URL/branch (or current source ZIP), Laravel/PHP versions, Cloud application/environment URL and whether its database is new or already populated. Confirm database engine and the actual private bucket/disk name. Do not send passwords, API tokens, real learner data or a real .env in chat. Those belong in your authorised environment.

With the actual repository available, the next implementation should be a reviewed integration branch, an explicit schema/auth mapping, locked dependencies and a staging deployment smoke test. Additional simulation-only features should not take priority over proving this installation.

## Run the supplied check

```bash
php artisan gta:cloud-check
php artisan gta:cloud-check --database
```

The first command only examines configuration/runtime and never connects to the database. The second additionally performs read-only target MySQL/MariaDB connection/migration-name checks. Both output JSON, do not print secrets and exit nonzero for blockers. They never install packages, create accounts, migrate, send mail or deploy. A passing configuration check would still not establish storage, schema/data correctness or user acceptance.

The setup check reports configuration/resource blockers independently from the new staging gate:

| Area | Concrete work before hosted use |
| --- | --- |
| Web access | `App\Http\Middleware\LocalTestOnly` now permits correctly configured, explicitly enabled staging HTTPS on the exact APP_URL host/port. Keep the middleware; set APP_ENV=staging and GTA_ENABLE_STAGING_PORTAL=true after preparing the HTTPS/key/session/cache profile. Production remains blocked. Do not use APP_ENV=local as a workaround. |
| Private evidence | The local disk is for local tests. Attach private durable object storage; the supplied lock now includes the S3 adapter and SDK without upgrades to existing packages. Restore with composer install, integrate its requirement against the actual repository lock, and test private uploads/downloads after redeployment. Cloud's injected disk name must match the evidence allowlist. |
| Database | Local MariaDB 11.8.6/InnoDB via PDO MySQL is now tested alongside SQLite. Oracle MySQL and the actual managed Cloud engine/schema remain unverified. Confirm engine/version/collation/TLS/extensions and map existing tables/IDs before migrations. |
| Sessions/cache | Use shared database/Redis sessions/cache with secure encrypted cookies. File-backed local sessions/cache are not the intended multi-instance setup. |
| Administrator | Use the implemented `gta:initial-admin` preview and explicit once-only creation on a confirmed empty staging application; instructions below. Existing accounts/domain records refuse bootstrap. Do not import demo accounts/passwords. |

`deploy/cloud.env.example` is a value-free staging reference, not a finished production .env. Preserve the existing APP_KEY and actual resource-injected credentials. No credentials or production dataset are included in the ZIP.

## Enable and initialise the isolated staging site

1. In the reviewed staging environment set APP_ENV=staging, APP_DEBUG=false, a valid existing environment key, canonical HTTPS APP_URL, SESSION_DRIVER=database (or provisioned Redis), SESSION_SECURE_COOKIE=true, SESSION_ENCRYPT=true and CACHE_STORE=database (or provisioned Redis). HTTP-only cookies retain their default true. Attach/migrate the matching resources first. Configure private durable storage before evidence acceptance.
2. Set GTA_ENABLE_STAGING_PORTAL=true and rebuild/redeploy cached configuration. HTTPS requests must match APP_URL exactly, including any explicit port. Other hosts, HTTP and production remain blocked. Verify HTTPS/proxy handling on the real Cloud environment; source proxy trust has not been broadened.
3. For a new empty application only, temporarily set GTA_ALLOW_INITIAL_ADMIN=true and rebuild/redeploy configuration. Run the preview:

```bash
php artisan gta:initial-admin
```

Create through a private interactive executor:

```bash
php artisan gta:initial-admin --create --name="Staging Administrator" --email="REPLACE-WITH-ADMIN-EMAIL"
```

It asks for password/confirmation using hidden prompts with no echo fallback. If the Cloud command executor does not support private interactive input, use a secure executor that can pipe private JSON standard input with exactly password and password_confirmation to the same command plus `--password-stdin --no-interaction`. Never put the actual secret in dashboard command text, CLI arguments, shell history, logs or chat. Preview never reads a password or creates a user. The existing password policy applies; do not use a shared/example password.

Creation is allowed once, requires no users/apprentices/employers/programmes/groups, and commits the new active admin with a singleton consumed marker and audit. It leaves email verification unknown and sends no email. Repeat attempts or failed audits cannot overwrite accounts or consume an incomplete setup. Use the existing account flow for a populated application.

4. Disable GTA_ALLOW_INITIAL_ADMIN and redeploy cached configuration. Sign in at the HTTPS staging URL, confirm the staging notice, save/reopen a fictional record and check sessions after redeployment. Read MARTIN_BATCH_v0.28.0.md for precise behaviour and limitations.

These steps enable fictional-data staging, not a production release. The gate does not itself validate the database schema, S3 bucket, real identity or complete compliance/history.

## Test the actual staging storage

```bash
php artisan gta:evidence-storage
php artisan gta:evidence-storage --probe
```

The first command is read-only configuration inspection. The second explicitly writes fictional temporary bytes to the current private S3 disk, hash-reads them, checks unsigned access at the configured API endpoint and deletes/verifies its random probe object. It is refused in production and never creates learner records or touches existing evidence. A cleanup failure returns nonzero and a synthetic relative key for operator cleanup. API denial alone does not certify bucket visibility or other public aliases; confirm the actual bucket/private-domain settings. Read MARTIN_BATCH_v0.29.0.md for full limits and legacy-file handling.

The S3 runtime adapter is now installed and locked. Source proxy trust, actual Cloud credentials/bucket configuration and existing file references have not been changed. The 531 committed task source PDFs remain unchanged.

## Native database correction in v0.30.0

Fresh signed-review tables now use a short explicit index name. Apply `2026_11_13_210000_normalize_signed_review_index.php` after earlier migrations to rename the legacy SQLite index while preserving data; fresh native installs already use the correct name. If an older native migration attempt failed, inspect partial tables and migration history before retrying. Do not auto-drop or reset an unknown/populated database.

The current suite, actual HTTP persistence and first-admin concurrency were checked on an isolated local MariaDB 11.8.6 server. This is an additional compatibility check, not the exact Oracle MySQL/Cloud environment. MARTIN_BATCH_v0.30.0.md supplies safe local reproduction commands. Default framework tests now refuse anything except testing/in-memory SQLite; the native runner owns and guards its disposable local server/schema.

## Inventory retained evidence before a move

```bash
php artisan gta:evidence-inventory
php artisan gta:evidence-inventory --verify-files --details
```

Default mode reads known database references/configuration only. The explicit option reads private files, deduplicates repeated references at the same disk/path and checks SHA-256/size, including retained snapshots/submission links. It does not move, delete, relink or approve migration. Missing schema/records/files, invalid/conflicting metadata and detected source changes return nonzero. Detailed output exposes internal record locators/codes while excluding paths, filenames and private notes.

Read MARTIN_BATCH_v0.31.0.md for exact coverage and limitations. This is not an unregistered-file/bucket listing or atomic backup. Preserve the real legacy location and saved aliases; reconcile missing/changed files and rescue old ephemeral uploads before redeployment. Actual source/target mapping and a controlled verified copy plan remain necessary.

## Cloud build and deployment settings

Use PHP 8.4.1+; locally tested PHP is 8.4.27, Laravel is 13.42.0. Confirm Composer platform requirements and PHP extensions in the real build. This standalone source uses committed CSS/JS and has no Node/Vite build; preserve Martin's own pipeline if integrating into an existing application.

After the actual repository/dependencies are integrated, the standalone build command is:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The migration deploy command for a reviewed, compatible staging schema is:

```bash
php artisan migrate --force
```

Do not run the entire create-table migration history over conflicting existing tables. Do not use migrate:fresh, overwrite the existing APP_KEY, or update Composer indiscriminately. Put build-time cache steps in Cloud's Build Commands; database migrations belong in Deploy Commands after schema review. Keep secrets/resources configured in Cloud, with separate staging and production values. Do not run composer setup or gta:demo against an existing/production database.

Use `/up` for Laravel process health, then separately verify `/login` and an authenticated workflow; `/up` alone does not prove hosted web access, database, storage or permissions. Test create/save/reopen/sign-out/restart, tutor and mentor scope, actual private PDF upload/check/download after redeploy, and denied-role access on staging. Record actual target-engine tests and screenshots before replacing the existing portal.

Leave recovery mail and automatic reminder scheduling disabled until identity/delivery and scheduler infrastructure are verified. `gta:portal-reminders` previews; `--record` stores in-portal records only, now across marking/Careers/reviews. No message delivery is implemented by enabling that schedule.

Take and restore a database/private-file backup before real-data migration. Application rollback does not automatically reverse database writes; review additive migration compatibility and use a documented forward repair/restore plan.

## Current honest acceptance position

TEST_RESULTS.json contains the executed local suite and real loopback HTTP persistence evidence. The new local HTTPS startup proof covers CLI administrator creation, sign-in/CSRF, shared database sessions/cache, save/reopen and PHP restart. An actual local MariaDB 11.8.6 server has now been tested, including native HTTP persistence and administrator concurrency. The exact managed Cloud database, Cloud build/bucket/domain, real administrator and production deployment remain unverified. Production access is still blocked. CLOUD_READINESS_LOCAL.json records the actual local diagnostic blockers, not a successful Cloud installation. The adapter and local PDO MySQL checks now pass; actual bucket/managed database configuration still needs acceptance. The supplied local signed-HTTP fixture and fresh no-dev build evidence are separate from actual Cloud acceptance.

## Official references checked 13 November 2026

- [Laravel 13 database transaction and implicit-commit guidance](https://laravel.com/framework/docs/database)
- [MariaDB local socket activation](https://mariadb.com/docs/server/server-management/starting-and-stopping-mariadb/systemd)
- [Laravel 13 HTTP requests and trusted proxies](https://laravel.com/framework/docs/requests)
- [Cloud environment build/deploy commands and variables](https://laravel.com/cloud/docs/environments)
- [Cloud Laravel deployment guide](https://laravel.com/cloud/docs/deploy-guides/laravel)
- [Cloud private object-storage integration and S3 adapter prerequisite](https://laravel.com/cloud/docs/resources/object-storage)

These references inform the handover; they do not replace testing Martin's actual environment.
