<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFiscalConfig>
 */
class CompanyFiscalConfigFactory extends Factory
{
    protected $model = CompanyFiscalConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_name'      => fake()->company().' S.L.',
            'tax_id'             => 'ES'.fake()->numerify('B########'),
            'legal_entity_type'  => fake()->randomElement(['SL', 'SA', 'Autónomo', 'Cooperativa']),
            'address'            => fake()->streetAddress(),
            'city'               => fake()->city(),
            'state'              => fake()->optional()->state(),
            'zip_code'           => fake()->postcode(),
            'country_code'       => 'ES',
            'is_oss'             => false,
            'is_roi'             => false,
            'currency'           => 'EUR',
            'fiscal_year_start'  => '01-01',
            'valid_from'         => now()->startOfYear(),
            'valid_until'        => null, // Config activa por defecto
            'is_active'          => true,
            'notes'              => null,
        ];
    }

    /**
     * Config activa actual (sin valid_until).
     */
    public function active(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active'   => true,
            'valid_until' => null,
            'valid_from'  => now()->startOfYear(),
        ]);
    }

    /**
     * Config histórica (con valid_until en el pasado).
     */
    public function historical(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active'   => false,
            'valid_from'  => now()->subYears(2)->startOfYear(),
            'valid_until' => now()->subYear()->endOfYear(),
        ]);
    }

    /**
     * Config para operador OSS.
     */
    public function oss(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_oss' => true,
        ]);
    }

    /**
     * Config para operador ROI (intracomunitario).
     */
    public function roi(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_roi' => true,
        ]);
    }

    /**
     * Config española.
     */
    public function spanish(): self
    {
        return $this->state(fn (array $attributes) => [
            'business_name'    => fake()->company().' S.L.',
            'tax_id'           => 'ES'.fake()->numerify('B########'),
            'country_code'     => 'ES',
            'legal_entity_type' => fake()->randomElement(['SL', 'SA', 'Autónomo']),
        ]);
    }

    /**
     * Config francesa.
     */
    public function french(): self
    {
        return $this->state(fn (array $attributes) => [
            'business_name'     => fake()->company().' SARL',
            'tax_id'            => 'FR'.fake()->numerify('##########'),
            'country_code'      => 'FR',
            'legal_entity_type' => 'SARL',
            'currency'          => 'EUR',
        ]);
    }

    /**
     * Config con notas de cambio.
     */
    public function withNotes(string $notes): self
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes,
        ]);
    }
}

