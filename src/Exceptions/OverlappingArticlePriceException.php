<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticlePrice;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * An article price would have been active at the same time as another price of
 * the same article and billing cadence.
 *
 * Two prices live at once make billing non-deterministic: Article::getPriceForInterval()
 * resolves a single value, so one of them silently wins and reaches invoice
 * lines (AID-601, ADR-012). The database cannot express "at most one valid at
 * any date" — no exclusion constraints in MySQL, and the re-keyed unique on
 * (article_id, billing_unit, billing_quantity, valid_from) permits multiple
 * NULL-cadence rows anyway (ADR-015 §2.3 declared limit) — so the invariant is
 * enforced here, at write time.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class OverlappingArticlePriceException extends RuntimeException
{
    /**
     * @param  Collection<int, ArticlePrice>  $conflicts
     */
    public static function forCandidate(ArticlePrice $candidate, Collection $conflicts): self
    {
        $describe = static fn (ArticlePrice $price): string => sprintf(
            '#%s %s→%s',
            $price->getKey()                    ?? 'new',
            $price->valid_from?->toDateString() ?? 'open',
            $price->valid_to?->toDateString()   ?? 'open',
        );

        return new self(sprintf(
            'Article %d, cadence %s: price %s conflicts with active price(s) [%s]. '
            .'At most one price per article and cadence may be active on any given date '
            .'(AID-601). Run `php artisan larabill:diagnose-price-overlaps` to list every '
            .'existing conflict.',
            $candidate->article_id,
            self::cadenceLabel($candidate),
            $describe($candidate),
            $conflicts->map($describe)->implode(', '),
        ));
    }

    /**
     * Human label of the candidate's cadence: "3 months", "1 year", or
     * "one-time (NULL cadence)" — the ADR-015 domain, never a frequency name.
     */
    private static function cadenceLabel(ArticlePrice $price): string
    {
        if ($price->billing_unit === null) {
            return 'one-time (NULL cadence)';
        }

        return sprintf(
            '%d %s%s',
            $price->billing_quantity,
            $price->billing_unit,
            (int) $price->billing_quantity === 1 ? '' : 's',
        );
    }
}
