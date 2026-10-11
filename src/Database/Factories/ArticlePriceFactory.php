<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class ArticlePriceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = ArticlePrice::class;

    /**
     * Define the model's default state.
     *
     * The default cadence is deterministic (1 month) — never random: cadence
     * is guard-governing (the AID-601 overlap invariant keys on it), and the
     * house rule forbids randomElement on guard-governing fields (AID-508).
     * Tests declaring another cadence set it explicitly.
     *
     * Note: Uses withoutPrices() to avoid creating duplicate prices when
     * ArticlePrice is being created explicitly.
     */
    public function definition(): array
    {
        return [
            'article_id'               => Article::factory()->withoutPrices(),
            'billing_unit'             => 'month',
            'billing_quantity'         => 1,
            'price'                    => FixedDecimal::ofUnscaled($this->faker->numberBetween(1000, 50000), 2), // €10 to €500
            'billing_days_in_advance'  => null,
            'valid_from'               => null,
            'valid_to'                 => null,
            'is_active'                => true,
        ];
    }

    /**
     * Convert a legacy `BillingFrequency` into the ADR-015 cadence columns at
     * the seeding boundary (AID-952). WEEKLY/BIWEEKLY throw — the new domain
     * cannot represent them, so no fixture can either.
     *
     * @return array{billing_unit: string|null, billing_quantity: int|null}
     */
    public static function cadenceAttributes(BillingFrequency $frequency): array
    {
        $interval = BillingInterval::fromLegacyFrequency($frequency->value);

        return [
            'billing_unit'     => $interval?->unit,
            'billing_quantity' => $interval?->quantity,
        ];
    }

    /**
     * Set the billing cadence from a legacy frequency. ONE_TIME becomes the
     * NULL cadence.
     */
    public function frequency(BillingFrequency $frequency): static
    {
        return $this->state(fn () => self::cadenceAttributes($frequency));
    }

    /**
     * Set the billing cadence directly, in the ADR-015 domain.
     */
    public function interval(?BillingInterval $interval): static
    {
        return $this->state(fn () => [
            'billing_unit'     => $interval?->unit,
            'billing_quantity' => $interval?->quantity,
        ]);
    }

    /**
     * Set the price.
     */
    public function price(int $price): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => FixedDecimal::ofUnscaled($price, 2),
        ]);
    }

    /**
     * Set billing days in advance.
     */
    public function daysInAdvance(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_days_in_advance' => $days,
        ]);
    }

    /**
     * One-time price (NULL cadence, no recurrence).
     */
    public function oneTime(): static
    {
        return $this->frequency(BillingFrequency::ONE_TIME);
    }

    /**
     * Monthly billing cadence (1 month).
     */
    public function monthly(): static
    {
        return $this->frequency(BillingFrequency::MONTHLY);
    }

    /**
     * Bimonthly billing cadence (2 months).
     */
    public function bimonthly(): static
    {
        return $this->frequency(BillingFrequency::BIMONTHLY);
    }

    /**
     * Quarterly billing cadence (3 months).
     */
    public function quarterly(): static
    {
        return $this->frequency(BillingFrequency::QUARTERLY);
    }

    /**
     * Semiannual billing cadence (6 months).
     */
    public function semiannual(): static
    {
        return $this->frequency(BillingFrequency::SEMIANNUAL);
    }

    /**
     * Yearly billing cadence (1 year).
     */
    public function yearly(): static
    {
        return $this->frequency(BillingFrequency::YEARLY);
    }

    /**
     * Biennial billing cadence (2 years).
     */
    public function biennial(): static
    {
        return $this->frequency(BillingFrequency::BIENNIAL);
    }

    /**
     * Triennial billing cadence (3 years).
     */
    public function triennial(): static
    {
        return $this->frequency(BillingFrequency::TRIENNIAL);
    }

    /**
     * Set validity period.
     */
    public function validBetween(Carbon $from, Carbon $to): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => $from,
            'valid_to'   => $to,
        ]);
    }

    /**
     * Set valid from date.
     */
    public function validFrom(Carbon $date): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => $date,
        ]);
    }

    /**
     * Set valid until date.
     */
    public function validUntil(Carbon $date): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_to' => $date,
        ]);
    }

    /**
     * Make the price currently valid (started in past, no end date).
     */
    public function currentlyValid(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now()->subMonth(),
            'valid_to'   => null,
            'is_active'  => true,
        ]);
    }

    /**
     * Make the price expired (valid period in the past).
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now()->subYear(),
            'valid_to'   => now()->subMonth(),
            'is_active'  => true,
        ]);
    }

    /**
     * Make the price future (valid period in the future).
     */
    public function future(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now()->addMonth(),
            'valid_to'   => now()->addYear(),
            'is_active'  => true,
        ]);
    }

    /**
     * Make the price inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Make the price active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
}
