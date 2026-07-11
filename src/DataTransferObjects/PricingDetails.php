<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

/**
 * PricingDetails DTO
 *
 * Represents detailed pricing information for an invoice item.
 * This includes base price, applied price, discounts, and pricing rules.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class PricingDetails
{
    /**
     * @param  array<string, mixed>|null  $additional
     */
    public function __construct(
        public float $basePrice,
        public float $appliedPrice,
        public ?string $pricingRule = null,
        public ?float $discountAmount = null,
        public ?float $discountPercentage = null,
        public ?int $overrideId = null,
        public ?array $additional = null,
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            basePrice: $data['base_price'],
            appliedPrice: $data['applied_price'],
            pricingRule: $data['pricing_rule']               ?? null,
            discountAmount: $data['discount_amount']         ?? null,
            discountPercentage: $data['discount_percentage'] ?? null,
            overrideId: $data['override_id']                 ?? null,
            additional: $data['additional']                  ?? null,
        );
    }

    /**
     * Convert to array.
     *
     * @return array{base_price: float, applied_price: float, pricing_rule: string|null, discount_amount: float|null, discount_percentage: float|null, override_id: int|null, additional: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'base_price'          => $this->basePrice,
            'applied_price'       => $this->appliedPrice,
            'pricing_rule'        => $this->pricingRule,
            'discount_amount'     => $this->discountAmount,
            'discount_percentage' => $this->discountPercentage,
            'override_id'         => $this->overrideId,
            'additional'          => $this->additional,
        ];
    }
}
