<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AID-555 (D2) — backfill the canonical proforma link on converted invoices.
 *
 * convertProformaToInvoice() persisted only the inverse mirror
 * (proforma.converted_invoice_id); invoices.proforma_id — the column the
 * proforma() belongsTo relation reads — was never written, so the
 * invoice→proforma direction was born broken. The service now writes it at
 * conversion time; this migration completes the link on already-converted
 * pairs from the mirror, without touching consumer-written links.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('invoices')
            ->whereNotNull('converted_invoice_id')
            ->select(['id', 'converted_invoice_id'])
            ->orderBy('id')
            ->chunkById(500, function ($proformas): void {
                foreach ($proformas as $proforma) {
                    DB::table('invoices')
                        ->where('id', $proforma->converted_invoice_id)
                        ->whereNull('proforma_id')
                        ->update(['proforma_id' => $proforma->id]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op: this is a one-way data completion. Nulling
     * proforma_id again could not distinguish backfilled links from
     * consumer-written ones.
     */
    public function down(): void
    {
        //
    }
};
