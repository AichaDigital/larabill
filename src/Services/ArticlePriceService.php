<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe write path for article prices — the guaranteed side of the non-overlap
 * invariant (AID-601, ADR-012).
 *
 * The model's saving hook is a safety net: two processes can validate and write
 * concurrently. This service closes that window by locking first.
 *
 * WHY THE LOCK IS ON THE ARTICLE ROW: `FOR UPDATE` only locks rows matching the
 * WHERE clause. The first price of a frequency matches zero rows, so locking
 * "the prices of this (article, frequency)" would not serialise two concurrent
 * first inserts at all. The parent articles row always exists; locking it
 * serialises every price write of that article, and avoids gap locks over empty
 * ranges (the deadlock source of AID-390/AID-570).
 *
 * NO RETRY HERE, BY DESIGN: callers typically wrap this in their own
 * DB::transaction, where an inner `attempts` would be a savepoint and buy
 * nothing (AID-570). The lock is held until the OUTER transaction commits;
 * deadlock retry belongs to whoever opens it.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class ArticlePriceService
{
    /**
     * Create an active price for one article and billing frequency.
     *
     * Throws OverlappingArticlePriceException when the range would be active at
     * the same time as another price of the same article and frequency.
     *
     * @throws OverlappingArticlePriceException
     */
    public function setPrice(
        Article $article,
        BillingFrequency $frequency,
        FixedDecimal $price,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
        ?int $billingDaysInAdvance = null,
    ): ArticlePrice {
        return DB::transaction(function () use ($article, $frequency, $price, $validFrom, $validTo, $billingDaysInAdvance): ArticlePrice {
            // Serialise every price write of this article (see class docblock).
            Article::query()->whereKey($article->getKey())->lockForUpdate()->first();

            // The saving hook re-checks with the same condition; validating here
            // is what makes the check meaningful, because it happens under lock.
            return ArticlePrice::query()->create([
                'article_id'              => $article->getKey(),
                'billing_frequency'       => $frequency,
                'price'                   => $price,
                'billing_days_in_advance' => $billingDaysInAdvance,
                'valid_from'              => $validFrom,
                'valid_to'                => $validTo,
                'is_active'               => true,
            ]);
        });
    }
}
