<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\CustomerFiscalData;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerFiscalData>
 */
class CustomerFiscalDataFactory extends Factory
{
    protected $model = CustomerFiscalData::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isCompany = fake()->boolean(30); // 30% empresas, 70% particulares

        return [
            'user_id'               => (string) Str::orderedUuid(), // UUID v7
            'fiscal_name'           => $isCompany ? fake()->company() : fake()->name(),
            'tax_id'                => $isCompany ? 'ES'.fake()->numerify('B########') : fake()->numerify('########X'),
            'legal_entity_type'     => $isCompany ? fake()->randomElement(['SL', 'SA', 'Autónomo']) : 'Particular',
            'address'               => fake()->streetAddress(),
            'city'                  => fake()->city(),
            'state'                 => fake()->optional()->state(),
            'zip_code'              => fake()->postcode(),
            'country_code'          => 'ES',
            'is_company'            => $isCompany,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now()->startOfYear(),
            'valid_until'           => null, // Config activa por defecto
            'is_active'             => true,
            'notes'                 => null,
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
     * Cliente empresa (B2B).
     */
    public function company(): self
    {
        return $this->state(fn (array $attributes) => [
            'fiscal_name'       => fake()->company().' S.L.',
            'tax_id'            => 'ES'.fake()->numerify('B########'),
            'legal_entity_type' => fake()->randomElement(['SL', 'SA', 'Autónomo', 'Cooperativa']),
            'is_company'        => true,
        ]);
    }

    /**
     * Cliente particular (B2C).
     */
    public function individual(): self
    {
        return $this->state(fn (array $attributes) => [
            'fiscal_name'       => fake()->name(),
            'tax_id'            => fake()->numerify('########').fake()->randomLetter(),
            'legal_entity_type' => 'Particular',
            'is_company'        => false,
        ]);
    }

    /**
     * Cliente con registro IVA intracomunitario.
     */
    public function euVatRegistered(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_eu_vat_registered' => true,
            'is_company'           => true, // Solo empresas pueden tener VAT ID
            'tax_id'               => fake()->randomElement(['FR', 'DE', 'IT', 'NL']).fake()->numerify('##########'),
        ]);
    }

    /**
     * Cliente exento de IVA.
     */
    public function vatExempt(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_exempt_vat' => true,
        ]);
    }

    /**
     * Cliente español.
     */
    public function spanish(): self
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'ES',
            'tax_id'       => $attributes['is_company']
                ? 'ES'.fake()->numerify('B########')
                : fake()->numerify('########').fake()->randomLetter(),
        ]);
    }

    /**
     * Cliente francés.
     */
    public function french(): self
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'FR',
            'tax_id'       => 'FR'.fake()->numerify('##########'),
        ]);
    }

    /**
     * Cliente alemán.
     */
    public function german(): self
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'DE',
            'tax_id'       => 'DE'.fake()->numerify('#########'),
        ]);
    }

    /**
     * Config sin dirección fiscal.
     */
    public function withoutAddress(): self
    {
        return $this->state(fn (array $attributes) => [
            'address'  => null,
            'city'     => null,
            'state'    => null,
            'zip_code' => null,
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

    /**
     * Config para un usuario específico.
     */
    public function forUser(string|int $userId): self
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => (string) $userId,
        ]);
    }
}
