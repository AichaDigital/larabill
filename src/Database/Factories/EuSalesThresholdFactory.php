<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\EuSalesThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * EuSalesThreshold Factory
 *
 * Uses integer base 100 for monetary amounts
 * Example: €1,234.56 is stored as 123456, €100.00 as 10000
 *
 * @extends Factory<EuSalesThreshold>
 */
class EuSalesThresholdFactory extends Factory
{
    protected $model = EuSalesThreshold::class;

    public function definition(): array
    {
        $currentYear = now()->year;

        return [
            'company_id'           => $this->faker->uuid(),
            'fiscal_year'          => $currentYear,
            'total_amount'         => $this->faker->numberBetween(0, 800000), // €0.00 to €8,000.00 in base 100
            'threshold_amount'     => 1000000, // €10,000.00 in base 100
            'threshold_exceeded'   => $this->faker->boolean(20), // 20% chance of exceeding threshold
            'exceeded_at'          => $this->faker->optional(0.2)->dateTimeBetween('-6 months', 'now'),
            'notification_sent'    => $this->faker->boolean(40),
            'notification_sent_at' => $this->faker->optional(0.4)->dateTimeBetween('-3 months', 'now'),
            'breakdown_by_country' => $this->generateCountryBreakdown(),
            'last_updated'         => $this->faker->dateTimeBetween('-1 month', 'now'),
            'created_at'           => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at'           => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Generate realistic country breakdown data
     *
     * @return array<string, array<string, mixed>>
     */
    private function generateCountryBreakdown(): array
    {
        $countries = $this->faker->randomElements([
            'FR', 'DE', 'IT', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'SE',
        ], $this->faker->numberBetween(2, 5));

        $breakdown       = [];
        $remainingAmount = $this->faker->numberBetween(100000, 800000); // €1,000 to €8,000 in base 100

        foreach ($countries as $country) {
            if ($country === end($countries)) {
                // Last country gets remaining amount
                $amount = $remainingAmount;
            } else {
                // Distribute amount among countries
                $amount = $this->faker->numberBetween(50000, min($remainingAmount - 50000, 300000)); // €500 to €3,000 in base 100
                $remainingAmount -= $amount;
            }

            $breakdown[$country] = [
                'amount'         => $amount,
                'percentage'     => 0, // Will be calculated
                'currency'       => 'EUR',
                'last_sale_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            ];
        }

        // Calculate percentages
        $totalAmount = array_sum(array_column($breakdown, 'amount'));
        foreach ($breakdown as &$data) {
            $data['percentage'] = $totalAmount > 0 ? round(($data['amount'] / $totalAmount) * 100, 2) : 0;
        }

        return $breakdown;
    }

    /**
     * Create a threshold that has been exceeded
     */
    public function exceeded(): static
    {
        return $this->state(function (array $attributes) {
            $exceededAmount = $this->faker->numberBetween(1000000, 2000000); // €10,000+ to €20,000+ in base 100

            return [
                'total_amount'         => $exceededAmount,
                'threshold_exceeded'   => true,
                'exceeded_at'          => $this->faker->dateTimeBetween('-6 months', 'now'),
                'notification_sent'    => $this->faker->boolean(80),
                'notification_sent_at' => $this->faker->optional(0.8)->dateTimeBetween('-3 months', 'now'),
                'breakdown_by_country' => $this->generateHighSalesBreakdown($exceededAmount),
            ];
        });
    }

    /**
     * Create a threshold that hasn't been exceeded
     */
    public function notExceeded(): static
    {
        return $this->state(function (array $attributes) {
            $underAmount = $this->faker->numberBetween(0, 800000); // €0 to €8,000 in base 100

            return [
                'total_amount'         => $underAmount,
                'threshold_exceeded'   => false,
                'exceeded_at'          => null,
                'notification_sent'    => false,
                'notification_sent_at' => null,
                'breakdown_by_country' => $this->generateCountryBreakdown(),
            ];
        });
    }

    /**
     * Create a threshold approaching the limit
     */
    public function approaching(): static
    {
        return $this->state(function (array $attributes) {
            $approachingAmount = $this->faker->numberBetween(800000, 950000); // €8,000 to €9,500 in base 100

            return [
                'total_amount'         => $approachingAmount,
                'threshold_exceeded'   => false,
                'exceeded_at'          => null,
                'notification_sent'    => false,
                'notification_sent_at' => null,
                'breakdown_by_country' => $this->generateCountryBreakdown(),
            ];
        });
    }

    /**
     * Create a threshold with custom amount
     */
    public function withAmount(int $amountInBase100): static
    {
        return $this->state(function (array $attributes) use ($amountInBase100) {
            return [
                'total_amount'         => $amountInBase100,
                'threshold_exceeded'   => $amountInBase100 >= 1000000, // €10,000 in base 100
                'exceeded_at'          => $amountInBase100 >= 1000000 ? $this->faker->dateTimeBetween('-6 months', 'now') : null,
                'notification_sent'    => $amountInBase100 >= 1000000 && $this->faker->boolean(80),
                'notification_sent_at' => $amountInBase100 >= 1000000 && $this->faker->boolean(80)
                    ? $this->faker->dateTimeBetween('-3 months', 'now')
                    : null,
                'breakdown_by_country' => $this->generateCountryBreakdown(),
            ];
        });
    }

    /**
     * Create a threshold with custom threshold amount
     */
    public function withThreshold(int $thresholdInBase100): static
    {
        return $this->state(function (array $attributes) use ($thresholdInBase100) {
            return [
                'threshold_amount'     => $thresholdInBase100,
                'total_amount'         => $this->faker->numberBetween(0, $thresholdInBase100 - 100000), // Under custom threshold
                'threshold_exceeded'   => false,
                'exceeded_at'          => null,
                'notification_sent'    => false,
                'notification_sent_at' => null,
            ];
        });
    }

    /**
     * Create a threshold for a specific fiscal year
     */
    public function forFiscalYear(int $year): static
    {
        return $this->state(function (array $attributes) use ($year) {
            return [
                'fiscal_year'          => $year,
                'total_amount'         => $this->faker->numberBetween(0, 500000), // Lower amounts for past years
                'threshold_exceeded'   => $this->faker->boolean(10), // Lower chance for past years
                'exceeded_at'          => $this->faker->optional(0.1)->dateTimeBetween("-{$year}-01-01", "-{$year}-12-31"),
                'notification_sent'    => $this->faker->boolean(20),
                'notification_sent_at' => $this->faker->optional(0.2)->dateTimeBetween("-{$year}-01-01", "-{$year}-12-31"),
                'last_updated'         => $this->faker->dateTimeBetween("-{$year}-01-01", "-{$year}-12-31"),
            ];
        });
    }

    /**
     * Create a threshold that needs notification
     */
    public function needsNotification(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'total_amount'         => $this->faker->numberBetween(1000000, 1500000), // €10,000+ to €15,000+ in base 100
                'threshold_exceeded'   => true,
                'exceeded_at'          => $this->faker->dateTimeBetween('-1 week', 'now'),
                'notification_sent'    => false,
                'notification_sent_at' => null,
            ];
        });
    }

    /**
     * Create a threshold with notification already sent
     */
    public function notificationSent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'total_amount'         => $this->faker->numberBetween(1000000, 2000000), // €10,000+ to €20,000+ in base 100
                'threshold_exceeded'   => true,
                'exceeded_at'          => $this->faker->dateTimeBetween('-1 month', '-1 week'),
                'notification_sent'    => true,
                'notification_sent_at' => $this->faker->dateTimeBetween('-1 month', '-1 week'),
            ];
        });
    }

    /**
     * Create a threshold with high sales
     */
    public function highSales(): static
    {
        return $this->state(function (array $attributes) {
            $highAmount = $this->faker->numberBetween(1500000, 5000000); // €15,000 to €50,000 in base 100

            return [
                'total_amount'         => $highAmount,
                'threshold_exceeded'   => true,
                'exceeded_at'          => $this->faker->dateTimeBetween('-1 year', '-1 month'),
                'notification_sent'    => true,
                'notification_sent_at' => $this->faker->dateTimeBetween('-1 year', '-1 month'),
                'breakdown_by_country' => $this->generateHighSalesBreakdown($highAmount),
            ];
        });
    }

    /**
     * Create a threshold with low sales
     */
    public function lowSales(): static
    {
        return $this->state(function (array $attributes) {
            $lowAmount = $this->faker->numberBetween(0, 300000); // €0 to €3,000 in base 100

            return [
                'total_amount'         => $lowAmount,
                'threshold_exceeded'   => false,
                'exceeded_at'          => null,
                'notification_sent'    => false,
                'notification_sent_at' => null,
                'breakdown_by_country' => $this->generateCountryBreakdown(),
            ];
        });
    }

    /**
     * Create a threshold with specific country breakdown
     *
     * @param  array<string, mixed>  $countries
     */
    public function withCountryBreakdown(array $countries): static
    {
        return $this->state(function (array $attributes) use ($countries) {
            return [
                'breakdown_by_country' => $countries,
            ];
        });
    }

    /**
     * Generate high sales breakdown for exceeded thresholds
     *
     * @return array<string, array<string, mixed>>
     */
    private function generateHighSalesBreakdown(int $totalAmount): array
    {
        $countries = $this->faker->randomElements([
            'FR', 'DE', 'IT', 'NL', 'BE', 'AT', 'PT', 'IE', 'FI', 'SE', 'DK', 'PL', 'CZ', 'HU',
        ], $this->faker->numberBetween(3, 8));

        $breakdown       = [];
        $remainingAmount = $totalAmount;

        foreach ($countries as $country) {
            if ($country === end($countries)) {
                // Last country gets remaining amount
                $amount = $remainingAmount;
            } else {
                // Distribute amount among countries
                $maxAmount = min($remainingAmount - 100000, 1000000); // Ensure at least €1,000 remains
                $amount    = $this->faker->numberBetween(100000, $maxAmount); // €1,000+ in base 100
                $remainingAmount -= $amount;
            }

            $breakdown[$country] = [
                'amount'         => $amount,
                'percentage'     => 0, // Will be calculated
                'currency'       => 'EUR',
                'last_sale_date' => $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
                'sales_count'    => $this->faker->numberBetween(5, 50),
            ];
        }

        // Calculate percentages
        foreach ($breakdown as &$data) {
            $data['percentage'] = $totalAmount > 0 ? round(($data['amount'] / $totalAmount) * 100, 2) : 0;
        }

        return $breakdown;
    }
}
