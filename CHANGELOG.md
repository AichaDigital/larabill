# Changelog

All notable changes to `larabill` will be documented in this file.

## [0.6.1] - 2026-02-16

### Fixed

- **Config**: `config/larabill.php` referenced test User model (`Tests\Models\User`) instead of `App\Models\User` in published config
- **Config**: Added top-level `user_model` config key — all code reads `config('larabill.user_model')` but the key was never defined in config (worked only via fallback)
- **InvoiceSeriesControl**: Wrong fallback for `user_model` config pointed to test class
- **Migrations**: Applied Pint formatting and added 3 missing `.php.stub` files for alteration migrations
- **Install command**: Removed 6 ghost entries from `$migrationOrder` that referenced non-existent stubs

### Changed

- **Migrations**: Converted 6 stub-only migrations to timestamped `.php` files for auto-loading in development

### Added

- **Tests**: MigrationHelper unit tests covering uuid, int, and ulid ID types
- **Docs**: Updated CONTRIBUTING.md and SCHEMA_REQUIREMENTS.md with migration pattern rules

## [0.6.0] - 2026-02-15

### Added

- **InvoiceNumber Value Object**: New VO for type-safe invoice number handling
- **InvoiceNumberingService**: Returns `InvoiceNumber` VO instead of raw strings
- **LegalEntityTypesSeeder**: Added `is_company` field support

### Changed

- **ADR-004**: Renamed `user_id` to `owner_user_id` in `user_tax_profiles` table
- **Install command**: Respect config priority and prevent migration duplicates (#12)
- **Migrations**: Use `MigrationHelper` for `billable_user_id` column

### Deprecated

- **BillingService**: Legacy numbering methods deprecated, removal target v0.7.0

### Removed

- **Filament dependency**: Package is now fully framework-agnostic (no Filament coupling)

### Documentation

- Added CONTRIBUTING.md with migration pattern standards
- Updated SCHEMA_REQUIREMENTS.md to version 2.1

## [0.5.0] - 2026-02-01

### Breaking Changes

- **ADR-001**: CompanyFiscalConfig replaces FiscalSettings — complete fiscal model refactor
- **ADR-002**: Migrated from binary UUID to string UUID v7 (native Laravel `Str::orderedUuid()`)
- **ADR-003**: Customer/User unification — `CustomerFiscalData` merged into `UserTaxProfile`
- **All enums**: Migrated from string-backed to int-backed, removed MySQL `enum()` usage

### Added

- **ADR-001**: `CompanyFiscalConfig` and `CustomerFiscalData` fiscal models with temporal validity
- **ADR-003**: `FiscalIntegrityChecker` service for fiscal change detection during proforma conversion
- **Article pricing**: Frequency-based pricing system (monthly, quarterly, annual, one-time)
- **Translatable articles**: Name and description fields support `spatie/laravel-translatable`
- **HasUserRelation trait**: UUID binary + Filament compatibility for user relationships
- **VatVerification**: Added `verified_at` field and soft deletes
- **Base100Int**: Migrated all monetary values to base-100 integer storage via `lara100` package
- **Verifactu integration**: Adapter services and VAT validation methods
- **Invoice methods**: `calculateTotals()` and tax validation
- **Install UX**: Improved `larabill:install` experience for production environments

### Fixed

- Migration ordering for foreign keys (invoices FK extracted to separate migration)
- Duplicate columns in incremental migrations
- Agnostic `user_id` types via `MigrationHelper::userIdColumn()` in all migrations
- CI compatibility for migration auto-loading

### Testing

- 953 tests passing (2754 assertions)
- Test coverage increased from 50.3% to 53.9%
- Comprehensive ADR-001 fiscal model tests

## [0.4.0-alpha] - 2025-01-17 (WIP)

### 🚀 **MAJOR REFACTOR**: Agnostic Billable Entity System

This is a **breaking change** release that fundamentally restructures the billing system to be agnostic to the billable entity, replacing the rigid User coupling with a flexible Customer entity.

---

### 🔥 **BREAKING CHANGES**

#### 1. **Customer Entity Replaces User Coupling**
- **Old**: Invoices were tightly coupled to `User` model
- **New**: Invoices are issued to `Customer` entities

**Migration Required**:
```bash
php artisan larabill:migrate-to-v040
```

#### 2. **New Core Tables**
- `legal_entity_types` - Flexible entity types (person, company, public entity)
- `issuer_config` - Singleton issuer configuration
- `issuer_tax_profiles` - Historical issuer fiscal data
- `customers` - Billable entities (replaces direct User link)
- `customer_tax_profiles` - Historical customer fiscal data  
- `commissions` - Multi-level commission system

#### 3. **Invoice Schema Changes**
- Added: `customer_id` (replaces various user fields)
- Added: `issuer_snapshot` (encrypted issuer fiscal data)
- Added: `customer_snapshot` (encrypted customer fiscal data)
- Added: `fiscal_snapshot` (encrypted tax context)
- Added: `fiscal_verification_id`, `fiscal_verification_qr`, `fiscal_verification_hash`
- Added: `converted_invoice_id` (for proforma conversion tracking)
- Added: `is_immutable` (locks proforma after conversion)

---

### ✨ **Added**

#### **Core Architecture**

**Single Issuer Model**  
Only one entity issues invoices (your company). Supports:
- Historical tracking of issuer identity changes
- Audit trail for legal name, tax ID changes
- Singleton pattern for active issuer configuration

**Agnostic Customer Entity**  
Flexible billable entity supporting:
- `relationship_type`: self, self_company, client, other
- Multiple fiscal identities per User
- Any legal entity type (person, company, public entity)

**Immutable Invoice Snapshots**  
Encrypted JSON snapshots capturing fiscal context at invoice time:
- Issuer fiscal data (legal name, tax ID, address)
- Customer fiscal data (name, tax ID, ROI verification)
- Tax context (rates, thresholds, ROI status, OSS)

#### **Models**

**New Models**:
- `LegalEntityType` - Flexible entity types with fiscal requirements
- `IssuerConfig` - Singleton issuer configuration
- `IssuerTaxProfile` - Historical issuer fiscal profiles
- `Customer` - Agnostic billable entity (replaces rigid User coupling)
- `CustomerTaxProfile` - Historical customer fiscal profiles
- `Commission` - Multi-level commission system

**Model Features**:
- Full Eloquent relationships
- Soft deletes support
- Comprehensive scopes (active, by type, by level)
- Factory support for testing

#### **Services**

**InvoiceService** (Refactored):
- `createInvoice()` - Creates invoice with encrypted snapshots
- `createProforma()` - Creates proforma invoice
- `convertProformaToInvoice()` - Converts proforma to final invoice with locking
- `createInvoiceItem()` - Creates invoice items with tax calculation
- `verifyInvoiceFiscally()` - Triggers fiscal verification via contract

**CommissionCalculationService** (New):
- Multi-level commission support (global, product group, product)
- Priority system (product > group > global)
- Date range validation
- Percentage and fixed amount types

**TaxCalculationService** (Updated):
- Integration with Customer and IssuerConfig context
- Support for encrypted snapshots

#### **Contracts & Testing**

**FiscalVerificationContract**:
- Interface for fiscal verification integrations
- Allows external packages (lara-verifactu, etc.) to implement
- Decoupled from core billing logic

**FakeFiscalVerification**:
- Test double for fiscal verification
- No external dependencies required for testing

#### **Migrations**

**New Migrations**:
- `2025_01_25_000001_create_legal_entity_types_table`
- `2025_01_25_000002_create_issuer_config_table`
- `2025_01_25_000003_create_issuer_tax_profiles_table`
- `2025_01_25_000004_create_customers_table`
- `2025_01_25_000005_create_customer_tax_profiles_table`
- `2025_01_25_000006_create_commissions_table`
- `2025_01_25_000007_add_v040_fields_to_invoices_table`

---

### 🔧 **Fixed**

#### **Migration System**
- Fixed duplicate index creation in `customers` table
- Resolved "index already exists" error via TDD approach
- Unified migration loading in tests
- Cleaned duplicate migrations from test directory

#### **PHPStan**
- Fixed covariance errors in factories
- Added missing PHPDoc properties
- Corrected Faker method calls

#### **CI/CD**
- Added VCS repositories for private packages (lara-verifactu, lararoi)
- Fixed Composer installation in GitHub Actions

#### **Enums**
- Added `PENDING` and `CONVERTED` statuses to `InvoiceStatus`

---

### 📚 **Documentation**

**New Documents**:
- `REFACTOR_ARQUITECTÓNICO-LARABILL-v0.4.0.md` - Architecture specification
- `REFACTOR_V040_PROGRESS.md` - Implementation progress
- `TAX_SYSTEM_ANALYSIS_AND_RECOMMENDATIONS.md` - Tax system analysis

**Updated**:
- README (pending)
- CHANGELOG (this file)

---

### 🧪 **Testing**

**Test Suite Status**: 640/913 tests passing (70%)

**New Tests** (55 total):
- Model tests (34): Customer, IssuerConfig, Commission, etc.
- Service tests (16): InvoiceService, CommissionCalculationService
- Integration tests (5): Complete billing flows

---

### 🎯 **Migration Guide**

#### **For Package Users**

1. **Update composer.json**:
```bash
composer require aichadigital/larabill:^0.4.0-alpha
```

2. **Run migrations**:
```bash
php artisan migrate
```

3. **Seed initial data**:
```bash
php artisan db:seed --class=LarabillSeeder
```

4. **Migrate existing data** (if upgrading):
```bash
php artisan larabill:migrate-to-v040
```

5. **Update code**:
- Replace `Invoice::create(['user_id' => ...])` with `Invoice::create(['customer_id' => ...])`
- Create `Customer` entities for your users
- Update fiscal verification integration (if using lara-verifactu)

---

### ⚠️ **Deprecations**

The following will be removed in v1.0.0:
- Direct `user_id` on invoices (use `customer_id`)
- Old `UserTaxProfile` model (use `CustomerTaxProfile`)

---

### 🚀 **Roadmap**

**v0.4.1**:
- Complete service implementation
- Fix remaining test failures

**v0.5.0**:
- Production-ready
- Complete documentation
- Migration command

**v1.0.0**:
- Stable API
- Remove deprecations
- Full Laravel 12 support

---

## [0.3.4] - 2025-01-13

### 🎯 Tax Rates System Refactor

#### Changed
- **Unified Tax Rates Migration**: Eliminated duplicate `tax_rates` migration for clarity and consistency
  - Removed: `2024_12_01_000006_create_tax_rates_table.php` (conflicting duplicate)
  - Enhanced: `2024_12_01_000000_create_tax_rates_table.php` with new features
  - **Action Required**: Users who published migrations must delete the duplicate (see migration guide)

#### Added
- **SoftDeletes Support**: Tax rates now use Laravel's native soft deletion
  - `deleted_at` column for "deleting" rates without losing historical data
  - Automatic filtering of deleted rates in queries
  - Easy restoration with `restore()` method
  - **Breaking**: Replaces custom `is_active` field with Laravel standard
- **Special Conditions (JSON)**: New metadata field for complex tax rules
  - Perfect for Spanish special territories (Canarias, Ceuta, Melilla)
  - Stores exemptions, territory types, special notes
  - Flexible JSON structure for any custom metadata
- **Enhanced Territory Support**: Better handling of Spanish special tax territories
  - Canary Islands (IC): IGIC tax system (7% / 3%)
  - Ceuta (CE): IPSI tax system (0% for digital services)
  - Melilla (ML): IPSI tax system (0% for digital services)
  - Full metadata in `special_conditions` field

#### Improved
- **TaxRatesSeeder**: Completely refactored for consistency
  - Now uses unified structure: `name`, `rate`, `region`, `type`, `special_conditions`
  - Comprehensive EU countries coverage (10 countries)
  - Spanish special territories with full metadata
  - Removed incompatible `country_code`, `tax_name` structure
- **TaxRate Model**: Enhanced with new features
  - Added `SoftDeletes` trait
  - New `special_conditions` cast to array
  - Updated PHPDoc with `deleted_at` property
- **TaxRateFactory**: New helper method
  - `withSpecialConditions(array $conditions)` state for testing
  - Default `special_conditions => null` in definition
- **Test Coverage**: Migration tests updated
  - Test database migration synchronized with main migration
  - All 856 tests passing (100%)

#### Documentation
- **New Guide**: `docs/TAX_RATES_MIGRATION_GUIDE.md`
  - Complete migration instructions for existing users
  - SoftDeletes usage examples
  - Special conditions examples (Canarias, Ceuta, Melilla)
  - Troubleshooting section
- **Comprehensive Analysis**: `docs/TAX_SYSTEM_ANALYSIS_AND_RECOMMENDATIONS.md`
  - 1,500+ lines of technical analysis
  - Comparison of old vs new structure
  - Spanish/EU requirements analysis
  - Decision matrix and recommendations

#### Migration Guide for Users
**If you haven't published migrations**: Nothing to do, just update the package.

**If you published migrations (v0.3.3 or earlier)**:
1. Delete duplicate: `rm database/migrations/*000006_create_tax_rates_table.php`
2. For production with data: Create ALTER TABLE migration to add new columns
3. For development: Use `migrate:fresh`

See `docs/TAX_RATES_MIGRATION_GUIDE.md` for detailed instructions.

---

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
