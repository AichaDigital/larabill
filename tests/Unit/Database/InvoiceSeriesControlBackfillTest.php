<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-390 — backfill of legacy NULL-scope invoice_series_control rows.
 *
 * Before the hardening, the unique index (prefix, serie, fiscal_year, user_id)
 * never fired for NULL scopes (NULL does not collide in MySQL/SQLite), so the
 * first-use race could leave DUPLICATE control rows for one logical series.
 * The backfill migration must collapse those duplicates (keeping the highest
 * counter, so no already-issued number is ever reused) and normalize the NULL
 * scope to the GLOBAL_SCOPE sentinel.
 *
 * Legacy rows are seeded with raw inserts on purpose: the model now defaults
 * user_id to the sentinel, and this test must reproduce the PRE-hardening
 * database state.
 */
const AID390_SENTINEL = '00000000-0000-0000-0000-000000000000';

function aid390LegacyRow(string $prefix, int $serie, int $lastNumber, ?string $userId = null): array
{
    return [
        'prefix'            => $prefix,
        'serie'             => $serie,
        'fiscal_year'       => 2026,
        'fiscal_year_start' => '2026-01-01',
        'fiscal_year_end'   => '2026-12-31',
        'last_number'       => $lastNumber,
        'start_number'      => 1,
        'reset_annually'    => true,
        'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
        'is_active'         => true,
        'user_id'           => $userId,
        'created_at'        => now(),
        'updated_at'        => now(),
    ];
}

function aid390RunBackfill(): void
{
    $migration = include __DIR__.'/../../../database/migrations/2026_07_10_000001_backfill_invoice_series_control_global_scope.php';
    $migration->up();
}

it('collapses duplicate NULL-scope controls keeping the highest counter', function () {
    // Pre-hardening state: the creation race left two controls for FAC/1/2026.
    DB::table('invoice_series_control')->insert([
        aid390LegacyRow('FAC', 1, 7),
        aid390LegacyRow('FAC', 1, 3),
    ]);

    aid390RunBackfill();

    $rows = DB::table('invoice_series_control')->where('prefix', 'FAC')->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->last_number)->toBe(7)
        ->and($rows->first()->user_id)->toBe(AID390_SENTINEL);
});

it('backfills every remaining NULL scope to the global sentinel', function () {
    DB::table('invoice_series_control')->insert([
        aid390LegacyRow('FAC', 1, 12),
        aid390LegacyRow('PRO', 0, 2),
    ]);

    aid390RunBackfill();

    expect(DB::table('invoice_series_control')->whereNull('user_id')->count())->toBe(0)
        ->and(DB::table('invoice_series_control')->where('user_id', AID390_SENTINEL)->count())->toBe(2);
});

it('leaves user-scoped and already-sentinel rows untouched', function () {
    $issuer = (string) Str::uuid();

    DB::table('invoice_series_control')->insert([
        aid390LegacyRow('FAC', 1, 5, $issuer),
        aid390LegacyRow('FAC', 1, 9, AID390_SENTINEL),
    ]);

    aid390RunBackfill();

    $scoped   = DB::table('invoice_series_control')->where('user_id', $issuer)->first();
    $sentinel = DB::table('invoice_series_control')->where('user_id', AID390_SENTINEL)->first();

    expect($scoped)->not->toBeNull()
        ->and((int) $scoped->last_number)->toBe(5)
        ->and($sentinel)->not->toBeNull()
        ->and((int) $sentinel->last_number)->toBe(9);
});
