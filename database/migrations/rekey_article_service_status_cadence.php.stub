<?php

declare(strict_types=1);

use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice 2b (AID-952) — re-key the AGREEMENT side: the
 * `article_service_status` cadence moves from the retired `BillingFrequency`
 * int to (`billing_unit`, `billing_quantity`).
 *
 * §4.1 mapping (single source: `BillingInterval::fromLegacyFrequency()`):
 * 0 → NULL/NULL (the one-time cadence; the `modality = one_time` flag comes
 * with T3's modality column, NOT here), 3 → month/1, 4 → month/2,
 * 5 → month/3, 6 → month/6, 7 → year/1, 8 → year/2, 9 → year/3.
 *
 * Loud-abort preflight BEFORE any DDL (§4.3, data-respect rule AID-398):
 * WEEKLY (1) and BIWEEKLY (2) rows abort independently, and any raw value
 * outside the legacy domain 0-9 aborts — none of those cadences exists in
 * the new domain and the package refuses to invent one. Nothing is altered
 * until the preflight passes, so a failed migration leaves the table
 * byte-identical.
 *
 * SCOPE (slice 2b only — cadence): the `status` column STAYS legacy (raw
 * int 0-4 of `ServiceStatus`) until the T2 lifecycle migration re-maps it
 * to the ADR-015 state strings; the `renewal` backfill (`expires_at`
 * branches), `close_scheduled_at`, `modality`, `account_status` and the
 * cause vocabulary are T2/T3 deliverables. This migration ONLY moves
 * cadence — every other column is left byte-identical.
 *
 * No shipped index keys on `billing_frequency`
 * (`create_article_service_status_table` indexes status, next_billing_date
 * and instance_identifier only), so there is nothing to drop before the
 * column removal.
 */
return new class extends Migration
{
    /**
     * The legacy `BillingFrequency` domain that ever persisted (enum cases 0-9).
     */
    private const LEGACY_DOMAIN_MIN = 0;

    private const LEGACY_DOMAIN_MAX = 9;

    /**
     * Legacy values with a §4.1 target (everything except WEEKLY/BIWEEKLY,
     * which the preflight aborts on).
     */
    private const BACKFILL_LEGACY_VALUES = [0, 3, 4, 5, 6, 7, 8, 9];

    public function up(): void
    {
        $this->preflightOrAbort();

        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->string('billing_unit', 8)
                ->nullable()
                ->comment("Billing cadence unit: 'month' | 'year'. NULL = one-time (ADR-015).");

            $table->unsignedTinyInteger('billing_quantity')
                ->nullable()
                ->comment('Billing cadence quantity: 1-12 (months) or 1-10 (years). NULL = one-time (ADR-015).');
        });

        // Backfill through the single source of the §4.1 mapping.
        foreach (self::BACKFILL_LEGACY_VALUES as $legacy) {
            $interval = BillingInterval::fromLegacyFrequency($legacy);

            DB::table('article_service_status')
                ->where('billing_frequency', $legacy)
                ->update([
                    'billing_unit'     => $interval?->unit,
                    'billing_quantity' => $interval?->quantity,
                ]);
        }

        // Only now may the old column go. No index keys on it (see class
        // docblock) and the data is backfilled, so the drop is safe on MySQL,
        // MariaDB and SQLite alike.
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropColumn('billing_frequency');
        });
    }

    /**
     * Loud-abort preflight (§4.3) — runs BEFORE any DDL, so an abort leaves
     * the table byte-identical. WEEKLY and BIWEEKLY are reported
     * INDEPENDENTLY (gate finding #9): each detected separately, with its own
     * actionable message.
     */
    private function preflightOrAbort(): void
    {
        // (a) Retired sub-monthly cadences — the new domain cannot represent
        // them and larabill will not invent a month/year approximation.
        foreach ([1 => 'WEEKLY', 2 => 'BIWEEKLY'] as $legacyValue => $legacyName) {
            $count = DB::table('article_service_status')
                ->where('billing_frequency', $legacyValue)
                ->count();

            if ($count > 0) {
                throw new RuntimeException(sprintf(
                    'Larabill agreement cadence migration aborted: %d article_service_status row(s) carry the '
                    .'retired %s billing frequency (raw value %d), which the ADR-015 cadence domain cannot '
                    .'represent (billing_unit/billing_quantity only allow month/year intervals). Larabill '
                    .'refuses to guess the cadence of an agreement. Remediation: resolve those rows manually '
                    .'before re-running `php artisan migrate` — map each one to the month/year interval it '
                    .'really billed, or retire it — then re-run this migration. The schema has NOT been '
                    .'modified.',
                    $count,
                    $legacyName,
                    $legacyValue,
                ));
            }
        }

        // (b) Out-of-domain raw values — no DB constraint ever guarded this
        // column, so a legacy database can silently hold values outside 0-9.
        $outOfDomain = DB::table('article_service_status')
            ->whereNotBetween('billing_frequency', [self::LEGACY_DOMAIN_MIN, self::LEGACY_DOMAIN_MAX])
            ->distinct()
            ->orderBy('billing_frequency')
            ->pluck('billing_frequency')
            ->all();

        if ($outOfDomain !== []) {
            throw new RuntimeException(sprintf(
                'Larabill agreement cadence migration aborted: article_service_status.billing_frequency holds '
                .'raw value(s) %s, outside the legacy BillingFrequency domain 0-9. No mapping exists for them '
                .'and the package refuses to invent one. Remediation: correct or retire those rows manually '
                .'before re-running `php artisan migrate` — decide what cadence each one really billed. The '
                .'schema has NOT been modified.',
                implode(', ', $outOfDomain),
            ));
        }
    }

    /**
     * Reverse the migrations.
     *
     * Only reversible while every cadence is legacy-representable: the
     * mapping 7.0 introduced is faithful back to the enum, but cadences
     * written through the NEW domain that have no legacy case (e.g. month/5)
     * cannot become an int — that would silently corrupt data (AID-398), so
     * the rollback refuses instead.
     */
    public function down(): void
    {
        $unmappable = DB::table('article_service_status')
            ->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->where('billing_unit', 'month')->whereNotIn('billing_quantity', [1, 2, 3, 6]);
                })->orWhere(function ($q): void {
                    $q->where('billing_unit', 'year')->whereNotIn('billing_quantity', [1, 2, 3]);
                });
            })
            ->exists();

        if ($unmappable) {
            throw new RuntimeException(
                'Larabill agreement cadence rollback aborted: article_service_status holds cadences without a '
                .'legacy BillingFrequency equivalent (month quantities other than 1/2/3/6, year quantities '
                .'other than 1/2/3). Rolling them back to an int would silently corrupt the cadence. Restore '
                .'the pre-migration state from a backup instead.'
            );
        }

        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->unsignedTinyInteger('billing_frequency')
                ->nullable()
                ->comment('Restored by the ADR-015 rollback. Nullable only because the restore backfills below.');
        });

        // Exact inverse of the §4.1 mapping.
        foreach (self::BACKFILL_LEGACY_VALUES as $legacy) {
            $interval = BillingInterval::fromLegacyFrequency($legacy);

            DB::table('article_service_status')
                ->when(
                    $interval === null,
                    fn ($query) => $query->whereNull('billing_unit')->whereNull('billing_quantity'),
                    fn ($query) => $query->where('billing_unit', $interval->unit)->where('billing_quantity', $interval->quantity),
                )
                ->update(['billing_frequency' => $legacy]);
        }

        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropColumn(['billing_unit', 'billing_quantity']);
        });
    }
};
