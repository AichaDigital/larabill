<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\Commission;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/.

/**
 * AID-242 — guardrail against orphan $fillable entries.
 *
 * Every column a core model declares as mass-assignable must exist in its table.
 * SQLite silently ignores an assignment to a non-existent column, so an orphan
 * goes unnoticed there; only a real MySQL schema check catches it.
 *
 * KNOWN_ORPHANS is an allow-list of pre-existing orphans that are out of scope
 * for AID-242 and tracked as follow-ups (see the issue). It may only shrink:
 * any NEW orphan fails the test. Pattern mirrors LARABILL_KNOWN_SCHEMA_DIVERGENCES.
 */
describe('AID-242 — fillable ↔ schema consistency (MySQL)', function () {

    it('has no unexpected orphan fillable columns in core models', function () {
        $this->bootstrap();

        $models = [
            InvoiceItem::class => 'invoice_items',
            Commission::class  => 'commissions',
            Invoice::class     => 'invoices',
            Article::class     => 'articles',
        ];

        // Pre-existing orphans in Invoice::$fillable, out of scope for AID-242 and
        // tracked as a follow-up. Residue of the ADR-003 Customer→User / fiscal-snapshot
        // refactor: these fillable keys have no column. `user_tax_info_encrypted` is
        // additionally read in code (EuSalesThresholdService, PDF) so it may be a latent
        // fiscal bug. This list may only shrink — a NEW orphan fails the test.
        $knownOrphans = [
            'invoices.user_tax_info_encrypted',
            'invoices.customer_data',
            'invoices.fiscal_data',
            'invoices.vat_verification',
        ];

        $orphans = [];
        foreach ($models as $model => $table) {
            foreach ((new $model)->getFillable() as $column) {
                if ($this->getMysqlColumnType($table, $column) === '') {
                    $orphans[] = "{$table}.{$column}";
                }
            }
        }

        $unexpected = array_values(array_diff($orphans, $knownOrphans));

        expect($unexpected)->toBe([], 'Unexpected orphan fillable columns: '.implode(', ', $unexpected));
    });

});
