<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\LegacyCadenceUnsupportedException;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
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
 * WHERE clause. The first price of a cadence matches zero rows, so locking
 * "the prices of this (article, cadence)" would not serialise two concurrent
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
     * Create an active price for one article and cadence — the ADR-015 entry
     * point (AID-952).
     *
     * The cadence is taken as a `?BillingInterval`: NULL is the one-time
     * cadence — a real, persisted state (§2.3) that deserves a first-class
     * parameter instead of a sentinel pair of nullable scalars, and the value
     * object validates unit/quantity at the boundary. Throws
     * OverlappingArticlePriceException when the range would be active at the
     * same time as another price of the same article and cadence — including
     * two one-time prices (NULL cadence vs NULL cadence).
     *
     * @throws OverlappingArticlePriceException
     */
    public function setIntervalPrice(
        Article $article,
        ?BillingInterval $interval,
        FixedDecimal $price,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
        ?int $billingDaysInAdvance = null,
    ): ArticlePrice {
        return DB::transaction(function () use ($article, $interval, $price, $validFrom, $validTo, $billingDaysInAdvance): ArticlePrice {
            // Serialise every price write of this article (see class docblock).
            Article::query()->whereKey($article->getKey())->lockForUpdate()->first();

            // The saving hook re-checks with the same condition; validating here
            // is what makes the check meaningful, because it happens under lock.
            return ArticlePrice::query()->create([
                'article_id'              => $article->getKey(),
                'billing_unit'            => $interval?->unit,
                'billing_quantity'        => $interval?->quantity,
                'price'                   => $price,
                'billing_days_in_advance' => $billingDaysInAdvance,
                'valid_from'              => $validFrom,
                'valid_to'                => $validTo,
                'is_active'               => true,
            ]);
        });
    }

    /**
     * Create an active price for one article and billing frequency — the
     * legacy-frequency signature, forwarded to `setIntervalPrice()`, which
     * holds the guarantee.
     *
     * @deprecated since 7.0.0, use `ArticlePriceService::setIntervalPrice()` (unit + quantity; `ONE_TIME` → NULL/NULL) — see UPGRADE-7.0.md
     *
     * WEEKLY and BIWEEKLY throw `LegacyCadenceUnsupportedException`: the ADR-015
     * cadence domain cannot represent them (the ONE qualified STABILITY.md §3
     * narrowing — zero corpus instances, AID-895 survey), and the migration preflight
     * loud-aborts any stored weekly row — so no 7.0 installation can hold such
     * data.
     *
     * @throws OverlappingArticlePriceException
     * @throws LegacyCadenceUnsupportedException
     */
    public function setPrice(
        Article $article,
        BillingFrequency $frequency,
        FixedDecimal $price,
        ?Carbon $validFrom = null,
        ?Carbon $validTo = null,
        ?int $billingDaysInAdvance = null,
    ): ArticlePrice {
        $this->triggerLegacyDeprecation(
            'ArticlePriceService::setPrice()',
            'ArticlePriceService::setIntervalPrice()',
        );

        return $this->setIntervalPrice(
            $article,
            BillingInterval::fromLegacyFrequency($frequency->value),
            $price,
            $validFrom,
            $validTo,
            $billingDaysInAdvance,
        );
    }

    /**
     * ONE legacy-deprecation emitter for this class's shim paths (ADR-015
     * §5): every delegating public path emits the same-shaped
     * `E_USER_DEPRECATED` through here. Internal code never calls legacy
     * paths, so nothing internal emits.
     */
    private function triggerLegacyDeprecation(string $method, string $replacement): void
    {
        trigger_error(sprintf(
            '%s is deprecated since larabill 7.0.0, use %s instead — see UPGRADE-7.0.md.',
            $method,
            $replacement,
        ), E_USER_DEPRECATED);
    }
}
