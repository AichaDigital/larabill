<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\TaxType;
use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\TaxRate>
 */
class TaxRateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = TaxRate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'               => fake()->words(3, true),
            'rate'               => fake()->randomElement([2100, 1000, 400]),
            'region'             => fake()->countryCode(),
            'type'               => TaxType::VAT,
            'special_conditions' => null,
        ];
    }

    /**
     * Indicate that the tax rate has special conditions.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function withSpecialConditions(array $conditions): static
    {
        return $this->state(fn (array $attributes) => [
            'special_conditions' => $conditions,
        ]);
    }
}
