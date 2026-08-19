<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\DataTransferObjects\PricingDetails;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\MissingContractPriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;

/**
 * PricingService
 *
 * Handles pricing calculations for articles including customer overrides and discounts.
 * This service centralizes all pricing logic to ensure consistency across the application.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class PricingService
{
    /**
     * Get the effective price for a customer at a specific frequency.
     * Returns the ArticlePrice for the frequency if no customer or no active override exists.
     */
    public function getEffectivePrice(Article $article, BillingFrequency $frequency, int|string|null $customerId): ?float
    {
        $basePrice = $article->getPriceFor($frequency);

        if ($basePrice === null) {
            return null;
        }

        if (! $customerId) {
            return $basePrice;
        }

        $override = $this->getActiveOverride($article, $customerId);

        return $override?->custom_price?->unscaledValue() ?? $basePrice;
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
    public function getActiveOverride(Article $article, int|string $customerId): ?ArticleOverride
    {
        return $article->overrides()
            ->active()
            ->forCustomer($customerId)
            ->first();
    }

    /**
     * Check if customer has active override.
     */
    public function hasActiveOverride(Article $article, int|string $customerId): bool
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
        if ($basePrice === 0.0) {
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
        int|string|null $customerId
    ): PricingDetails {
        $basePrice    = $article->getPriceFor($frequency) ?? 0.0;
        $override     = $customerId ? $this->getActiveOverride($article, $customerId) : null;
        $appliedPrice = $override?->custom_price?->unscaledValue() ?? $basePrice;

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
     * Create pricing details DTO from the CONTRACT, for recurring emission.
     *
     * Reads the price stored on the agreement and consults neither the
     * catalogue nor overrides: `effective_price` is contractual state, not a
     * cache (ADR-004, AID-956 D1). Changing an ArticlePrice or an
     * ArticleOverride therefore does not reprice a live agreement.
     *
     * Discounts are null on purpose: there is no stored historical base to
     * compute them against, and inventing one by querying the catalogue is
     * exactly what this method exists to avoid.
     *
     * `overrideId` is carried as an OBSERVATION — "which discount the contract
     * pointed at when it was emitted" — never as provenance of the amount:
     * both columns are independently fillable and the FK is nullOnDelete, so
     * nothing guarantees that override set that price (AID-956 D6).
     */
    public function createPricingDetailsForContract(ArticleServiceStatus $service): PricingDetails
    {
        // Absent is null and ONLY null: a contract price of zero is a valid
        // agreement and is billed as zero (AID-956 D7). Never write this as
        // `if (! $price)`.
        if ($service->effective_price === null) {
            throw MissingContractPriceException::forService($service);
        }

        $contractPrice = $service->effective_price->unscaledValue();

        return new PricingDetails(
            basePrice: $contractPrice,
            appliedPrice: $contractPrice,
            pricingRule: 'contract_price',
            discountAmount: null,
            discountPercentage: null,
            overrideId: $service->current_override_id,
        );
    }

    /**
     * Validate if price is within acceptable range.
     * Prevents prices below cost if cost_price is set.
     */
    public function validatePrice(Article $article, float $price): bool
    {
        if ($article->cost_price !== null && $article->cost_price->unscaledValue() > 0 && $price < $article->cost_price->unscaledValue()) {
            return false;
        }

        return $price >= 0;
    }

    /**
     * Calculate profit margin for a given price.
     */
    public function calculateProfitMargin(Article $article, float $price): ?float
    {
        if ($article->cost_price === null || $article->cost_price->isZero()) {
            return null;
        }

        return $price - $article->cost_price->unscaledValue();
    }

    /**
     * Calculate profit margin percentage for a given price.
     */
    public function calculateProfitMarginPercentage(Article $article, float $price): ?float
    {
        if ($article->cost_price === null || $article->cost_price->isZero() || $price === 0.0) {
            return null;
        }

        $margin = $this->calculateProfitMargin($article, $price);

        return round(($margin / $price) * 100, 2);
    }
}
