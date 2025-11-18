<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\{IssuerConfig, IssuerTaxProfile};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\IssuerConfig>
 */
class IssuerConfigFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\AichaDigital\Larabill\Models\IssuerConfig>
     */
    protected $model = IssuerConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fiscalYear = now()->year;

        return [
            'id'                            => 1, // Singleton
            'current_tax_profile_id'        => null,
            'is_roi_registered'             => false,
            'is_oss_registered'             => false,
            'eu_sales_threshold'            => 10000.00, // €10,000
            'current_eu_sales'              => 0,
            'fiscal_year'                   => $fiscalYear,
            'fiscal_year_start'             => now()->startOfYear(),
            'fiscal_year_end'               => now()->endOfYear(),
            'threshold_exceeded'            => false,
            'threshold_exceeded_at'         => null,
            'threshold_notification_sent'   => false,
            'fiscal_settings'               => null,
            'metadata'                      => null,
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (IssuerConfig $issuer) {
            // Create default tax profile if none exists
            if (! $issuer->currentTaxProfile) {
                $profile = IssuerTaxProfile::factory()->create([
                    'is_current' => true,
                ]);

                $issuer->update(['current_tax_profile_id' => $profile->id]);
            }
        });
    }

    /**
     * Indicate that the issuer is ROI registered.
     */
    public function roiRegistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_roi_registered' => true,
        ]);
    }

    /**
     * Indicate that the issuer is OSS registered.
     */
    public function ossRegistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_oss_registered' => true,
        ]);
    }

    /**
     * Indicate that the threshold has been exceeded.
     */
    public function thresholdExceeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_eu_sales'      => 12000.00, // Above threshold
            'threshold_exceeded'    => true,
            'threshold_exceeded_at' => now(),
        ]);
    }
}
