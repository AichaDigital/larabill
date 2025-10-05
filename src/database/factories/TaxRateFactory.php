<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * TaxRate Factory
 *
 * Uses integer base 100 for tax rates (percentages)
 * Example: 21.50% is stored as 2150, 12.34% as 1234
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'country_code' => $this->faker->randomElement(['ES', 'FR', 'DE', 'IT', 'PT', 'NL', 'BE', 'AT']),
            'country_name' => $this->faker->randomElement([
                'Spain', 'France', 'Germany', 'Italy', 'Portugal',
                'Netherlands', 'Belgium', 'Austria'
            ]),
            'tax_name' => $this->faker->randomElement([
                'VAT', 'IVA', 'TVA', 'USt', 'IVA', 'BTW', 'TVA', 'USt'
            ]),
            'tax_type' => $this->faker->randomElement(['standard', 'reduced', 'super_reduced', 'exempt']),
            'rate' => $this->faker->randomElement([2100, 1900, 2000, 1000, 400, 0]), // Base 100 rates
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
            'applies_to' => $this->faker->randomElement(['products', 'services', 'both']),
            'special_conditions' => $this->faker->optional(0.3)->randomElements([
                'medical_services',
                'education',
                'books',
                'food_basic',
                'transport',
                'utilities',
                'insurance'
            ], $this->faker->numberBetween(1, 3)),
        ];
    }

    /**
     * Create a Spanish VAT rate
     */
    public function spanish(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code' => 'ES',
                'country_name' => 'Spain',
                'tax_name' => 'IVA',
                'rate' => 2100, // 21% in base 100
                'tax_type' => 'standard',
                'applies_to' => 'both',
                'is_active' => true,
            ];
        });
    }

    /**
     * Create a French VAT rate
     */
    public function french(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code' => 'FR',
                'country_name' => 'France',
                'tax_name' => 'TVA',
                'rate' => 2000, // 20% in base 100
                'tax_type' => 'standard',
                'applies_to' => 'both',
                'is_active' => true,
            ];
        });
    }

    /**
     * Create a German VAT rate
     */
    public function german(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code' => 'DE',
                'country_name' => 'Germany',
                'tax_name' => 'USt',
                'rate' => 1900, // 19% in base 100
                'tax_type' => 'standard',
                'applies_to' => 'both',
                'is_active' => true,
            ];
        });
    }

    /**
     * Create a reduced rate
     */
    public function reduced(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'rate' => $this->faker->randomElement([1000, 800, 600]), // 10%, 8%, or 6% in base 100
                'tax_type' => 'reduced',
                'applies_to' => $this->faker->randomElement(['products', 'services']),
                'special_conditions' => $this->faker->randomElements([
                    'books',
                    'food_basic',
                    'medical_services',
                    'education'
                ], $this->faker->numberBetween(1, 2)),
            ];
        });
    }

    /**
     * Create a super reduced rate
     */
    public function superReduced(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'rate' => $this->faker->randomElement([400, 300, 200]), // 4%, 3%, or 2% in base 100
                'tax_type' => 'super_reduced',
                'applies_to' => 'products',
                'special_conditions' => $this->faker->randomElements([
                    'food_basic',
                    'medical_products',
                    'books',
                    'utilities'
                ], $this->faker->numberBetween(1, 2)),
            ];
        });
    }

    /**
     * Create an exempt rate
     */
    public function exempt(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'rate' => 0, // 0% in base 100
                'tax_type' => 'exempt',
                'applies_to' => $this->faker->randomElement(['products', 'services', 'both']),
                'special_conditions' => $this->faker->randomElements([
                    'medical_services',
                    'education',
                    'insurance',
                    'financial_services',
                    'postal_services'
                ], $this->faker->numberBetween(1, 3)),
            ];
        });
    }

    /**
     * Create an inactive rate
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
