# Deployment Guide

Operator guide for deploying and running the multi-brand portal. Read this
alongside `config/brands.php` (the brand registry) and
`enviroments/.env.example` (the env stubs).

## 1. The multi-brand model

This is **one codebase, one deployment, one database per brand**. There is no
tenants table and no runtime lookup — brands are static and known at deploy
time (see `config/brands.php`, top-level keys `komiut` and `safiri`).

The brand is resolved **per request**:

- **Mobile apps** send an `X-App-Key: <brand key>` header. `BrandRegistry::resolveByAppKey()`
  maps it to a brand.
- **Web** resolves by hostname. `BrandRegistry::resolveByHost()` maps the
  request host (e.g. `portal.komiut.co.ke`) to a brand.

Resolution **fails closed**: an unknown host or app key resolves to no brand and
the request is rejected (404). There is deliberately no default brand, because
falling back would mean serving one brand's data out of another brand's
database.

Once resolved, `App\Brands\BrandContext::apply()` points the default DB
connection at that brand's own database, isolates the Redis cache keyspace, and
sets the session cookie/domain. This is the single place that activates a brand,
shared by the HTTP middleware, the queue Context hook, and the console commands.

## 2. Provisioning each brand database

Each brand has its own MySQL/MariaDB database, named by the `connection` key in
`config/brands.php` (`komiut`, `safiri`), which maps to a connection block in
`config/database.php`.

For each brand:

1. Create an empty database and a DB user with access to it.
2. Set the per-brand env vars. They are stubbed in `enviroments/.env.example`:

   **Komiut**
   ```
   KOMIUT_DB_HOST=127.0.0.1
   KOMIUT_DB_PORT=3306
   KOMIUT_DB_DATABASE=komiut
   KOMIUT_DB_USERNAME=
   KOMIUT_DB_PASSWORD=
   ```

   **2Safiri**
   ```
   SAFIRI_DB_HOST=127.0.0.1
   SAFIRI_DB_PORT=3306
   SAFIRI_DB_DATABASE=safiri
   SAFIRI_DB_USERNAME=
   SAFIRI_DB_PASSWORD=
   ```

   Also set the brand routing / feature vars from the same file:
   `KOMIUT_HOST` / `SAFIRI_HOST` (web hostname), `KOMIUT_APP_KEY` /
   `SAFIRI_APP_KEY` (mobile app key — leave blank to disable mobile access),
   and the `*_SESSION_*` / `*_FEATURE_*` toggles.

## 3. Running migrations — per brand

Because migrations must run against **each brand database separately**, use the
first-class `brand:migrate` command (in `app/Console/Commands/BrandMigrate.php`).
It fans the migrator out across brands, always passing `--force` so it is
non-interactive and production-safe.

```bash
# Migrate every brand
php artisan brand:migrate

# Migrate a single brand
php artisan brand:migrate --brand=komiut

# Drop + re-migrate (and optionally seed) each brand
php artisan brand:migrate --fresh
php artisan brand:migrate --fresh --seed

# Dump the SQL that would run, without executing it
php artisan brand:migrate --pretend
```

Notes:

- `--brand=*` can be repeated to target several brands
  (`--brand=komiut --brand=safiri`).
- One brand's failure does **not** abort the run — the remaining brands are
  still migrated — but the command exits non-zero so CI/monitoring notices.
- Do **not** run bare `php artisan migrate`: it only touches the default
  connection and would leave the other brand un-migrated.

## 4. Running scheduled / console work — per brand

Console and scheduled work has no request, so nothing resolves a brand
automatically. Any scheduled job that touches brand data must fan out through
`brand:each` (in `app/Console/Commands/BrandEach.php`), which runs a given
artisan command once per brand, each against its own database:

```bash
php artisan brand:each app:generate-user-points
php artisan brand:each app:some-job --brand=safiri
```

In the scheduler this looks like:

```php
$schedule->command('brand:each app:generate-user-points')->everyTenMinutes();
```

This is already wired into the scheduler — brand-scoped scheduled jobs run for
komiut, then safiri.

## 5. Mobile-app contract

Every request to `/api/auth/*` runs through the `brand` middleware, which
resolves the brand **before** authentication (the users table lives inside the
brand database). The mobile apps **must** send:

```
X-App-Key: <that brand's key>
```

matching the brand's configured `*_APP_KEY`. An unknown or missing brand key
resolves to no brand and the request returns **404**.

Payment callback routes (NCBA / Coop / Daraja) are deliberately outside this
group: those providers post from their own servers with no `X-App-Key`, so they
resolve their brand differently and are not subject to this contract.

## 6. PHP version and Docker

**Deploy target is PHP 8.4 — not 8.5.** `phpspreadsheet` caps the supported PHP
version below 8.5, so the runtime must stay on 8.4. The brand Dockerfiles
(`Docker/Komiut/Dockerfile` and `Docker/2safiri/Dockerfile`) build
`FROM php:8.4-fpm`. Keep them there; do not bump to 8.5 until the spreadsheet
dependency supports it.
