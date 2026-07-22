<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticlePrice;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * An article price would have been active at the same time as another price of
 * the same article and billing frequency.
 *
 * Two prices live at once make billing non-deterministic: Article::getPriceFor()
 * resolves a single value, so one of them silently wins and reaches invoice
 * lines (AID-601, ADR-012). The database cannot express "at most one valid at
 * any date" — no exclusion constraints in MySQL — so the invariant is enforced
 * here, at write time.
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
            'Article %d, frequency %s: price %s conflicts with active price(s) [%s]. '
            .'At most one price per article and frequency may be active on any given date '
            .'(AID-601). Run `php artisan larabill:diagnose-price-overlaps` to list every '
            .'existing conflict.',
            $candidate->article_id,
            $candidate->billing_frequency->name,
            $describe($candidate),
            $conflicts->map($describe)->implode(', '),
        ));
    }
}
