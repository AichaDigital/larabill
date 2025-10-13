# Changelog

All notable changes to `larabill` will be documented in this file.

## [0.2.0] - 2025-01-13

### Breaking Changes

- **Removed deprecated `CompanyConfigService`**: All functionality has been migrated to `FiscalSettings` model
  - Use `FiscalSettings::getOrCreateForUser()` instead of `CompanyConfigService::getCurrentConfig()`
  - Use `FiscalSettings` model methods directly instead of service methods
- **Removed deprecated methods from `VatVerification` model**:
  - Removed `findByVatNumber()` - use `whereVatCode()` scope instead
  - Removed `findByVatNumberAndCountry()` - use `findByVatCodeAndCountry()` instead

### Added

- **Binary UUID Relationship Support**: Implemented `BinaryUuidBuilder` to enable full Eloquent relationship support when using `EfficientUuid` cast
  - Custom query builder automatically converts UUID strings to binary in WHERE and WHERE IN clauses
  - Fixes relationships (`belongsTo`, `hasMany`) for models using binary UUID storage
  - Zero performance penalty for non-UUID queries
  - Applied to `Invoice` and `InvoiceItem` models
- **Auto-apply Destination VAT**: `FiscalSettings::checkThreshold()` now automatically enables `apply_destination_iva` when threshold is exceeded and `auto_apply_destination` is true

### Changed

- **Migration**: `invoice_items.invoice_id` column changed from `uuid()` (char 36) to `binary(16)` for consistency with `Invoice` model's binary UUID storage
- **Enhanced `FiscalSettings` model**:
  - Added `incrementEuSales()` method for updating EU sales amounts
  - Improved `checkThreshold()` to auto-enable destination VAT when configured
- **Refactored Integration Tests**: All tests in `VatSystemIntegrationTest` now use `FiscalSettings` directly instead of deprecated service

### Fixed

- **UUID Binary Relationships**: Fixed `Invoice` ↔ `InvoiceItem` relationship queries when using binary UUID storage
- **EU Sales Threshold**: Fixed auto-application of destination VAT when threshold is exceeded

### Removed

- `CompanyConfigService` class and all its methods
- `CompanyConfigServiceTest` test file
- Deprecated `findByVatNumber()` and `findByVatNumberAndCountry()` methods from `VatVerification`

### Testing

- ✅ All 453 tests passing
- ✅ 0 PHPStan errors (level 6)
- ✅ 100% code style compliance (Pint)
- ✅ Binary UUID relationships fully tested and working

### Migration Guide

If you were using `CompanyConfigService`:

```php
// ❌ Old (deprecated)
$config = app(CompanyConfigService::class)->getCurrentConfig();

// ✅ New
$config = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
```

If you were using deprecated `VatVerification` methods:

```php
// ❌ Old
$verification = VatVerification::findByVatNumberAndCountry($vat, $country);

// ✅ New
$verification = VatVerification::findByVatCodeAndCountry($vat, $country);
```

For Binary UUID relationships, add to your models:

```php
use AichaDigital\Larabill\Database\Query\BinaryUuidBuilder;

public function newEloquentBuilder($query)
{
    return new BinaryUuidBuilder($query);
}
```
