# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Larabill is a professional billing and invoicing Laravel package for Spanish/EU/worldwide taxation. It provides:
- VAT verification (AbstractAPI, APILayer)
- Complex tax calculations (Spanish IVA, Canary Islands IGIC, Ceuta/Melilla IPSI, EU reverse charge, worldwide)
- Invoice management with immutability and encryption
- ROI (Registry of Intra-community Operators) verification
- PDF generation with customizable templates

**Package namespace**: `AichaDigital\Larabill`

## Development Commands

### Testing
```bash
# Run all tests
composer test
vendor/bin/pest

# Run with coverage
composer test-coverage
vendor/bin/pest --coverage

# Run specific test file
vendor/bin/pest tests/Feature/BillingServiceTest.php

# Run single test
vendor/bin/pest --filter="can create invoice with Spanish IVA"

# Run tests by group
vendor/bin/pest --group=integration
```

### Code Quality
```bash
# Format code (Laravel Pint with group imports)
composer format
vendor/bin/pint

# Static analysis (PHPStan level 5)
composer analyse
composer phpstan
vendor/bin/phpstan analyse --memory-limit=1G

# Generate PHPStan baseline
composer phpstan-baseline
vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G
```

### Package Development
```bash
# Refresh package discovery
composer prepare
php vendor/bin/testbench package:discover --ansi

# Test in workbench app (Orchestra Testbench)
cd workbench/
```

## Architecture

### Core Services Layer
The package uses a service-oriented architecture with clear separation of concerns:

**BillingService** (`src/Services/BillingService.php`)
- Main entry point for invoice creation
- Orchestrates tax calculation, ROI verification, immutability
- Delegates to specialized services

**TaxCalculationService** (`src/Services/TaxCalculationService.php`)
- Handles all tax scenarios: Spanish domestic, EU B2B/B2C, worldwide
- Delegates to DestinationVatService for EU threshold checks
- Returns comprehensive tax details with invoice notes

**VatVerificationService** (`src/Services/VatVerificationService.php`)
- VAT number validation via external APIs (AbstractAPI, APILayer)
- Caches results in `vat_verifications` table (30 days default)
- Returns company name, address, validity status

**RoiVerificationService** (`src/Services/RoiVerificationService.php`)
- Verifies Spanish ROI (Registry of Intra-community Operators) status
- Determines if transaction requires ROI taxation
- Stores verification results in `user_roi_verifications`

**DestinationVatService** (`src/Services/DestinationVatService.php`)
- EU B2C distance selling threshold management (€10,000)
- Determines whether to apply origin or destination VAT

**PDF Services** (`src/Services/PDF/`)
- PDFService: Main PDF generation orchestrator
- DomPDFService: DomPDF implementation
- DefaultPDFConnector: Basic HTML-to-PDF connector
- Supports custom connectors via `PDFConnectorInterface`

**CompanyConfigService** (`src/Services/CompanyConfigService.php`)
- Manages company fiscal configuration (VAT number, ROI status, OSS registration)
- Single source of truth for company tax settings

**ModelMappingService** (`src/Services/ModelMappingService.php`)
- Agnostic model resolution from config (allows custom User models, etc.)

### Key Models

**Invoice** - Immutable invoice records with encryption support
**InvoiceItem** - Line items with tax calculations
**UserTaxInfo** - Customer tax information (VAT number, country, ROI status)
**TaxRate** - Historical tax rates (IVA, IGIC, IPSI)
**VatVerification** - Cached VAT API verification results
**CompanyFiscalConfig** - Company tax configuration (ROI, OSS)
**InvoiceTemplate** - Dynamic invoice templates (Blade-based)
**CountryVatRate** - EU country VAT rates for destination taxation

### Service Dependencies Pattern
Services use constructor dependency injection with optional parameters and fallback to `app()` helper:

```php
public function __construct(?TaxCalculationService $taxService = null)
{
    $this->taxService = $taxService ?? app(TaxCalculationService::class);
}
```

This pattern allows both manual instantiation in tests and Laravel container resolution.

## Tax Calculation Logic

### Flow Decision Tree
1. **Spanish special territories** (Canarias: IGIC, Ceuta/Melilla: IPSI) → `calculateSpecialSpanishTax()`
2. **EU to EU** → `calculateEUTax()` → checks B2B reverse charge or B2C destination VAT
3. **Worldwide** (non-EU) → `calculateWorldwideTax()` → typically 0% (no VAT)
4. **Spanish domestic** → `calculateSpanishTax()` → IVA (21%, 10%, 4%)

### ROI (Spanish Registry) Rules
- ROI status verification required for Spanish B2B transactions
- If customer is ROI-registered: 0% VAT with reverse charge
- Affects invoice notes generation (`invoice_notes` field)

### EU B2C Distance Selling
- Threshold: €10,000 per year per country
- Below threshold: Apply seller country VAT
- Above threshold: Apply buyer country VAT (requires OSS registration)
- Managed by `DestinationVatService` and `EuSalesThreshold` model

## Monetary Values Convention

**CRITICAL**: All monetary values use **integer cents** (base 100), never floats:
- €12.34 stored as `1234`
- 21.50% stored as `2150`
- Prevents floating-point rounding errors in fiscal calculations
- Applied to: prices, tax rates, amounts, thresholds

Models, factories, tests, and migrations ALL use this convention.

## Configuration & Extensibility

### Published Assets
```bash
php artisan vendor:publish --tag="larabill-migrations"
php artisan vendor:publish --tag="larabill-config"
php artisan vendor:publish --tag="larabill-views"
```

### Agnostic Model Configuration
The package supports custom models via `config/larabill.php`:

```php
'models' => [
    'user' => \App\Models\User::class,
    'invoice' => \AichaDigital\Larabill\Models\Invoice::class,
    // ... customize any model
]
```

Use `ModelMappingService::getModel('user')` to resolve configured models.

### PDF Customization
1. Create custom connector implementing `PDFConnectorInterface`
2. Publish and customize invoice templates
3. Configure in `config/larabill.php`

## Testing Philosophy

**Framework**: Pest PHP 3.8+ (preparing for Pest 4)
**Database**: SQLite in-memory for all tests
**Coverage Target**: 80%+ minimum

### Test Structure
- `tests/Feature/` - End-to-end service integration tests
- `tests/Unit/` - Isolated model/service logic tests
- `tests/Models/` - Custom test models (User factory, etc.)

### Test Organization
```php
// Use it() for behavior description
it('can create invoice with Spanish IVA', function () {
    // arrange-act-assert
});

// Use group() for organization (NOT describe())
it('validates VAT number format')->group('vat', 'validation');
```

### Factory Usage
ALL models have factories. Always use factories in tests:
```php
$invoice = Invoice::factory()->spanish()->create();
$user = User::factory()->withTaxInfo()->create();
```

Factory states provide domain-specific configurations: `spanish()`, `canarian()`, `b2b()`, `proforma()`, etc.

## Coding Standards

**PHP Version**: 8.3+
**Strict Types**: `declare(strict_types=1)` in all files
**PSR**: PSR-1, PSR-2, PSR-12 compliance
**Laravel Pint**: Custom rules with group imports enabled

### Import Conventions
```php
use Illuminate\Database\Eloquent\{Model, Relations\HasMany};
use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};
```

Group related imports, organize by: built-ins → external → Laravel → App.

### Type Declarations
- Typed properties (not docblocks)
- Return types always specified (including `void`)
- Nullable: `?Type` not `Type|null`
- Generics in docblocks: `/** @return Collection<int, Invoice> */`

### Control Flow
- Happy path last (handle errors first)
- Avoid `else` (use early returns)
- Always use curly braces
- Separate conditions over compound `&&`

### Comments
- Methods and classes documented in English
- Avoid inline comments (write expressive code)
- When needed: explain "why" not "what"

## Package-Specific Patterns

### Sequential Invoice Numbers
Invoice numbers use format: `{PREFIX}-{YYYY}-{NUMBER}` (e.g., `FAC-2025-0001`)
- Configurable prefix (`LARABILL_INVOICE_PREFIX`, `LARABILL_PROFORMA_PREFIX`)
- Optional yearly reset (`LARABILL_YEARLY_RESET=true`)
- Thread-safe counter management in `BillingService`

### Immutability & Encryption
- Invoices can be marked immutable (prevents updates/deletion)
- Sensitive data encrypted via Laravel's `Crypt` facade
- Configured per-company in `CompanyFiscalConfig`

### Migration Strategy
- One table per migration file
- Never combine complex actions (indexes, foreign keys, constraints) in one file
- Migrations in `database/migrations/` with date prefix: `2024_12_01_000001_`

### Environment Variables
All package config uses `LARABILL_*` prefix:
```env
LARABILL_COMPANY_VAT_NUMBER=ESB12345678
LARABILL_ABSTRACTAPI_KEY=your_key
LARABILL_VAT_PREFERRED_API=abstractapi
LARABILL_IMMUTABILITY_ENABLED=true
```

Use `config('larabill.company.vat_number')` not `env()` outside config files.

## Common Workflows

### Adding New Tax Scenario
1. Add test case in `tests/Feature/TaxCalculationServiceTest.php`
2. Implement logic in `TaxCalculationService::calculateTax()`
3. Add invoice notes template if needed
4. Update `InvoiceFactory` with new state if applicable

### Extending VAT Verification
1. Implement new API client in `VatApiIntegrationService`
2. Add API config to `config/larabill.php`
3. Update cache strategy in `VatVerificationService`
4. Add integration tests with mocked API responses

### Custom Invoice Template
1. Create Blade template in `resources/views/templates/`
2. Seed in `InvoiceTemplatesSeeder`
3. Test rendering in `tests/Feature/PDFServiceTest.php`

## Known Technical Decisions

- **No `down()` methods in migrations**: Only `up()` methods (one-way migrations)
- **Service instantiation pattern**: Optional constructor DI with `app()` fallback for flexibility
- **Integer cents for money**: Prevents float precision issues in tax calculations
- **Cached VAT verification**: 30-day default to reduce API calls/costs
- **Agnostic models**: Configurable to work with any Laravel app structure

## PHPStan Configuration

Level 5 with strict rules:
- `checkOctaneCompatibility: true`
- `checkModelProperties: true`
- `treatPhpDocTypesAsCertain: false`
- Baseline file: `phpstan-baseline.neon` (currently empty after cleanup)

Run before committing complex changes.

## Package Service Provider

Registers migrations, views, config via Spatie Package Tools.

Key bindings:
- `Larabill::class` - Singleton main class
- Facade alias: `'larabill'`

Migrations auto-run with package installation (configurable).

## Important Files to Check

- `config/larabill.php` - All package configuration
- `src/Services/TaxCalculationService.php` - Tax logic entry point
- `tests/Feature/BillingServiceTest.php` - E2E invoice creation scenarios
- `database/seeders/TaxRatesSeeder.php` - Spanish tax rates (IVA, IGIC, IPSI)
- `phpstan.neon.dist` - Static analysis configuration
