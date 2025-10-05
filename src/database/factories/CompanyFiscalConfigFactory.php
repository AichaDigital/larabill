<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CompanyFiscalConfig Factory
 *
 * Uses integer base 100 for monetary amounts
 * Example: €1,234.56 is stored as 123456, €100.00 as 10000
 */
class CompanyFiscalConfigFactory extends Factory
{
    protected $model = CompanyFiscalConfig::class;

    public function definition(): array
    {
        $currentYear = now()->year;

        return [
            'company_id'              => $this->faker->uuid(),
            'apply_destination_iva'   => $this->faker->boolean(30), // 30% chance of applying destination VAT
            'eu_sales_threshold'      => 1000000, // €10,000.00 in base 100
            'current_eu_sales_amount' => $this->faker->numberBetween(0, 800000), // €0.00 to €8,000.00 in base 100
            'threshold_exceeded'      => $this->faker->boolean(20), // 20% chance of exceeding threshold
            'threshold_exceeded_at'   => $this->faker->optional(0.2)->dateTimeBetween('-6 months', 'now'),
            'fiscal_year'             => $currentYear,
            'auto_apply_destination'  => $this->faker->boolean(60),
            'notification_sent'       => $this->faker->boolean(40),
            'notification_sent_at'    => $this->faker->optional(0.4)->dateTimeBetween('-3 months', 'now'),
            'last_threshold_check'    => $this->faker->dateTimeBetween('-1 month', 'now'),
            'created_at'              => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at'              => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Create a configuration that applies destination VAT
     */
    public function appliesDestinationVat(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'apply_destination_iva'  => true,
                'auto_apply_destination' => true,
                'threshold_exceeded'     => true,
                'threshold_exceeded_at'  => $this->faker->dateTimeBetween('-6 months', 'now'),
                'notification_sent'      => true,
                'notification_sent_at'   => $this->faker->dateTimeBetween('-3 months', 'now'),
            ];
        });
    }

    /**
     * Create a configuration that doesn't apply destination VAT
     */
    public function doesNotApplyDestinationVat(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'apply_destination_iva'   => false,
                'auto_apply_destination'  => false,
                'threshold_exceeded'      => false,
                'threshold_exceeded_at'   => null,
                'notification_sent'       => false,
                'notification_sent_at'    => null,
                'current_eu_sales_amount' => $this->faker->numberBetween(0, 500000), // Under threshold
            ];
        });
    }

    /**
     * Create a configuration with threshold exceeded
     */
    public function thresholdExceeded(): static
    {
        return $this->state(function (array $attributes) {
            $exceededAmount = $this->faker->numberBetween(1000000, 2000000); // €10,000+ to €20,000+ in base 100

            return [
                'apply_destination_iva'   => true,
                'threshold_exceeded'      => true,
                'current_eu_sales_amount' => $exceededAmount,
                'threshold_exceeded_at'   => $this->faker->dateTimeBetween('-6 months', 'now'),
                'auto_apply_destination'  => true,
                'notification_sent'       => $this->faker->boolean(80),
                'notification_sent_at'    => $this->faker->optional(0.8)->dateTimeBetween('-3 months', 'now'),
            ];
        });
    }

    /**
     * Create a configuration approaching threshold
     */
    public function approachingThreshold(): static
    {
        return $this->state(function (array $attributes) {
            $approachingAmount = $this->faker->numberBetween(800000, 950000); // €8,000 to €9,500 in base 100

            return [
                'apply_destination_iva'   => false,
                'threshold_exceeded'      => false,
                'current_eu_sales_amount' => $approachingAmount,
                'threshold_exceeded_at'   => null,
                'auto_apply_destination'  => false,
                'notification_sent'       => false,
                'notification_sent_at'    => null,
            ];
        });
    }

    /**
     * Create a configuration with custom threshold
     */
    public function withCustomThreshold(int $thresholdInBase100): static
    {
        return $this->state(function (array $attributes) use ($thresholdInBase100) {
            return [
                'eu_sales_threshold'      => $thresholdInBase100,
                'current_eu_sales_amount' => $this->faker->numberBetween(0, $thresholdInBase100 - 100000), // Under custom threshold
                'threshold_exceeded'      => false,
                'threshold_exceeded_at'   => null,
            ];
        });
    }

    /**
     * Create a configuration for a specific fiscal year
     */
    public function forFiscalYear(int $year): static
    {
        return $this->state(function (array $attributes) use ($year) {
            return [
                'fiscal_year'             => $year,
                'current_eu_sales_amount' => 0, // Reset for new year
                'threshold_exceeded'      => false,
                'threshold_exceeded_at'   => null,
                'notification_sent'       => false,
                'notification_sent_at'    => null,
                'last_threshold_check'    => $this->faker->dateTimeBetween("-{$year}-01-01", "-{$year}-12-31"),
            ];
        });
    }

    /**
     * Create a configuration that needs notification
     */
    public function needsNotification(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'apply_destination_iva'  => true,
                'threshold_exceeded'     => true,
                'auto_apply_destination' => true,
                'notification_sent'      => false,
                'notification_sent_at'   => null,
                'threshold_exceeded_at'  => $this->faker->dateTimeBetween('-1 week', 'now'),
            ];
        });
    }

    /**
     * Create a configuration with notification already sent
     */
    public function notificationSent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'apply_destination_iva'  => true,
                'threshold_exceeded'     => true,
                'auto_apply_destination' => true,
                'notification_sent'      => true,
                'notification_sent_at'   => $this->faker->dateTimeBetween('-1 month', '-1 week'),
            ];
        });
    }

    /**
     * Create a configuration with high EU sales
     */
    public function highEuSales(): static
    {
        return $this->state(function (array $attributes) {
            $highAmount = $this->faker->numberBetween(1500000, 5000000); // €15,000 to €50,000 in base 100

            return [
                'current_eu_sales_amount' => $highAmount,
                'threshold_exceeded'      => true,
                'apply_destination_iva'   => true,
                'auto_apply_destination'  => true,
                'threshold_exceeded_at'   => $this->faker->dateTimeBetween('-1 year', '-1 month'),
            ];
        });
    }

    /**
     * Create a configuration with low EU sales
     */
    public function lowEuSales(): static
    {
        return $this->state(function (array $attributes) {
            $lowAmount = $this->faker->numberBetween(0, 300000); // €0 to €3,000 in base 100

            return [
                'current_eu_sales_amount' => $lowAmount,
                'threshold_exceeded'      => false,
                'apply_destination_iva'   => false,
                'auto_apply_destination'  => false,
                'threshold_exceeded_at'   => null,
                'notification_sent'       => false,
                'notification_sent_at'    => null,
            ];
        });
    }
}
