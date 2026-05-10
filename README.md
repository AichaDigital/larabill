# Larabill - Professional Billing & Invoicing for Laravel

[![Tests](https://img.shields.io/github/actions/workflow/status/AichaDigital/larabill/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/AichaDigital/larabill/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/AichaDigital/larabill/branch/main/graph/badge.svg?style=flat-square)](https://codecov.io/gh/AichaDigital/larabill)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue?style=flat-square)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-%5E12.0%20%7C%20%5E13.0-red?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/badge/license-AGPL--3.0--or--later-green?style=flat-square)](LICENSE.md)

> ⚠️ **DEVELOPMENT VERSION** — This package is under active development (`dev-main`). Schema upgrades between versions are not promised; use `migrate:fresh`.

Larabill is a professional, **UUID-first** billing and invoicing package for Laravel applications. It provides comprehensive VAT verification, tax calculation for Spain/EU/worldwide, and flexible invoice generation with immutability protection. The consumer app's `users.id` MUST be UUID v7 char(36) — see [`docs/setup-uuid.md`](docs/setup-uuid.md) and [ADR-006](docs/ADR-006-uuid-first-no-agnostic.md).

## 🎯 Features

### Core Functionality
- **Invoice Management**: UUID-based IDs, sequential numbering, proforma invoices, immutable records
- **Tax Calculation**: Spanish (IVA), Canary Islands (IGIC), Ceuta/Melilla (IPSI), EU reverse charge, worldwide
- **VAT/Tax Code Verification**: Integration with AbstractAPI and APILayer for real-time validation
- **Fiscal Data Management**: Company and customer fiscal configurations with temporal validity
- **PDF Generation**: Built-in invoice PDF generation using DomPDF
- **EU Compliance**: Full support for EU B2B reverse charge and destination VAT rules

### Technical Excellence
- **String UUID v7**: Ordered UUIDs for invoices and the consumer's `users.id` (ADR-002, ADR-006)
- **Base-100 Integers**: Precise monetary calculations (no floating-point errors)
- **Preflight check**: `larabill:install` aborts cleanly when `users.id` is not UUID-compatible
- **Temporal Validity**: Fiscal configurations with `valid_from`/`valid_until` dates
- **Invoice Immutability**: Protection against modifications after issuance

## 📦 Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- `users.id` UUID v7 char(36) — see [`docs/setup-uuid.md`](docs/setup-uuid.md)

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

# Optional: override the User model class. Must use UUID v7 char(36) ids.
LARABILL_USER_MODEL="App\\Models\\User"
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

**Current status (v0.8.0)**: 933 tests passing on SQLite + UUID-first contract demonstrated on MySQL 8.

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
