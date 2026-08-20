<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticleOverride;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A customer/article pair may have at most ONE active override on any given
 * date (AID-974). Sister of OverlappingArticlePriceException (ADR-012).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class OverlappingArticleOverrideException extends RuntimeException
{
    /** @param  Collection<int, ArticleOverride>  $conflicts */
    public static function forRange(ArticleOverride $candidate, Collection $conflicts): self
    {
        $detail = $conflicts
            ->map(fn (ArticleOverride $c): string => sprintf(
                '#%s [%s .. %s]',
                $c->getKey(),
                $c->valid_from?->toDateString() ?? 'open',
                $c->valid_to?->toDateString()   ?? 'open',
            ))
            ->implode(', ');

        return new self(sprintf(
            'The override range [%s .. %s] for customer %s on article %s overlaps an active one: %s. '.
            'A customer/article pair may hold at most one active override on any given date.',
            $candidate->valid_from?->toDateString() ?? 'open',
            $candidate->valid_to?->toDateString()   ?? 'open',
            (string) $candidate->customer_id,
            (string) $candidate->article_id,
            $detail,
        ));
    }
}
