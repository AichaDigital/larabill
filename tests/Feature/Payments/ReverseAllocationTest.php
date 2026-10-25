<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

mutates(PaymentService::class);

/*
 * PaymentService::reverseAllocation() (AID-962, spec §5.6): one allocation
 * goes back where its money came from — to the entries it consumed when it
 * was credit-funded, to ONE new SURPLUS entry when it was direct.
 */

function aid962RaPay(int $amount, array $allocations, string $reference = 'TRF-RA'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents($amount), 'EUR', now()->subDays(2)->startOfSecond(),
        PaymentMethod::BankTransfer, $reference, TestCase::USER_UUID_1, $allocations,
    );
}

function aid962RaInvariant(): void
{
    PaymentAllocation::all()->each(fn (PaymentAllocation $r) => aid962AssertFundingInvariant($r));
}

it('reverts a credit-funded allocation back to its entries without creating a SURPLUS', function () {
    $a       = PaymentFixtures::sentInvoice(12100);
    $b       = PaymentFixtures::sentInvoice(5000);
    $p       = aid962RaPay(15000, [new Allocation($a->id, cents(12100))]);
    $svc     = app(PaymentService::class);
    $row     = $svc->allocate($p, new Allocation($b->id, cents(2900)));
    $surplus = $p->creditMovements()->entries()->sole();
    $count   = CreditMovement::count();
    aid962RaInvariant();

    $out = $svc->reverseAllocation($row->id, 'wrong invoice', TestCase::USER_UUID_3);

    $applied = (int) CreditMovement::query()->where('allocation_id', $row->id)
        ->where('kind', CreditMovementKind::APPLIED->value)->whereNull('reversed_at')->sum('amount');
    expect($out->status)->toBe(AllocationStatus::REVERSED)
        ->and($applied)->toBe(0)
        ->and($surplus->remaining()->unscaledValue())->toBe(2900)
        ->and(CreditMovement::count())->toBe($count)
        ->and(CreditMovement::query()->where('allocation_id', $row->id)->where('kind', CreditMovementKind::SURPLUS->value)->count())->toBe(0)
        ->and($p->availableCredit()->unscaledValue())->toBe(2900)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::SENT);
    aid962RaInvariant();

    // The payment's credit is unspent again, so the whole payment can be voided (spec §5.6).
    expect(app(PaymentService::class)->reverse($p, 'void')->status)->toBe(GroupedPaymentStatus::REVERSED);
    aid962RaInvariant();
});

it('reverts a direct allocation into one SURPLUS of the exact applied amount', function () {
    $a   = PaymentFixtures::sentInvoice(12100);
    $p   = aid962RaPay(15000, [new Allocation($a->id, cents(12100))]);
    $row = $p->allocations()->sole();

    app(PaymentService::class)->reverseAllocation($row->id, 'wrong invoice', TestCase::USER_UUID_3);

    $new = CreditMovement::query()->where('allocation_id', $row->id)->sole();
    expect($new->kind)->toBe(CreditMovementKind::SURPLUS)
        ->and($new->amount->unscaledValue())->toBe(12100)
        ->and($new->grouped_payment_id)->toBe($p->id)
        ->and($new->billable_user_id)->toBe(TestCase::USER_UUID_2)
        ->and($new->currency)->toBe('EUR')
        ->and($new->consumes_movement_id)->toBeNull()
        ->and($new->occurred_at->equalTo($p->paid_at))->toBeTrue()
        ->and($p->availableCredit()->unscaledValue())->toBe(15000)
        ->and($p->unallocatedAmount()->unscaledValue())->toBe(15000)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($a->fresh()->paid_at)->toBeNull();
    aid962RaInvariant();
});

it('keeps the invariant Σ active APPLIED ∈ {0, applied_amount} after every operation', function () {
    $a   = PaymentFixtures::sentInvoice(6000);
    $b   = PaymentFixtures::sentInvoice(4000);
    $c   = PaymentFixtures::sentInvoice(9000);
    $svc = app(PaymentService::class);

    $p = aid962RaPay(10000, [new Allocation($a->id, cents(6000))]);                       // SURPLUS 4000
    aid962RaInvariant();
    $rb = $svc->allocate($p, new Allocation($b->id, cents(4000)));                         // credit-funded
    aid962RaInvariant();
    $svc->reverseAllocation($p->allocations()->where('invoice_id', $a->id)->sole()->id, 'move'); // direct → SURPLUS 6000
    aid962RaInvariant();
    $rc = $svc->allocate($p, new Allocation($c->id, cents(6000)));                         // credit-funded
    aid962RaInvariant();
    $svc->reverseAllocation($rb->id, 'undo b');                                            // credit → back to SURPLUS 4000
    aid962RaInvariant();
    $svc->reverseAllocation($rc->id, 'undo c');
    aid962RaInvariant();
    $again = $svc->allocate($p, new Allocation($c->id, cents(9000)));                      // spans both entries
    aid962RaInvariant();

    expect(CreditMovement::query()->where('allocation_id', $again->id)->whereNull('reversed_at')->count())->toBe(2)
        ->and($p->availableCredit()->unscaledValue())->toBe(1000)
        ->and($c->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::SENT);
});

it('sets active_invoice_id to null on the reversed row and recomputes the invoice', function () {
    $x  = PaymentFixtures::sentInvoice(10000);
    $p1 = aid962RaPay(4000, [new Allocation($x->id, cents(4000))], 'P1');
    aid962RaPay(3000, [new Allocation($x->id, cents(3000))], 'P2');
    $row = $p1->allocations()->sole();
    $svc = app(PaymentService::class);

    $out = $svc->reverseAllocation($row->id, 'misapplied', TestCase::USER_UUID_3);

    expect($out->active_invoice_id)->toBeNull()
        ->and($out->status)->toBe(AllocationStatus::REVERSED)
        ->and($out->reversed_at)->not->toBeNull()
        ->and($out->reversed_by)->toBe(TestCase::USER_UUID_3)
        ->and($out->reverse_reason)->toBe('misapplied')
        ->and($x->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($x->fresh()->amountPaid()->unscaledValue())->toBe(3000);

    // A second call is a stable no-op: same audit, no second SURPLUS.
    $again = $svc->reverseAllocation($row->id, 'other', TestCase::USER_UUID_1);
    expect($again->reverse_reason)->toBe('misapplied')
        ->and(CreditMovement::query()->where('allocation_id', $row->id)->count())->toBe(1);
    aid962RaInvariant();
});
