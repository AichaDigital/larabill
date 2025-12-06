# Larabill - Professional Billing & Invoicing for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/larabill/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aichadigital/larabill/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![PHP Version](https://img.shields.io/packagist/dependency-v/aichadigital/larabill/php?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![Laravel](https://img.shields.io/packagist/dependency-v/aichadigital/larabill/illuminate/contracts?style=flat-square&label=laravel)](https://packagist.org/packages/aichadigital/larabill)
[![License](https://img.shields.io/packagist/l/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)

> ⚠️ **DEVELOPMENT VERSION** - This package is under active development (dev-main). Target: v1.0 stable by December 15, 2025.

Larabill is a professional, agnostic billing and invoicing package for Laravel applications. It provides comprehensive VAT verification, tax calculation for Spain/EU/worldwide, and flexible invoice generation with immutability protection.

## 🎯 Features

### Core Functionality
- **Invoice Management**: UUID-based IDs, sequential numbering, proforma invoices, immutable records
- **Tax Calculation**: Spanish (IVA), Canary Islands (IGIC), Ceuta/Melilla (IPSI), EU reverse charge, worldwide
- **VAT/Tax Code Verification**: Integration with AbstractAPI and APILayer for real-time validation
- **Fiscal Data Management**: Company and customer fiscal configurations with temporal validity
- **PDF Generation**: Built-in invoice PDF generation using DomPDF
- **EU Compliance**: Full support for EU B2B reverse charge and destination VAT rules
- **Filament 4 Integration**: Ready-to-use admin panel resources

### Technical Excellence
- **String UUID v7**: Ordered UUIDs for invoices (optimal for MySQL indexes)
- **Base-100 Integers**: Precise monetary calculations (no floating-point errors)
- **User Agnostic**: Works with any User model (UUID, ULID, or integer IDs)
- **Temporal Validity**: Fiscal configurations with `valid_from`/`valid_until` dates
- **Invoice Immutability**: Protection against modifications after issuance

## 📦 Requirements

- PHP ^8.3
- Laravel ^11.0 | ^12.0
- Filament ^4.0 (optional, for admin panel)

## 🚀 Installation

### Via Composer

```bash
composer require aichadigital/larabill
```

### Publish Configuration

```bash
php artisan vendor:publish --tag="larabill-config"
```

### Run the Installer

```bash
php artisan larabill:install
```

This will:
1. Publish migrations
2. Run database migrations
3. Seed default tax categories and rates

### Manual Installation (if preferred)

```bash
# Publish migrations
php artisan vendor:publish --tag="larabill-migrations"

# Run migrations
php artisan migrate

# Seed default data
php artisan db:seed --class="AichaDigital\Larabill\Database\Seeders\TaxCategoriesSeeder"
php artisan db:seed --class="AichaDigital\Larabill\Database\Seeders\TaxRatesSeeder"
```

## ⚙️ Configuration

### Environment Variables

Add these to your `.env` file:

```env
# Tax Code Verification APIs
LARABILL_ABSTRACTAPI_KEY="your_abstractapi_key"
LARABILL_APILAYER_KEY="your_apilayer_key"
LARABILL_VAT_PREFERRED_API="abstractapi"
LARABILL_VAT_CACHE_DAYS=30

# Invoice Numbering
LARABILL_INVOICE_PREFIX="FAC"
LARABILL_PROFORMA_PREFIX="PRO"

# User ID Type (auto-detected if not set)
LARABILL_USER_ID_TYPE="uuid"  # Options: uuid, int, ulid
```

### Model Configuration

Configure your user model in `config/larabill.php`:

```php
'models' => [
    'user' => \App\Models\User::class,
    'invoice' => \AichaDigital\Larabill\Models\Invoice::class,
    'invoice_item' => \AichaDigital\Larabill\Models\InvoiceItem::class,
    // ...
],
```

## 🏗️ Architecture

### Fiscal Data Model

Larabill separates company and customer fiscal data with temporal validity:

```
CompanyFiscalConfig    → Company fiscal settings (one active at a time)
CustomerFiscalData     → Customer fiscal data (historical per customer)
Invoice                → Immutable invoice with fiscal snapshot
```

**Key principles**:
- Company config changes apply from a specific date forward
- Customer data changes are historical (never modify past records)
- Invoices capture fiscal snapshot at creation time
- Invoices are **absolutely immutable** once issued

### UUID Strategy

Larabill uses **string UUID v7** for invoices:

```php
// Model with UUID
use AichaDigital\Larabill\Concerns\HasUuid;

class Invoice extends Model
{
    use HasUuid;
}

// Migration
$table->uuid('id')->primary();
```

### Monetary Values (Base 100)

**All monetary values use integers in base 100** to avoid floating-point errors:

```php
// €12.34 stored as:
$invoice->total_amount = 1234;

// 21% IVA stored as:
$taxRate->rate = 2100;
```

Use the `Base100Int` cast from the `lara100` package.

## 📖 Usage

### Creating an Invoice

```php
use AichaDigital\Larabill\Services\BillingService;

$billingService = app(BillingService::class);

$invoice = $billingService->createInvoice([
    'user_id' => $user->id,
    'items' => [
        [
            'description' => 'Professional Service',
            'quantity' => 1,
            'unit_price' => 10000, // €100.00 in base 100
            'tax_rate' => 2100,    // 21% in base 100
        ]
    ]
]);
```

### Tax Calculation

```php
use AichaDigital\Larabill\Services\TaxCalculationService;

$taxService = app(TaxCalculationService::class);

// EU B2B reverse charge
$result = $taxService->calculateTax(10000, 'ES', 'DE', isB2B: true);
// Returns: tax_rate = 0 (reverse charge applies)

// EU B2C destination VAT
$result = $taxService->calculateTax(10000, 'ES', 'FR', isB2B: false);
// Returns: tax_rate = 2000 (20% French VAT)
```

### VAT Verification

```php
use AichaDigital\Larabill\Services\VatVerificationService;

$vatService = app(VatVerificationService::class);

$result = $vatService->verifyVatCode('ESB12345678', 'ES');

if ($result['is_valid']) {
    echo "Valid VAT for: " . $result['company_name'];
}
```

### Company Fiscal Configuration

```php
use AichaDigital\Larabill\Models\CompanyFiscalConfig;

// Get current active config
$config = CompanyFiscalConfig::getActive();

// Create new config (previous becomes inactive)
$newConfig = CompanyFiscalConfig::create([
    'tax_id' => 'ESB12345678',
    'company_name' => 'Your Company S.L.',
    'address' => 'Calle Test 123',
    'city' => 'Madrid',
    'postal_code' => '28001',
    'country_code' => 'ES',
    'is_oss' => true,
    'valid_from' => now(),
]);
```

### Customer Fiscal Data

```php
use AichaDigital\Larabill\Models\CustomerFiscalData;

// Get current fiscal data for a customer
$fiscalData = CustomerFiscalData::getActiveForUser($userId);

// Create new fiscal data (historical record)
$newData = CustomerFiscalData::createForUser($userId, [
    'tax_id' => 'FR12345678901',
    'business_name' => 'Client SARL',
    'country_code' => 'FR',
    'is_business' => true,
]);
```

## 🎨 Filament Integration

Larabill includes ready-to-use Filament 4 resources:

```php
// In your Filament panel provider
use AichaDigital\Larabill\Filament\Resources\InvoiceResource;
use AichaDigital\Larabill\Filament\Resources\CompanyFiscalConfigResource;
use AichaDigital\Larabill\Filament\Resources\CustomerFiscalDataResource;

public function panel(Panel $panel): Panel
{
    return $panel
        ->resources([
            InvoiceResource::class,
            CompanyFiscalConfigResource::class,
            CustomerFiscalDataResource::class,
        ]);
}
```

## 🧪 Testing

```bash
# Run all tests
composer test

# Run specific tests
composer test -- --filter=Invoice

# Run with coverage
composer test-coverage

# Static analysis
vendor/bin/phpstan analyse
```

**Current status**: 866 tests passing, 34 skipped (external dependencies)

### ⚠️ SQLite In-Memory Testing with Binary UUIDs

By default, Larabill uses `uuid_binary` (16-byte binary storage) for optimal MySQL performance. However, **SQLite has limitations with binary UUIDs that cause foreign key constraint failures**.

**The Problem**:

When using SQLite in-memory testing (common in CI/CD and `phpunit.xml`), binary UUID storage creates issues with foreign key references. SQLite cannot properly match binary columns across tables, resulting in:

```
SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed
```

**The Solution**:

Add this environment variable to your `phpunit.xml`:

```xml
<php>
    <!-- Other env vars... -->
    <env name="LARABILL_USER_ID_TYPE" value="uuid"/>
</php>
```

This forces Larabill to use string UUIDs (36 characters) during testing, which SQLite handles correctly.

**Why This Matters**:

- **Production (MySQL/PostgreSQL)**: Use `uuid_binary` for 55% storage savings and better performance
- **Testing (SQLite in-memory)**: Use `uuid` for compatibility

This is a known limitation of SQLite's binary handling, not a bug in Larabill. For production-like testing, consider using MySQL or PostgreSQL test databases.

**Alternative Approach**:

If you prefer to test with the same configuration as production, use a real MySQL database in your test environment:

```xml
<php>
    <env name="DB_CONNECTION" value="mysql"/>
    <env name="DB_DATABASE" value="larabill_test"/>
</php>
```

## 📚 Documentation

| Document | Description |
|----------|-------------|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Core architecture and domain model |
| [CHANGELOG.md](CHANGELOG.md) | Version history and breaking changes |
| [TAX_SYSTEM_ANALYSIS.md](docs/TAX_SYSTEM_ANALYSIS_AND_RECOMMENDATIONS.md) | Tax system design decisions |

For AI agents working with this package, see [.claude/project.md](.claude/project.md).

## 🗺️ Roadmap

### v1.0.0 (Target: December 15, 2025)
- ✅ Core invoice management
- ✅ Spanish tax system (IVA, IGIC, IPSI)
- ✅ EU reverse charge (B2B)
- ✅ Fiscal data with temporal validity
- ✅ Filament 4 admin resources
- 🔄 VeriFACTU integration (Spain AEAT)
- 🔄 WHMCS migration tools

### v2.0.0 (Future)
- Multi-tenancy support
- Subscription billing
- Payment gateway integration (Stripe, PayPal, Redsys)
- Advanced reporting

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔒 Security

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## 📄 License

GNU Affero General Public License v3.0 (AGPL-3.0-or-later). See [LICENSE.md](LICENSE.md) for details.

This means:
- ✅ You can use, modify, and distribute this software
- ✅ You must share any modifications under the same license
- ⚠️ If you run this as a network service, you must provide the source code to users
- ⚠️ You must preserve copyright and attribution notices

## 👥 Credits

- [AichaDigital](https://aichadigital.es)
- [All Contributors](../../contributors)
