<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AID-429 — the fiscal series width must match the norm, not an arbitrary cap.
 *
 * The AEAT VERI*FACTU schema (SuministroInformacion.xsd) types NumSerieFactura
 * as TextoIDFacturaType with maxLength=60. larabill composes that field as
 * `prefix . series_number` (VerifactuAdapter sends serie=prefix and the raw
 * unpadded correlative, worst case 10 digits for an unsigned int), so the
 * honest ceiling for the series literal is 60 − 10 = 50 characters. The
 * original varchar(10) was an artificial cap at 1/5 of what the norm allows
 * and rejected legitimate series names (e.g. RECT-CASTRIS).
 *
 * Widening is data-safe by construction: no existing value can be truncated,
 * no index loses selectivity, and every unique key keeps working (the columns
 * only grow). Both tables move together so the numbering counter
 * (invoice_series_control) can always hold any series an invoice carries.
 *
 * InvoiceSeriesResolver::MAX_LENGTH guards the same 50-char contract at the
 * application layer — the resolver and these columns must stay in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('prefix', 50)
                ->default('FAC')
                ->comment('Real fiscal series (AEAT NumSerieFactura component). Max 50 chars: XSD TextoIDFacturaType(60) minus 10-digit worst-case correlative (AID-429).')
                ->change();
        });

        Schema::table('invoice_series_control', function (Blueprint $table): void {
            $table->string('prefix', 50)
                ->comment('Real fiscal series this counter numbers. Max 50 chars: XSD TextoIDFacturaType(60) minus 10-digit worst-case correlative (AID-429).')
                ->change();
        });
    }

    /**
     * Narrowing back is NOT data-safe: any series longer than 10 characters
     * would be truncated. MySQL in strict mode fails the ALTER instead of
     * truncating (fail-loud, the desired behaviour); do not run this down()
     * while long series literals exist.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('prefix', 10)
                ->default('FAC')
                ->comment('User customizable prefix (max 10 chars): FAC, PRO, RECT, etc.')
                ->change();
        });

        Schema::table('invoice_series_control', function (Blueprint $table): void {
            $table->string('prefix', 10)
                ->comment('User customizable prefix (max 10 chars): FAC, PRO, RECT, INTERNAL, etc.')
                ->change();
        });
    }
};
