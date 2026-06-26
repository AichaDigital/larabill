<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\CountryVatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CountryVatRate Factory
 *
 * Uses integer base 100 for monetary values (percentages, rates)
 * Example: 21.50% is stored as 2150, 12.34% as 1234
 *
 * @extends Factory<CountryVatRate>
 */
class CountryVatRateFactory extends Factory
{
    protected $model = CountryVatRate::class;

    public function definition(): array
    {
        return [
            'country_code'  => $this->faker->unique()->countryCode(),
            'country_name'  => $this->faker->country(),
            'standard_rate' => FixedDecimal::ofUnscaled($this->faker->numberBetween(1500, 2700), 2), // 15% to 27% in base 100
            'reduced_rates' => [
                'general'       => $this->faker->numberBetween(500, 1500), // 5% to 15% in base 100
                'super_reduced' => $this->faker->numberBetween(0, 500), // 0% to 5% in base 100
            ],
            'exempt_categories' => [
                $this->faker->randomElement(['medical_services', 'education', 'books', 'food']),
                $this->faker->randomElement(['transport', 'utilities', 'insurance']),
            ],
            'is_active'    => true,
            'data_source'  => 'factory',
            'last_updated' => now(),
        ];
    }

    /**
     * Create Spanish VAT rates
     */
    public function spanish(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code'  => 'ES',
                'country_name'  => 'Spain',
                'standard_rate' => FixedDecimal::ofUnscaled(2100, 2), // 21% in base 100
                'reduced_rates' => [
                    'general'       => 1000, // 10% in base 100
                    'super_reduced' => 400, // 4% in base 100
                ],
                'exempt_categories' => [
                    'medical_services',
                    'education',
                    'books',
                    'food_basic',
                ],
            ];
        });
    }

    /**
     * Create French VAT rates
     */
    public function french(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code'  => 'FR',
                'country_name'  => 'France',
                'standard_rate' => FixedDecimal::ofUnscaled(2000, 2), // 20% in base 100
                'reduced_rates' => [
                    'general'       => 1000, // 10% in base 100
                    'super_reduced' => 550, // 5.5% in base 100
                ],
                'exempt_categories' => [
                    'medical_services',
                    'education',
                    'books',
                ],
            ];
        });
    }

    /**
     * Create German VAT rates
     */
    public function german(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code'  => 'DE',
                'country_name'  => 'Germany',
                'standard_rate' => FixedDecimal::ofUnscaled(1900, 2), // 19% in base 100
                'reduced_rates' => [
                    'general' => 700, // 7% in base 100
                ],
                'exempt_categories' => [
                    'medical_services',
                    'education',
                    'books',
                ],
            ];
        });
    }

    /**
     * Create inactive VAT rate
     */
    public function inactive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
}
