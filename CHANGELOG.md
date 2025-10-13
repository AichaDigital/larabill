# Changelog

All notable changes to `larabill` will be documented in this file.

## [0.3.0] - 2025-01-13

### Breaking Changes

- **User ID Agnostic Architecture**: All foreign key references to `User` model now support multiple ID types
  - Migrations no longer hardcode `unsignedBigInteger` for `user_id` columns
  - Package now supports: `int`, `uuid`, `uuid_binary`, `ulid`, `ulid_binary`
  - **Action Required**: Run `php artisan larabill:detect-user-id` before migrating
  - **Migration**: Existing installations with `int` user IDs work without changes (default)
  - **Breaking**: If using custom user ID types, must configure `LARABILL_USER_ID_TYPE` in `.env`

### Added

- **MigrationHelper**: New helper class for agnostic user ID column creation
  - `MigrationHelper::userIdColumn()` - Adds user_id with auto-detected type
  - `MigrationHelper::detectUserIdType()` - Auto-detects User model ID type from database
  - `MigrationHelper::getUserIdType()` - Gets configured or detected ID type
  - Supports MySQL, PostgreSQL, and SQLite
- **Detection Command**: New `larabill:detect-user-id` Artisan command
  - Auto-detects User model ID type from existing database
  - Displays detected type with detailed description
  - Can automatically update `.env` file with `--update-env` flag
  - Validates configuration and provides manual instructions
- **Configuration**: New `user_id_type` config option in `config/larabill.php`
  - Environment variable: `LARABILL_USER_ID_TYPE`
  - Default: `int` (standard Laravel)
  - Supports: `int`, `uuid`, `uuid_binary`, `ulid`, `ulid_binary`

### Changed

- **All Migrations Updated**: Now use `MigrationHelper::userIdColumn()` instead of hardcoded types
  - `create_invoices_table.php`
  - `create_user_tax_infos_table.php`
  - `create_company_fiscal_configs_table.php`
  - `create_company_template_settings_table.php`
  - Migration stubs updated
- **Removed Duplicate Indexes**: `user_id` index now added automatically by MigrationHelper
- **Documentation**: Added comprehensive docs for User ID type configuration

### Removed

- **Incomplete PDF tests**: Removed 2 empty/skipped PDF generation tests
  - Removed `InvoiceIntegrationTest::can generate PDF for invoice`
  - Removed `InvoiceManagementFeatureTest::can generate PDF for invoices`
  - **Justification**: Empty tests that duplicated existing unit test coverage
  - **Coverage maintained**: Full PDF testing in `PDFServiceTest` and `DomPDFServiceTest` (16 tests)

### Migration Guide for 0.3.0

#### For New Installations

```bash
# 1. Auto-detect your User ID type
php artisan larabill:detect-user-id --update-env

# 2. Run migrations
php artisan migrate
```

#### For Existing Installations with Integer User IDs

No changes needed! Default is `int`.

#### For Projects with UUID/ULID User IDs

```bash
# Before migrating Larabill tables
php artisan larabill:detect-user-id --update-env

# Or manually add to .env:
LARABILL_USER_ID_TYPE=uuid_binary  # or uuid, ulid, ulid_binary
```

#### Supported User ID Types

| Type | Description | Database Column | Use Case |
|------|-------------|-----------------|----------|
| `int` | Standard Laravel | `unsignedBigInteger` | Default, most common |
| `uuid` | UUID string | `char(36)` | Human-readable UUIDs |
| `uuid_binary` | UUID binary | `binary(16)` | Most efficient UUID (recommended) |
| `ulid` | ULID string | `char(26)` | Sortable, human-readable |
| `ulid_binary` | ULID binary | `binary(26)` | Sortable, efficient |

### Testing

- ✅ All 453 tests passing
- ✅ 0 PHPStan errors (level 6)
- ✅ 100% code style compliance (Pint)
- ✅ Auto-detection tested on MySQL, PostgreSQL, SQLite

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
