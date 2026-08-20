<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\OverlappingArticleOverrideException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Write path for customer price overrides — the GUARANTEED side of the
 * non-overlap invariant (AID-974 D5), sister of ArticlePriceService (ADR-012).
 *
 * The model's saving hook is a safety net: two processes can validate and write
 * concurrently, each checking before the other writes. This service closes that
 * window by locking first.
 *
 * WHY THE LOCK IS ON THE ARTICLE ROW: `FOR UPDATE` only locks rows matching the
 * WHERE clause. The first override of a customer/article pair matches zero rows,
 * so locking "the overrides of this pair" would not serialise two concurrent
 * first inserts at all. The parent articles row always exists; locking it
 * serialises every override write of that article, and avoids gap locks over
 * empty ranges (the deadlock source of AID-390/AID-570).
 *
 * NO RETRY HERE, BY DESIGN: callers typically wrap this in their own
 * DB::transaction, where an inner `attempts` would be a savepoint and buy
 * nothing (AID-570). The lock is held until the OUTER transaction commits;
 * deadlock retry belongs to whoever opens it.
 *
 * CREATE-ONLY ON PURPOSE: silently replacing a live override would be deciding
 * the consumer's price. An overlapping range is rejected, not absorbed.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ArticleOverrideService
{
    /**
     * Create an active price override for one customer and article.
     *
     * Throws OverlappingArticleOverrideException when the range would be active
     * at the same time as another override of the same customer/article pair.
     *
     * @throws InvalidArgumentException when valid_from falls after valid_to
     * @throws OverlappingArticleOverrideException
     */
    public function setOverride(
        Article $article,
        int|string $customerId,
        FixedDecimal $customPrice,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
        ?string $reason = null,
    ): ArticleOverride {
        // Day grain, like every other comparison of these two `date` columns
        // (AID-974 D2bis): ends landing on the same stored date are a valid
        // one-day range, whatever their time of day.
        if ($validFrom !== null && $validTo !== null && $validFrom->toDateString() > $validTo->toDateString()) {
            throw new InvalidArgumentException(sprintf(
                'The override range is inverted: valid_from %s falls after valid_to %s.',
                $validFrom->toDateString(),
                $validTo->toDateString(),
            ));
        }

        return DB::transaction(function () use ($article, $customerId, $customPrice, $validFrom, $validTo, $reason): ArticleOverride {
            // Serialise every override write of this article (see class docblock).
            Article::query()->whereKey($article->getKey())->lockForUpdate()->first();

            // The saving hook re-checks with the same condition; validating here
            // is what makes the check meaningful, because it happens under lock.
            return ArticleOverride::query()->create([
                'article_id'   => $article->getKey(),
                'customer_id'  => $customerId,
                'custom_price' => $customPrice,
                'valid_from'   => $validFrom,
                'valid_to'     => $validTo,
                'reason'       => $reason,
                'is_active'    => true,
            ]);
        });
    }
}
