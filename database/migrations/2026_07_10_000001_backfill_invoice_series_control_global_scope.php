<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AID-390 — normalize the issuer scope of invoice_series_control.
 *
 * The unique index (prefix, serie, fiscal_year, user_id) never fired for
 * NULL scopes (NULL does not collide in MySQL/SQLite unique indexes), so a
 * concurrent first use of a series could create DUPLICATE control rows and
 * fork the correlative numbering. The service now stores the GLOBAL_SCOPE
 * sentinel (InvoiceSeriesControl::GLOBAL_SCOPE) instead of NULL; this
 * migration brings existing data to that contract:
 *
 * 1. Collapse duplicate NULL-scope rows per logical series, keeping the row
 *    with the HIGHEST last_number (no already-issued number is ever reused).
 * 2. Backfill the remaining NULL scopes to the sentinel.
 */
return new class extends Migration
{
    /**
     * Issuer-scope sentinel (kept literal on purpose: it is a persisted
     * contract value, and the published migration stays self-contained).
     */
    private const GLOBAL_SCOPE = '00000000-0000-0000-0000-000000000000';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $survivorIds = DB::table('invoice_series_control')
            ->whereNull('user_id')
            ->orderByDesc('last_number')
            ->orderBy('id')
            ->get(['id', 'prefix', 'serie', 'fiscal_year'])
            ->unique(fn (object $row): string => $row->prefix.'|'.$row->serie.'|'.$row->fiscal_year)
            ->pluck('id')
            ->all();

        DB::table('invoice_series_control')
            ->whereNull('user_id')
            ->whereNotIn('id', $survivorIds)
            ->delete();

        DB::table('invoice_series_control')
            ->whereNull('user_id')
            ->update(['user_id' => self::GLOBAL_SCOPE]);
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op: this is a one-way data normalization. Reverting
     * the sentinel to NULL would reopen the duplicate-control hole, and the
     * collapsed duplicates cannot be restored anyway.
     */
    public function down(): void
    {
        //
    }
};
