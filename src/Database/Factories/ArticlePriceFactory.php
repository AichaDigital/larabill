<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArticlePriceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = ArticlePrice::class;

    /**
     * Define the model's default state.
     *
     * Note: Uses withoutPrices() to avoid creating duplicate prices when
     * ArticlePrice is being created explicitly.
     */
    public function definition(): array
    {
        return [
            'article_id'               => Article::factory()->withoutPrices(),
            'billing_frequency'        => BillingFrequency::MONTHLY,
            'price'                    => $this->faker->numberBetween(1000, 50000), // €10 to €500
            'billing_days_in_advance'  => null,
            'valid_from'               => null,
            'valid_to'                 => null,
            'is_active'                => true,
        ];
    }

    /**
     * Set the billing frequency.
     */
    public function frequency(BillingFrequency $frequency): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_frequency' => $frequency,
        ]);
    }

    /**
     * Set the price.
     */
    public function price(int $price): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => $price,
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
     * One-time price (no recurrence).
     */
    public function oneTime(): static
    {
        return $this->frequency(BillingFrequency::ONE_TIME);
    }

    /**
     * Weekly billing frequency.
     */
    public function weekly(): static
    {
        return $this->frequency(BillingFrequency::WEEKLY);
    }

    /**
     * Biweekly billing frequency.
     */
    public function biweekly(): static
    {
        return $this->frequency(BillingFrequency::BIWEEKLY);
    }

    /**
     * Monthly billing frequency.
     */
    public function monthly(): static
    {
        return $this->frequency(BillingFrequency::MONTHLY);
    }

    /**
     * Bimonthly billing frequency.
     */
    public function bimonthly(): static
    {
        return $this->frequency(BillingFrequency::BIMONTHLY);
    }

    /**
     * Quarterly billing frequency.
     */
    public function quarterly(): static
    {
        return $this->frequency(BillingFrequency::QUARTERLY);
    }

    /**
     * Semiannual billing frequency.
     */
    public function semiannual(): static
    {
        return $this->frequency(BillingFrequency::SEMIANNUAL);
    }

    /**
     * Yearly billing frequency.
     */
    public function yearly(): static
    {
        return $this->frequency(BillingFrequency::YEARLY);
    }

    /**
     * Biennial billing frequency.
     */
    public function biennial(): static
    {
        return $this->frequency(BillingFrequency::BIENNIAL);
    }

    /**
     * Triennial billing frequency.
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
