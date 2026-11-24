# GTA Portal

Laravel apprentice-management portal for Doncaster GTA (current release: **Pack 82 revision 3 / `GTA-P82-R3-2026-11-24`**).

## Requirements

- PHP 8.4+ with standard Laravel extensions (`pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `xml`, `fileinfo`)
- Composer
- MySQL / MariaDB (local Herd or Laravel Cloud) — PHPUnit runs against in-memory SQLite
- Node.js (for JS verification scripts / Playwright E2E)

## Local Development Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan gta:demo
```

- Copy `.env.example` only for a fresh local setup; never overwrite an existing `.env` or `APP_KEY`.
- `gta:demo` seeds 2 fictional apprentices and 10 role accounts into an empty database and prints the generated local test password.
- To load or preview the 250-apprentice fictional QA cohort (CLI only):
  ```sh
  php database/qa-seed/seed-gta-qa.php --inbox="your-test-inbox@example.com" --batch=GTAQA-250
  # Add --apply to import
  ```

## Running Tests

```sh
APP_DEBUG=false APP_KEY=base64:c3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3M= ./vendor/bin/phpunit
```

## Laravel Cloud Configuration

See `deploy/cloud.env.example` and `.env.example` for the full list of environment flags. Key Pack 82 settings:

- `GTA_RPL_DATE_PROGRAMMES=ST0499:1.3,ST0068:1.5,ST0403:1.3,ST0448:1.2` — enables approved block-date rules for the four active programmes.
- `GTA_WEEKLY_RETENTION_SCHEDULED=false` — keep `false` until controlled tutor/apprentice alias mail delivery and duplicate prevention are verified, then set `true`.
- `GTA_PRIVATE_EVIDENCE_DISK` / `GTA_PRIVATE_EVIDENCE_ALLOWED_DISKS` — point to the private Cloud storage disk(s).

### Post-Deploy Verification Commands

```sh
php artisan migrate:status
php artisan gta:pack82-check
php artisan schedule:list
```

## Scheduled Commands

The Laravel scheduler (`routes/console.php`) registers:

- `gta:portal-reminders --record` (daily 09:00)
- `gta:support-plan-reminders --record` (daily 09:05)
- `gta:apprentice-alerts --prepare --limit=250` (daily 09:10)
- `gta:apprentice-alerts --send --limit=20` (every minute)
- `gta:retention-followups` (daily 07:00)
- `gta:weekly-registers` (hourly)
- `gta:weekly-retention --record --send` (Mondays 09:00 `Europe/London`, gated by `GTA_WEEKLY_RETENTION_SCHEDULED`)
