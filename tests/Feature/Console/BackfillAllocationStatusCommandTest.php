<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/*
 * Final-review A2 — the deploy window of 8.3.0: between `migrate` and the
 * code switch, an 8.2 reversal nulls `active_invoice_id` but leaves the pivot
 * row `status = 0` (ACTIVE). `larabill:backfill-allocation-status` re-applies
 * the migration's two idempotent UPDATEs (AllocationStatusBackfill).
 */

/** One legacy-shaped pivot row: ACTIVE status with the 8.x NULL reversal marker. */
function aid962LegacyPivotRow(GroupedPayment $payment, string $invoiceId, ?string $updatedAt, ?string $createdAt): int
{
    return (int) DB::table('grouped_payment_invoice')->insertGetId([
        'grouped_payment_id' => $payment->id,
        'invoice_id'         => $invoiceId,
        'applied_amount'     => 12100,
        'status'             => AllocationStatus::ACTIVE->value,
        'active_invoice_id'  => null,
        'previous_status'    => InvoiceStatus::SENT->value,
        'created_at'         => $createdAt,
        'updated_at'         => $updatedAt,
    ]);
}

it('backfills both legacy shapes, prints the counts, and is idempotent', function () {
    $reversed = GroupedPayment::factory()->create([
        'billable_user_id' => TestCase::USER_UUID_2,
        'status'           => GroupedPaymentStatus::REVERSED->value,
        'reversed_at'      => '2026-10-20 09:00:00',
        'reversed_by'      => TestCase::USER_UUID_1,
        'reverse_reason'   => 'chargeback',
    ]);
    $posted = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);

    $a = PaymentFixtures::sentInvoice(12100);
    $b = PaymentFixtures::sentInvoice(12100);
    $c = PaymentFixtures::sentInvoice(12100);

    $underReversed     = aid962LegacyPivotRow($reversed, (string) $a->id, '2026-10-19 08:00:00', '2026-10-18 08:00:00');
    $residualUpdatedAt = aid962LegacyPivotRow($posted, (string) $b->id, '2026-10-19 08:00:00', '2026-10-18 08:00:00');
    $residualNoTimes   = aid962LegacyPivotRow($posted, (string) $c->id, null, '2026-10-18 08:00:00');

    $this->artisan('larabill:backfill-allocation-status')
        ->expectsOutputToContain('1 row(s) under a REVERSED payment, 2 residual row(s) under a POSTED payment')
        ->assertExitCode(0);

    $row = fn (int $id): object => DB::table('grouped_payment_invoice')->where('id', $id)->first();

    expect($row($underReversed))
        ->status->toBe(AllocationStatus::REVERSED->value)
        ->reversed_by->toBe(TestCase::USER_UUID_1)
        ->reverse_reason->toBe('chargeback')
        ->and((string) $row($underReversed)->reversed_at)->toBe('2026-10-20 09:00:00');

    expect($row($residualUpdatedAt))
        ->status->toBe(AllocationStatus::REVERSED->value)
        ->reverse_reason->toBe('backfill-8.3')
        ->and((string) $row($residualUpdatedAt)->reversed_at)->toBe('2026-10-19 08:00:00');

    // NULL-safe: no updated_at falls back to created_at (never a NULL reversed_at).
    expect((string) $row($residualNoTimes)->reversed_at)->toBe('2026-10-18 08:00:00')
        ->and($row($residualNoTimes)->reverse_reason)->toBe('backfill-8.3');

    // The CHANGELOG verification query.
    expect(DB::table('grouped_payment_invoice')->where('status', 0)->whereNull('active_invoice_id')->count())->toBe(0);

    // A re-run touches nothing.
    $this->artisan('larabill:backfill-allocation-status')
        ->expectsOutputToContain('0 row(s) under a REVERSED payment, 0 residual row(s) under a POSTED payment')
        ->assertExitCode(0);
});

it('leaves ACTIVE rows with their active_invoice_id alone', function () {
    $posted = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    $inv    = PaymentFixtures::sentInvoice(12100);

    $id = (int) DB::table('grouped_payment_invoice')->insertGetId([
        'grouped_payment_id' => $posted->id,
        'invoice_id'         => $inv->id,
        'applied_amount'     => 12100,
        'status'             => AllocationStatus::ACTIVE->value,
        'active_invoice_id'  => $inv->id,
        'previous_status'    => InvoiceStatus::SENT->value,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $this->artisan('larabill:backfill-allocation-status')->assertExitCode(0);

    expect(DB::table('grouped_payment_invoice')->where('id', $id)->value('status'))->toBe(AllocationStatus::ACTIVE->value);
});
