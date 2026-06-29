<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Enums\CommissionLevel;
use AichaDigital\Larabill\Enums\CommissionType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\Commission;
use Illuminate\Support\Collection;

/**
 * Commission Calculation Service
 *
 * Handles multi-level commission calculations:
 * - Priority: product > product_group > global
 * - Supports percentage and fixed-amount commissions
 * - Validates amount and quantity thresholds
 */
class CommissionCalculationService
{
    /**
     * Calculate commission for an invoice item.
     *
     * @param  int|null  $articleId  Article ID
     * @param  string|null  $productGroup  Product group name
     * @param  float  $baseAmount  Amount to calculate on (taxable or total)
     * @param  int  $quantity  Quantity of items
     * @param  \DateTimeInterface|null  $date  Date to check validity (default: now)
     * @return array{
     *     commission_id: int|null,
     *     commission_name: string|null,
     *     commission_rate: float|null,
     *     commission_type: int|null,
     *     commission_amount: float,
     *     commission_level: int|null
     * }
     */
    public function calculateForItem(
        ?int $articleId = null,
        ?string $productGroup = null,
        float $baseAmount = 0,
        int $quantity = 1,
        ?\DateTimeInterface $date = null
    ): array {
        $date = $date ?? now();

        // Find applicable commission with priority (product > product_group > global)
        $commission = $this->findApplicableCommission($articleId, $productGroup, $date);

        if (! $commission) {
            return [
                'commission_id'     => null,
                'commission_name'   => null,
                'commission_rate'   => null,
                'commission_type'   => null,
                'commission_amount' => 0.0,
                'commission_level'  => null,
            ];
        }

        $amount = $commission->calculateAmount($baseAmount, $quantity);

        return [
            'commission_id'     => $commission->id,
            'commission_name'   => $commission->name,
            'commission_rate'   => $commission->rate->unscaledValue(),
            'commission_type'   => $commission->type->value,
            'commission_amount' => $amount,
            'commission_level'  => $commission->level->value,
        ];
    }

    /**
     * Calculate commission amount (simplified return).
     *
     * @param  float  $baseAmount  Amount to calculate on
     * @param  int|null  $articleId  Article ID
     * @param  string|null  $productGroup  Product group name
     * @param  \DateTimeInterface|null  $date  Date to check validity
     * @return float Commission amount
     */
    public function calculateCommission(
        float $baseAmount,
        ?int $articleId = null,
        ?string $productGroup = null,
        ?\DateTimeInterface $date = null
    ): float {
        $result = $this->calculateForItem($articleId, $productGroup, $baseAmount, 1, $date);

        return $result['commission_amount'];
    }

    /**
     * Calculate total commissions for multiple items.
     *
     * @param  array<array{article_id?: int, product_group?: string, base_amount: float, quantity?: int}>  $items
     * @return array{
     *     total_commission: float,
     *     items: array<array{commission_id: int|null, commission_name: string|null, commission_amount: float}>
     * }
     */
    public function calculateForItems(array $items, ?\DateTimeInterface $date = null): array
    {
        $date                = $date ?? now();
        $totalCommission     = 0;
        $itemsWithCommission = [];

        foreach ($items as $item) {
            $result = $this->calculateForItem(
                $item['article_id']    ?? null,
                $item['product_group'] ?? null,
                $item['base_amount'],
                $item['quantity'] ?? 1,
                $date
            );

            $totalCommission += $result['commission_amount'];
            $itemsWithCommission[] = $result;
        }

        return [
            'total_commission' => $totalCommission,
            'items'            => $itemsWithCommission,
        ];
    }

    /**
     * Find applicable commission with priority logic.
     */
    protected function findApplicableCommission(
        ?int $articleId,
        ?string $productGroup,
        \DateTimeInterface $date
    ): ?Commission {
        // Try product-level first (highest priority)
        if ($articleId) {
            $commission = Commission::active()
                ->level(CommissionLevel::PRODUCT)
                ->forArticle($articleId)
                ->validForDate($date)
                ->first();

            if ($commission) {
                return $commission;
            }
        }

        // Try product-group level (medium priority)
        if ($productGroup) {
            $commission = Commission::active()
                ->level(CommissionLevel::PRODUCT_GROUP)
                ->forProductGroup($productGroup)
                ->validForDate($date)
                ->first();

            if ($commission) {
                return $commission;
            }
        }

        // Fall back to global level (lowest priority)
        return Commission::active()
            ->level(CommissionLevel::GLOBAL)
            ->validForDate($date)
            ->first();
    }

    /**
     * Get all active commissions grouped by level.
     *
     * @return array{
     *     global: Collection<int, Commission>|Collection<int|string, mixed>,
     *     product_group: Collection<int, Commission>|Collection<int|string, mixed>,
     *     product: Collection<int, Commission>|Collection<int|string, mixed>
     * }
     */
    public function getActiveCommissions(): array
    {
        $commissions = Commission::active()->get()->groupBy('level');

        return [
            'global'        => $commissions->get(CommissionLevel::GLOBAL->value, collect()),
            'product_group' => $commissions->get(CommissionLevel::PRODUCT_GROUP->value, collect()),
            'product'       => $commissions->get(CommissionLevel::PRODUCT->value, collect()),
        ];
    }

    /**
     * Preview commission for an article.
     *
     * @return array{
     *     has_commission: bool,
     *     commission: array{id: int|null, name: string|null, rate: float|null, type: int|null, level: int|null}|null,
     *     amount: float
     * }
     */
    public function previewForArticle(int $articleId, float $baseAmount, int $quantity = 1): array
    {
        $article = Article::find($articleId);
        if (! $article) {
            return [
                'has_commission' => false,
                'commission'     => null,
                'amount'         => 0,
            ];
        }

        $result = $this->calculateForItem(
            $articleId,
            $article->metadata['product_group'] ?? null,
            $baseAmount,
            $quantity
        );

        return [
            'has_commission' => $result['commission_id'] !== null,
            'commission'     => $result['commission_id'] ? [
                'id'     => $result['commission_id'],
                'name'   => $result['commission_name'],
                'rate'   => $result['commission_rate'],
                'type'   => $result['commission_type'],
                'level'  => $result['commission_level'],
            ] : null,
            'amount' => $result['commission_amount'],
        ];
    }

    /**
     * Get commission statistics.
     *
     * @return array{
     *     total_active: int,
     *     by_level: array<string, int>,
     *     by_type: array<string, int>
     * }
     */
    public function getStatistics(?\DateTimeInterface $startDate = null, ?\DateTimeInterface $endDate = null): array
    {
        $query = Commission::active();

        if ($startDate) {
            $query->where('valid_from', '>=', $startDate);
        }

        if ($endDate) {
            $query->where(function ($q) use ($endDate) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '<=', $endDate);
            });
        }

        $commissions = $query->get();

        return [
            'total_active' => $commissions->count(),
            'by_level'     => [
                'global'        => $commissions->where('level', CommissionLevel::GLOBAL)->count(),
                'product_group' => $commissions->where('level', CommissionLevel::PRODUCT_GROUP)->count(),
                'product'       => $commissions->where('level', CommissionLevel::PRODUCT)->count(),
            ],
            'by_type' => [
                'percentage' => $commissions->where('type', CommissionType::PERCENTAGE)->count(),
                'fixed'      => $commissions->where('type', CommissionType::FIXED)->count(),
            ],
        ];
    }
}
