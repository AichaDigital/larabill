<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\VatCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * VatCategory Factory
 *
 * Uses integer base 100 for VAT rates (percentages)
 * Example: 21.50% is stored as 2150, 12.34% as 1234
 *
 * @extends Factory<VatCategory>
 */
class VatCategoryFactory extends Factory
{
    protected $model = VatCategory::class;

    public function definition(): array
    {
        $categoryType = $this->faker->randomElement(['standard', 'reduced', 'super_reduced', 'exempt']);

        return [
            'name' => $this->faker->randomElement([
                'Standard Rate',
                'Reduced Rate',
                'Super Reduced Rate',
                'Exempt',
                'Books and Publications',
                'Food and Beverages',
                'Medical Services',
                'Educational Services',
                'Transport Services',
                'Utilities',
                'Insurance Services',
                'Financial Services',
                'Postal Services',
                'Cultural Services',
                'Sports Services',
            ]),
            'description'         => $this->faker->optional(0.8)->sentence(),
            'country_code'        => $this->faker->randomElement(['ES', 'FR', 'DE', 'IT', 'PT', 'NL', 'BE', 'AT']),
            'vat_rate'            => $this->getRateForCategoryType($categoryType),
            'category_type'       => $categoryType,
            'is_active'           => $this->faker->boolean(90), // 90% chance of being active
            'applies_to_products' => $this->faker->boolean(80),
            'applies_to_services' => $this->faker->boolean(80),
            'special_conditions'  => $this->faker->optional(0.4)->randomElements([
                'medical_services',
                'education',
                'books',
                'food_basic',
                'transport',
                'utilities',
                'insurance',
                'financial_services',
            ], $this->faker->numberBetween(1, 3)),
            'sort_order'         => $this->faker->numberBetween(1, 100),
            'parent_category_id' => null,
            'last_updated'       => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }

    /**
     * Get appropriate rate for category type
     */
    private function getRateForCategoryType(string $categoryType): int
    {
        return match ($categoryType) {
            'standard'      => $this->faker->randomElement([2100, 2000, 1900]), // 21%, 20%, 19% in base 100
            'reduced'       => $this->faker->randomElement([1000, 800, 600]), // 10%, 8%, 6% in base 100
            'super_reduced' => $this->faker->randomElement([400, 300, 200]), // 4%, 3%, 2% in base 100
            'exempt'        => 0, // 0% in base 100
            default         => 2100, // Default to 21% in base 100
        };
    }

    /**
     * Create a Spanish VAT category
     */
    public function spanish(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'country_code'        => 'ES',
                'name'                => 'IVA Estándar España',
                'vat_rate'            => 2100, // 21% in base 100
                'category_type'       => 'standard',
                'applies_to_products' => true,
                'applies_to_services' => true,
                'is_active'           => true,
            ];
        });
    }

    /**
     * Create a standard rate category
     */
    public function standard(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'category_type'       => 'standard',
                'vat_rate'            => $this->faker->randomElement([2100, 2000, 1900]), // 21%, 20%, 19% in base 100
                'applies_to_products' => true,
                'applies_to_services' => true,
            ];
        });
    }

    /**
     * Create a reduced rate category
     */
    public function reduced(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'category_type'       => 'reduced',
                'vat_rate'            => $this->faker->randomElement([1000, 800, 600]), // 10%, 8%, 6% in base 100
                'applies_to_products' => $this->faker->boolean(80),
                'applies_to_services' => $this->faker->boolean(60),
                'special_conditions'  => $this->faker->randomElements([
                    'books',
                    'food_basic',
                    'medical_services',
                    'education',
                ], $this->faker->numberBetween(1, 2)),
            ];
        });
    }

    /**
     * Create a super reduced rate category
     */
    public function superReduced(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'category_type'       => 'super_reduced',
                'vat_rate'            => $this->faker->randomElement([400, 300, 200]), // 4%, 3%, 2% in base 100
                'applies_to_products' => true,
                'applies_to_services' => false,
                'special_conditions'  => $this->faker->randomElements([
                    'food_basic',
                    'medical_products',
                    'books',
                    'utilities',
                ], $this->faker->numberBetween(1, 2)),
            ];
        });
    }

    /**
     * Create an exempt category
     */
    public function exempt(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'category_type'       => 'exempt',
                'vat_rate'            => 0, // 0% in base 100
                'applies_to_products' => $this->faker->boolean(60),
                'applies_to_services' => $this->faker->boolean(80),
                'special_conditions'  => $this->faker->randomElements([
                    'medical_services',
                    'education',
                    'insurance',
                    'financial_services',
                    'postal_services',
                ], $this->faker->numberBetween(1, 3)),
            ];
        });
    }

    /**
     * Create a product-only category
     */
    public function productsOnly(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'applies_to_products' => true,
                'applies_to_services' => false,
            ];
        });
    }

    /**
     * Create a service-only category
     */
    public function servicesOnly(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'applies_to_products' => false,
                'applies_to_services' => true,
            ];
        });
    }

    /**
     * Create an inactive category
     */
    public function inactive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }

    /**
     * Create a child category
     */
    public function child(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'parent_category_id' => $this->faker->numberBetween(1, 10),
            ];
        });
    }
}
