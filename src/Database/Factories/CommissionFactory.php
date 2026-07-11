<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CommissionAppliesTo;
use AichaDigital\Larabill\Enums\CommissionLevel;
use AichaDigital\Larabill\Enums\CommissionType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\Commission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commission>
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class CommissionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Commission>
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
            'level'              => CommissionLevel::GLOBAL,
            'article_id'         => null,
            'product_group'      => null,
            'type'               => CommissionType::PERCENTAGE,
            'rate'               => FixedDecimal::ofUnscaled((int) $rate, 2),
            'applies_to'         => CommissionAppliesTo::TAXABLE_AMOUNT,
            'valid_from'         => now(),
            'valid_until'        => null,
            'is_active'          => true,
            'min_amount'         => null,
            'max_amount'         => null,
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
            'level'      => CommissionLevel::PRODUCT,
            'article_id' => Article::factory(),
        ]);
    }

    /**
     * Indicate that this is a product-group commission.
     */
    public function productGroup(?string $group = null): static
    {
        return $this->state(fn (array $attributes) => [
            'level'         => CommissionLevel::PRODUCT_GROUP,
            'product_group' => $group ?? $this->faker->word(),
        ]);
    }

    /**
     * Indicate that this is a global commission.
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => CommissionLevel::GLOBAL,
        ]);
    }

    /**
     * Indicate that this is a fixed-amount commission.
     */
    public function fixed(?float $amount = null): static
    {
        $fixedAmount = $amount ?? $this->faker->randomFloat(2, 1, 100);

        return $this->state(fn (array $attributes) => [
            'type' => CommissionType::FIXED,
            'rate' => FixedDecimal::ofUnscaled((int) $fixedAmount, 2),
        ]);
    }

    /**
     * Indicate that this is a percentage commission.
     */
    public function percentage(?float $rate = null): static
    {
        $percentageRate = $rate ?? $this->faker->randomFloat(4, 1, 30);

        return $this->state(fn (array $attributes) => [
            'type' => CommissionType::PERCENTAGE,
            'rate' => FixedDecimal::ofUnscaled((int) $percentageRate, 2),
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
