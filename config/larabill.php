<?php

declare(strict_types=1);
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxRate;
use App\Models\User;

return [
    // User model class (used by relationships and factories).
    // The model's `id` column MUST be UUID v7 char(36). See docs/setup-uuid.md.
    'user_model' => env('LARABILL_USER_MODEL', User::class),

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
        // Fiscal series (the AEAT NumSerieFactura series component) per fiscal
        // type. Resolved by InvoiceSeriesResolver (AID-307). A consumer may
        // also pass an explicit series per invoice to run multiple series for
        // the same fiscal type (RD 1619/2012 art. 6). Keys match
        // InvoiceSerieType::label().
        //
        // NO DEFAULT VALUE ON PURPOSE (AID-589). A fiscal series is a business
        // decision — one installation runs 'FAC', another runs per-tenant
        // series like 'CASTRIS'/'AAICHA'. Before AID-589 this shipped with
        // 'FAC'/'PRO'/'RECT'/'TIK' baked in, so a fresh install that never
        // touched this file still emitted an invented fiscal series with no
        // warning. Set the env var (or the key directly) for every fiscal
        // type you actually issue, BEFORE creating invoices of that type —
        // an unresolved type throws MissingFiscalSeriesException instead of
        // guessing.
        'series' => [
            'invoice'       => env('LARABILL_SERIES_INVOICE'),
            'proforma'      => env('LARABILL_SERIES_PROFORMA'),
            'rectificative' => env('LARABILL_SERIES_RECTIFICATIVE'),
            'simplified'    => env('LARABILL_SERIES_SIMPLIFIED'),
        ],

        // Legacy prefix keys — resolver fallback for a v4 consumer that
        // upgraded without re-publishing this config. NO DEFAULT VALUE either
        // (AID-589, same reasoning as 'series' above): only set these if your
        // installation genuinely used FAC/PRO historically and wants that
        // preserved without touching the 'series' map.
        'proforma_prefix'         => null,
        'invoice_prefix'          => null,
        'suffix_format'           => 'Y', // 'Y' for yearly reset, 'N' for continuous numeric
        'start_number'            => 1,
        'fiscal_year_start_month' => 1, // 1 for January, 7 for July, etc.
    ],

    // Model mappings for extensibility. The user model is deliberately NOT
    // listed here: it resolves through 'user_model' above (LARABILL_USER_MODEL).
    // Set 'models.user' only as an explicit override — when present and the
    // class exists, it wins over 'user_model' (AID-553).
    'models' => [
        'invoice'               => Invoice::class,
        'invoice_item'          => InvoiceItem::class,
        'tax_rate'              => TaxRate::class,
        'company_fiscal_config' => CompanyFiscalConfig::class,
    ],

    // Field mappings for custom field names
    'field_mappings' => [
        //
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

        /*
        | Whether this installation's invoices must carry the fiscal verification
        | QR. It declares the DOCUMENT CONTRACT, not a legal obligation: larabill
        | does not know the taxpayer type, exclusions, elected mode or effective
        | adoption date. The consumer turns it on when it actually operates that
        | flow — voluntarily during the trial period, or once obliged.
        |
        | VeriFACTU calendar (BOE, RD 1007/2023 consolidated:
        | https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840 — and AEAT's
        | deadline-extension notice:
        | https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html):
        | 2027-01-01 for corporate income tax payers, 2027-07-01 for the rest of
        | art. 3.1 obliged parties; before that the period is a trial one.
        |
        | true  => a fiscal invoice without a coherent record and a usable QR is
        |          a hard failure: no PDF is produced.
        | false => such an invoice is rendered without the tax block.
        */
        'require_fiscal_verification_qr' => env('LARABILL_REQUIRE_FISCAL_QR', false),

        /*
        | The safe-restyle guarantee (ADR-011, AID-502). After rendering a
        | FISCAL invoice, larabill asserts that every non-empty mandatory
        | fiscal datum handed to the template (number, dates, party
        | identification, per-line and per-rate figures, totals — RD 1619/2012
        | arts. 6/7) appears in the output: a consumer template may restyle
        | everything, but it may not silently drop or rewrite fiscal values.
        |
        | true  => a template that drops mandatory content is an explicit
        |          failure (FiscalContentMissingException at the frontier).
        | false => the installation assumes the risk of emitting non-compliant
        |          documents from its own templates. Proformas are never
        |          validated (not fiscal documents).
        */
        'validate_fiscal_content' => env('LARABILL_PDF_VALIDATE_FISCAL_CONTENT', true),
    ],

    // Destination VAT settings
    'destination_vat' => [
        'default_threshold'      => 1000000, // €10,000.00 in base-100 minor units (FixedDecimal:2)
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

        // Fail loudly (before issuing anything) when no
        // RecurringEmissionHookContract binding exists (AID-836). Enable it
        // when your compliance path (e.g. Verifactu registration, OSS
        // accumulation) runs inside the emission boundary and must never be
        // silently skipped. The gate does not apply to dry runs. The
        // effective default lives at the read site, not in this file.
        'require_emission_hook' => (bool) env('LARABILL_REQUIRE_EMISSION_HOOK', false),
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
