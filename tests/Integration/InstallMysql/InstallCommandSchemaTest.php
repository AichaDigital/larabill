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
        // after installing in production order. This list mirrors the 27 create_*
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
            'vat_verifications',
            'invoice_templates',
            'company_template_settings',
            'eu_sales_thresholds',
            'roi_queries',
            'user_roi_verifications',
            'grouped_payments',
            'grouped_payment_invoice',
        ];

        foreach ($expectedTables as $table) {
            expect($this->mysqlTableExists($table))
                ->toBeTrue("Table `{$table}` missing after production install order");
        }

        // Every $migrationOrder entry (27 create + 7 modifiers) applied cleanly.
        expect($this->appliedMigrationCount())->toBe(34);

        // The package defines inter-table FK constraints (e.g. invoice_items →
        // invoices, tax_group_tax_rate → tax_groups/tax_rates). They could only
        // be created if every referenced table already existed at that point in
        // the install order — so a non-zero FK count is positive proof the
        // $migrationOrder sequence is FK-safe, not just that tables exist.
        expect($this->foreignKeyConstraintCount())->toBeGreaterThan(0);
    });
});
