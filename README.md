# Larabill - Professional Billing & Invoicing for Laravel

<!-- AI-BADGES:START profile=essential -->
[![Latest Version](https://img.shields.io/packagist/v/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![Tests](https://img.shields.io/github/actions/workflow/status/AichaDigital/larabill/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/AichaDigital/larabill/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Code Coverage](https://img.shields.io/codecov/c/github/AichaDigital/larabill?style=flat-square&logo=codecov)](https://codecov.io/gh/AichaDigital/larabill)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat-square&logo=php)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/packagist/php-v/aichadigital/larabill.svg?style=flat-square&logo=php)](https://packagist.org/packages/aichadigital/larabill)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-red.svg?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
<!-- AI-BADGES:END -->

> ℹ️ **Schema upgrade policy** — Larabill does not promise in-place schema upgrades between versions. Install fresh and seed with `larabill:install` (or `migrate:fresh`) rather than migrating an existing schema across major versions. See [ADR-006](docs/ADR-006-uuid-first-no-agnostic.md).

Larabill is a professional, **UUID-first** billing and invoicing package for Laravel applications. It provides tax calculation for Spain/EU/worldwide and flexible invoice generation with immutability protection, plus an optional thin bridge to intra-community VAT/NIF verification (delegated to the [`lararoi`](https://github.com/aichadigital/lararoi) package). The consumer app's `users.id` MUST be UUID v7 char(36) — see [`docs/setup-uuid.md`](docs/setup-uuid.md) and [ADR-006](docs/ADR-006-uuid-first-no-agnostic.md).

## 🎯 Features

### Core Functionality
- **Invoice Management**: UUID-based IDs, sequential numbering, proforma invoices, immutable records
- **Tax Calculation**: Spanish (IVA), Canary Islands (IGIC), Ceuta/Melilla (IPSI), EU reverse charge, worldwide
- **VAT/NIF Verification (optional)**: thin bridge that delegates to the `lararoi` package (VIES and other providers). Not wired into invoice issuance — reverse charge is driven by the `is_roi_taxed` flag
- **Fiscal Data Management**: Company and customer fiscal configurations with temporal validity
- **PDF Generation**: Built-in invoice PDF generation using DomPDF
- **EU Compliance**: Full support for EU B2B reverse charge and destination VAT rules

### Technical Excellence
- **String UUID v7**: Ordered UUIDs for invoices and the consumer's `users.id` (ADR-006)
- **FixedDecimal money**: Precise monetary value objects backed by base-100 integers (no floating-point errors)
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
CompanyFiscalConfig    → Issuer fiscal settings (one active at a time)
UserTaxProfile         → Customer fiscal data, temporally versioned per user
Invoice                → Immutable invoice with fiscal snapshot
```

**Key principles**:
- The customer is a `User` (ADR-003); businesses and sub-accounts are modelled with `parent_user_id`. The legacy `CustomerFiscalData` model was removed.
- Company config changes apply from a specific date forward
- `UserTaxProfile` records are temporally versioned (`valid_from`/`valid_until`) — never modify past records
- Invoices capture a fiscal snapshot at creation time
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

### Monetary Values (FixedDecimal)

**Money is stored as base-100 integers and exposed as `FixedDecimal` value objects** (from `lara100`), so there are no floating-point errors. You assign the unscaled base-100 integer; reading the attribute returns a `FixedDecimal`:

```php
// Assign the base-100 integer (€12.34 → 1234):
$invoice->total_amount = 1234;

// Reading the attribute returns a FixedDecimal value object
// (base-100 backed, scale 2) — not a raw int:
$money = $invoice->total_amount; // FixedDecimal
```

Invoice and invoice-item money attributes use the `FixedDecimalCast` (scale 2) from the `lara100` package. Note: query-builder access (`->value()`, `->sum()`, `->where()`) returns the raw integer, while Eloquent attribute access returns a `FixedDecimal`.

## 📖 Usage

### Creating an Invoice

```php
use AichaDigital\Larabill\Services\BillingService;

$billingService = app(BillingService::class);

$invoice = $billingService->createInvoice([
    'user_id' => $user->id, // UUID v7 of the customer (a User; ADR-003)
    'items' => [
        [
            'description'  => 'Professional Service',
            'quantity'     => 100,           // base-100: 100 = 1.0 unit
            'unit_price'   => 10000,         // base-100: 10000 = €100.00
            'tax_group_id' => $taxGroup->id, // resolves the applicable VAT/IGIC/IPSI
        ],
    ],
]);
```

### Tax Calculation

```php
use AichaDigital\Larabill\Services\TaxCalculationService;

$taxService = app(TaxCalculationService::class);

// Calculate taxes for a single line. Amounts are base-100 integers. The
// applicable rate (Spanish IVA, Canary IGIC, Ceuta/Melilla IPSI, EU reverse
// charge or destination VAT) is resolved from the TaxGroup and the customer's
// fiscal profile — not passed in directly.
$result = $taxService->calculateForInvoiceItem([
    'quantity'         => 100,           // base-100: 1.0 unit
    'base_price'       => 10000,         // base-100: €100.00
    'tax_group_id'     => $taxGroup->id,
    'billable_user_id' => $user->id,     // optional: drives B2B / destination rules
]);

// $result keys (base-100 integers + breakdown):
//   taxable_amount, total_tax_amount, total_amount, tax_group_id, taxes_applied
```

### VAT/NIF Verification (optional bridge to lararoi)

Intra-community VAT/NIF verification is owned by the [`lararoi`](https://github.com/aichadigital/lararoi) package. Larabill exposes a single thin bridge action that delegates to lararoi's contract and returns its canonical result unchanged:

```php
use AichaDigital\Larabill\Actions\VerifyVatNumber;

// Pass the VAT number WITHOUT the country prefix ("B12345678", not "ESB12345678").
$result = VerifyVatNumber::run('B12345678', 'ES');

if ($result['is_valid']) {
    echo 'Valid VAT for: '.$result['company_name'];
}
```

Providers (VIES, isvat, vatlayer, …), caching and optional tracking are configured in lararoi, not here — publish its config with `php artisan vendor:publish --tag="lararoi-config"`. This bridge is **not** wired into invoice issuance: reverse charge is decided by the invoice's `is_roi_taxed` flag, never by a live lookup.

### Company Fiscal Configuration

```php
use AichaDigital\Larabill\Models\CompanyFiscalConfig;

// Get current active config
$config = CompanyFiscalConfig::getActive();

// Create new config (the previous active one is auto-closed)
$newConfig = CompanyFiscalConfig::createNew([
    'tax_id'        => 'ESB12345678',
    'business_name' => 'Your Company S.L.',
    'address'       => 'Calle Test 123',
    'city'          => 'Madrid',
    'zip_code'      => '28001',
    'country_code'  => 'ES',
    'is_oss'        => true,
    'valid_from'    => now(),
]);
```

### User Tax Profile

The customer is a `User` (ADR-003); their fiscal data lives in `UserTaxProfile`, temporally versioned. This replaces the removed `CustomerFiscalData` model.

```php
use AichaDigital\Larabill\Models\UserTaxProfile;

// Get the active fiscal profile for a user
$profile = UserTaxProfile::getActiveForOwner($user->id);

// Create a new profile (previous stays as history)
$newProfile = UserTaxProfile::createForOwner($user->id, [
    'fiscal_name'  => 'Client SARL',
    'tax_id'       => 'FR12345678901',
    'country_code' => 'FR',
    'is_company'   => true,
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

**Current status (v3.1.3)**: 928 tests passing on SQLite, plus MySQL 8 integration tests (real column types and unique constraints) and fork-based concurrency tests. The UUID-first contract is demonstrated on MySQL 8.

## 📚 Documentation

| Document | Description |
|----------|-------------|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Core architecture and domain model |
| [setup-uuid.md](docs/setup-uuid.md) | UUID-first onboarding for the consumer app |
| [ADR-006](docs/ADR-006-uuid-first-no-agnostic.md) | UUID-first decision (supersedes the agnostic id contract) |
| [TAX_RATES_MIGRATION_GUIDE.md](docs/TAX_RATES_MIGRATION_GUIDE.md) | Tax rates migration guide |
| [CHANGELOG.md](CHANGELOG.md) | Version history and breaking changes |

For AI agents working with this package, see [.claude/project.md](.claude/project.md).

## 🗺️ Roadmap

### Shipped
- ✅ Core invoice management (immutable records, UUID v7, sequential numbering, proforma)
- ✅ Spanish tax system (IVA, IGIC, IPSI)
- ✅ EU reverse charge (B2B) and destination VAT
- ✅ Fiscal data with temporal validity (`CompanyFiscalConfig`, `UserTaxProfile`)
- ✅ `FixedDecimal` money type (base-100, no floating-point errors)
- ✅ VeriFACTU integration (Spain AEAT) via `lara-verifactu`
- ✅ Grouped payments
- ✅ Legal-retention contract (`LegallyRetainable`) for GDPR tooling

### Under consideration
- Subscription billing
- Payment gateway integration (Stripe, PayPal, Redsys)
- Advanced reporting

See the [CHANGELOG](CHANGELOG.md) for the full release history.

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
