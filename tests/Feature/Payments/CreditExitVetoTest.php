<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\Payments\ExitBands;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

mutates(CustomerCredit::class, PaymentService::class);

/*
 * AID-1864 (spec 2026-11-06): an APPLIED or REFUNDED credit exit whose write
 * is vetoed by a model listener must abort the whole call. Eloquent's save()
 * returns false without throwing on a `saving`/`creating` veto, and
 * Builder::create() swallows it; before the fix the allocation and the
 * invoice state committed without their exit, or a refund was reported and
 * never recorded.
 *
 * Each of the four exit writes has its own tests (spec §5 table):
 *   recordExit() segments      → T1, T2, T3, T4
 *   recordExit() NULL band     → T8, T9
 *   consumeEntries() segments  → T5
 *   consumeEntries() NULL band → T10
 * T11 pins that the guard reads the bool save() returns, not `exists`.
 * T7 (the rollback on real engines) lives in tests/Integration/Mysql.
 */

/**
 * A TOP_UP payment of $cents received $daysAgo days ago: one entry, no allocation.
 */
function aid1864TopUp(int $cents, int $daysAgo = 1): GroupedPayment
{
    return app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents($cents), 'EUR', now()->subDays($daysAgo)->startOfSecond(),
        PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
}

/**
 * A 10000 TOP_UP whose entry carries an active legacy exit of 2000 with no
 * band: ExitBands::segmentsFor() returns null for it, so the next exit is
 * written by the NULL-band branch. 8000 stay consumable.
 *
 * @return array{0: GroupedPayment, 1: CreditMovement}
 */
function aid1864NullBandEntry(): array
{
    $payment = aid1864TopUp(10000);

    /** @var CreditMovement $entry */
    $entry = $payment->creditMovements()->entries()->sole();

    CreditMovement::factory()->create([
        'billable_user_id'     => TestCase::USER_UUID_2,
        'currency'             => 'EUR',
        'kind'                 => CreditMovementKind::REFUNDED,
        'amount'               => cents(-2000),
        'grouped_payment_id'   => $payment->id,
        'consumes_movement_id' => $entry->id,
        'band_start'           => null,
        'occurred_at'          => now()->subMinutes(30)->startOfSecond(),
    ]);

    expect(ExitBands::segmentsFor($entry, 1000))->toBeNull(); // the fixture really routes through the NULL-band branch

    return [$payment, $entry];
}

/**
 * Run $call with a listener that vetoes the $onAttempt-th write of an exit of
 * $kind, and expect the checked-write refusal. The listener is registered
 * here — after the fixtures, which write CreditMovement rows of their own —
 * and always removed.
 */
function aid1864ExpectVetoRefusal(CreditMovementKind $kind, Closure $call, int $onAttempt = 1): void
{
    $attempts = 0;
    CreditMovement::saving(function (CreditMovement $movement) use ($kind, $onAttempt, &$attempts): ?bool {
        if ($movement->kind !== $kind) {
            return null;
        }

        return ++$attempts === $onAttempt ? false : null;
    });

    try {
        expect($call)->toThrow(LogicException::class, 'The write of a credit exit was vetoed by a model listener');
    } finally {
        CreditMovement::flushEventListeners();
        CreditMovement::clearBootedModels();
    }

    expect($attempts)->toBe($onAttempt); // the veto fired on the write under test, not earlier and not never
}

function aid1864Exits(CreditMovementKind $kind): int
{
    return CreditMovement::query()->where('kind', $kind->value)->count();
}

function aid1864Balance(): int
{
    return CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue();
}

describe('AID-1864 — CustomerCredit::apply() fails closed', function () {
    it('T1: rolls back the allocation and the invoice state when its only exit is vetoed', function () {
        $payment = aid1864TopUp(5000);
        $invoice = PaymentFixtures::sentInvoice(3000);
        $before  = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown();

        expect(ExitBands::segmentsFor($payment->creditMovements()->entries()->sole(), 3000))->not->toBeNull();

        aid1864ExpectVetoRefusal(
            CreditMovementKind::APPLIED,
            fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000)),
        );

        $after = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown();
        expect(PaymentAllocation::query()->count())->toBe(0)
            ->and(aid1864Exits(CreditMovementKind::APPLIED))->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and($invoice->fresh()->paid_at)->toBeNull()
            ->and(aid1864Balance())->toBe(5000)
            ->and($after)->toEqual($before);
    });

    it('T2: rolls back the first payment too when the exit of the second is vetoed', function () {
        aid1864TopUp(2000, daysAgo: 3);
        aid1864TopUp(2000, daysAgo: 2);
        $invoice = PaymentFixtures::sentInvoice(3000);

        aid1864ExpectVetoRefusal(
            CreditMovementKind::APPLIED,
            fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000)),
            onAttempt: 2,
        );

        expect(PaymentAllocation::query()->count())->toBe(0)
            ->and(aid1864Exits(CreditMovementKind::APPLIED))->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and(aid1864Balance())->toBe(4000);
    });

    it('T6: leaves no drift behind — the same call succeeds afterwards and is fully funded', function () {
        aid1864TopUp(5000);
        $invoice = PaymentFixtures::sentInvoice(3000);

        aid1864ExpectVetoRefusal(
            CreditMovementKind::APPLIED,
            fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000)),
        );

        $rows = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000));

        /** @var PaymentAllocation $row */
        $row    = $rows->sole();
        $funded = CreditMovement::query()->where('allocation_id', $row->id)->get()
            ->sum(fn (CreditMovement $exit): int => abs($exit->amount->unscaledValue()));

        expect($row->applied_amount->unscaledValue())->toBe(3000)
            ->and($funded)->toBe(3000)
            ->and(PaymentAllocation::query()->count())->toBe(1)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
            ->and(aid1864Balance())->toBe(2000);
    });

    it('T8: rolls back when the vetoed exit is the one of a NULL-band entry', function () {
        [, $entry] = aid1864NullBandEntry();
        $invoice   = PaymentFixtures::sentInvoice(3000);

        aid1864ExpectVetoRefusal(
            CreditMovementKind::APPLIED,
            fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000)),
        );

        expect(PaymentAllocation::query()->count())->toBe(0)
            ->and(aid1864Exits(CreditMovementKind::APPLIED))->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and($entry->refresh()->remaining()->unscaledValue())->toBe(8000);
    });
});

describe('AID-1864 — the checked writer reads the result of save(), not the model state', function () {
    it('T11: still refuses when the vetoing listener marks the unsaved model as existing', function () {
        aid1864TopUp(5000);
        $invoice = PaymentFixtures::sentInvoice(3000);

        // A guard that looked at `$exit->exists` instead of the bool save()
        // returns would be fooled by this listener and keep the old defect.
        CreditMovement::saving(function (CreditMovement $movement): ?bool {
            if ($movement->kind !== CreditMovementKind::APPLIED) {
                return null;
            }
            $movement->exists = true;

            return false;
        });

        try {
            expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000)))
                ->toThrow(LogicException::class, 'The write of a credit exit was vetoed by a model listener');
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }

        expect(PaymentAllocation::query()->count())->toBe(0)
            ->and(aid1864Exits(CreditMovementKind::APPLIED))->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and(aid1864Balance())->toBe(5000);
    });
});

describe('AID-1864 — CustomerCredit::refund() fails closed', function () {
    it('T3: records nothing and throws when its only exit is vetoed', function () {
        aid1864TopUp(5000);

        aid1864ExpectVetoRefusal(
            CreditMovementKind::REFUNDED,
            fn () => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(2000), 'EUR', now(), PaymentMethod::BankTransfer),
        );

        expect(aid1864Exits(CreditMovementKind::REFUNDED))->toBe(0)
            ->and(aid1864Balance())->toBe(5000);
    });

    it('T4: rolls back the first exit when the second is vetoed', function () {
        aid1864TopUp(2000, daysAgo: 3);
        aid1864TopUp(2000, daysAgo: 2);

        aid1864ExpectVetoRefusal(
            CreditMovementKind::REFUNDED,
            fn () => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(3000), 'EUR', now(), PaymentMethod::BankTransfer),
            onAttempt: 2,
        );

        expect(aid1864Exits(CreditMovementKind::REFUNDED))->toBe(0)
            ->and(aid1864Balance())->toBe(4000);
    });

    it('T9: records nothing when the vetoed exit is the one of a NULL-band entry', function () {
        [, $entry] = aid1864NullBandEntry();

        aid1864ExpectVetoRefusal(
            CreditMovementKind::REFUNDED,
            fn () => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(1000), 'EUR', now(), PaymentMethod::BankTransfer),
        );

        expect(aid1864Exits(CreditMovementKind::REFUNDED))->toBe(1) // the legacy seed only
            ->and($entry->refresh()->remaining()->unscaledValue())->toBe(8000);
    });
});

describe('AID-1864 — PaymentService::allocate() fails closed', function () {
    it('T5: rolls back the allocation and the invoice state when its exit is vetoed', function () {
        $payment = aid1864TopUp(5000);
        $invoice = PaymentFixtures::sentInvoice(3000);

        expect(ExitBands::segmentsFor($payment->creditMovements()->entries()->sole(), 3000))->not->toBeNull();

        aid1864ExpectVetoRefusal(
            CreditMovementKind::APPLIED,
            fn () => app(PaymentService::class)->allocate($payment, new Allocation($invoice->id, cents(3000))),
        );

        expect(PaymentAllocation::query()->count())->toBe(0)
            ->and(aid1864Exits(CreditMovementKind::APPLIED))->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and($invoice->fresh()->paid_at)->toBeNull()
            ->and($payment->fresh()->availableCredit()->unscaledValue())->toBe(5000);
    });

    it('T10: rolls back when the vetoed exit is the one of a NULL-band entry', function () {
        [$payment] = aid1864NullBandEntry();
        $invoice   = PaymentFixtures::sentInvoice(3000);

        aid1864ExpectVetoRefusal(
            CreditMovementKind::APPLIED,
            fn () => app(PaymentService::class)->allocate($payment, new Allocation($invoice->id, cents(3000))),
        );

        expect(PaymentAllocation::query()->count())->toBe(0)
            ->and(aid1864Exits(CreditMovementKind::APPLIED))->toBe(0)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and($payment->fresh()->availableCredit()->unscaledValue())->toBe(8000);
    });
});
