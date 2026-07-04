# Upgrading larabill 3.x → 4.0

## TL;DR

larabill **4.0 removes its own VAT/ROI verification layer**. Intra-community
VAT/NIF verification is now owned entirely by the sibling package
[`aichadigital/lararoi`](https://github.com/aichadigital/lararoi), and larabill
consumes it through a thin bridge.

**Are you affected?**

- **No** — if you issue invoices normally. Reverse charge has always been driven
  by the `is_roi_taxed` flag on the invoice, and that is unchanged. The removed
  code was never wired into billing (it was dead, `@deprecated` since v0.4.0).
- **Yes** — only if your app directly referenced any of the removed classes, or
  read/wrote the removed tables. See the mapping below.

## What was removed

| Removed (larabill 3.x) | Replacement (4.0) |
|------------------------|-------------------|
| `AichaDigital\Larabill\Services\VatVerificationService` | `AichaDigital\Larabill\Actions\VerifyVatNumber` (delegates to lararoi) |
| `AichaDigital\Larabill\Services\RoiVerificationService` | — (was dead code; use the bridge above) |
| `AichaDigital\Larabill\Services\VatApiIntegrationService` | lararoi's providers (VIES, isvat, vatlayer, …) |
| `AichaDigital\Larabill\Models\VatVerification` | lararoi's cache model (`roi_vat_verifications`) |
| `AichaDigital\Larabill\Models\RoiQuery` | lararoi's tracking log (`roi_verification_queries`) |
| `AichaDigital\Larabill\Models\UserRoiVerification` | — (superseded by lararoi's tracking) |
| Tables `vat_verifications`, `roi_queries`, `user_roi_verifications` | lararoi's `roi_*` tables (package-managed, `php artisan migrate`) |
| Config keys `LARABILL_ABSTRACTAPI_KEY`, `LARABILL_APILAYER_KEY`, `LARABILL_VAT_PREFERRED_API`, `LARABILL_VAT_CACHE_DAYS` | lararoi config (`lararoi-config`) |

## Migration steps

### 1. Update the dependency

`aichadigital/lararoi ^1.0` is now a real (activated) dependency. Composer pulls
it automatically. It declares **`ext-soap`** (its VIES SOAP provider) as a
platform requirement, so make sure the SOAP extension is enabled in your app and
CI environments, or `composer install` will fail to resolve.

```bash
composer update aichadigital/larabill
```

### 2. If you verified VAT numbers via larabill

Replace direct use of the old service with the bridge action:

```php
// Before (larabill 3.x)
use AichaDigital\Larabill\Services\VatVerificationService;
$result = app(VatVerificationService::class)->verifyVatNumber('ESB12345678', 'ES');

// After (larabill 4.0) — note: pass the number WITHOUT the country prefix
use AichaDigital\Larabill\Actions\VerifyVatNumber;
$result = VerifyVatNumber::run('B12345678', 'ES');
```

The result array keeps lararoi's canonical shape
(`is_valid`, `vat_code`, `country_code`, `company_name`, `company_address`,
`api_source`, `cached`, `request_date`).

### 3. Configure providers/cache in lararoi

Provider order, API keys, caching and optional tracking now live in lararoi's
config, not larabill's. Publish it and set your keys there:

```bash
php artisan vendor:publish --tag="lararoi-config"
```

### 4. If you read the removed tables

`vat_verifications`, `roi_queries` and `user_roi_verifications` are no longer
managed by larabill (their migration files left the package with 4.0). lararoi
owns its own `roi_*` schema (run `php artisan migrate` after installing
lararoi). If you stored verification history, migrate it to lararoi's tracking
log (`roi_verification_queries`) — see lararoi's `docs/integration.md`.

### 5. Existing databases coming from 3.x

A database that already ran larabill 3.x's migrations still carries the legacy
tables.

**Before the first `php artisan migrate` after the upgrade, export the legacy
cache.** Its rows are TTL-bound VIES cache, but they may hold residual
evidence value (raw VIES responses under `response_data`, verification
timestamps) and the preflight drop is one-way:

```bash
# add your usual --host/--user/--password options
mysqldump --single-transaction --quick --skip-lock-tables \
  <database> vat_verifications > vat_verifications-pre-lararoi.sql
```

What happens on `php artisan migrate`:

- **`vat_verifications` self-heals (lararoi >= 1.0.4, required by larabill
  4.0.2).** lararoi ships a preflight migration that drops the legacy table
  together with its orphaned ledger row
  (`2024_12_01_000007_create_vat_verifications_table`) — but ONLY under
  DOUBLE proof: the physical index fingerprint (the UNIQUE composite
  `(vat_code, country_code)` without the plain one — the shape only
  larabill 3.x produced) AND that ledger row. A ledger row alone never
  costs a table. lararoi then creates its canonical `roi_vat_verifications`
  fresh. No manual step.
- **If the preflight aborts instead**, either proof is missing (schema-dump
  pruned ledgers, repaired tables, a homonymous table owned by your app), so
  lararoi refuses to claim the table. If it belongs to another part of your
  application, RENAME it (a backup alone does not clear the abort — the
  preflight still sees `vat_verifications`), then re-run
  `php artisan migrate`; never use the hatch below for a table your app
  owns. If you have VERIFIED it is the legacy larabill cache and exported it
  (see above), set `LARAROI_ASSUME_LEGACY_VAT_TABLE=true` (config
  `lararoi.upgrade.assume_legacy_vat_table`) for that one deploy — an
  explicit operator decision that lets the preflight claim the table. Never
  leave the flag enabled permanently.
- **`roi_queries` and `user_roi_verifications` are inert zombies.** They
  collide with nothing — larabill and lararoi both ignore them. Export any
  verification history you want to keep, then drop them and clean their
  ledger rows at your convenience:

  ```sql
  DROP TABLE IF EXISTS roi_queries;
  DROP TABLE IF EXISTS user_roi_verifications;
  -- "migrations" below = YOUR configured migrations table
  -- (config database.migrations.table; only the name differs if customized)
  DELETE FROM migrations WHERE migration IN (
      '2026_02_16_000004_create_roi_queries_table',
      '2026_02_16_000005_create_user_roi_verifications_table'
  );
  ```

## Notes

- Invoice issuance is **not** wired to live verification: reverse charge is still
  the `is_roi_taxed` input flag. The bridge is an optional, on-demand seam.
- larabill does not track `composer.lock` (it is a library); CI resolves lararoi
  fresh from Packagist.
