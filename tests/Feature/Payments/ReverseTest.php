<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

mutates(PaymentService::class);

/*
 * PaymentService::reverse() (AID-962, spec §5.5) — flow 7 of the wish list:
 * void a whole payment, its allocations and its credit entries.
 */

function aid962ReversePay(int $amount, array $allocations, string $reference = 'TRF-R'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents($amount), 'EUR', now()->subDay()->startOfSecond(),
        PaymentMethod::BankTransfer, $reference, TestCase::USER_UUID_1, $allocations,
    );
}

it('reverses allocations and entries and recomputes every touched invoice', function () {
    $x  = PaymentFixtures::sentInvoice(10000);
    $y  = PaymentFixtures::sentInvoice(2000);
    $p1 = aid962ReversePay(10000, [new Allocation($x->id, cents(7000)), new Allocation($y->id, cents(2000))], 'P1'); // SURPLUS 1000
    aid962ReversePay(3000, [new Allocation($x->id, cents(3000))], 'P2');
    // Both invoices start PAID, so each transition below can only come from a recompute.
    expect($x->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($y->fresh()->status)->toBe(InvoiceStatus::PAID);

    $reversed = app(PaymentService::class)->reverse($p1, 'bank returned it', TestCase::USER_UUID_3);

    expect($reversed->status)->toBe(GroupedPaymentStatus::REVERSED)
        ->and($reversed->reverse_reason)->toBe('bank returned it')
        ->and($reversed->reversed_by)->toBe(TestCase::USER_UUID_3)
        ->and($reversed->reversed_at)->not->toBeNull();

    $rows = PaymentAllocation::query()->where('grouped_payment_id', $p1->id)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->status)->toBe(AllocationStatus::REVERSED)
            ->and($row->active_invoice_id)->toBeNull()
            ->and($row->reversed_at)->not->toBeNull()
            ->and($row->reversed_by)->toBe(TestCase::USER_UUID_3)
            ->and($row->reverse_reason)->toBe('bank returned it');
    }

    $entry = $p1->creditMovements()->sole();
    expect($entry->reversed_at)->not->toBeNull()
        ->and($entry->reverse_reason)->toBe('bank returned it')
        ->and($p1->fresh()->availableCredit()->unscaledValue())->toBe(0)
        ->and($x->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)   // PAID → PARTIALLY_PAID: P2 still live, never SENT
        ->and($x->fresh()->paid_at)->toBeNull()                            // the settlement date does not survive the fall from PAID (impl gate round 3, P1 false green)
        ->and($x->fresh()->amountPaid()->unscaledValue())->toBe(3000)
        ->and($y->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($y->fresh()->paid_at)->toBeNull();

    PaymentAllocation::all()->each(fn (PaymentAllocation $r) => aid962AssertFundingInvariant($r));
});

it('refuses when an entry has an active APPLIED exit', function () {
    $a = PaymentFixtures::sentInvoice(12100);
    $b = PaymentFixtures::sentInvoice(5000);
    $p = aid962ReversePay(15000, [new Allocation($a->id, cents(12100))]);
    app(PaymentService::class)->allocate($p, new Allocation($b->id, cents(2900)));

    expect(fn () => app(PaymentService::class)->reverse($p, 'void'))
        ->toThrow(InsufficientCreditException::class, $p->creditMovements()->entries()->sole()->id);

    expect($p->fresh()->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and(PaymentAllocation::query()->active()->count())->toBe(2);

    PaymentAllocation::all()->each(fn (PaymentAllocation $r) => aid962AssertFundingInvariant($r));
});

it('refuses when an entry has an active REFUNDED exit', function () {
    $p     = aid962ReversePay(3000, []);
    $entry = $p->creditMovements()->sole();
    CreditMovement::query()->create([
        'billable_user_id'     => $p->billable_user_id, 'currency' => 'EUR', 'kind' => CreditMovementKind::REFUNDED,
        'amount'               => FixedDecimal::ofUnscaled(-500, 2), 'grouped_payment_id' => $p->id,
        'consumes_movement_id' => $entry->id, 'method' => PaymentMethod::BankTransfer, 'occurred_at' => now()->startOfSecond(),
    ]);

    expect(fn () => app(PaymentService::class)->reverse($p, 'void'))->toThrow(InsufficientCreditException::class);
    expect($p->fresh()->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($entry->fresh()->reversed_at)->toBeNull();
});

it('is a stable no-op on a second reverse', function () {
    $a     = PaymentFixtures::sentInvoice(5000);
    $p     = aid962ReversePay(5000, [new Allocation($a->id, cents(5000))]);
    $svc   = app(PaymentService::class);
    $first = $svc->reverse($p, 'first reason', TestCase::USER_UUID_3);
    $again = $svc->reverse($first->fresh(), 'second reason', TestCase::USER_UUID_1);

    expect($again->reverse_reason)->toBe('first reason')
        ->and($again->reversed_by)->toBe(TestCase::USER_UUID_3)
        ->and(PaymentAllocation::query()->sole()->reverse_reason)->toBe('first reason')
        ->and($a->fresh()->status)->toBe(InvoiceStatus::SENT);
});
