<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\RelationshipType;
use AichaDigital\Larabill\Models\{Customer, UserTaxProfile};
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\AichaDigital\Larabill\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\AichaDigital\Larabill\Models\Customer>
     */
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'                 => null,
            'relationship_type'       => RelationshipType::CLIENT,
            'display_name'            => $this->faker->name(),
            'internal_code'           => $this->faker->optional()->numerify('CUST-####'),
            'legal_entity_type_code'  => 'INDIVIDUAL',
            'is_active'               => true,
            'inactive_since'          => null,
            'inactive_reason'         => null,
            'notes'                   => $this->faker->optional()->sentence(),
            'metadata'                => null,
        ];
    }

    /**
     * Indicate that the customer is linked to a user (self).
     */
    public function self(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => RelationshipType::SELF,
        ]);
    }

    /**
     * Indicate that the customer is the user's company.
     */
    public function selfCompany(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type'      => RelationshipType::SELF_COMPANY,
            'legal_entity_type_code' => 'LIMITED_COMPANY',
            'display_name'           => $this->faker->company(),
        ]);
    }

    /**
     * Indicate that the customer is a client.
     */
    public function client(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship_type' => RelationshipType::CLIENT,
        ]);
    }

    /**
     * Indicate that the customer is a company.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'legal_entity_type_code' => 'LIMITED_COMPANY',
            'display_name'           => $this->faker->company(),
        ]);
    }

    /**
     * Indicate that the customer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active'       => false,
            'inactive_since'  => now(),
            'inactive_reason' => 'Test deactivation',
        ]);
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Customer $customer) {
            // Auto-create UserTaxProfile for the customer's user (ADR-003)
            if ($customer->user_id) {
                UserTaxProfile::factory()->create([
                    'user_id'     => $customer->user_id,
                    'valid_from'  => now()->subYear(),
                    'valid_until' => null,
                    'is_active'   => true,
                ]);
            }
        });
    }
}
