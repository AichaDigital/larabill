<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AID-1611 — the instance identifier is a LABEL, not an identity (operator
 * decision 2026-10-11, option A; blocks the WHMCS importer, AID-895).
 *
 * The `customer_article_instance_unique` index on
 * `(customer_id, article_id, instance_identifier)` made the instance name an
 * identity across the agreement's WHOLE lifetime. Because `CLOSED` is a
 * terminal state (ADR-015 §3.1), a closed agreement permanently blocked
 * re-contracting the same resource:
 *
 *   - a re-hire (the client returns to the same room/domain/account),
 *   - a rescue after the low (pay again → the closed row cannot reopen and a
 *     new one collides),
 *   - the plan change A → B → A (`PlanChangeService::createSuccessor()`
 *     re-contracts the SAME instance name),
 *   - the WHMCS import (WHMCS allows several same-named services, even live
 *     at once — the importer must bring them all).
 *
 * larabill stops enforcing instance-name uniqueness. A contract's identity
 * is its row id — and, for imported agreements, the UNIQUE `origin_key`
 * (migration 047), which is the importer's real idempotency key. Whether one
 * resource may hold two live contracts is the vertical's/consumer's rule
 * (ADR-015 division of responsibilities), not the package's.
 *
 * BEHAVIOUR NOTE: a consumer that relied on the duplicate `QueryException`
 * as a duplicate detector stops seeing it — the detection (if needed) moves
 * to the consumer. Dropping the constraint only RELAXES: no existing row is
 * rejected, no data is transformed, so this is provably data-safe DDL and
 * ships as a minor (STABILITY.md §2). The plain composite index keeps the
 * lookups by (customer, article, instance) fast.
 *
 * No loud-abort preflight — there is nothing to map and nothing to lose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropUnique('customer_article_instance_unique');
            $table->index(['customer_id', 'article_id', 'instance_identifier'], 'customer_article_instance_index');
        });
    }

    /**
     * Reverse the migration by restoring the retired constraint. NOTE: on a
     * database that accumulated same-instance contracts while the constraint
     * was relaxed, re-creating the UNIQUE index fails loud with the
     * duplicate keys listed by the engine — the honest rollback for a
     * relaxation is the previous release's backup, not a silent dedup that
     * would invent which agreement "loses".
     */
    public function down(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropIndex('customer_article_instance_index');
            $table->unique(['customer_id', 'article_id', 'instance_identifier'], 'customer_article_instance_unique');
        });
    }
};
