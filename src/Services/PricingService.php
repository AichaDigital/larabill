<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\DataTransferObjects\PricingDetails;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\{Article, ArticleOverride, ArticleServiceStatus};

/**
 * PricingService
 *
 * Handles pricing calculations for articles including customer overrides and discounts.
 * This service centralizes all pricing logic to ensure consistency across the application.
 */
class PricingService
{
    /**
     * Get the effective price for a customer at a specific frequency.
     * Returns the ArticlePrice for the frequency if no customer or no active override exists.
     */
    public function getEffectivePrice(Article $article, BillingFrequency $frequency, ?int $customerId): ?float
    {
        $basePrice = $article->getPriceFor($frequency);

        if ($basePrice === null) {
            return null;
        }

        if (! $customerId) {
            return $basePrice;
        }

        $override = $this->getActiveOverride($article, $customerId);

        return $override->custom_price ?? $basePrice;
    }

    /**
     * Get the effective price for a service (uses the service's billing frequency).
     */
    public function getEffectivePriceForService(ArticleServiceStatus $service): ?float
    {
        return $this->getEffectivePrice(
            $service->article,
            $service->billing_frequency,
            $service->customer_id
        );
    }

    /**
     * Get active override for customer.
     */
    public function getActiveOverride(Article $article, int $customerId): ?ArticleOverride
    {
        return $article->overrides()
            ->active()
            ->forCustomer($customerId)
            ->first();
    }

    /**
     * Check if customer has active override.
     */
    public function hasActiveOverride(Article $article, int $customerId): bool
    {
        return $this->getActiveOverride($article, $customerId) !== null;
    }

    /**
     * Calculate discount amount.
     */
    public function calculateDiscountAmount(float $basePrice, float $appliedPrice): float
    {
        return max(0, $basePrice - $appliedPrice);
    }

    /**
     * Calculate discount percentage.
     */
    public function calculateDiscountPercentage(float $basePrice, float $appliedPrice): float
    {
        if ($basePrice == 0) {
            return 0.0;
        }

        $discount = $this->calculateDiscountAmount($basePrice, $appliedPrice);

        return round(($discount / $basePrice) * 100, 2);
    }

    /**
     * Create pricing details DTO for an article, frequency and customer.
     */
    public function createPricingDetails(
        Article $article,
        BillingFrequency $frequency,
        ?int $customerId
    ): PricingDetails {
        $basePrice    = $article->getPriceFor($frequency) ?? 0.0;
        $override     = $customerId ? $this->getActiveOverride($article, $customerId) : null;
        $appliedPrice = $override?->custom_price ?? $basePrice;

        $discountAmount     = $this->calculateDiscountAmount($basePrice, $appliedPrice);
        $discountPercentage = $this->calculateDiscountPercentage($basePrice, $appliedPrice);

        return new PricingDetails(
            basePrice: $basePrice,
            appliedPrice: $appliedPrice,
            pricingRule: $override ? 'customer_override' : 'base_price',
            discountAmount: $discountAmount         > 0 ? $discountAmount : null,
            discountPercentage: $discountPercentage > 0 ? $discountPercentage : null,
            overrideId: $override?->id,
        );
    }

    /**
     * Create pricing details DTO for a service (uses service's billing frequency).
     */
    public function createPricingDetailsForService(ArticleServiceStatus $service): PricingDetails
    {
        return $this->createPricingDetails(
            $service->article,
            $service->billing_frequency,
            $service->customer_id
        );
    }

    /**
     * Validate if price is within acceptable range.
     * Prevents prices below cost if cost_price is set.
     */
    public function validatePrice(Article $article, float $price): bool
    {
        if ($article->cost_price !== null && $article->cost_price > 0 && $price < $article->cost_price) {
            return false;
        }

        return $price >= 0;
    }

    /**
     * Calculate profit margin for a given price.
     */
    public function calculateProfitMargin(Article $article, float $price): ?float
    {
        if (! $article->cost_price) {
            return null;
        }

        return $price - $article->cost_price;
    }

    /**
     * Calculate profit margin percentage for a given price.
     */
    public function calculateProfitMarginPercentage(Article $article, float $price): ?float
    {
        if (! $article->cost_price || $price == 0) {
            return null;
        }

        $margin = $this->calculateProfitMargin($article, $price);

        return round(($margin / $price) * 100, 2);
    }
}
