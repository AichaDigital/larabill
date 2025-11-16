<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\{Customer, CustomerTaxProfile};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\CustomerTaxProfile>
 */
class CustomerTaxProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\AichaDigital\Larabill\Models\CustomerTaxProfile>
     */
    protected $model = CustomerTaxProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isCompany = $this->faker->boolean(30);

        return [
            'customer_id'            => Customer::factory(),
            'legal_name'             => $isCompany ? $this->faker->company() : $this->faker->name(),
            'commercial_name'        => $isCompany ? $this->faker->optional()->companySuffix() : null,
            'tax_id'                 => $this->generateSpanishTaxId($isCompany),
            'legal_entity_type_code' => $isCompany ? 'SOCIEDAD_LIMITADA' : 'PERSONA_FISICA',
            'address'                => $this->faker->streetAddress(),
            'address_line_2'         => $this->faker->optional()->streetAddress(),
            'city'                   => $this->faker->city(),
            'state'                  => $this->faker->optional()->state(),
            'postal_code'            => $this->faker->postcode(),
            'country_code'           => 'ES',
            'phone'                  => $this->faker->optional()->phoneNumber(),
            'email'                  => $this->faker->optional()->email(),
            'vat_number'             => null,
            'vat_number_verified'    => false,
            'vat_verified_at'        => null,
            'vat_verification_data'  => null,
            'valid_from'             => now()->subYear(),
            'valid_until'            => null,
            'is_current'             => true,
            'change_reason'          => null,
            'metadata'               => null,
        ];
    }

    /**
     * Generate Spanish tax ID (NIF/CIF).
     */
    protected function generateSpanishTaxId(bool $isCompany): string
    {
        if ($isCompany) {
            // CIF: Letter + 8 digits
            $letter = $this->faker->randomElement(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J']);

            return $letter.str_pad((string) $this->faker->numberBetween(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        }

        // NIF: 8 digits + letter
        $number  = str_pad((string) $this->faker->numberBetween(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
        $letter  = $letters[(int) $number % 23];

        return $number.$letter;
    }

    /**
     * Indicate that this is a person.
     */
    public function person(): static
    {
        return $this->state(fn (array $attributes) => [
            'legal_name'             => $this->faker->name(),
            'commercial_name'        => null,
            'tax_id'                 => $this->generateSpanishTaxId(false),
            'legal_entity_type_code' => 'PERSONA_FISICA',
        ]);
    }

    /**
     * Indicate that this is a company.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'legal_name'             => $this->faker->company(),
            'commercial_name'        => $this->faker->optional()->companySuffix(),
            'tax_id'                 => $this->generateSpanishTaxId(true),
            'legal_entity_type_code' => 'SOCIEDAD_LIMITADA',
        ]);
    }

    /**
     * Indicate that this profile has EU VAT.
     */
    public function withEuVat(): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_number'          => 'ES'.$this->generateSpanishTaxId(true),
            'vat_number_verified' => true,
            'vat_verified_at'     => now(),
        ]);
    }

    /**
     * Indicate that this profile is not current (historical).
     */
    public function historical(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current'  => false,
            'valid_until' => now()->subDay(),
        ]);
    }
}
