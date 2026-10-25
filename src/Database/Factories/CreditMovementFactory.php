<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Deterministic defaults (AID-558 discipline): a live SURPLUS entry of
 * 29.00 EUR. A test that needs an exit, a reversal or a link declares it.
 *
 * @extends Factory<CreditMovement>
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class CreditMovementFactory extends Factory
{
    protected $model = CreditMovement::class;

    public function definition(): array
    {
        return [
            'billable_user_id'     => (string) Str::orderedUuid(),
            'currency'             => 'EUR',
            'kind'                 => CreditMovementKind::SURPLUS->value,
            'amount'               => FixedDecimal::ofUnscaled(2900, 2),
            'grouped_payment_id'   => GroupedPayment::factory(),
            'allocation_id'        => null,
            'consumes_movement_id' => null,
            'advance_invoice_id'   => null,
            'method'               => null,
            'recorded_by'          => null,
            'reference'            => null,
            'notes'                => null,
            'occurred_at'          => '2026-10-01 10:00:00',
            'reversed_at'          => null,
            'reversed_by'          => null,
            'reverse_reason'       => null,
        ];
    }
}
