<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The AID-962 allocation-status backfill (spec §3.2), shared by migration
 * `2026_10_24_000001_add_allocations_and_credit_movements` and the
 * `larabill:backfill-allocation-status` command.
 *
 * 8.x `reverse()` keeps the pivot rows and only sets `active_invoice_id =
 * NULL`. Every row still `status = 0` (ACTIVE) with that NULL marker becomes
 * REVERSED (1):
 *
 *   1. parent payment REVERSED — the parent's audit trio is copied;
 *   2. residual (parent POSTED, a state with no known writer) —
 *      `reversed_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)`
 *      and `reverse_reason = 'backfill-8.3'`, so the anomaly stays visible.
 *
 * Idempotent: both statements only touch rows still ACTIVE with the marker,
 * so a re-run (the deploy-window repair, after the code switch) is a no-op
 * on rows already backfilled. Portable query-builder statements (correlated
 * subqueries, no UPDATE … JOIN: SQLite does not compile it).
 *
 * Published copies of migration 053 call run() by name and live in the
 * consumer's repository forever (replayed on every fresh install and
 * rollback): the class name and the run() signature are therefore permanent
 * for consumers, exactly like MigrationHelper.
 *
 * @api Called by published migration stubs and by the backfill command.
 */
final class AllocationStatusBackfill
{
    private const PIVOT = 'grouped_payment_invoice';

    /**
     * @return array{from_reversed_parent: int, residual: int}
     */
    public static function run(): array
    {
        $fromParent = fn (string $column): Builder => DB::table('grouped_payments')
            ->select('grouped_payments.'.$column)
            ->whereColumn('grouped_payments.id', self::PIVOT.'.grouped_payment_id');

        // 1. Parent REVERSED (the only production writer of the NULL marker):
        //    copy the parent's audit trio.
        $fromReversedParent = DB::table(self::PIVOT)
            ->whereNull('active_invoice_id')
            ->where('status', 0)
            ->whereIn('grouped_payment_id', fn ($query) => $query->select('id')->from('grouped_payments')->where('status', 1))
            ->update([
                'status'         => 1,
                'reversed_at'    => $fromParent('reversed_at'),
                'reversed_by'    => $fromParent('reversed_by'),
                'reverse_reason' => $fromParent('reverse_reason'),
            ]);

        // 2. Residual (parent POSTED — no known writer): REVERSED, visibly.
        //    NULL-safe: consumers may have seeded pivot rows by hand.
        $residual = DB::table(self::PIVOT)
            ->whereNull('active_invoice_id')
            ->where('status', 0)
            ->update([
                'status'         => 1,
                'reversed_at'    => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                'reverse_reason' => 'backfill-8.3',
            ]);

        return ['from_reversed_parent' => $fromReversedParent, 'residual' => $residual];
    }
}
