<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArticleOverrideFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = ArticleOverride::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return [
            'customer_id'  => $userModel::factory(),
            'article_id'   => Article::factory(),
            'custom_price' => $this->faker->numberBetween(500, 40000), // €5 to €400
            'reason'       => $this->faker->randomElement([
                'Premium customer discount',
                'Annual contract',
                'Volume discount',
                'Promotional offer',
                'Special agreement',
            ]),
            'valid_from' => now(),
            'valid_to'   => null,
            'is_active'  => true,
        ];
    }

    /**
     * Indicate that the override is currently valid.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active'  => true,
            'valid_from' => now()->subDays($this->faker->numberBetween(1, 30)),
            'valid_to'   => null,
        ]);
    }

    /**
     * Indicate that the override is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the override is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active'  => true,
            'valid_from' => now()->subMonths(3),
            'valid_to'   => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }

    /**
     * Indicate that the override is pending (starts in future).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active'  => true,
            'valid_from' => now()->addDays($this->faker->numberBetween(1, 30)),
            'valid_to'   => null,
        ]);
    }

    /**
     * Set a specific validity period.
     */
    public function validFor(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_from' => now(),
            'valid_to'   => now()->addDays($days),
        ]);
    }

    /**
     * Set a specific discount percentage.
     * Note: Uses MONTHLY frequency by default to calculate base price.
     */
    public function withDiscount(int $discountPercentage, BillingFrequency $frequency = BillingFrequency::MONTHLY): static
    {
        return $this->afterMaking(function (ArticleOverride $override) use ($discountPercentage, $frequency) {
            $basePrice              = $override->article->getPriceFor($frequency) ?? 0;
            $override->custom_price = (int) ($basePrice * (1 - $discountPercentage / 100));
        });
    }

    /**
     * Set for a specific customer.
     */
    public function forCustomer(int|string $customerId): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Set for a specific article.
     */
    public function forArticle(Article $article): static
    {
        return $this->state(fn (array $attributes) => [
            'article_id' => $article->id,
        ]);
    }
}
