<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Support;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * Deterministic SQLite fixtures shared by the payment-allocation and
 * customer-credit tests (AID-962). Every field a guard reads is pinned —
 * nothing here inherits a dice roll from a factory.
 */
final class PaymentFixtures
{
    /**
     * An ISSUED, SENT, mutable fiscal invoice of $totalCents, billed to
     * $billable and issued by USER_UUID_1, with no payment and no due date.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function sentInvoice(int $totalCents, string $billable = TestCase::USER_UUID_2, array $overrides = []): Invoice
    {
        return Invoice::factory()->sent()->create(array_merge([
            'user_id'          => TestCase::USER_UUID_1,
            'billable_user_id' => $billable,
            'total_amount'     => FixedDecimal::ofUnscaled($totalCents, 2),
            'is_immutable'     => false,
            'paid_at'          => null,
            'due_date'         => null,
        ], $overrides));
    }

    /**
     * One of the three deterministic test users seeded by TestCase
     * (default: USER_UUID_2, the billed customer of sentInvoice()).
     */
    public static function user(string $id = TestCase::USER_UUID_2): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('larabill.user_model');

        return $userModel::query()->findOrFail($id);
    }
}
