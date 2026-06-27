<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GroupedPayment> */
class GroupedPaymentFactory extends Factory
{
    protected $model = GroupedPayment::class;

    public function definition(): array
    {
        return [
            // billable_user_id is NOT NULL — supply a UUID by default (Codex #9).
            'billable_user_id' => (string) Str::orderedUuid(),
            'amount'           => FixedDecimal::ofUnscaled($this->faker->numberBetween(1000, 100000), 2),
            'currency'         => 'EUR',
            'paid_at'          => $this->faker->dateTimeBetween('-30 days', 'now'),
            'reference'        => $this->faker->optional()->bothify('TRF-#####'),
            'idempotency_key'  => (string) Str::orderedUuid(),
            'status'           => GroupedPaymentStatus::POSTED->value,
            'reversed_at'      => null,
            'reversed_by'      => null,
            'reverse_reason'   => null,
            'notes'            => null,
        ];
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'         => GroupedPaymentStatus::REVERSED->value,
            'reversed_at'    => now(),
            'reverse_reason' => 'test reversal',
        ]);
    }
}
