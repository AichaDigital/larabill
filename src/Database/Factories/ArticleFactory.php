<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\{BillingFrequency, ItemType};
use AichaDigital\Larabill\Models\{Article, TaxGroup, UnitMeasure};
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'base_price'        => $this->faker->numberBetween(1000, 50000), // €10 to €500
            'cost_price'        => $this->faker->numberBetween(500, 30000), // €5 to €300
            'is_recurring'      => true,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'billing_interval'  => 1,
            'subscription_type' => null,
            'tax_group_id'      => null,
            'unit_measure_id'   => null,
            'is_active'         => true,
            'metadata'          => [],
        ];
    }

    /**
     * Indicate that the article is a service.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type'         => ItemType::SERVICE,
            'is_recurring'      => true,
            'billing_frequency' => BillingFrequency::MONTHLY,
        ]);
    }

    /**
     * Indicate that the article is a good (product).
     */
    public function good(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type'         => ItemType::GOOD,
            'is_recurring'      => false,
            'billing_frequency' => null,
            'billing_interval'  => 1,
        ]);
    }

    /**
     * Indicate that the article is recurring.
     */
    public function recurring(?BillingFrequency $frequency = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recurring'      => true,
            'billing_frequency' => $frequency ?? BillingFrequency::MONTHLY,
        ]);
    }

    /**
     * Indicate that the article is not recurring (one-time).
     */
    public function oneTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recurring'      => false,
            'billing_frequency' => null,
        ]);
    }

    /**
     * Indicate that the article is monthly recurring.
     */
    public function monthly(): static
    {
        return $this->recurring(BillingFrequency::MONTHLY);
    }

    /**
     * Indicate that the article is quarterly recurring.
     */
    public function quarterly(): static
    {
        return $this->recurring(BillingFrequency::QUARTERLY);
    }

    /**
     * Indicate that the article is yearly recurring.
     */
    public function yearly(): static
    {
        return $this->recurring(BillingFrequency::YEARLY);
    }

    /**
     * Indicate that the article is a lifetime purchase.
     */
    public function lifetime(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recurring'      => false,
            'billing_frequency' => BillingFrequency::LIFETIME,
        ]);
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
     * Set specific pricing.
     */
    public function pricing(int $basePrice, ?int $costPrice = null): static
    {
        return $this->state(fn (array $attributes) => [
            'base_price' => $basePrice,
            'cost_price' => $costPrice,
        ]);
    }

    /**
     * Configure as a hosting service.
     */
    public function hosting(): static
    {
        return $this->service()
            ->monthly()
            ->category('hosting')
            ->pricing(2900, 1500) // €29 base, €15 cost
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
            ->yearly()
            ->category('domains')
            ->pricing(1200, 800) // €12 base, €8 cost
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
            ->monthly()
            ->category('licenses')
            ->pricing(9900, 5000) // €99 base, €50 cost
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
            ->pricing(50000, 30000) // €500 base, €300 cost
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
