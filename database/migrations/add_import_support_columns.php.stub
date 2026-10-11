<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice 6 (AID-895) — IMPORT SUPPORT: the package-side surface the
 * WHMCS importer (consumer side, repo `clientes`) drives (spec §7 task 6).
 *
 * TWO additive columns, NO backfill:
 *
 *   - `invoices.is_historical` bool default false — set by the importer on
 *     EVERY document migrated from the legacy system. Plain, visible,
 *     queryable (§2.5). Existing invoices are NOT historical: the default
 *     false needs no backfill.
 *
 *   - `article_service_status.origin_key` varchar(64) nullable + UNIQUE —
 *     the idempotency key of the importer (gate round 11 P2). Format
 *     `whmcs:<source_table>:<source_id>` is the IMPORTER's convention and is
 *     deliberately NOT enforced here: larabill only guarantees the uniqueness
 *     that makes re-import UPDATE-instead-of-duplicate possible (the
 *     find-or-UPDATE flow itself belongs to the importer). Existing
 *     agreements have no origin, so NULL + no backfill is correct, and a
 *     UNIQUE nullable index admits many NULLs on every engine.
 *
 * The existing nullable unindexed `external_reference` is NOT an idempotency
 * key and stays untouched (§2.5 explicit).
 *
 * ADDITIVE ONLY: no existing column is touched, no data is transformed, no
 * loud-abort preflight — there is nothing to map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('is_historical')
                ->default(false)
                ->comment('Imported-document flag (ADR-015 §2.5): true on EVERY document migrated from the legacy system; false for anything native. Queryable provenance marker, not fiscal content.');
        });

        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->string('origin_key', 64)
                ->nullable()
                ->comment('Import idempotency key (ADR-015 §2.5): format `whmcs:<source_table>:<source_id>` — the importer\'s convention, not enforced here. UNIQUE so a re-import UPDATES the matched row instead of duplicating it. NULL for native agreements.');
            $table->unique('origin_key');
        });
    }

    /**
     * Reverse the migration. Both columns are purely additive and carry no
     * data the rest of the schema depends on, so dropping them restores the
     * pre-migration state exactly.
     */
    public function down(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropUnique(['origin_key']);
            $table->dropColumn('origin_key');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('is_historical');
        });
    }
};
