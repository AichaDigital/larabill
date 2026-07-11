<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DataTransferObjects;

use Carbon\Carbon;

/**
 * BillingDetails DTO
 *
 * Represents billing cycle and period information for recurring services.
 * This DTO stores temporal information about the billing period.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class BillingDetails
{
    /**
     * @param  array<string, mixed>|null  $additional
     */
    public function __construct(
        public ?string $billingCycle = null,
        public ?Carbon $periodStart = null,
        public ?Carbon $periodEnd = null,
        public ?Carbon $nextBillingDate = null,
        public ?int $billingInterval = null,
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
            billingCycle: $data['billing_cycle'] ?? null,
            periodStart: isset($data['period_start']) ? Carbon::parse($data['period_start']) : null,
            periodEnd: isset($data['period_end']) ? Carbon::parse($data['period_end']) : null,
            nextBillingDate: isset($data['next_billing_date']) ? Carbon::parse($data['next_billing_date']) : null,
            billingInterval: $data['billing_interval'] ?? null,
            additional: $data['additional']            ?? null,
        );
    }

    /**
     * Convert to array.
     *
     * @return array{billing_cycle: string|null, period_start: string|null, period_end: string|null, next_billing_date: string|null, billing_interval: int|null, additional: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'billing_cycle'     => $this->billingCycle,
            'period_start'      => $this->periodStart?->toIso8601String(),
            'period_end'        => $this->periodEnd?->toIso8601String(),
            'next_billing_date' => $this->nextBillingDate?->toIso8601String(),
            'billing_interval'  => $this->billingInterval,
            'additional'        => $this->additional,
        ];
    }
}
