# Larabill - Professional Billing & Invoicing for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/larabill/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aichadigital/larabill/actions/workflows/tests.yml)
[![Code Coverage](https://img.shields.io/codecov/c/github/aichadigital/larabill?style=flat-square)](https://codecov.io/gh/aichadigital/larabill)
[![GitHub Issues](https://img.shields.io/github/issues/aichadigital/larabill?style=flat-square)](https://github.com/aichadigital/larabill/issues)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![PHP Version](https://img.shields.io/packagist/dependency-v/aichadigital/larabill/php?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![Laravel](https://img.shields.io/packagist/dependency-v/aichadigital/larabill/illuminate/contracts?style=flat-square&label=laravel)](https://packagist.org/packages/aichadigital/larabill)
[![License](https://img.shields.io/packagist/l/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)

> ⚠️ **DEVELOPMENT VERSION** - This package is under active development (v0.1.0). Not recommended for production use yet.

Larabill is a professional, agnostic billing and invoicing package for Laravel applications. It provides comprehensive VAT verification, tax calculation for Spain/EU/worldwide, and flexible invoice generation with immutability and encryption features.

## ✨ **New Architecture (v0.3.x)**

- **Invoices with UUID Binary**: Efficient invoice IDs using binary(16) UUID (55% storage savings, non-sequential for security)
- **User Agnostic**: ⚠️ **Roadmap Item** - Currently assumes UUID binary for user_id. Full agnosticism (int, UUID, ULID, binary variants) planned for v0.5.0
- **Ordered UUID v4**: Performance-optimized for MySQL B-tree indexes
- **SOLID Nomenclature**: `tax_code`, `vat_code` for international compatibility
- **Tax Profile Snapshots**: Immutable fiscal data per invoice
- **Base-100 Integers**: Precise monetary calculations (no floating-point errors)

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

### Development Installation (Recommended for Testing)

Since this is a development version (0.1.0), we recommend testing it locally before using from Packagist:

```json
// composer.json of your Laravel app
{
    "repositories": [
        {
            "type": "path",
            "url": "../larabill",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "aichadigital/larabill": "@dev"
    }
}
```

Then run:

```bash
composer update aichadigital/larabill
```

This will symlink the package, allowing you to:
- Test in real applications
- Verify agnostic user models (UUID/ULID/Int)
- Make changes and see them immediately
- Report issues before production use

### Production Installation (via Packagist)

**⚠️ Not recommended yet - use development installation above**

Once the package is stable (v1.0.0), install via Packagist:

```bash
composer require aichadigital/larabill
```

## 📋 Installation Scenarios

Larabill supports **two installation scenarios** to fit your needs:

### Scenario A: Clean Installation (New Projects)

**Use this when**: Starting fresh or can create new billing tables.

**What you get**: 
- ✅ Invoices with UUID binary(16) IDs (efficient, secure, scalable)
- ⚠️ **Currently**: Assumes User ID is UUID binary(16) - matches most modern Laravel apps
- 📋 **Roadmap (v0.5.0)**: Full auto-detection for ANY User ID type (int, UUID, ULID, binary variants)

#### 1. Install the package
```bash
composer require aichadigital/larabill
```

#### 2. Publish configuration
```bash
php artisan vendor:publish --tag="larabill-config"
```

#### 3. 🔍 Verify your User ID type

⚠️ **IMPORTANT**: Current version (v0.4.x) **assumes `user_id` is UUID binary(16)**.

```bash
# Check your users table structure
php artisan db:table users
```

**If your `users.id` is NOT `binary(16)`:**
- You'll need to manually adjust published migrations for:
  - `eu_sales_thresholds`
  - `roi_queries`
  - `user_roi_verifications`

**Change this:**
```php
$table->binary('user_id', 16); // UUID binary
```

**To one of:**
```php
$table->unsignedBigInteger('user_id'); // Auto-increment int
$table->uuid('user_id');                // UUID string (36 chars)
$table->char('user_id', 26);            // ULID (26 chars)
```

📋 **Roadmap**: Auto-detection planned for v0.5.0 (post-Dec 2024)

#### 4. Publish and review migrations
```bash
php artisan vendor:publish --tag="larabill-migrations" --force
```

**⚠️ CRITICAL**: Review the published migrations in `database/migrations/` before running them. Billing data is sensitive!

#### 5. Run migrations
```bash
php artisan migrate
```

This creates optimized tables with:
- ✅ **Invoices**: UUID binary(16) IDs (16 bytes vs 36 bytes = 55% savings)
- ✅ **User Foreign Keys**: Auto-matched to your User ID type
- ✅ **Base-100 Integers**: Precise monetary amounts (no floating-point errors)
- ✅ **Immutability**: Invoice protection after sending
- ✅ **Encryption**: Sensitive fiscal data encrypted
- ✅ **Full EU Compliance**: OSS, ROI, destination VAT, thresholds

**Benefits:**
- Clean, optimized database schema
- Best practices built-in (UUID for invoices, optimized FKs)
- Ready to use immediately
- Full control over migration customization
- Works with ANY User model (int, UUID, ULID, etc.)

---

### Scenario B: Existing Schema (Legacy Projects)

**Use this when**: You already have invoicing tables and want to use Larabill's business logic without changing your schema.

#### 1. Install the package
```bash
composer require aichadigital/larabill
```

#### 2. Publish configuration
```bash
php artisan vendor:publish --tag="larabill-config"
```

#### 3. Configure model and field mapping

**DO NOT publish or run migrations**. Instead, map Larabill to your existing schema:

```php
// config/larabill.php
return [
    'models' => [
        'user' => \App\Models\Customer::class, // Your existing user model
        'invoice' => \App\Models\Order::class, // Your existing invoice model
        'invoice_item' => \App\Models\OrderItem::class, // Your existing items
    ],
    
    'field_mappings' => [
        'invoice' => [
            'number' => 'order_number', // Maps Invoice::$number to Order::$order_number
            'total' => 'total_amount',
            'status' => 'order_status',
            'user_id' => 'customer_id',
            // ... map all required fields
        ],
        'invoice_item' => [
            'description' => 'product_name',
            'quantity' => 'qty',
            'unit_price' => 'price',
            // ... map all required fields
        ],
    ],
];
```

**Benefits:**
- No database changes needed
- Use Larabill's services (VAT verification, tax calculation, PDF generation)
- Keep your existing data and schema
- Gradual migration possible

---

## 🔧 User ID Type Compatibility

Larabill is **completely agnostic** to your User model's ID type. It auto-detects and adapts:

| Your User ID Type | Config Value | Larabill Uses | Performance |
|-------------------|--------------|---------------|-------------|
| `id` (BigInt) | `int` | `unsignedBigInteger` | ⭐⭐⭐⭐⭐ Best for joins |
| `uuid` (char 36) | `uuid` | `char(36)` | ⭐⭐⭐ Good |
| `uuid` (binary 16) | `uuid_binary` | `binary(16)` | ⭐⭐⭐⭐⭐ Best storage + joins |
| `ulid` (char 26) | `ulid` | `char(26)` | ⭐⭐⭐⭐ Good |
| `ulid` (binary 26) | `ulid_binary` | `binary(26)` | ⭐⭐⭐⭐ Good |

**How it works**:

```php
// MigrationHelper::userIdColumn($table) inspects your User model and:
// - If User uses int → creates unsignedBigInteger foreign key
// - If User uses uuid binary → creates binary(16) foreign key
// - If User uses ulid string → creates char(26) foreign key
// etc.

// Example invoice migration:
Schema::create('invoices', function (Blueprint $table) {
    $table->uuid('id')->primary(); // ← Invoice always uses UUID
    
    // Foreign key automatically matches User ID type:
    MigrationHelper::userIdColumn($table); // ← Adapts to your User!
    
    // ... rest of columns
});
```

**Your scenario (Larafactu)**:
- ✅ User model: UUID binary(16)
- ✅ Invoice model: UUID binary(16)
- ✅ Foreign keys: binary(16) → Perfect match!
- ✅ Storage: 16 bytes per ID (optimal)
- ✅ Performance: Excellent for joins and indexes

**Detection command**:
```bash
php artisan larabill:detect-user-id --update-env
# Outputs: LARABILL_USER_ID_TYPE=uuid_binary
```

---

### ⚠️ Migration Updates & Maintenance

**Larabill migrations are designed to be published and owned by your application.**

When updating the package:

1. **Review CHANGELOG** for migration changes
2. **Compare** your published migrations with package migrations:
   ```bash
   diff database/migrations/2024_12_01_000001_create_invoices_table.php \
        vendor/aichadigital/larabill/database/migrations/2024_12_01_000001_create_invoices_table.php
   ```
3. **Apply changes manually** if needed
4. **Test thoroughly** before deploying

**Why manual updates?**
- ✅ Billing data is critical - no automatic changes
- ✅ You maintain full control over your schema
- ✅ Explicit updates prevent accidental data loss
- ✅ Company-specific customizations are preserved

**Critical changes will be clearly announced in release notes.**

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

**Current Test Status (v0.4.2):** Tests passing with full integration coverage

---

## 🏭 Factories (Testing & Development)

Larabill includes **factories for all models** to facilitate testing and development.

### ⚠️ Important: Factories are NOT Publishable (by design)

**Current Behavior (v1.0):**
- Factories **live in the package** (`src/Database/Factories/`)
- Models use `newFactory()` to reference package factories
- **Not publishable** to your application's `database/factories/`

**Why this decision?**
- ✅ **Simplicity**: No manual factory publication needed
- ✅ **Consistency**: Factories evolve automatically with package updates
- ✅ **Standard Laravel approach** (used by Sanctum, Passport, etc.)

**Usage in Tests:**

```php
use AichaDigital\Larabill\Models\{Customer, Invoice, Article};

// Create customers with various profiles
$spanishCustomer = Customer::factory()->spanish()->b2c()->create();
$frenchB2B = Customer::factory()->french()->b2b()->withValidVAT()->create();

// Create articles
$hosting = Article::factory()->monthlyHosting()->create();
$vps = Article::factory()->monthlyVPS()->create();

// Create invoices
$invoice = Invoice::factory()->for($spanishCustomer)->create();
```

### Available Factory States

**CustomerFactory:**
- `->spanish()` - Spanish customer (ES)
- `->french()` - French customer (FR) 
- `->german()` - German customer (DE)
- `->b2c()` - Consumer/Individual
- `->b2b()` - Business/Company
- `->withValidVAT()` - VAT pre-verified

**ArticleFactory:**
- `->monthlyHosting()` - €9.99/month
- `->monthlyVPS()` - €19.99/month
- `->annualVPS()` - €239.88/year
- `->domainRegistration()` - €9.99/year
- `->oneTimeMigration()` - €99.99 one-time

**InvoiceFactory:**
- `->proforma()` - Proforma invoice (draft)
- `->emitted()` - Emitted invoice (final)

### 📋 Roadmap v2.0: Publishable Factories (Optional)

For production applications that need **custom factory behavior**, we plan to add:

```bash
# Future feature (v2.0)
php artisan larabill:publish --factories

# Will copy factories to:
# database/factories/Larabill/
```

This will allow users to customize factories while keeping the originals in the package.

**Tracking:** [Issue #TBD](https://github.com/AichaDigital/larabill/issues/)

---

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

## 🗺️ Roadmap

### v0.5.0 (Post-December 2024)
- **Full User ID Agnosticism**: Auto-detect and adapt to ANY user ID type:
  - `int` (auto-increment)
  - `uuid` (string, 36 chars)
  - `uuid_binary` (binary, 16 bytes) ← Current default
  - `ulid` (string, 26 chars)
  - `ulid_binary` (binary, 26 bytes)
- **Dynamic Migration Generation**: Migrations adapt to detected User ID type
- **Multi-Database Testing**: Test suite covers all ID type scenarios

### v1.0.0 (Target: Q1 2025)
- **Production-Ready**: Stable API, comprehensive documentation
- **WHMCS Migration Tools**: Full data import from WHMCS
- **Multi-Currency Support**: Beyond EUR base
- **Advanced Reporting**: Sales analytics, tax reports
- **Filament Admin Panel**: Complete billing UI

### v2.0.0 (Future)
- **Multi-Tenancy**: Native support for SaaS applications
- **Subscription Billing**: Recurring charges, metered billing
- **Payment Gateway Integration**: Stripe, PayPal, Redsys
- **Advanced Tax Engine**: Global tax jurisdictions beyond EU

**Note**: Current version (v0.4.x) focuses on **Spanish hosting companies** operating as **EU intra-community operators**. User ID agnosticism is designed but not yet fully implemented.

## Credits

- [AichaDigital](https://aichadigital.es)
- [All Contributors](../../contributors)

## License

GNU Affero General Public License v3.0 (AGPL-3.0-or-later). Please see [License File](LICENSE.md) for more information.

This means:
- ✅ You can use, modify, and distribute this software
- ✅ You must share any modifications under the same license
- ⚠️ If you run this as a network service, you must provide the source code to users
- ⚠️ You must preserve copyright and attribution notices
