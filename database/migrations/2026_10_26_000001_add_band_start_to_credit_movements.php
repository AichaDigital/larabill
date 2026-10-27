<?php

declare(strict_types=1);

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AID-1778 — `credit_movements.band_start`, the persisted position of an exit
 * on its entry's cent tape (spec §5.3, §5.7).
 *
 * THE COLUMN. An APPLIED/REFUNDED exit consumes a contiguous band
 * `[band_start, band_start + abs(amount))` of the entry it consumes
 * (`consumes_movement_id`); the end is DERIVED by arithmetic, never stored.
 * NULL on entries (they own the tape), on legacy rows this backfill could not
 * reconstruct exactly (AMBIGUOUS, below), and on every exit created afterwards
 * on an unresolvable entry (the creators propagate NULL — no quiet re-anchor).
 * A NULL on an ACTIVE exit therefore MEANS «this entry's position history is
 * declared unresolvable», not «unknown yet» — the helper refuses such entries
 * loudly instead of inventing a split from an ambiguous history.
 *
 * The column is additive and nullable: no default of business value changes,
 * nothing breaks, and the ledger arithmetic (`remaining()`, `plan()`, FIFO,
 * the funding checks) never reads it.
 *
 * BACKFILL — the 8.3.0 data programme (spec §5.7, operator L8/L12). L8:
 * «No se debe presentar un backfill heurístico como dato histórico cierto.»
 * The criterion E1+E2+E3 is a CONSERVATIVE RECONSTRUCTION CRITERION accepted
 * by the operator (L12), not a proof of the real historical order; an entry
 * qualifies EXACT only when ALL of:
 *
 *   (E1) no exit of the entry carries `reversed_at` — the tape was never
 *        disturbed, so no hole/refill order exists to reconstruct;
 *   (E2) the entry's ACTIVE exits, ordered by `id`, carry STRICTLY INCREASING
 *        `created_at` (second precision; a tie is as disqualifying as a
 *        decrease — J2: with a tie the COMB id order is the evidence under
 *        test itself, so no third witness exists);
 *   (E3) where the entry is linked to an advance invoice and the artifacts
 *        exist, they must recompute exactly against the proposed bands (below).
 *
 * First-fit stacking from 0 along that (id, created_at) order is then the
 * UNIQUE band assignment consistent with both stamps and E1. Any other entry
 * is AMBIGUOUS: ALL of its exits stay NULL.
 *
 * E3 is THIS migration's own conservative comparison (the 8.4 pro-rata helper
 * is not part of the installed base and must not be a migration dependency):
 * the advance's frozen totals give the rounding function f(c) =
 * HalfUp(c·T/G) on exact BigInteger arithmetic (c < G; f(G) = T), and the
 * artifacts are the deduction lines (`invoice_items.deducts_invoice_id` =
 * advance) and the rectificative lines (items of invoices with
 * `rectifies_invoice_id` = advance). Each artifact's (|total_amount|,
 * |total_tax_amount|) pair must be matched by a DISTINCT exit's proposed
 * (|amount|, f(b) − f(a)) pair — multiset containment with multiplicities.
 * Artifacts that cannot be attributed to one exit (a second entry linked to
 * the same advance — possible under 8.3.0's one-directional link guard —, a
 * bundled artifact, or a broken frozen snapshot) surface as containment
 * failures, i.e. conservative AMBIGUOUS — never as invented bands.
 *
 * BOUNDARIES (J4). MySQL/MariaDB DDL is not transactional (the grammar ships
 * `$transactions = false`; Migrator runs up() bare and logs only after it
 * returns), so the migration defines its own boundaries and is IDEMPOTENT and
 * RESUMABLE: (1) the `addColumn` DDL runs FIRST, guarded by `hasColumn` — a
 * stop after it leaves the column present with every row NULL, the unprocessed
 * state; (2) the backfill runs PER ENTRY, each entry's classification + writes
 * inside its OWN DB::transaction — a partial stop leaves a prefix of entries
 * banded and the rest untouched; (3) a re-run skips entries whose active exits
 * are ALL banded already (per-entry transactions make the state all-or-nothing
 * per entry) and re-classifies the rest — already-banded entries are no-ops.
 *
 * No index: band assignment reads the exits of ONE entry, served by the
 * existing `consumes_movement_id` index (2026_10_24_000001, AID-962).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addBandStartColumn();
        $this->backfillLegacyBands();
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_movements', 'band_start')) {
            Schema::table('credit_movements', function (Blueprint $table): void {
                $table->dropColumn('band_start');
            });
        }
    }

    /**
     * The DDL boundary: guarded, so a re-run after a stop between the DDL and
     * the backfill (the migration is not yet logged — Migrator runs up() bare)
     * skips the addColumn instead of failing with a duplicate-column error.
     */
    private function addBandStartColumn(): void
    {
        if (Schema::hasColumn('credit_movements', 'band_start')) {
            return;
        }

        Schema::table('credit_movements', function (Blueprint $table): void {
            $table->bigInteger('band_start')->nullable()
                ->comment('Base-100 cent offset where this exit\'s band starts on its entry\'s cent tape (AID-1778); NULL on entries and on unresolvable entries');
        });
    }

    /**
     * The data boundary: ONE transaction PER ENTRY, so a partial stop leaves a
     * prefix of entries banded and the rest NULL — and re-running completes it.
     */
    private function backfillLegacyBands(): void
    {
        $entryIds = DB::table('credit_movements')
            ->whereNotNull('consumes_movement_id')
            ->distinct()
            ->pluck('consumes_movement_id');

        foreach ($entryIds as $entryId) {
            $this->backfillEntry((string) $entryId);
        }
    }

    /**
     * The per-entry resumable unit (spec §5.7, J4): classify the entry and,
     * when EXACT, write its first-fit bands — inside its OWN transaction.
     * Public so an interrupted backfill can be resumed unit by unit (and so
     * the upgrade-path test can stop after a known entry).
     */
    public function backfillEntry(string $entryId): void
    {
        DB::transaction(function () use ($entryId): void {
            $entry = DB::table('credit_movements')->where('id', $entryId)->first();

            if ($entry === null) {
                return;
            }

            $exits = DB::table('credit_movements')
                ->where('consumes_movement_id', $entryId)
                ->orderBy('id')
                ->get();

            $active = $exits->filter(static fn (object $exit): bool => $exit->reversed_at === null)->values();

            if ($active->isEmpty()) {
                return;
            }

            // Idempotency: per-entry transactions make the state all-or-nothing
            // per entry; any banded active exit means this entry was processed
            // already (8.4 creators never leave a banded/NULL mix — they
            // propagate NULL onto a whole unresolvable entry).
            if ($active->contains(static fn (object $exit): bool => $exit->band_start !== null)) {
                return;
            }

            // (E1) no reversed exit on the entry — the tape was never disturbed,
            // so no hole/refill order exists to reconstruct.
            if ($exits->contains(static fn (object $exit): bool => $exit->reversed_at !== null)) {
                return;
            }

            // (E2) `created_at` STRICTLY increasing along id order (J2 — a tie
            // is as disqualifying as a decrease: with a tie the COMB id order
            // is the evidence under test itself, so no third witness exists).
            // Second-precision 'Y-m-d H:i:s' strings compare lexicographically.
            $createdAt = $active->map(static fn (object $exit): string => (string) $exit->created_at)->all();
            for ($i = 1, $n = count($createdAt); $i < $n; $i++) {
                if (strcmp($createdAt[$i - 1], $createdAt[$i]) >= 0) {
                    return;
                }
            }

            // First-fit proposal: stack from 0 along the (id, created_at) order.
            $cursor  = 0;
            $startOf = [];
            foreach ($active as $exit) {
                $startOf[(string) $exit->id] = $cursor;
                $cursor += abs((int) $exit->amount);
            }

            // Tape bound (implementation gate round 2): the active exits must
            // fit the entry's gross. A history whose exits exceed it would
            // receive a band outside the tape — not exact, so it stays NULL
            // and the pro-rata refuses it loudly, never a guessed band.
            if ($cursor > abs((int) $entry->amount)) {
                return;
            }

            // (E3) artifact cross-check where the advance's artifacts exist.
            if ($entry->advance_invoice_id !== null
                && ! $this->artifactsMatch((string) $entry->advance_invoice_id, $active, $startOf)) {
                return;
            }

            foreach ($startOf as $exitId => $start) {
                DB::table('credit_movements')->where('id', $exitId)->update(['band_start' => $start]);
            }
        });
    }

    /**
     * (E3) — THIS migration's own conservative comparison (the 8.4 pro-rata
     * helper is not part of the installed base and must not be a dependency).
     * The advance's frozen totals give the rounding function
     * f(c) = HalfUp(c·T/G) on exact BigInteger arithmetic (c < G; f(G) = T);
     * the artifacts are the deduction lines (`invoice_items.deducts_invoice_id`
     * = advance) and the rectificative lines (items of invoices with
     * `rectifies_invoice_id` = advance). Every artifact's (|total_amount|,
     * |total_tax_amount|) pair must be matched by a DISTINCT exit's proposed
     * (|amount|, f(b) − f(a)) pair — multiset containment with multiplicities.
     * Artifacts that cannot be attributed to one exit (a second entry linked
     * to the same advance — possible under 8.3.0's one-directional link guard
     * —, a bundled artifact, or a broken frozen snapshot) surface as
     * containment failures, i.e. conservative AMBIGUOUS — never invented bands.
     *
     * @param  Collection<int, object>  $activeExits
     * @param  array<string, int>  $startOf
     */
    private function artifactsMatch(string $advanceId, Collection $activeExits, array $startOf): bool
    {
        $advance = DB::table('invoices')->where('id', $advanceId)->first();

        if ($advance === null) {
            return false; // broken link: nothing to witness the order
        }

        $gross = (int) $advance->total_amount;
        $tax   = (int) $advance->total_tax_amount;

        if ($gross <= 0 || $tax < 0 || $tax > $gross) {
            return false;
        }

        // Frozen snapshot validation (spec §5.2, G6): per line, Σ taxes_applied
        // amounts equals the line's tax; at most ONE distinct non-zero rate.
        $rates = [];
        foreach (DB::table('invoice_items')->where('invoice_id', $advanceId)->get() as $line) {
            $applied = json_decode((string) $line->taxes_applied, true);
            $sum     = 0;

            foreach (is_array($applied) ? $applied : [] as $entry) {
                $amount = (int) ($entry['amount'] ?? 0);
                $sum   += $amount;

                if ($amount !== 0) {
                    $rateId = $entry['source_rate_id'] ?? null;

                    if ($rateId === null) {
                        return false; // a non-zero tax with no rate: broken snapshot
                    }

                    $rates[(string) $rateId] = true;
                }
            }

            if ($sum !== (int) $line->total_tax_amount) {
                return false;
            }
        }

        if (count($rates) > 1) {
            return false; // a multi-rate advance has no single f to check against
        }

        $taxOf = static function (int $cents) use ($gross, $tax): int {
            if ($cents >= $gross) {
                return $tax; // f(G) = T exactly (spec §5.2)
            }

            return BigInteger::of($cents)->multipliedBy($tax)
                ->dividedBy($gross, RoundingMode::HALF_UP)
                ->toInt();
        };

        $proposed = [];
        foreach ($activeExits as $exit) {
            $length     = abs((int) $exit->amount);
            $start      = $startOf[(string) $exit->id];
            $proposed[] = $length.'|'.($taxOf($start + $length) - $taxOf($start));
        }

        $artifactKeys = [];
        foreach (DB::table('invoice_items')->whereNotNull('deducts_invoice_id')->where('deducts_invoice_id', $advanceId)->get() as $item) {
            $artifactKeys[] = abs((int) $item->total_amount).'|'.abs((int) $item->total_tax_amount);
        }

        $rectifierIds = DB::table('invoices')->where('rectifies_invoice_id', $advanceId)->pluck('id');

        if ($rectifierIds->isNotEmpty()) {
            foreach (DB::table('invoice_items')->whereIn('invoice_id', $rectifierIds->all())->get() as $item) {
                $artifactKeys[] = abs((int) $item->total_amount).'|'.abs((int) $item->total_tax_amount);
            }
        }

        foreach ($artifactKeys as $key) {
            $index = array_search($key, $proposed, true);

            if ($index === false) {
                return false; // an artifact no exit can account for: ambiguous
            }

            unset($proposed[$index]);
            $proposed = array_values($proposed);
        }

        return true;
    }
};
