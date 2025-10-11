<?php

declare(strict_types=1);

return [
    // VAT verification API settings
    'vat_apis' => [
        'abstractapi' => [
            'key'     => env('LARABILL_ABSTRACTAPI_KEY'),
            'url'     => env('LARABILL_ABSTRACTAPI_URL', 'https://vat.abstractapi.com/v1/validate/'),
            'timeout' => env('LARABILL_ABSTRACTAPI_TIMEOUT', 10),
        ],
        'apilayer' => [
            'key'     => env('LARABILL_APILAYER_KEY'),
            'url'     => env('LARABILL_APILAYER_URL', 'http://apilayer.net/api/validate'),
            'timeout' => env('LARABILL_APILAYER_TIMEOUT', 10),
        ],
        'preferred_api'       => env('LARABILL_VAT_PREFERRED_API', 'abstractapi'), // 'abstractapi' | 'apilayer'
        'cache_duration_days' => env('LARABILL_VAT_CACHE_DAYS', 30), // How long to cache VAT verification results
    ],

    // Company fiscal data
    'company' => [
        'name'       => env('LARABILL_COMPANY_NAME', 'Your Company S.L.'),
        'vat_number' => env('LARABILL_COMPANY_VAT', 'ESB12345678'),
        'country'    => env('LARABILL_COMPANY_COUNTRY', 'ES'),
        'is_roi'     => env('LARABILL_COMPANY_IS_ROI', true), // Registered in EU VAT One Stop Shop
    ],

    // EU Countries for VAT rules
    'eu_countries' => [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ],

    // Invoice numbering configuration
    'invoice_numbering' => [
        'proforma_prefix'         => 'PRO',
        'invoice_prefix'          => 'FAC',
        'suffix_format'           => 'Y', // 'Y' for yearly reset, 'N' for continuous numeric
        'start_number'            => 1,
        'fiscal_year_start_month' => 1, // 1 for January, 7 for July, etc.
    ],

    // Model mappings for extensibility
    'models' => [
        'user'              => \AichaDigital\Larabill\Tests\Models\User::class, // Your application's User model
        'user_tax_profile'  => \AichaDigital\Larabill\Models\UserTaxProfile::class,
        'invoice'           => \AichaDigital\Larabill\Models\Invoice::class,
        'invoice_item'      => \AichaDigital\Larabill\Models\InvoiceItem::class,
        'tax_rate'          => \AichaDigital\Larabill\Models\TaxRate::class,
        'vat_verification'  => \AichaDigital\Larabill\Models\VatVerification::class,
        'fiscal_settings'   => \AichaDigital\Larabill\Models\FiscalSettings::class,
    ],

    // Field mappings for custom field names
    'field_mappings' => [
        'user_tax_profile' => [
            // 'user_id' => 'customer_id',
            // 'tax_code' => 'fiscal_code',
            // 'business_name' => 'company_name',
            // 'address' => 'street_address',
            // 'city' => 'municipality',
            // 'postal_code' => 'zip_code',
            // 'country' => 'country_code',
            // 'state' => 'region',
            // 'phone' => 'contact_phone',
        ],
        'fiscal_settings' => [
            // 'user_id' => 'customer_id',
        ],
        'vat_verification' => [
            // 'vat_code' => 'tax_number',
        ],
    ],

    // PDF generation settings
    'pdf' => [
        'font_path'           => storage_path('fonts/'),
        'font_cache'          => storage_path('fonts/'),
        'temp_dir'            => sys_get_temp_dir(),
        'chroot'              => realpath(base_path()),
        'log_output'          => false,
        'enable_html5_parser' => true,
        'enable_css_float'    => true,
        'enable_php'          => true,
        'enable_remote'       => true,
    ],

    // Destination VAT settings
    'destination_vat' => [
        'default_threshold'      => 10000.0, // €10,000.00 (Base100 uses floats)
        'currency'               => 'EUR',
        'fiscal_year_start'      => '01-01', // MM-DD format
        'auto_apply_destination' => true,
    ],
];
