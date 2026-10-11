<?php

declare(strict_types=1);

// TestCase is bound to this directory in tests/Pest.php
// (InstallCommandMysqlTestCase → environment=production + redirected database_path).

describe('larabill:install — production install path on MySQL (AID-287)', function () {

    it('publishes stubs in $migrationOrder and installs the full schema with FKs intact', function () {
        // Sanity: the production override is actually in effect, otherwise the
        // ServiceProvider would auto-load the dev .php migrations and we would
        // be testing the wrong path.
        expect(app()->environment('production'))->toBeTrue();

        // Consumer-side users table (UUID-first, ADR-006). The install command
        // preflights users.id as UUID before publishing.
        $this->createUsersTable();

        // Production install path: publish the .php.stub files into
        // database_path('migrations') with incremental timestamps in
        // $migrationOrder order. In production + non-interactive the command
        // does NOT auto-run migrate (it only prints the next step).
        $this->artisan('larabill:install', [
            '--force'          => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // Run the published stubs — the only migrations registered in production.
        // A wrong FK order in $migrationOrder would make this fail.
        $this->artisan('migrate', [
            '--force'    => true,
            '--database' => 'testing',
        ])->assertExitCode(0);

        // Every create_* table the install command is responsible for must exist
        // after installing in production order. This list mirrors the 24 create_*
        // entries of $migrationOrder; adding a table to the package must update it.
        $expectedTables = [
            'legal_entity_types',
            'tax_rates',
            'tax_groups',
            'tax_group_tax_rate',
            'tax_categories',
            'unit_measures',
            'country_vat_rates',
            'vat_categories',
            'user_tax_infos',
            'user_tax_profiles',
            'company_fiscal_configs',
            'invoice_series_control',
            'invoices',
            'invoice_items',
            'articles',
            'article_prices',
            'article_overrides',
            'article_service_status',
            'commissions',
            'invoice_templates',
            'company_template_settings',
            'eu_sales_thresholds',
            'grouped_payments',
            'grouped_payment_invoice',
            'agreement_causes',
        ];

        foreach ($expectedTables as $table) {
            expect($this->mysqlTableExists($table))
                ->toBeTrue("Table `{$table}` missing after production install order");
        }

        // Every $migrationOrder entry (25 create + 8 modifiers + 2 data
        // backfills AID-390/AID-555 + the two ADR-015 cadence re-keys + the
        // ADR-015 cause vocabulary: table + closure_cause_id FK + the AID-949
        // slice B status program + the AID-953 modality column + the AID-953
        // guarantee capability + snapshot + registry + settlement key + the
        // AID-895 import-support columns: invoices.is_historical + the
        // article_service_status.origin_key UNIQUE idempotency key) applied
        // cleanly. AID-307 adds the invoices
        // series unique-index swap; AID-429 adds the prefix widening; AID-450
        // adds the phantom-template cleanup; AID-555 adds the proforma-link
        // backfill; AID-952 re-keys the article_prices and
        // article_service_status cadence; AID-949 adds the agreement_causes
        // vocabulary, the closure_cause_id FK and the §4.2 status program;
        // AID-953 adds the modality column, then the guarantee capability +
        // snapshot + registry + settlement key; AID-895 adds the import
        // support columns (invoices.is_historical, article_service_status
        // .origin_key UNIQUE).
        expect($this->appliedMigrationCount())->toBe(44);

        // The package defines inter-table FK constraints (e.g. invoice_items →
        // invoices, tax_group_tax_rate → tax_groups/tax_rates). They could only
        // be created if every referenced table already existed at that point in
        // the install order — so a non-zero FK count is positive proof the
        // $migrationOrder sequence is FK-safe, not just that tables exist.
        expect($this->foreignKeyConstraintCount())->toBeGreaterThan(0);
    });
});
