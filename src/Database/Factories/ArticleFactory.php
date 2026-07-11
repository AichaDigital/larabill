<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\UnitMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class ArticleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Article::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'code'              => strtoupper($this->faker->unique()->lexify('ART-????')),
            'name'              => $this->faker->words(3, true),
            'description'       => $this->faker->sentence(),
            'item_type'         => ItemType::SERVICE,
            'category'          => $this->faker->randomElement(['hosting', 'domains', 'licenses', 'software', 'hardware']),
            'cost_price'        => FixedDecimal::ofUnscaled($this->faker->numberBetween(500, 30000), 2), // €5 to €300
            'subscription_type' => null,
            'tax_group_id'      => null,
            'unit_measure_id'   => null,
            'is_active'         => true,
            'metadata'          => [],
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Article $article) {
            // Create a default price if none specified via states
            if ($article->prices()->count() === 0) {
                ArticlePrice::factory()
                    ->for($article)
                    ->create([
                        'billing_frequency' => $article->isService()
                            ? BillingFrequency::MONTHLY
                            : BillingFrequency::ONE_TIME,
                        'price' => FixedDecimal::ofUnscaled($this->faker->numberBetween(1000, 50000), 2),
                    ]);
            }
        });
    }

    /**
     * Indicate that the article is a service.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type' => ItemType::SERVICE,
        ]);
    }

    /**
     * Indicate that the article is a good (product).
     */
    public function good(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type' => ItemType::GOOD,
        ]);
    }

    /**
     * Add a price with specific frequency.
     * Note: This replaces all auto-created prices with the specified one.
     */
    public function withPrice(BillingFrequency $frequency, int $price, ?int $daysInAdvance = null): static
    {
        return $this->afterCreating(function (Article $article) use ($frequency, $price, $daysInAdvance) {
            // Delete all auto-created prices to ensure deterministic state
            $article->prices()->delete();

            ArticlePrice::factory()
                ->for($article)
                ->create([
                    'billing_frequency'        => $frequency,
                    'price'                    => FixedDecimal::ofUnscaled($price, 2),
                    'billing_days_in_advance'  => $daysInAdvance,
                ]);
        });
    }

    /**
     * Add multiple prices for different frequencies.
     *
     * @param  array<int, array{frequency: BillingFrequency, price: int}>|array<BillingFrequency, int>  $prices
     */
    public function withPrices(array $prices): static
    {
        return $this->afterCreating(function (Article $article) use ($prices) {
            // Delete any auto-created prices first to avoid duplicates
            $article->prices()->delete();

            foreach ($prices as $key => $value) {
                // Support both formats:
                // 1. [['frequency' => BillingFrequency::MONTHLY, 'price' => 2900], ...]
                // 2. [BillingFrequency::MONTHLY->value => 2900, ...] (using value as key)
                if (is_array($value)) {
                    $frequency = $value['frequency'];
                    $price     = $value['price'];
                } elseif ($key instanceof BillingFrequency) {
                    $frequency = $key;
                    $price     = $value;
                } else {
                    // Key is int (enum value), value is price
                    $frequency = BillingFrequency::from($key);
                    $price     = $value;
                }

                ArticlePrice::factory()
                    ->for($article)
                    ->create([
                        'billing_frequency' => $frequency,
                        'price'             => FixedDecimal::ofUnscaled((int) $price, 2),
                    ]);
            }
        });
    }

    /**
     * Add a monthly price.
     */
    public function monthly(int $price = 2900): static
    {
        return $this->withPrice(BillingFrequency::MONTHLY, $price);
    }

    /**
     * Add a quarterly price.
     */
    public function quarterly(int $price = 7900): static
    {
        return $this->withPrice(BillingFrequency::QUARTERLY, $price);
    }

    /**
     * Add a yearly price.
     */
    public function yearly(int $price = 29000): static
    {
        return $this->withPrice(BillingFrequency::YEARLY, $price);
    }

    /**
     * Add a one-time price.
     */
    public function oneTime(int $price = 9900): static
    {
        return $this->withPrice(BillingFrequency::ONE_TIME, $price);
    }

    /**
     * Add typical service prices (monthly, quarterly, yearly).
     */
    public function withTypicalPrices(int $monthlyPrice = 2900): static
    {
        return $this->withPrices([
            BillingFrequency::MONTHLY->value   => $monthlyPrice,
            BillingFrequency::QUARTERLY->value => (int) ($monthlyPrice * 2.7),  // ~10% discount
            BillingFrequency::YEARLY->value    => (int) ($monthlyPrice * 10),   // ~17% discount
        ]);
    }

    /**
     * Track if prices should be auto-created.
     */
    protected bool $skipAutoPrices = false;

    /**
     * Skip automatic price creation.
     */
    public function withoutPrices(): static
    {
        return $this->afterCreating(function (Article $article) {
            // Delete any auto-created prices
            $article->prices()->delete();
        });
    }

    /**
     * Associate the article with a tax group.
     */
    public function withTaxGroup(?TaxGroup $taxGroup = null): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_group_id' => $taxGroup?->id ?? TaxGroup::factory(),
        ]);
    }

    /**
     * Associate the article with a unit measure.
     */
    public function withUnitMeasure(?UnitMeasure $unitMeasure = null): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_measure_id' => $unitMeasure?->id ?? UnitMeasure::factory(),
        ]);
    }

    /**
     * Indicate that the article is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific category.
     */
    public function category(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }

    /**
     * Set a specific code.
     */
    public function code(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $code,
        ]);
    }

    /**
     * Set cost price.
     */
    public function costPrice(int $costPrice): static
    {
        return $this->state(fn (array $attributes) => [
            'cost_price' => FixedDecimal::ofUnscaled($costPrice, 2),
        ]);
    }

    /**
     * Configure as a hosting service.
     */
    public function hosting(): static
    {
        return $this->service()
            ->category('hosting')
            ->costPrice(1500)
            ->withTypicalPrices(2900)
            ->state(fn (array $attributes) => [
                'name'     => 'Hosting '.$this->faker->randomElement(['Basic', 'Pro', 'Premium', 'Enterprise']),
                'metadata' => [
                    'service' => [
                        'requires_instance'   => true,
                        'instance_type'       => 'domain',
                        'instance_validation' => [
                            'type'     => 'domain',
                            'required' => true,
                        ],
                    ],
                ],
            ]);
    }

    /**
     * Configure as a domain service.
     */
    public function domain(string $tld = 'com'): static
    {
        return $this->service()
            ->category('domains')
            ->costPrice(800)
            ->yearly(1200)
            ->state(fn (array $attributes) => [
                'name'     => "Domain .{$tld}",
                'code'     => 'DOMAIN-'.strtoupper($tld),
                'metadata' => [
                    'service' => [
                        'requires_instance' => true,
                        'instance_type'     => 'domain',
                    ],
                ],
            ]);
    }

    /**
     * Configure as a software license.
     */
    public function license(): static
    {
        return $this->service()
            ->category('licenses')
            ->costPrice(5000)
            ->withTypicalPrices(9900)
            ->state(fn (array $attributes) => [
                'name'     => $this->faker->company().' License',
                'metadata' => [
                    'service' => [
                        'requires_instance' => false,
                    ],
                ],
            ]);
    }

    /**
     * Configure as a physical product.
     */
    public function product(): static
    {
        return $this->good()
            ->category('hardware')
            ->costPrice(30000)
            ->oneTime(50000)
            ->state(fn (array $attributes) => [
                'name'     => $this->faker->words(2, true).' '.$this->faker->randomElement(['Pro', 'Plus', 'Max']),
                'metadata' => [
                    'physical' => [
                        'weight'      => $this->faker->randomFloat(2, 0.1, 10),
                        'track_stock' => true,
                    ],
                ],
            ]);
    }
}
