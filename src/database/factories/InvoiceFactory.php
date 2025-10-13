<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invoice Factory
 *
 * Uses integer base 100 for monetary values (amounts, prices)
 * Example: €12.34 is stored as 1234, €1.00 as 100
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal  = $this->faker->numberBetween(1000, 100000); // €10.00 to €1000.00 in base 100
        $taxAmount = (int) ($subtotal * 0.21); // 21% tax in base 100
        $total     = $subtotal + $taxAmount;

        return [
            'number'                  => 'INV-'.$this->faker->unique()->numerify('######'),
            'type'                    => $this->faker->randomElement(['standard', 'credit', 'debit']),
            'status'                  => $this->faker->randomElement(['draft', 'sent', 'paid', 'overdue']),
            'user_id'                 => $this->faker->numberBetween(1, 100),
            'user_tax_info_encrypted' => $this->faker->optional()->sentence(),
            'is_immutable'            => $this->faker->boolean(20), // 20% chance
            'immutable_at'            => $this->faker->optional(0.2)->dateTime(),
            'subtotal'                => $subtotal,
            'tax_amount'              => $taxAmount,
            'total'                   => $total,
            'fiscal_data'             => [
                'company_name' => $this->faker->company(),
                'vat_code'     => $this->faker->optional()->numerify('ES##########'),
                'address'      => $this->faker->address(),
            ],
            'vat_verification' => [
                'verified'   => $this->faker->boolean(80),
                'api_source' => $this->faker->randomElement(['abstractapi', 'apilayer']),
                'last_check' => $this->faker->dateTime(),
            ],
            'due_date' => $this->faker->optional(0.8)->dateTimeBetween('now', '+30 days'),
            'paid_at'  => $this->faker->optional(0.3)->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Create a paid invoice
     */
    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status'  => 'paid',
                'paid_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Create an overdue invoice
     */
    public function overdue(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status'   => 'overdue',
                'due_date' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
                'paid_at'  => null,
            ];
        });
    }

    /**
     * Create an immutable invoice
     */
    public function immutable(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_immutable' => true,
                'immutable_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            ];
        });
    }

    /**
     * Create a draft invoice
     */
    public function draft(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status'   => 'draft',
                'paid_at'  => null,
                'due_date' => null,
            ];
        });
    }

    /**
     * Create a credit invoice (negative amounts)
     */
    public function credit(): static
    {
        return $this->state(function (array $attributes) {
            $subtotal  = -$this->faker->numberBetween(1000, 10000); // Negative amount
            $taxAmount = (int) ($subtotal * 0.21);
            $total     = $subtotal + $taxAmount;

            return [
                'type'       => 'credit',
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $total,
            ];
        });
    }
}
