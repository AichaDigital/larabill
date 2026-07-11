<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AID-307 — the invoice uniqueness must hang from the real fiscal SERIES.
 *
 * Before AID-307 the unique key was (serie, series_number, fiscal_year), where
 * `serie` is the fiscal TYPE (InvoiceSerieType), not the series. That conflated
 * type with series and made two real series of the same type collide
 * (FAC-2026-000001 and ARB-2026-000001 both reduced to serie='1'). The real
 * series lives in `prefix`, so the key gains it.
 *
 * The new key (prefix, serie, series_number, fiscal_year) is a SUPERSET of the
 * old one: any row set that satisfied the stricter old key also satisfies the
 * relaxed new key (viol_new ⊆ viol_old), so this DDL touches no rows and the
 * ADD UNIQUE cannot fail on existing v4 data (all invoices carry prefix='FAC'
 * or 'PRO', which never collide across types). See UPGRADE-5.0.md.
 *
 * MySQL DDL is not transactional, so the new index is added BEFORE the old one
 * is dropped: the two coexist (the old key's columns are a prefix subset of the
 * new one), leaving no window without a uniqueness guard. `fiscal_number` is
 * also globally unique, a second safety net against any transient duplicate.
 *
 * Note (large tables): ADD UNIQUE builds an index — a real DDL cost on tables
 * with millions of rows. Prefer a maintenance window or pt-online-schema-change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['prefix', 'serie', 'series_number', 'fiscal_year']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['serie', 'series_number', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['serie', 'series_number', 'fiscal_year']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['prefix', 'serie', 'series_number', 'fiscal_year']);
        });
    }
};
