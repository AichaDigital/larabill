<?php

declare(strict_types=1);
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Customer;
use AichaDigital\Larabill\Models\CustomerFiscalData;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Models\VatVerification;
use App\Models\User;

return [
    // User model class (used by relationships and factories).
    // The model's `id` column MUST be UUID v7 char(36). See docs/setup-uuid.md.
    'user_model' => env('LARABILL_USER_MODEL', User::class),

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

    // Regional configuration (v0.3.3+)
    'region' => [
        'country'     => env('LARABILL_COUNTRY', 'ES'),        // ISO 3166-1 alpha-2
        'region'      => env('LARABILL_REGION', null),          // State/Province (US: 'CA', 'NY')
        'tax_system'  => env('LARABILL_TAX_SYSTEM', 'vat'), // vat, sales_tax, gst, hst
        'fiscal_zone' => env('LARABILL_FISCAL_ZONE', 'eu'), // eu, us, au, ca, other
    ],

    // Fiscal compliance rules (v0.3.3+)
    'compliance' => [
        'requires_correlative_numbering' => true,  // CEE: true, USA: false
        'requires_service_dates'         => true,          // CEE servicios: true
        'requires_tax_verification'      => false,      // CEE B2B: true
        'requires_fiscal_qr'             => false,             // España TBAI: true
    ],

    // Fiscal year configuration (v0.3.3+)
    'fiscal_year' => [
        'start_month' => env('LARABILL_FISCAL_START_MONTH', 1), // 1=Enero
        'start_day'   => env('LARABILL_FISCAL_START_DAY', 1),     // 1
    ],

    // Legal retention (lara-privacy `LegallyRetainable` contract).
    // How long a fiscal invoice must be kept, anchored at the end of the fiscal
    // year of its legal date. The duration lives here, not as a magic number in
    // the model. Default 6 years: Código de Comercio art. 30 (commercial books);
    // the 4-year LGT tax prescription is shorter, so 6 is the conservative hold.
    'retention' => [
        'fiscal_years' => (int) env('LARABILL_RETENTION_FISCAL_YEARS', 6),
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
        'user'                  => User::class, // Your application's User model
        'invoice'               => Invoice::class,
        'invoice_item'          => InvoiceItem::class,
        'tax_rate'              => TaxRate::class,
        'vat_verification'      => VatVerification::class,
        'company_fiscal_config' => CompanyFiscalConfig::class,
        'customer_fiscal_data'  => CustomerFiscalData::class,
        'customer'              => Customer::class,
    ],

    // Field mappings for custom field names
    'field_mappings' => [
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

    /*
    |--------------------------------------------------------------------------
    | Admin Notifications Configuration
    |--------------------------------------------------------------------------
    |
    | Configure admin notifications for critical system events.
    | Used for fiscal integrity alerts and other critical notifications.
    |
    */
    'admin' => [
        // Admin email(s) for critical notifications (comma-separated for multiple)
        'email' => env('LARABILL_ADMIN_EMAIL'),

        // Admin panel path (for notification action URLs)
        'path' => env('LARABILL_ADMIN_PATH', '/admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue behavior for Larabill background jobs.
    | If queue_connection is null, uses default Laravel queue connection.
    | Supported drivers: redis, sqs, beanstalkd, sync (no queue)
    |
    */
    'queue' => [
        // Queue connection (redis, sqs, beanstalkd, sync, etc.)
        'connection' => env('LARABILL_QUEUE_CONNECTION'),

        // Specific queue name for Larabill jobs
        'name' => env('LARABILL_QUEUE_NAME', 'default'),

        // Number of times to retry failed jobs
        'tries' => (int) env('LARABILL_QUEUE_TRIES', 3),

        // Timeout in seconds
        'timeout' => (int) env('LARABILL_QUEUE_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recurring Billing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic recurring billing generation.
    | Invoices are generated X days in advance of the service billing date.
    |
    */
    'recurring_billing' => [
        // Days in advance to generate invoices (global default)
        // Can be overridden per article via billing_days_in_advance field
        'days_in_advance' => (int) env('LARABILL_BILLING_DAYS_IN_ADVANCE', 7),

        // Schedule time for daily recurring billing run (24h format: HH:MM)
        'schedule_time' => env('LARABILL_BILLING_SCHEDULE_TIME', '00:00'),

        // Send email notifications after creating invoices
        'send_notifications' => (bool) env('LARABILL_BILLING_SEND_NOTIFICATIONS', true),

        // Payment terms in days (for calculating due_date)
        'payment_terms_days' => (int) env('LARABILL_PAYMENT_TERMS_DAYS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Reminders Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic payment reminder emails for unpaid invoices.
    | Disabled if 'enabled' is false.
    |
    */
    'payment_reminders' => [
        // Enable automatic payment reminders
        'enabled' => (bool) env('LARABILL_REMINDERS_ENABLED', true),

        // Schedule time for daily reminder run (24h format: HH:MM)
        'schedule_time' => env('LARABILL_REMINDERS_SCHEDULE_TIME', '10:00'),

        // Days before due date for first reminder
        'first_reminder_days' => (int) env('LARABILL_REMINDER_FIRST', 7),

        // Days before due date for second reminder
        'second_reminder_days' => (int) env('LARABILL_REMINDER_SECOND', 3),

        // Days after due date for overdue notice
        'overdue_days' => (int) env('LARABILL_REMINDER_OVERDUE', 1),

        // Days after due date for second overdue + suspension warning
        'overdue_suspension_days' => (int) env('LARABILL_REMINDER_SUSPENSION', 7),
    ],
];
