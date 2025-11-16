<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\{Article, Commission};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\Commission>
 */
class CommissionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = Commission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rate = $this->faker->randomFloat(4, 1, 30);

        return [
            'level'              => 'global',
            'article_id'         => null,
            'product_group'      => null,
            'type'               => 'percentage',
            'rate'               => $rate,
            'rate_base100'       => (int) ($rate * 100),
            'applies_to'         => 'taxable_amount',
            'valid_from'         => now(),
            'valid_until'        => null,
            'is_active'          => true,
            'min_amount_base100' => null,
            'max_amount_base100' => null,
            'min_quantity'       => null,
            'name'               => $this->faker->words(3, true),
            'description'        => $this->faker->optional()->sentence(),
            'metadata'           => null,
        ];
    }

    /**
     * Indicate that this is a product-level commission.
     */
    public function product(): static
    {
        return $this->state(fn (array $attributes) => [
            'level'      => 'product',
            'article_id' => Article::factory(),
        ]);
    }

    /**
     * Indicate that this is a product-group commission.
     */
    public function productGroup(?string $group = null): static
    {
        return $this->state(fn (array $attributes) => [
            'level'         => 'product_group',
            'product_group' => $group ?? $this->faker->word(),
        ]);
    }

    /**
     * Indicate that this is a global commission.
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => 'global',
        ]);
    }

    /**
     * Indicate that this is a fixed-amount commission.
     */
    public function fixed(?float $amount = null): static
    {
        $fixedAmount = $amount ?? $this->faker->randomFloat(2, 1, 100);

        return $this->state(fn (array $attributes) => [
            'type'         => 'fixed',
            'rate'         => $fixedAmount,
            'rate_base100' => (int) ($fixedAmount * 100),
        ]);
    }

    /**
     * Indicate that this is a percentage commission.
     */
    public function percentage(?float $rate = null): static
    {
        $percentageRate = $rate ?? $this->faker->randomFloat(4, 1, 30);

        return $this->state(fn (array $attributes) => [
            'type'         => 'percentage',
            'rate'         => $percentageRate,
            'rate_base100' => (int) ($percentageRate * 100),
        ]);
    }

    /**
     * Indicate that this commission is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that this commission has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_until' => now()->subDay(),
        ]);
    }
}
