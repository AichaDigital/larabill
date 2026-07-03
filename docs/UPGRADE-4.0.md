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

`vat_verifications`, `roi_queries` and `user_roi_verifications` no longer exist.
lararoi owns its own `roi_*` schema (run `php artisan migrate` after installing
lararoi). If you stored verification history, migrate it to lararoi's tracking
log (`roi_verification_queries`) — see lararoi's `docs/integration.md`.

## Notes

- Invoice issuance is **not** wired to live verification: reverse charge is still
  the `is_roi_taxed` input flag. The bridge is an optional, on-demand seam.
- larabill does not track `composer.lock` (it is a library); CI resolves lararoi
  fresh from Packagist.
