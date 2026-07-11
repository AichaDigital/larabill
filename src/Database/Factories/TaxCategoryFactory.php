<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\TaxCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxCategory>
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class TaxCategoryFactory extends Factory
{
    protected $model = TaxCategory::class;

    public function definition(): array
    {
        return [
            'code'         => $this->faker->unique()->regexify('[A-Z]{3}_[A-Z]{2}'),
            'name'         => $this->faker->words(3, true),
            'tax_type'     => 'vat',
            'description'  => $this->faker->sentence(),
            'default_rate' => FixedDecimal::ofUnscaled((int) $this->faker->randomFloat(2, 0, 30), 2),
            'country_code' => 'ES',
            'region_code'  => null,
            'is_active'    => true,
            'sort_order'   => $this->faker->numberBetween(1, 100),
            'metadata'     => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withRegion(string $regionCode): static
    {
        return $this->state(fn (array $attributes) => [
            'region_code' => $regionCode,
        ]);
    }

    public function salesTax(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_type'     => 'sales_tax',
            'country_code' => 'US',
        ]);
    }

    public function gst(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_type'     => 'gst',
            'country_code' => 'AU',
        ]);
    }
}
