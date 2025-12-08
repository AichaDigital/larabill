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
            'name'            => ['en' => $this->faker->company(), 'es' => $this->faker->company()],
            'abbreviation'    => ['en' => strtoupper($this->faker->lexify('??'))],
            'country_code'    => 'ES',
            'description'     => ['en' => $this->faker->sentence()],
            'requires_tax_id' => true,
            'is_active'       => true,
            'sort_order'      => $this->faker->numberBetween(0, 100),
            'metadata'        => [
                'tax_id_format' => 'CIF',
            ],
        ];
    }

    /**
     * Indicate that the entity is a person type.
     */
    public function person(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'PERSONA_FISICA',
            'name' => ['es' => 'Persona Física', 'en' => 'Individual/Natural Person'],
        ]);
    }

    /**
     * Indicate that the entity is a limited company type.
     */
    public function limitedCompany(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'SOCIEDAD_LIMITADA',
            'name' => ['es' => 'Sociedad de Responsabilidad Limitada', 'en' => 'Limited Liability Company'],
        ]);
    }

    /**
     * Indicate that the entity is a self-employed type.
     */
    public function selfEmployed(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'AUTONOMO',
            'name' => ['es' => 'Trabajador Autónomo', 'en' => 'Self-Employed/Freelancer'],
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
