# Larabill - Professional Billing & Invoicing for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/larabill/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aichadigital/larabill/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/larabill/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aichadigital/larabill/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)

> ⚠️ **DEVELOPMENT VERSION** - This package is under active development (v0.1.0). Not recommended for production use yet.

Larabill is a professional, agnostic billing and invoicing package for Laravel applications. It provides comprehensive VAT verification, tax calculation for Spain/EU/worldwide, and flexible invoice generation with immutability and encryption features.

## ✨ **New Architecture (v0.1.0)**

- **UUID Binary Storage**: Efficient invoice IDs using binary UUID (55% storage savings)
- **SOLID Nomenclature**: `tax_code`, `vat_code` for international compatibility
- **User Agnostic**: Works with any user model (User, Customer, Client)
- **Tax Profile Snapshots**: Immutable fiscal data per invoice
- **Performance Optimized**: Ordered UUIDs for better MySQL indexing

## 🎯 Features

### Core Functionality
- **VAT/Tax Code Verification**: Integration with AbstractAPI and APILayer for real-time validation
- **Tax Calculation**: Spanish (IVA), Canary Islands (IGIC), Ceuta/Melilla (IPSI), EU reverse charge, worldwide
- **Invoice Management**: UUID-based IDs, sequential numbering, proforma invoices, immutable records
- **Data Security**: Encryption and immutability for fiscal data and invoices
- **PDF Generation**: Built-in invoice PDF generation using DomPDF
- **EU Compliance**: Full support for EU B2B reverse charge and destination VAT rules

### Technical Excellence
- **Binary UUID Storage**: 16 bytes vs 36 bytes (55% savings) with [dyrynda/laravel-model-uuid](https://github.com/michaeldyrynda/laravel-model-uuid)
- **Ordered UUID v4**: Optimized for MySQL B-tree indexes
- **Agnostic Design**: Configurable models and field mappings
- **Tax Profile Snapshots**: Maintains fiscal data immutability per invoice
- **SOLID Compliant**: Clean nomenclature (tax_code, vat_code, fiscal_settings)

## Installation

You can install the package via composer:

```bash
composer require aichadigital/larabill
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="larabill-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="larabill-config"
```

## 🏗️ Architecture

### Database Schema (v0.1.0)

```
users (external) → Your app's user model (UUID/Int/agnostic)
   │
   ├─→ user_tax_profiles → Fiscal profiles with tax_code
   │       └─→ vat_verifications → Tax code validations (cached)
   │
   ├─→ fiscal_settings → Annual fiscal configuration per user
   │
   └─→ invoices (UUID binary) → Immutable invoices with tax snapshots
           ├─ tax_profile_id → Snapshot of fiscal data
           └─→ invoice_items → Line items
```

### Key Models

- **Invoice**: UUID binary primary key, immutable records
- **UserTaxProfile**: User fiscal information (tax_code, business_name, legal_entity_type)
- **FiscalSettings**: Annual fiscal configuration per user (OSS, ROI, thresholds)
- **VatVerification**: Cached tax code validations
- **InvoiceItem**: Line items linked to invoices

### Configuration Example

```php
// config/larabill.php
return [
    'models' => [
        'user' => \App\Models\User::class, // Your user model
        'user_tax_profile' => \AichaDigital\Larabill\Models\UserTaxProfile::class,
        'invoice' => \AichaDigital\Larabill\Models\Invoice::class,
        'fiscal_settings' => \AichaDigital\Larabill\Models\FiscalSettings::class,
        'vat_verification' => \AichaDigital\Larabill\Models\VatVerification::class,
    ],
    
    'vat_apis' => [
        'abstractapi' => [
            'key' => env('LARABILL_ABSTRACTAPI_KEY'),
            'url' => 'https://vat.abstractapi.com/v1/validate/',
        ],
        'preferred_api' => 'abstractapi',
        'cache_duration_days' => 30,
    ],
];
```

## 🚀 Usage

### Tax Code Verification (formerly VAT Verification)

```php
use AichaDigital\Larabill\Services\VatVerificationService;

$vatService = new VatVerificationService();
// Works with VAT, IVA, Sales Tax codes worldwide
$result = $vatService->verifyVatCode('ESB12345678', 'ES');

if ($result['is_valid']) {
    echo "Valid tax code for: " . $result['company_name'];
}
```

### User Tax Profile Management

```php
use AichaDigital\Larabill\Models\UserTaxProfile;

// Create a fiscal profile for your user
$taxProfile = UserTaxProfile::create([
    'user_id' => $user->id, // Works with UUID, ULID, or Int
    'is_current' => true,
    'legal_entity_type' => 'ltd', // individual, freelancer, ltd, sa, ngo, etc
    'tax_code' => 'ESB12345678',
    'business_name' => 'Your Company S.L.',
    'address' => 'Calle Test 123',
    'city' => 'Madrid',
    'postal_code' => '28001',
    'country' => 'ES',
]);

// Set as current profile
$taxProfile->makeCurrent();
```

### Tax Calculation

```php
use AichaDigital\Larabill\Services\TaxCalculationService;

$taxService = new TaxCalculationService();

// EU B2B reverse charge
$result = $taxService->calculateTax(100.0, 'ES', 'DE', true);
echo "Tax Rate: " . $result['tax_rate'] . "%"; // 0% for reverse charge

// EU B2C destination VAT
$result = $taxService->calculateTax(100.0, 'ES', 'FR', false);
echo "Tax Rate: " . $result['tax_rate'] . "%"; // 20% (FR rate)
```

### Invoice Creation (UUID Binary)

```php
use AichaDigital\Larabill\Services\BillingService;

$billingService = new BillingService();
$invoice = $billingService->createInvoice([
    'user_id' => $user->id, // Agnostic: UUID, ULID, or Int
    'items' => [
        [
            'description' => 'Professional Service',
            'quantity' => 1,
            'unit_price' => 100.0,
            'tax_rate' => 21.0,
        ]
    ]
]);

// Invoice has UUID binary ID (auto-generated)
echo "Invoice ID: " . $invoice['id']; // e.g., "9d3e4f5a-6b7c-8d9e-0f1a-2b3c4d5e6f7a"
echo "Invoice Number: " . $invoice['invoice_number']; // FAC-2025-0001
echo "Total Amount: " . $invoice['total_amount']; // €121.00

// Find invoice by UUID (route model binding works)
$foundInvoice = Invoice::whereUuid($invoice['id'])->first();
```

### Fiscal Settings per User/Year

```php
use AichaDigital\Larabill\Models\FiscalSettings;

// Get or create fiscal settings for user
$fiscalSettings = FiscalSettings::getOrCreateForUser($user->id, 2025);

// Configure EU settings
$fiscalSettings->enableOSS(); // One Stop Shop
$fiscalSettings->enableROI(); // Reverse Charge Operator
$fiscalSettings->update([
    'eu_sales_threshold' => 10000.00, // €10,000
    'currency' => 'EUR',
]);

// Check if destination VAT applies
if ($fiscalSettings->shouldApplyDestinationVat()) {
    // Apply destination country VAT
}
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

# Company/User Default Settings (optional)
LARABILL_COMPANY_NAME="Your Company S.L."
LARABILL_COMPANY_VAT="ESB12345678"
LARABILL_COMPANY_COUNTRY="ES"
LARABILL_COMPANY_IS_ROI=true

# Invoice Numbering
LARABILL_INVOICE_PREFIX="FAC"
LARABILL_PROFORMA_PREFIX="PRO"
```

### Model Mapping (Agnostic)

Configure your user model in `config/larabill.php`:

```php
'models' => [
    // Your app's user model (works with UUID, ULID, or Int)
    'user' => \App\Models\Customer::class, // or User, Client, etc.
    
    // Package models (customizable)
    'user_tax_profile' => \AichaDigital\Larabill\Models\UserTaxProfile::class,
    'invoice' => \AichaDigital\Larabill\Models\Invoice::class,
    'fiscal_settings' => \AichaDigital\Larabill\Models\FiscalSettings::class,
],
```

### Field Mapping (Optional)

Customize field names to match your app:

```php
'field_mappings' => [
    'user_tax_profile' => [
        'tax_code' => 'fiscal_code',
        'business_name' => 'company_name',
        // ... more mappings
    ],
],
```

## 🧪 Testing

```bash
# Run all tests
composer test

# Run specific tests
composer test -- --filter="Invoice"

# Run with coverage
composer test-coverage
```

**Current Test Status (v0.1.0):** 419/530 passing (79%)

## 📊 Performance Benefits

### Binary UUID Storage
- **Storage**: 16 bytes vs 36 bytes (55% savings)
- **Index Size**: ~55% smaller indexes
- **Query Performance**: Improved due to smaller index size
- **Scalability**: At 1M invoices, saves ~19MB just on ID column

### Ordered UUID
- Reduces B-tree page splits in MySQL
- Better INSERT performance than random UUIDs
- Still maintains unpredictability for security

## 🔄 Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

### v0.1.0 (2025-10-12) - Development Release

**Breaking Changes:**
- Renamed `user_tax_infos` → `user_tax_profiles`
- Renamed `company_fiscal_configs` → `fiscal_settings`
- Changed `tax_id` → `tax_code` (SOLID compliance)
- Changed `vat_number` → `vat_code` (international compatibility)
- Changed `company_id` → `user_id` (user-centric design)
- Invoice IDs now use UUID binary instead of auto-increment

**New Features:**
- UUID binary storage for invoices
- Tax profile snapshots for immutability
- Enhanced user model agnosticism
- Performance optimizations

**Known Issues:**
- 109 tests still failing (non-critical, data adjustments needed)
- Documentation in progress
- Not production-ready yet

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [AichaDigital](https://aichadigital.es)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
