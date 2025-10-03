<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Larabill Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Larabill billing and invoicing module.
    | This file allows customization of models, tax rates, and other settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which models to use for users and tax information.
    | These can be customized to match your application's structure.
    |
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
    |
    | Your company's fiscal information for invoice generation.
    |
    */
    'company' => [
        'name' => env('LARABILL_COMPANY_NAME', 'Your Company S.L.'),
        'vat_number' => env('LARABILL_COMPANY_VAT_NUMBER', 'ESB12345678'),
        'address' => env('LARABILL_COMPANY_ADDRESS', 'Your Address'),
        'city' => env('LARABILL_COMPANY_CITY', 'Your City'),
        'postal_code' => env('LARABILL_COMPANY_POSTAL_CODE', '12345'),
        'country' => env('LARABILL_COMPANY_COUNTRY', 'ES'),
        'is_roi' => env('LARABILL_COMPANY_IS_ROI', true), // Reverse Charge Operator
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Numbering
    |--------------------------------------------------------------------------
    |
    | Configuration for invoice and proforma numbering.
    |
    */
    'numbering' => [
        'invoice_prefix' => env('LARABILL_INVOICE_PREFIX', 'FAC'),
        'proforma_prefix' => env('LARABILL_PROFORMA_PREFIX', 'PRO'),
        'start_number' => env('LARABILL_START_NUMBER', 1),
        'yearly_reset' => env('LARABILL_YEARLY_RESET', true),
        'fiscal_year_start' => env('LARABILL_FISCAL_YEAR_START', '01-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT Verification APIs
    |--------------------------------------------------------------------------
    |
    | Configuration for VAT number verification services.
    |
    */
    'vat_apis' => [
        'abstractapi' => [
            'key' => env('LARABILL_ABSTRACTAPI_KEY'),
            'url' => 'https://vat.abstractapi.com/v1/validate/',
            'timeout' => 10,
        ],
        'apilayer' => [
            'key' => env('LARABILL_APILAYER_KEY'),
            'url' => 'http://apilayer.net/api/validate',
            'timeout' => 10,
        ],
        'preferred_api' => env('LARABILL_PREFERRED_API', 'abstractapi'),
        'cache_duration_days' => env('LARABILL_CACHE_DURATION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | EU Countries
    |--------------------------------------------------------------------------
    |
    | List of EU countries for tax calculation purposes.
    |
    */
    'eu_countries' => [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ],

    /*
    |--------------------------------------------------------------------------
    | Spanish Special Regions
    |--------------------------------------------------------------------------
    |
    | Special tax regions in Spain with different tax rules.
    |
    */
    'spanish_special_regions' => [
        'canarias' => ['ES35', 'ES38'], // Las Palmas, Tenerife
        'ceuta_melilla' => ['ES51', 'ES52'], // Ceuta, Melilla
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Tax Rates
    |--------------------------------------------------------------------------
    |
    | Default tax rates for different regions and types.
    |
    */
    'default_tax_rates' => [
        'es' => [
            'general' => 21.0,
            'reduced' => 10.0,
            'super_reduced' => 4.0,
            'exempt' => 0.0,
        ],
        'canarias' => [
            'general' => 7.0, // IGIC
            'reduced' => 3.0,
            'exempt' => 0.0,
        ],
        'ceuta_melilla' => [
            'general' => 0.0, // IPSI handled separately
            'exempt' => 0.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    |
    | General invoice generation and management settings.
    |
    */
    'invoice' => [
        'immutability_enabled' => env('LARABILL_IMMUTABILITY_ENABLED', true),
        'encryption_enabled' => env('LARABILL_ENCRYPTION_ENABLED', true),
        'pdf_generation' => env('LARABILL_PDF_GENERATION', true),
        'default_currency' => env('LARABILL_DEFAULT_CURRENCY', 'EUR'),
        'payment_terms_days' => env('LARABILL_PAYMENT_TERMS_DAYS', 30),
    ],
];
