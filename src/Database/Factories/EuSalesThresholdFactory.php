<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * EuSalesThreshold Factory
 *
 * Monetary amounts are base-100 minor units wrapped in FixedDecimal:2
 * (€1,234.56 => 123456). breakdown_by_country is a flat map of integer cents
 * per country code, matching the model's storage contract (AID-240).
 *
 * @extends Factory<EuSalesThreshold>
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class EuSalesThresholdFactory extends Factory
{
    protected $model = EuSalesThreshold::class;

    public function definition(): array
    {
        $currentYear = now()->year;

        return [
            'user_id'              => $this->faker->uuid(),
            'fiscal_year'          => $currentYear,
            'total_amount'         => $this->money($this->faker->numberBetween(0, 800000)), // €0 – €8,000
            'threshold_amount'     => $this->money(1000000), // €10,000.00
            'threshold_exceeded'   => false,
            'exceeded_at'          => null,
            'notification_sent'    => $this->faker->boolean(40),
            'breakdown_by_country' => $this->countryBreakdown(),
            'currency'             => 'EUR',
            'last_updated'         => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Wrap base-100 minor units into a FixedDecimal:2 the cast accepts.
     */
    private function money(int $cents): FixedDecimal
    {
        return FixedDecimal::ofUnscaled($cents, 2);
    }

    /**
     * Flat per-country breakdown in integer cents.
     *
     * @return array<string, int>
     */
    private function countryBreakdown(): array
    {
        $countries = $this->faker->randomElements(
            ['FR', 'DE', 'IT', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'SE'],
            $this->faker->numberBetween(2, 5)
        );

        $breakdown = [];
        foreach ($countries as $country) {
            $breakdown[$country] = $this->faker->numberBetween(50000, 300000); // €500 – €3,000
        }

        return $breakdown;
    }

    /**
     * A threshold that has been exceeded (≥ €10,000).
     */
    public function exceeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount'       => $this->money($this->faker->numberBetween(1000000, 2000000)),
            'threshold_exceeded' => true,
            'exceeded_at'        => $this->faker->dateTimeBetween('-6 months', 'now'),
            'notification_sent'  => $this->faker->boolean(80),
        ]);
    }

    /**
     * A threshold that has not been exceeded.
     */
    public function notExceeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount'       => $this->money($this->faker->numberBetween(0, 800000)),
            'threshold_exceeded' => false,
            'exceeded_at'        => null,
            'notification_sent'  => false,
        ]);
    }

    /**
     * A threshold approaching the limit (€8,000 – €9,500).
     */
    public function approaching(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount'       => $this->money($this->faker->numberBetween(800000, 950000)),
            'threshold_exceeded' => false,
            'exceeded_at'        => null,
            'notification_sent'  => false,
        ]);
    }

    /**
     * A threshold with a custom amount (base-100 minor units).
     */
    public function withAmount(int $amountInBase100): static
    {
        $exceeded = $amountInBase100 >= 1000000;

        return $this->state(fn (array $attributes): array => [
            'total_amount'       => $this->money($amountInBase100),
            'threshold_exceeded' => $exceeded,
            'exceeded_at'        => $exceeded ? $this->faker->dateTimeBetween('-6 months', 'now') : null,
            'notification_sent'  => $exceeded && $this->faker->boolean(80),
        ]);
    }

    /**
     * A threshold with a custom threshold amount (base-100 minor units).
     */
    public function withThreshold(int $thresholdInBase100): static
    {
        return $this->state(fn (array $attributes): array => [
            'threshold_amount'   => $this->money($thresholdInBase100),
            'total_amount'       => $this->money($this->faker->numberBetween(0, max(0, $thresholdInBase100 - 100000))),
            'threshold_exceeded' => false,
            'exceeded_at'        => null,
            'notification_sent'  => false,
        ]);
    }

    /**
     * A threshold for a specific fiscal year.
     */
    public function forFiscalYear(int $year): static
    {
        return $this->state(fn (array $attributes): array => [
            'fiscal_year'  => $year,
            'total_amount' => $this->money($this->faker->numberBetween(0, 500000)),
        ]);
    }

    /**
     * A threshold that needs notification (exceeded, not yet notified).
     */
    public function needsNotification(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount'       => $this->money($this->faker->numberBetween(1000000, 1500000)),
            'threshold_exceeded' => true,
            'exceeded_at'        => $this->faker->dateTimeBetween('-1 week', 'now'),
            'notification_sent'  => false,
        ]);
    }

    /**
     * A threshold with notification already sent.
     */
    public function notificationSent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_amount'       => $this->money($this->faker->numberBetween(1000000, 2000000)),
            'threshold_exceeded' => true,
            'exceeded_at'        => $this->faker->dateTimeBetween('-1 month', '-1 week'),
            'notification_sent'  => true,
        ]);
    }

    /**
     * A threshold with a specific country breakdown (integer cents per country).
     *
     * @param  array<string, int>  $countries
     */
    public function withCountryBreakdown(array $countries): static
    {
        return $this->state(fn (array $attributes): array => [
            'breakdown_by_country' => $countries,
        ]);
    }
}
