<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\UserTaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserTaxProfile>
 */
class UserTaxProfileFactory extends Factory
{
    protected $model = UserTaxProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isCompany = fake()->boolean(30); // 30% companies, 70% individuals

        return [
            'user_id'                => (string) Str::orderedUuid(), // UUID v7
            'fiscal_name'            => $isCompany ? fake()->company() : fake()->name(),
            'tax_id'                 => $isCompany ? 'ES'.fake()->numerify('B########') : fake()->numerify('########X'),
            'legal_entity_type_code' => null,
            'address'                => fake()->streetAddress(),
            'city'                   => fake()->city(),
            'state'                  => fake()->optional()->state(),
            'zip_code'               => fake()->postcode(),
            'country_code'           => 'ES',
            'is_company'             => $isCompany,
            'is_eu_vat_registered'   => false,
            'is_exempt_vat'          => false,
            'valid_from'             => now()->startOfYear(),
            'valid_until'            => null, // Active config by default
            'is_active'              => true,
            'notes'                  => null,
        ];
    }

    /**
     * Active current config (no valid_until).
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
     * Historical config (with valid_until in the past).
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
     * Company client (B2B).
     */
    public function company(): self
    {
        return $this->state(fn (array $attributes) => [
            'fiscal_name' => fake()->company().' S.L.',
            'tax_id'      => 'ES'.fake()->numerify('B########'),
            'is_company'  => true,
        ]);
    }

    /**
     * Individual client (B2C).
     */
    public function individual(): self
    {
        return $this->state(fn (array $attributes) => [
            'fiscal_name' => fake()->name(),
            'tax_id'      => fake()->numerify('########').fake()->randomLetter(),
            'is_company'  => false,
        ]);
    }

    /**
     * Client with EU VAT registration (intra-community).
     */
    public function euVatRegistered(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_eu_vat_registered' => true,
            'is_company'           => true, // Only companies can have VAT ID
            'tax_id'               => fake()->randomElement(['FR', 'DE', 'IT', 'NL']).fake()->numerify('##########'),
        ]);
    }

    /**
     * VAT exempt client.
     */
    public function vatExempt(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_exempt_vat' => true,
        ]);
    }

    /**
     * Spanish client.
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
     * French client.
     */
    public function french(): self
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'FR',
            'tax_id'       => 'FR'.fake()->numerify('##########'),
        ]);
    }

    /**
     * German client.
     */
    public function german(): self
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'DE',
            'tax_id'       => 'DE'.fake()->numerify('#########'),
        ]);
    }

    /**
     * Config without fiscal address.
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
     * Config with change notes.
     */
    public function withNotes(string $notes): self
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes,
        ]);
    }

    /**
     * Config for a specific user.
     */
    public function forUser(string|int $userId): self
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => (string) $userId,
        ]);
    }

    /**
     * Config with legal entity type.
     */
    public function withLegalEntityType(string $code): self
    {
        return $this->state(fn (array $attributes) => [
            'legal_entity_type_code' => $code,
        ]);
    }
}
