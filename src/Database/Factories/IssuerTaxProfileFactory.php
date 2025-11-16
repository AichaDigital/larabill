<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\IssuerTaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\IssuerTaxProfile>
 */
class IssuerTaxProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = IssuerTaxProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name'             => $this->faker->company(),
            'commercial_name'        => $this->faker->optional()->companySuffix(),
            'tax_id'                 => 'B'.str_pad((string) $this->faker->numberBetween(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'legal_entity_type_code' => 'SOCIEDAD_LIMITADA',
            'address'                => $this->faker->streetAddress(),
            'address_line_2'         => $this->faker->optional()->secondaryAddress(),
            'city'                   => $this->faker->city(),
            'state'                  => $this->faker->optional()->state(),
            'postal_code'            => $this->faker->postcode(),
            'country_code'           => 'ES',
            'phone'                  => $this->faker->optional()->phoneNumber(),
            'email'                  => $this->faker->optional()->companyEmail(),
            'website'                => $this->faker->optional()->url(),
            'vat_number'             => 'ESB'.str_pad((string) $this->faker->numberBetween(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'is_roi_registered'      => false,
            'is_oss_registered'      => false,
            'valid_from'             => now()->subYear(),
            'valid_until'            => null,
            'is_current'             => true,
            'change_reason'          => null,
            'metadata'               => null,
        ];
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

    /**
     * Indicate that the issuer is ROI registered.
     */
    public function roiRegistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_roi_registered' => true,
        ]);
    }

    /**
     * Indicate that the issuer is OSS registered.
     */
    public function ossRegistered(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_oss_registered' => true,
        ]);
    }
}
