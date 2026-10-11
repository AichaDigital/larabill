<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\AgreementCause;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 slice B (AID-949) — apply the §4.2 status program to
 * `article_service_status`, together with the accounting axis, the renewal
 * attribute, the deferred-closures carry-over and the retirement of
 * `cancellation_type`. This migration and the close/suspend API land as ONE
 * atomic unit: the suite is only green when both exist.
 *
 * §4.2 mapping BY RAW STORED VALUE (the legacy `ServiceStatus` backed ints
 * 0-4 persisted in the char(1) column; the `A/P/...` letters in the old
 * migration comment were a stale spec error, gate finding #2):
 *
 *   0 (ACTIVE)    → active
 *   1 (PENDING)   → pending
 *   2 (SUSPENDED) → suspended, cause NULL — legacy data cannot establish why;
 *                   inventing `unpaid` would violate the cause-optional
 *                   principle (gate finding #3)
 *   2.5 deferred  → rows still ACTIVE with a `cancellation_effective_at`
 *                   (the 6.x end-of-period/notice cancellations):
 *                     future date → stays active + `close_scheduled_at` =
 *                       the stored date; the new scheduler closes it on
 *                       that date with cause NULL and the emission gate
 *                       never bills beyond it (§3.2);
 *                     today or past → closed, cause NULL — every today-date
 *                       handled, no unhandled branch (gate round 7 P1 #3);
 *   3 (CANCELLED) → closed, cause NULL (`CancellationType` records timing
 *                   mechanics, not commercial cause — the data does not
 *                   prove `client_request`);
 *   4 (EXPIRED)   → closed + cause `fulfilment` — the only mapping the
 *                   source data establishes (the expiry process set it).
 *
 * Additional §2.1 deliverables:
 *   - `close_scheduled_at` date nullable — deferred closure carried over;
 *   - `account_status` varchar(12) default `up_to_date` — the accounting
 *     axis, independent of service state (operator decision: WHMCS
 *     conflated them);
 *   - `renewal` varchar(12) backfilled from `expires_at`: NULL → `auto`,
 *     non-null → `closed_term` (a dated agreement must not silently become
 *     auto-renewing);
 *   - `cancellation_type` DROPPED — retired with the commercial policy it
 *     encodes (ADR-014). The legacy type survives in the dispatched legacy
 *     event payload and, for shim-forwarded cancellations, in the closure
 *     metadata; NO invented metadata column beyond what §2.1 declares.
 *     `cancellation_requested_at` / `cancellation_effective_at` /
 *     `refund_unused` keep their columns untouched (§2.1).
 *
 * Loud-abort preflight BEFORE any DDL (§4.3, data-respect rule AID-398):
 * (b) any CANCELLED (3) row lacking `cancellation_type` — the
 *     importer-shadow state AID-949 warned about;
 * (c) any `status` outside 0-4, any `cancellation_type` outside 0-2 —
 *     none of these columns carries a DB domain constraint, so
 *     out-of-domain rows can exist silently (gate finding #5).
 * The `fulfilment` cause id is resolved through
 * `AgreementCause::findActive('closed', 'fulfilment')` in the preflight
 * phase too, so a missing seed aborts while the table is still
 * byte-identical.
 */
return new class extends Migration
{
    /**
     * The legacy `ServiceStatus` domain that ever persisted (enum cases 0-4).
     */
    private const LEGACY_STATUS_DOMAIN = ['0', '1', '2', '3', '4'];

    /**
     * The legacy `CancellationType` domain that ever persisted (enum cases 0-2).
     */
    private const LEGACY_TYPE_DOMAIN = ['0', '1', '2'];

    private ?int $fulfilmentCauseId = null;

    public function up(): void
    {
        $this->preflightOrAbort();

        // ── DDL: widen the state column, add the new attributes ──────────────
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->string('status', 16)
                ->comment('Agreement state (ADR-015 §3.1): pending | active | suspended | guarantee | closed.')
                ->change();

            $table->date('close_scheduled_at')
                ->nullable()
                ->comment('Deferred closure carried over from 6.x (ADR-015 §4.2 row 2.5): the scheduler closes the agreement on this date.');

            $table->string('account_status', 12)
                ->default('up_to_date')
                ->comment("Accounting axis, independent of service state (ADR-015 §2.1): 'up_to_date' | 'overdue'.");

            $table->string('renewal', 12)
                ->default('auto')
                ->comment("Renewal attribute (ADR-015 §2.1): 'auto' | 'closed_term'. Backfilled from expires_at.");
        });

        // ── §4.2 status backfill, BY RAW STORED VALUE ─────────────────────────
        $today = now()->toDateString();

        // Row 2.5 FIRST — deferred closures still ACTIVE with an effective
        // date. Future → scheduled (the stored date); today/past → closed.
        // Cause stays NULL on every deferred branch: the 6.x cancellation
        // type recorded timing mechanics, not a commercial cause.
        DB::table('article_service_status')
            ->where('status', '0')
            ->whereNotNull('cancellation_effective_at')
            ->where('cancellation_effective_at', '>', $today)
            ->update(['close_scheduled_at' => DB::raw('cancellation_effective_at')]);

        DB::table('article_service_status')
            ->where('status', '0')
            ->whereNotNull('cancellation_effective_at')
            ->where('cancellation_effective_at', '<=', $today)
            ->update(['status' => 'closed']);

        // The plain mapping for what remains.
        DB::table('article_service_status')->where('status', '0')->update(['status' => 'active']);
        DB::table('article_service_status')->where('status', '1')->update(['status' => 'pending']);
        DB::table('article_service_status')->where('status', '2')->update(['status' => 'suspended']);
        DB::table('article_service_status')->where('status', '3')->update(['status' => 'closed']);

        // Row 4: the only cause the source data establishes.
        DB::table('article_service_status')
            ->where('status', '4')
            ->update([
                'status'           => 'closed',
                'closure_cause_id' => $this->fulfilmentCauseId,
            ]);

        // ── renewal backfill (§2.1): both expires_at branches ────────────────
        DB::table('article_service_status')->whereNull('expires_at')->update(['renewal' => 'auto']);
        DB::table('article_service_status')->whereNotNull('expires_at')->update(['renewal' => 'closed_term']);

        // ── Retire the commercial policy the column encoded (ADR-014) ────────
        Schema::table('article_service_status', function (Blueprint $table): void {
            $table->dropColumn('cancellation_type');
        });
    }

    /**
     * Loud-abort preflight (§4.3) — runs BEFORE any DDL, so an abort leaves
     * the table byte-identical. Every finding is reported with actionable
     * remediation text; nothing is guessed.
     */
    private function preflightOrAbort(): void
    {
        // The `fulfilment` seed must exist before any row can map to it.
        $this->fulfilmentCauseId = AgreementCause::findActive('closed', 'fulfilment')->id;

        // (b) The importer-shadow state: a CANCELLED row without its type is
        // data the package cannot interpret — abort, do not approximate.
        $shadowCount = DB::table('article_service_status')
            ->where('status', '3')
            ->whereNull('cancellation_type')
            ->count();

        if ($shadowCount > 0) {
            throw new RuntimeException(sprintf(
                'Larabill agreement status migration aborted: %d article_service_status row(s) are CANCELLED '
                .'(raw status 3) but carry no cancellation_type — the importer-shadow state AID-949 warned '
                .'about. The package refuses to invent the missing timing mechanics. Remediation: resolve '
                .'those rows manually before re-running `php artisan migrate` — set the legacy '
                .'cancellation_type each row was really cancelled under (0=immediate, 1=end of period, '
                .'2=notice period), or retire the row. The schema has NOT been modified.',
                $shadowCount,
            ));
        }

        // (c) Out-of-domain raw values — no DB constraint ever guarded these
        // columns, so a legacy database can silently hold values outside the
        // legacy enum domains.
        $outOfDomainStatus = DB::table('article_service_status')
            ->whereNotIn('status', self::LEGACY_STATUS_DOMAIN)
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->all();

        if ($outOfDomainStatus !== []) {
            throw new RuntimeException(sprintf(
                'Larabill agreement status migration aborted: article_service_status.status holds raw '
                .'value(s) %s, outside the legacy ServiceStatus domain 0-4. No mapping exists for them and '
                .'the package refuses to invent one. Remediation: correct or retire those rows manually '
                .'before re-running `php artisan migrate` — decide what state each agreement was really in. '
                .'The schema has NOT been modified.',
                implode(', ', $outOfDomainStatus),
            ));
        }

        $outOfDomainType = DB::table('article_service_status')
            ->whereNotNull('cancellation_type')
            ->whereNotIn('cancellation_type', self::LEGACY_TYPE_DOMAIN)
            ->distinct()
            ->orderBy('cancellation_type')
            ->pluck('cancellation_type')
            ->all();

        if ($outOfDomainType !== []) {
            throw new RuntimeException(sprintf(
                'Larabill agreement status migration aborted: article_service_status.cancellation_type holds '
                .'raw value(s) %s, outside the legacy CancellationType domain 0-2. No mapping exists for '
                .'them and the package refuses to invent one. Remediation: correct or retire those rows '
                .'manually before re-running `php artisan migrate`. The schema has NOT been modified.',
                implode(', ', $outOfDomainType),
            ));
        }
    }

    /**
     * The §4.2 mapping is NOT invertible and the rollback refuses instead of
     * corrupting data (AID-398): closed collapses the legacy 3 and 4 (plus
     * the today/past deferred branch), the `fulfilment` cause assignment has
     * no inverse, and the retired `cancellation_type` data is gone. Restoring
     * the pre-migration state is a backup restore, not a migration.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Larabill agreement status rollback aborted: the ADR-015 §4.2 status mapping is not invertible — '
            .'closed collapses the legacy CANCELLED (3), EXPIRED (4) and today/past deferred branches, the '
            .'fulfilment cause assignment has no inverse, and the retired cancellation_type data is gone. '
            .'Restore the pre-migration state from a backup instead.'
        );
    }
};
