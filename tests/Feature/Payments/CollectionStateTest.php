<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Pins Invoice::recomputeCollectionState() against the table of spec §4.4
 * (AID-962). Row shape: seeded collection status, document status, due date
 * keyword, allocation rows as [status 0 ACTIVE|1 REVERSED, cents, applied_at
 * hour-of-today|null], payment.paid_at hour-of-today, expected status,
 * expected paid_at hour-of-today|null (null = paid_at stays null).
 *
 * The «completing» rows are inserted in NON-ascending applied_at order (12:00
 * first, then 10:00) so a paid_at taken from the last row instead of the MAX
 * fails (final review B3; spec §13.10 row 46).
 */
dataset('collection-state', [
    'paid zero, no due date'                  => [InvoiceStatus::SENT, InvoiceDocumentStatus::ISSUED, 'none', [[1, 12100, null]], 8, InvoiceStatus::SENT, null],
    'paid zero, overdue'                      => [InvoiceStatus::SENT, InvoiceDocumentStatus::ISSUED, 'yesterday', [[1, 12100, null]], 8, InvoiceStatus::OVERDUE, null],
    'paid zero, due today is not overdue'     => [InvoiceStatus::SENT, InvoiceDocumentStatus::ISSUED, 'today', [[1, 12100, null]], 8, InvoiceStatus::SENT, null],
    'partial'                                 => [InvoiceStatus::SENT, InvoiceDocumentStatus::ISSUED, 'yesterday', [[0, 5000, 10]], 8, InvoiceStatus::PARTIALLY_PAID, null],
    'completing: paid_at is the latest apply' => [InvoiceStatus::PARTIALLY_PAID, InvoiceDocumentStatus::ISSUED, 'yesterday', [[0, 7100, 12], [0, 5000, 10]], 8, InvoiceStatus::PAID, 12],
    'falls from PAID: paid_at is cleared'     => [InvoiceStatus::PAID, InvoiceDocumentStatus::ISSUED, 'none', [[0, 5000, 10], [1, 7100, 12]], 8, InvoiceStatus::PARTIALLY_PAID, null],
    'legacy row without applied_at'           => [InvoiceStatus::SENT, InvoiceDocumentStatus::ISSUED, 'none', [[0, 12100, null]], 9, InvoiceStatus::PAID, 9],
    'reversed row never feeds paid_at'        => [InvoiceStatus::SENT, InvoiceDocumentStatus::ISSUED, 'none', [[0, 12100, 10], [1, 4000, 14]], 8, InvoiceStatus::PAID, 10],
    'PENDING reversed back'                   => [InvoiceStatus::PENDING, InvoiceDocumentStatus::ISSUED, 'none', [[1, 12100, null]], 8, InvoiceStatus::SENT, null],
    'PREPARED document is a no-op'            => [InvoiceStatus::DRAFT, InvoiceDocumentStatus::PREPARED, 'none', [[0, 5000, 10]], 8, InvoiceStatus::DRAFT, null],
    'CANCELLED is a no-op'                    => [InvoiceStatus::CANCELLED, InvoiceDocumentStatus::ISSUED, 'none', [[0, 5000, 10]], 8, InvoiceStatus::CANCELLED, null],
    'CONVERTED is a no-op'                    => [InvoiceStatus::CONVERTED, InvoiceDocumentStatus::ISSUED, 'none', [[0, 5000, 10]], 8, InvoiceStatus::CONVERTED, null],
    'CONVERTING is a no-op'                   => [InvoiceStatus::CONVERTING, InvoiceDocumentStatus::ISSUED, 'none', [[0, 5000, 10]], 8, InvoiceStatus::CONVERTING, null],
    'PAID with zero rows stays PAID'          => [InvoiceStatus::PAID, InvoiceDocumentStatus::ISSUED, 'none', [], 8, InvoiceStatus::PAID, 9], // no rows → no-op: the seeded paid_at (09:00) survives untouched
]);

it('derives the collection state from the allocation rows', function (
    InvoiceStatus $seeded,
    InvoiceDocumentStatus $document,
    string $due,
    array $rows,
    int $paymentPaidHour,
    InvoiceStatus $expectedStatus,
    ?int $expectedPaidHour,
) {
    $today = Carbon::today();
    $at    = static fn (int $hour): Carbon => $today->copy()->setTime($hour, 0);

    $dueDate = match ($due) {
        'yesterday' => $today->copy()->subDay(),
        'today'     => $today->copy(),
        default     => null,
    };

    if ($document === InvoiceDocumentStatus::PREPARED) {
        // A conversion-born row: the coherence guard forbids hand-stamping it.
        $invoice = Invoice::factory()
            ->prepared(Invoice::factory()->proforma()->create([
                'user_id'          => TestCase::USER_UUID_1,
                'billable_user_id' => TestCase::USER_UUID_2,
            ]))
            ->create([
                'user_id'          => TestCase::USER_UUID_1,
                'billable_user_id' => TestCase::USER_UUID_2,
                'total_amount'     => FixedDecimal::ofUnscaled(12100, 2),
                'is_immutable'     => false,
            ]);
    } else {
        $invoice = PaymentFixtures::sentInvoice(12100, overrides: [
            'status'   => $seeded,
            'due_date' => $dueDate,
            // A seeded PAID invoice carries a settlement date, so the fall to
            // PARTIALLY_PAID must CLEAR it (impl gate round 3: a recompute that
            // keeps the stale paid_at was a false green before this row).
            'paid_at'  => $seeded === InvoiceStatus::PAID ? $at(9) : null,
        ]);
    }

    $payment = GroupedPayment::factory()->create([
        'billable_user_id' => $invoice->billable_user_id,
        'paid_at'          => $at($paymentPaidHour),
    ]);

    foreach ($rows as [$status, $cents, $appliedHour]) {
        DB::table('grouped_payment_invoice')->insert([
            'grouped_payment_id' => $payment->id,
            'invoice_id'         => $invoice->id,
            'applied_amount'     => $cents,
            'previous_status'    => InvoiceStatus::SENT->value,
            'previous_paid_at'   => null,
            'status'             => $status,
            'applied_at'         => $appliedHour === null ? null : $at($appliedHour),
            'active_invoice_id'  => $status      === 0 ? $invoice->id : null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    $invoice->recomputeCollectionState();

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe($expectedStatus);

    if ($expectedPaidHour === null) {
        expect($fresh->paid_at)->toBeNull();
    } else {
        expect($fresh->paid_at->equalTo($at($expectedPaidHour)))->toBeTrue();
    }
})->with('collection-state');
