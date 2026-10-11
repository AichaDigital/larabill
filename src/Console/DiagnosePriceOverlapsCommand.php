<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Lists active article prices that are valid at the same time as another price
 * of the same article and cadence (AID-601, ADR-012; re-keyed to the ADR-015
 * `billing_unit`/`billing_quantity` domain by AID-952).
 *
 * Read-only. Exit code 1 when overlaps exist, so a consumer can wire it as a
 * pre-upgrade gate: after AID-601 the package refuses to save such rows, and
 * existing ones must be resolved by whoever owns the pricing decision — the
 * package will not pick a winner, because that is deciding money for the
 * consumer.
 *
 * Uses the query builder rather than Eloquent: this is a reporting self-join
 * over pairs, not domain logic. The cadence match is null-safe on purpose: a
 * NULL cadence (one-time price) is real persisted state, and SQL `=` would
 * never match two NULLs — the join must see two one-time prices of one article
 * as the overlap they are.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class DiagnosePriceOverlapsCommand extends Command
{
    protected $signature = 'larabill:diagnose-price-overlaps';

    protected $description = 'List article prices that are active at the same time for the same article and billing cadence';

    public function handle(): int
    {
        $pairs = DB::table('article_prices as a')
            ->join('article_prices as b', function (JoinClause $join) {
                $join->on('a.article_id', '=', 'b.article_id')
                    // Null-safe cadence match: `a.x = b.x` never matches two NULLs.
                    ->whereRaw('(a.billing_unit = b.billing_unit OR (a.billing_unit IS NULL AND b.billing_unit IS NULL))')
                    ->whereRaw('(a.billing_quantity = b.billing_quantity OR (a.billing_quantity IS NULL AND b.billing_quantity IS NULL))')
                    ->whereColumn('a.id', '<', 'b.id');
            })
            ->where('a.is_active', true)
            ->where('b.is_active', true)
            // a.from <= b.to  (NULL = open end)
            ->whereRaw('(a.valid_from IS NULL OR b.valid_to IS NULL OR a.valid_from <= b.valid_to)')
            // b.from <= a.to
            ->whereRaw('(b.valid_from IS NULL OR a.valid_to IS NULL OR b.valid_from <= a.valid_to)')
            ->orderBy('a.article_id')
            ->orderBy('a.billing_unit')
            ->orderBy('a.billing_quantity')
            ->get([
                'a.article_id',
                'a.billing_unit',
                'a.billing_quantity',
                'a.id as a_id', 'a.valid_from as a_from', 'a.valid_to as a_to', 'a.price as a_price',
                'b.id as b_id', 'b.valid_from as b_from', 'b.valid_to as b_to', 'b.price as b_price',
            ]);

        if ($pairs->isEmpty()) {
            $this->info('No overlapping article prices found.');

            return self::SUCCESS;
        }

        $this->table(
            ['article', 'cadence', 'price A', 'range A', 'price B', 'range B'],
            $pairs->map(fn (object $pair): array => [
                (string) $pair->article_id,
                $this->cadenceLabel($pair->billing_unit, $pair->billing_quantity),
                "#{$pair->a_id} ({$pair->a_price})",
                ($pair->a_from ?? 'open').' → '.($pair->a_to ?? 'open'),
                "#{$pair->b_id} ({$pair->b_price})",
                ($pair->b_from ?? 'open').' → '.($pair->b_to ?? 'open'),
            ])->all(),
        );

        $this->error(sprintf(
            '%d overlapping pair(s) found. Larabill refuses to save overlapping active prices '
            .'(AID-601); resolve these before upgrading. Deciding which price survives is a '
            .'pricing decision and belongs to you, not to the package.',
            $pairs->count(),
        ));

        return self::FAILURE;
    }

    /**
     * Human label of the cadence for the report: "3 months", "1 year",
     * "one-time" for the NULL cadence.
     */
    private function cadenceLabel(?string $unit, ?int $quantity): string
    {
        if ($unit === null) {
            return 'one-time';
        }

        return sprintf('%d %s%s', $quantity, $unit, $quantity === 1 ? '' : 's');
    }
}
