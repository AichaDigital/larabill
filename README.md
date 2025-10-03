# Larabill - Professional Billing & Invoicing for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/larabill/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aichadigital/larabill/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aichadigital/larabill/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aichadigital/larabill/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/aichadigital/larabill.svg?style=flat-square)](https://packagist.org/packages/aichadigital/larabill)

Larabill is a professional billing and invoicing module for Laravel applications. It provides comprehensive VAT verification, tax calculation for Spain/UE/worldwide, and flexible invoice generation with immutability and encryption features.

## Features

- **VAT Verification**: Integration with AbstractAPI and APILayer for real-time VAT number validation
- **Tax Calculation**: Support for Spanish (IVA), Canary Islands (IGIC), Ceuta/Melilla (IPSI), EU reverse charge, and worldwide taxation
- **Invoice Management**: Sequential numbering, proforma invoices, and immutable invoice records
- **Data Security**: Encryption and immutability options for fiscal data and invoices
- **Agnostic Design**: Configurable models and field mappings for different Laravel applications
- **PDF Generation**: Built-in PDF invoice generation using DomPDF
- **EU Compliance**: Full support for EU B2B reverse charge and destination VAT rules

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

This is the contents of the published config file:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    */
    'models' => [
        'user' => \App\Models\User::class,
        'user_tax_info' => \AichaDigital\Larabill\Models\UserTaxInfo::class,
        'invoice' => \AichaDigital\Larabill\Models\Invoice::class,
        'invoice_item' => \AichaDigital\Larabill\Models\InvoiceItem::class,
        'tax_rate' => \AichaDigital\Larabill\Models\TaxRate::class,
        'vat_verification' => \AichaDigital\Larabill\Models\VatVerification::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Company Information
    |--------------------------------------------------------------------------
    */
    'company' => [
        'name' => env('LARABILL_COMPANY_NAME', 'Your Company S.L.'),
        'vat_number' => env('LARABILL_COMPANY_VAT_NUMBER', 'ESB12345678'),
        'country' => env('LARABILL_COMPANY_COUNTRY', 'ES'),
        'is_roi' => env('LARABILL_COMPANY_IS_ROI', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT Verification APIs
    |--------------------------------------------------------------------------
    */
    'vat_apis' => [
        'abstractapi' => [
            'key' => env('LARABILL_ABSTRACTAPI_KEY'),
            'url' => 'https://vat.abstractapi.com/v1/validate/',
        ],
        'apilayer' => [
            'key' => env('LARABILL_APILAYER_KEY'),
            'url' => 'http://apilayer.net/api/validate',
        ],
    ],
];
```

## Usage

### VAT Verification

```php
use AichaDigital\Larabill\Services\VatVerificationService;

$vatService = new VatVerificationService();
$result = $vatService->verifyVatNumber('ESB12345678', 'ES');

if ($result['is_valid']) {
    echo "Valid VAT number for: " . $result['company_name'];
}
```

### Tax Calculation

```php
use AichaDigital\Larabill\Services\TaxCalculationService;

$taxService = new TaxCalculationService();
$result = $taxService->calculateTax(100.0, 'ES', 'DE', true); // EU B2B reverse charge

echo "Tax Rate: " . $result['tax_rate'] . "%"; // 0% for reverse charge
echo "Tax Amount: " . $result['tax_amount']; // 0.0
```

### Invoice Creation

```php
use AichaDigital\Larabill\Services\BillingService;

$billingService = new BillingService();
$invoice = $billingService->createInvoice([
    'user_id' => 1,
    'items' => [
        [
            'description' => 'Professional Service',
            'quantity' => 1,
            'unit_price' => 100.0,
            'tax_rate' => 21.0,
        ]
    ]
]);

echo "Invoice Number: " . $invoice['invoice_number']; // FAC-0001
echo "Total Amount: " . $invoice['total_amount']; // 121.0
```

## Environment Variables

Add these to your `.env` file:

```env
# Company Information
LARABILL_COMPANY_NAME="Your Company S.L."
LARABILL_COMPANY_VAT_NUMBER="ESB12345678"
LARABILL_COMPANY_COUNTRY="ES"
LARABILL_COMPANY_IS_ROI=true

# VAT Verification APIs
LARABILL_ABSTRACTAPI_KEY="your_abstractapi_key"
LARABILL_APILAYER_KEY="your_apilayer_key"
LARABILL_PREFERRED_API="abstractapi"
LARABILL_CACHE_DURATION_DAYS=30

# Invoice Settings
LARABILL_INVOICE_PREFIX="FAC"
LARABILL_PROFORMA_PREFIX="PRO"
LARABILL_START_NUMBER=1
LARABILL_YEARLY_RESET=true
LARABILL_IMMUTABILITY_ENABLED=true
LARABILL_ENCRYPTION_ENABLED=true
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [AichaDigital](https://aichadigital.es)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
