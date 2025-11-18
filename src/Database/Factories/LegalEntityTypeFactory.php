<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\LegalEntityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\LegalEntityType>
 */
class LegalEntityTypeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\AichaDigital\Larabill\Models\LegalEntityType>
     */
    protected $model = LegalEntityType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->word());

        return [
            'code'            => $code,
            'name'            => $this->faker->company(),
            'name_en'         => $this->faker->companySuffix(),
            'abbreviation'    => strtoupper($this->faker->lexify('??')),
            'country_code'    => 'ES',
            'description'     => $this->faker->sentence(),
            'requires_tax_id' => true,
            'is_company'      => $this->faker->boolean(70),
            'is_active'       => true,
            'sort_order'      => $this->faker->numberBetween(0, 100),
            'metadata'        => [
                'tax_id_format' => 'CIF',
            ],
        ];
    }

    /**
     * Indicate that the entity is a person (not company).
     */
    public function person(): static
    {
        return $this->state(fn (array $attributes) => [
            'code'       => 'PERSONA_FISICA',
            'name'       => 'Persona Física',
            'name_en'    => 'Individual/Natural Person',
            'is_company' => false,
        ]);
    }

    /**
     * Indicate that the entity is a company.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'code'       => 'SOCIEDAD_LIMITADA',
            'name'       => 'Sociedad de Responsabilidad Limitada',
            'name_en'    => 'Limited Liability Company',
            'is_company' => true,
        ]);
    }

    /**
     * Indicate that the entity is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
