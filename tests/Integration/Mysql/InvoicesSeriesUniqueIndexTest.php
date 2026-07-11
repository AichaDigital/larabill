<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/.

/*
 * AID-307 — the invoices uniqueness hangs from the real fiscal series.
 *
 * The unique key moved from (serie, series_number, fiscal_year) to
 * (prefix, serie, series_number, fiscal_year). This test proves, on real
 * MySQL, that the index changed shape AND changed behaviour: two real series
 * of the same fiscal type and number (which collided under the old key) now
 * coexist, while an exact duplicate under the new key is still rejected.
 */

function insertSeriesInvoice(string $userId, string $prefix, int $serie, int $seriesNumber, int $year, string $fiscalNumber): void
{
    DB::table('invoices')->insert([
        'id'               => Uuid::uuid7()->toString(),
        'fiscal_number'    => $fiscalNumber,
        'prefix'           => $prefix,
        'serie'            => $serie,
        'series_number'    => $seriesNumber,
        'fiscal_year'      => $year,
        'invoice_date'     => "{$year}-03-01",
        'user_id'          => $userId,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
}

describe('invoices series unique index (AID-307)', function () {
    it('carries the real series in the composite unique and drops the type-only one', function () {
        $this->bootstrap();

        expect($this->getUniqueIndexColumns('invoices', 'invoices_prefix_serie_series_number_fiscal_year_unique'))
            ->toBe(['prefix', 'serie', 'series_number', 'fiscal_year']);

        // The old type-only unique must be gone.
        expect($this->getUniqueIndexColumns('invoices', 'invoices_serie_series_number_fiscal_year_unique'))
            ->toBe([]);
    });

    it('lets two real series of the same type and number coexist (would collide under the old key)', function () {
        $this->bootstrap();
        $userId = $this->seedUser();

        insertSeriesInvoice($userId, 'FAC', 1, 1, 2026, 'FAC-2026-000001');
        insertSeriesInvoice($userId, 'EXPORT', 1, 1, 2026, 'EXPORT-2026-000001');

        expect(DB::table('invoices')->count())->toBe(2);
    });

    it('still rejects an exact duplicate under the new key', function () {
        $this->bootstrap();
        $userId = $this->seedUser();

        insertSeriesInvoice($userId, 'FAC', 1, 1, 2026, 'FAC-2026-000001');

        // Same (prefix, serie, series_number, fiscal_year), different fiscal_number
        // to isolate the composite unique from the fiscal_number global unique.
        $duplicate = fn () => insertSeriesInvoice($userId, 'FAC', 1, 1, 2026, 'FAC-2026-DUP');

        expect($duplicate)->toThrow(QueryException::class);
    });
});
