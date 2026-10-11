<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice 1 (AID-953) — ADD `modality` to `article_service_status`
 * (§2.1): `recurring` | `one_time` | `non_billable`, what KIND of contract
 * the row carries, independent of its lifecycle state.
 *
 * Backfill — decided by PERSISTED DATA, total over the current domain:
 *
 *   - rows with NULL cadence → `one_time`. This is the §4.1 ONE_TIME
 *     carry-over: slice 2b (AID-952) already left those rows with
 *     `billing_unit`/`billing_quantity` NULL, and a NULL cadence IS a
 *     one-time agreement — real persisted data, not an absence. Their
 *     `next_billing_date` artifact is CLEARED in the same update (AID-1595):
 *     the 6.x engine wrote a next date on ONE_TIME rows too, but a one-time
 *     agreement never bills again, and a persisted date would abort the
 *     whole 7.0 recurrent run at the emission gate (the loud RuntimeException
 *     gate stays as defense in depth — unreachable through this path);
 *   - every cadence-bearing row → `recurring` (the column default, which
 *     the DDL below already lands — the backfill below only has to move
 *     the NULL-cadence rows).
 *
 * NO loud-abort preflight, deliberately — unlike the §4.3 siblings: the
 * mapping above is TOTAL over the current domain. Slice 2b's preflight
 * already loud-aborted any cadence outside the §4.1 domain (WEEKLY,
 * BIWEEKLY, raw values beyond 0-9), so by the time this migration runs
 * every row is either NULL-cadence or a schedulable interval — there is no
 * third case the mapping could fail to cover, and nothing to refuse.
 *
 * `non_billable` is NOT derivable from legacy data: it exists for the
 * consumer/importer (§2.5 Free Account) to set explicitly. No backfill
 * invents it — the mapping lands zero rows there, and the upgrade-path
 * test pins that.
 *
 * ADDITIVE ONLY: no existing column is touched; every lifecycle column
 * (`status`, `renewal`, `close_scheduled_at`, cadence, dates) stays
 * byte-identical. Nothing outside this column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->string('modality', 12)
                ->default('recurring')
                ->comment("Agreement modality (ADR-015 §2.1): 'recurring' | 'one_time' | 'non_billable'. Backfilled from the cadence domain (NULL cadence = one_time); non_billable is importer-only (§2.5) — never derived from legacy data.");
        });

        // §4.1 ONE_TIME carry-over: slice 2b left ONE_TIME rows with NULL
        // cadence; the modality that describes them lands HERE. The
        // next_billing_date of those rows is the OLD ENGINE's artifact
        // (AID-1595): a one-time agreement never bills again, and a
        // persisted date would abort the whole recurrent run at the 7.0
        // emission gate. Cleared in the same update — one statement, one
        // pass over the same rows.
        DB::table('article_service_status')
            ->whereNull('billing_unit')
            ->update(['modality' => 'one_time', 'next_billing_date' => null]);
    }

    /**
     * Reverse the migrations. The column is purely additive and its values
     * are derived from data the tree still holds (the cadence columns), so
     * dropping it restores the pre-migration state — EXCEPT the cleared
     * one_time `next_billing_date` artifacts (AID-1595): those dates were
     * the old engine's leftovers and are not re-derivable, so `down()` does
     * not restore them. Nothing else is lost.
     */
    public function down(): void
    {
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropColumn('modality');
        });
    }
};
