<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

mutates(PaymentService::class);

/*
 * PaymentService::allocate() (AID-962, spec §5.3): a later allocation of a
 * payment's remainder, funded FIFO from THIS payment's credit entries.
 */

/** A 15000 transfer paying 12100 of invoice A: one SURPLUS entry of 2900. */
function aid962AllocatePayment(): array
{
    $a = PaymentFixtures::sentInvoice(12100);
    $p = app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents(15000), 'EUR', now()->subDay()->startOfSecond(),
        PaymentMethod::BankTransfer, 'TRF-1', TestCase::USER_UUID_1, [new Allocation($a->id, cents(12100))],
    );

    return [$p, $a];
}

function aid962Refund(GroupedPayment $p, CreditMovement $entry, int $cents): CreditMovement
{
    return CreditMovement::query()->create([
        'billable_user_id'     => $p->billable_user_id, 'currency' => $p->currency, 'kind' => CreditMovementKind::REFUNDED,
        'amount'               => FixedDecimal::ofUnscaled(-$cents, 2), 'grouped_payment_id' => $p->id,
        'consumes_movement_id' => $entry->id, 'method' => PaymentMethod::BankTransfer, 'occurred_at' => now()->startOfSecond(),
    ]);
}

it('allocates from a payment remainder, consuming its entries FIFO', function () {
    [$p]     = aid962AllocatePayment();
    $surplus = $p->creditMovements()->sole();
    $b       = PaymentFixtures::sentInvoice(5000);

    $row = app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(2900)), TestCase::USER_UUID_3);

    expect($b->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($row->status)->toBe(AllocationStatus::ACTIVE)
        ->and($row->grouped_payment_id)->toBe($p->id)
        ->and($row->invoice_id)->toBe($b->id)
        ->and($row->active_invoice_id)->toBe($b->id)
        ->and($row->applied_amount->unscaledValue())->toBe(2900)
        ->and($row->previous_status)->toBe(InvoiceStatus::SENT->value)
        ->and($row->applied_at)->not->toBeNull();

    $exit = CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->sole();
    expect($exit->amount->unscaledValue())->toBe(-2900)
        ->and($exit->consumes_movement_id)->toBe($surplus->id)
        ->and($exit->allocation_id)->toBe($row->id)
        ->and($exit->grouped_payment_id)->toBe($p->id)
        ->and($exit->billable_user_id)->toBe(TestCase::USER_UUID_2)
        ->and($exit->currency)->toBe('EUR')
        ->and($exit->recorded_by)->toBe(TestCase::USER_UUID_3)
        ->and($surplus->remaining()->unscaledValue())->toBe(0)
        ->and($p->availableCredit()->unscaledValue())->toBe(0)
        ->and($p->unallocatedAmount()->unscaledValue())->toBe(0);

    PaymentAllocation::all()->each(fn (PaymentAllocation $a) => aid962AssertFundingInvariant($a));
});

it('consumes the oldest entry first and spans entries, one APPLIED exit per entry', function () {
    [$p, $a] = aid962AllocatePayment();
    $svc     = app(PaymentService::class);
    $first   = $p->creditMovements()->sole();                 // SURPLUS 2900 (receivedAt)
    $svc->reverseAllocation($p->allocations()->sole()->id, 'move'); // SURPLUS 12100 (paid_at, later id)
    $second  = $p->creditMovements()->where('id', '!=', $first->id)->sole();
    $b       = PaymentFixtures::sentInvoice(5000);

    $row = $svc->allocate($p, new Allocation($b->id, cents(5000)));

    $exits = CreditMovement::query()->where('allocation_id', $row->id)->where('kind', CreditMovementKind::APPLIED->value)->orderBy('id')->get();
    expect($exits)->toHaveCount(2)
        ->and($exits[0]->consumes_movement_id)->toBe($first->id)
        ->and($exits[0]->amount->unscaledValue())->toBe(-2900)
        ->and($exits[1]->consumes_movement_id)->toBe($second->id)
        ->and($exits[1]->amount->unscaledValue())->toBe(-2100)
        ->and($first->remaining()->unscaledValue())->toBe(0)
        ->and($second->remaining()->unscaledValue())->toBe(10000)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::SENT);

    PaymentAllocation::all()->each(fn (PaymentAllocation $r) => aid962AssertFundingInvariant($r));
});

it('rejects more than the available credit of the payment', function () {
    [$p]   = aid962AllocatePayment();
    aid962Refund($p, $p->creditMovements()->sole(), 1000);   // remainder arithmetic 2900, available 1900
    $b     = PaymentFixtures::sentInvoice(5000);

    expect($p->unallocatedAmount()->unscaledValue())->toBe(2900);
    expect(fn () => app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(2900))))
        ->toThrow(AllocationExceedsPaymentException::class, 'requested 2900, available 1900');

    expect($b->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and(PaymentAllocation::query()->where('invoice_id', $b->id)->count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0);
});

it('rejects more than the invoice outstanding', function () {
    [$p] = aid962AllocatePayment();
    $b   = PaymentFixtures::sentInvoice(2000);

    expect(fn () => app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(2900))))
        ->toThrow(AllocationExceedsInvoiceException::class);

    expect(PaymentAllocation::query()->where('invoice_id', $b->id)->count())->toBe(0)
        ->and($p->availableCredit()->unscaledValue())->toBe(2900);
});

it('uses appliedAt for applied_at and occurred_at', function () {
    [$p] = aid962AllocatePayment();
    $b   = PaymentFixtures::sentInvoice(2900);
    $at  = now()->subDays(3)->startOfSecond();

    $row  = app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(2900)), null, $at);
    $exit = CreditMovement::query()->where('allocation_id', $row->id)->sole();

    expect($row->fresh()->applied_at->equalTo($at))->toBeTrue()
        ->and($exit->occurred_at->equalTo($at))->toBeTrue()
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($b->fresh()->paid_at->equalTo($at))->toBeTrue();

    aid962AssertFundingInvariant($row);
});

it('rejects an invoice of another customer', function () {
    [$p] = aid962AllocatePayment();
    $b   = PaymentFixtures::sentInvoice(2900, TestCase::USER_UUID_3);

    app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(2900)));
})->throws(GroupedPaymentValidationException::class);

it('rejects allocating from a reversed payment', function () {
    $p = app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents(3000), 'EUR', now()->subDay()->startOfSecond(),
        PaymentMethod::BankTransfer, 'TOP', null, [], CreditMovementKind::TOP_UP,
    );
    app(PaymentService::class)->reverse($p, 'void');
    $b = PaymentFixtures::sentInvoice(3000);

    expect(fn () => app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(1000))))
        ->toThrow(AllocationExceedsPaymentException::class, 'available 0');
});
