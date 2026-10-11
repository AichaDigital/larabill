<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice 2 (AID-953) — the GUARANTEE feature (§2.1 capability +
 * snapshot, §2.6 registry, §3.4 settlement key). ALL ADDITIVE — no existing
 * column is touched and no data is transformed.
 *
 * NO BACKFILL, deliberately, piece by piece:
 *
 * - `articles.guarantee_days` is a CAPABILITY the consumer sets forward on
 *   the products that carry one (§2.6: domains and provisioned VPS simply
 *   carry NULL — exclusion is the consumer's data, not package logic).
 *   Backfilling it for existing products would invent a commercial policy
 *   (registry-only stance): every existing product keeps NULL = no window
 *   until the consumer decides otherwise.
 * - `article_service_status.guarantee_days` is the SNAPSHOT frozen at
 *   contracting (§3.4, gate round 6 P2). It can only be written when an
 *   agreement is contracted; agreements contracted before this migration
 *   had no guarantee capability to snapshot, and backfilling one would
 *   invent a right that never existed — the same doctrine as the §4.2
 *   cause-NULL mappings (legacy data cannot establish it, so nothing is
 *   invented).
 * - `article_guarantee_operations` is a new table: empty by construction.
 * - `invoices.settlement_operation_key` is minted at issuance time by the
 *   §3.4 settlement flow; no invoice predates the mechanism, so NULL is the
 *   historical truth for every existing row.
 *
 * Column order note: the registry table is created BEFORE the
 * `invoices.settlement_operation_key` column is added because both reference
 * `invoices`, never the other way around — the new column carries no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── §2.1: capability on the product ─────────────────────────────────
        Schema::table('articles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('guarantee_days')
                ->nullable()
                ->comment('Guarantee refund window in days (ADR-015 §2.6): the product capability. NULL/0 = no window. Exclusion is consumer data, never package logic.');
        });

        // ── §2.1: the SNAPSHOT frozen into the agreement at contracting ─────
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->unsignedSmallInteger('guarantee_days')
                ->nullable()
                ->comment('Guarantee window snapshot frozen at contracting (ADR-015 §3.4, gate round 6 P2): later product changes never move an existing window. NULL/0 = no window.');
        });

        // ── §2.6: the operations registry ───────────────────────────────────
        Schema::create('article_guarantee_operations', function (Blueprint $table): void {
            // UUID v7 PK (HasUuid): the registry row id IS the deterministic
            // settlement operation key's identity (§3.4: guarantee_ops:{uuid}).
            $table->uuid('id')->primary();

            $table->foreignId('agreement_id')
                ->constrained('article_service_status')
                ->restrictOnDelete()
                ->comment('FK to the agreement (article_service_status) whose guarantee window this operation belongs to');

            // The PAID invoice the refund corrects — materially fiscal for a
            // rectificative (ADR-014): the R1 reaches its frozen context.
            $table->foreignUuid('original_invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete()
                ->comment('FK to the emitted invoice the refund corrects (ADR-014 original of the rectificative)');

            // The rectificative, once issued — recorded by settle() Phase 2.
            // nullOnDelete matches the invoices.rectifies_invoice_id FK style;
            // the fiscal trace also lives on the invoice itself.
            $table->foreignUuid('rectifies_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete()
                ->comment('FK to the rectificative issued for this operation (ADR-015 §3.4 Phase 2); NULL until settled');

            $table->integer('amount')
                ->comment('Refund amount in Base-100 (FixedDecimalCast:2 at model level), strictly positive; the rectificative issues it negated');

            $table->string('status', 12)
                ->default('pending')
                ->comment("Registry status: 'pending' | 'refunded'");

            $table->dateTime('refunded_at')->nullable()
                ->comment('When the refund was confirmed (ADR-015 §3.4 confirmRefund)');

            // Nullable-sentinel idempotency (gate round 2 P1): 'PENDING' while
            // the operation is pending, NULL once refunded — refunded rows
            // never collide. The UNIQUE index on the pair makes two
            // concurrent pendings for the same agreement impossible.
            $table->string('pending_slot', 16)->nullable()
                ->comment("Sentinel pattern (ADR-015 §2.6): fixed 'PENDING' while pending, NULL once refunded");

            $table->timestamps();

            $table->unique(['agreement_id', 'pending_slot'], 'article_guarantee_operations_pending_slot_unique');
        });

        // ── §3.4: the serialisation point of settlement issuance ───────────
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('settlement_operation_key')
                ->nullable()
                ->comment('Deterministic settlement operation key (ADR-015 §3.4): guarantee_ops:{registry_row_uuid}. UNIQUE — no interleaving can issue two fiscal corrections for one claim');

            $table->unique('settlement_operation_key', 'invoices_settlement_operation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_settlement_operation_key_unique');
            $table->dropColumn('settlement_operation_key');
        });

        Schema::dropIfExists('article_guarantee_operations');

        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropColumn('guarantee_days');
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('guarantee_days');
        });
    }
};
