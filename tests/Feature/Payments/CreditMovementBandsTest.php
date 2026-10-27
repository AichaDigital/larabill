<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Database\Eloquent\Collection;

mutates(CustomerCredit::class, PaymentService::class);

/*
 * AID-1778 — the persisted exit bands (spec §5.3–§5.4): every exit creator
 * (CustomerCredit::recordExit() for apply/refund and
 * PaymentService::consumeEntries() for allocate) places its take on the
 * entry's cent tape LOWEST-GAP-FIRST under the source-payment lock it already
 * holds, ONE row per filled gap, and propagates NULL on an unresolvable entry.
 *
 * The band of an exit is [band_start, band_start + abs(amount)); the end is
 * derived, never stored. Reversal freeing is a read-side fact: a reversed
 * exit keeps its stored band for audit but leaves the tape (only ACTIVE rows
 * are read), so a refilled hole lands on the freed band, never on the
 * frontier — the R20 counterexample pins it.
 */

/**
 * A TOP_UP payment with no allocations: one TOP_UP entry of $cents.
 *
 * @return array{0: GroupedPayment, 1: CreditMovement}
 */
function aid1778Entry(int $cents, string $billable = TestCase::USER_UUID_2): array
{
    $payment = app(PaymentService::class)->receive(
        $billable,
        cents($cents),
        'EUR',
        now()->subHour()->startOfSecond(),
        PaymentMethod::BankTransfer,
        null,
        null,
        [],
        CreditMovementKind::TOP_UP,
    );

    /** @var CreditMovement $entry */
    $entry = $payment->creditMovements()->entries()->sole();

    return [$payment, $entry];
}

/**
 * The ACTIVE exits of $entry ordered by band position.
 */
function aid1778Exits(CreditMovement $entry): Collection
{
    return CreditMovement::query()
        ->where('consumes_movement_id', $entry->id)
        ->orderBy('band_start')
        ->orderBy('id')
        ->get();
}

it('assigns the band lowest-first on apply and tiles the tape at exhaustion', function () {
    [, $entry] = aid1778Entry(10000);
    $invoice   = PaymentFixtures::sentInvoice(10000);
    $credit    = app(CustomerCredit::class);

    $credit->apply(TestCase::USER_UUID_2, $invoice, cents(3000));
    $credit->apply(TestCase::USER_UUID_2, $invoice, cents(7000));

    $exits = aid1778Exits($entry);
    expect($exits)->toHaveCount(2)
        ->and($exits[0]->band_start)->toBe(0)
        ->and($exits[0]->amount->unscaledValue())->toBe(-3000)
        ->and($exits[1]->band_start)->toBe(3000)
        ->and($exits[1]->amount->unscaledValue())->toBe(-7000)
        ->and($entry->refresh()->remaining()->unscaledValue())->toBe(0);
});

it('assigns the band lowest-first on refund', function () {
    [, $entry] = aid1778Entry(10000);
    $credit    = app(CustomerCredit::class);
    $at        = now()->subMinutes(5)->startOfSecond();

    $first  = $credit->refund(TestCase::USER_UUID_2, cents(3000), 'EUR', $at, PaymentMethod::BankTransfer);
    $second = $credit->refund(TestCase::USER_UUID_2, cents(7000), 'EUR', $at, PaymentMethod::BankTransfer);

    expect($first)->toHaveCount(1)
        ->and($first[0]->kind)->toBe(CreditMovementKind::REFUNDED)
        ->and($first[0]->band_start)->toBe(0)
        ->and($first[0]->amount->unscaledValue())->toBe(-3000)
        ->and($first[0]->allocation_id)->toBeNull()
        ->and($second)->toHaveCount(1)
        ->and($second[0]->band_start)->toBe(3000)
        ->and($second[0]->amount->unscaledValue())->toBe(-7000)
        ->and($entry->refresh()->remaining()->unscaledValue())->toBe(0);
});

it('assigns the band lowest-first on allocate (PaymentService::consumeEntries)', function () {
    [$payment, $entry] = aid1778Entry(10000);
    $invoice           = PaymentFixtures::sentInvoice(10000);
    $service           = app(PaymentService::class);

    $service->allocate($payment, new Allocation($invoice->id, cents(3000)));
    $service->allocate($payment, new Allocation($invoice->id, cents(7000)));

    $exits = aid1778Exits($entry);
    expect($exits)->toHaveCount(2)
        ->and($exits[0]->kind)->toBe(CreditMovementKind::APPLIED)
        ->and($exits[0]->band_start)->toBe(0)
        ->and($exits[0]->amount->unscaledValue())->toBe(-3000)
        ->and($exits[1]->band_start)->toBe(3000)
        ->and($exits[1]->amount->unscaledValue())->toBe(-7000);
});

it('reversal frees the band as a read-side fact and the refill lands on the freed band, never the frontier', function () {
    [$payment, $entry] = aid1778Entry(10000);
    $invoice           = PaymentFixtures::sentInvoice(10000);
    $service           = app(PaymentService::class);

    $first  = $service->allocate($payment, new Allocation($invoice->id, cents(1000)));
    $second = $service->allocate($payment, new Allocation($invoice->id, cents(1000)));
    $exits  = aid1778Exits($entry);
    expect($exits[0]->band_start)->toBe(0)
        ->and($exits[1]->band_start)->toBe(1000);

    $service->reverseAllocation($first->id, 'refill test');

    // The reversed exit's stored band STAYS on the row (audit history).
    $reversed = $exits[0]->refresh();
    expect($reversed->reversed_at)->not->toBeNull()
        ->and($reversed->band_start)->toBe(0);

    $third = $service->allocate($payment, new Allocation($invoice->id, cents(1000)));

    $refill = CreditMovement::query()->where('allocation_id', $third->id)->sole();
    expect($refill->band_start)->toBe(0)
        ->and($refill->reversed_at)->toBeNull();
});

it('splits a take spanning two gaps into TWO APPLIED exit rows on the same allocation', function () {
    // The R28 tape: G = 2500 — active band [1000,2000) after the two takes,
    // hole [0,1000) freed by the reversal, frontier [2000,2500). A take of
    // 1500 fills BOTH gaps fully: two rows, and the entry is exhausted.
    [$payment, $entry] = aid1778Entry(2500);
    $invoice           = PaymentFixtures::sentInvoice(2500);
    $service           = app(PaymentService::class);

    $first = $service->allocate($payment, new Allocation($invoice->id, cents(1000)));
    $service->allocate($payment, new Allocation($invoice->id, cents(1000)));
    $service->reverseAllocation($first->id, 'split test');

    // Free tape: the hole [0,1000) plus the frontier beyond [1000,2000).
    $row = $service->allocate($payment, new Allocation($invoice->id, cents(1500)));

    $rows = CreditMovement::query()
        ->where('allocation_id', $row->id)
        ->where('kind', CreditMovementKind::APPLIED->value)
        ->orderBy('band_start')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->band_start)->toBe(0)
        ->and($rows[0]->amount->unscaledValue())->toBe(-1000)
        ->and($rows[1]->band_start)->toBe(2000)
        ->and($rows[1]->amount->unscaledValue())->toBe(-500)
        ->and($rows->pluck('consumes_movement_id')->unique()->all())->toBe([$entry->id])
        ->and($rows->pluck('allocation_id')->unique()->all())->toBe([$row->id])
        ->and((int) $rows->sum(fn (CreditMovement $m): int => $m->amount->unscaledValue()))->toBe(-1500);

    aid962AssertFundingInvariant($row->refresh());
    expect($entry->refresh()->remaining()->unscaledValue())->toBe(0)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::PAID);
});

it('splits a take spanning two gaps into TWO REFUNDED exit rows returned in the refund collection', function () {
    // The K4 tape: G = 2500, the refund counterpart of the R28 scenario.
    [, $entry] = aid1778Entry(2500);
    $credit    = app(CustomerCredit::class);
    $at        = now()->subMinutes(5)->startOfSecond();

    $first  = $credit->refund(TestCase::USER_UUID_2, cents(1000), 'EUR', $at, PaymentMethod::BankTransfer);
    $second = $credit->refund(TestCase::USER_UUID_2, cents(1000), 'EUR', $at, PaymentMethod::BankTransfer);
    expect($first[0]->band_start)->toBe(0)
        ->and($second[0]->band_start)->toBe(1000);

    // A REFUNDED exit has no reversal writer in 8.3.0 (declared for 8.4), so
    // the fixture stamps the reversal directly — exactly what that writer
    // will do to the audit columns only.
    CreditMovement::query()->whereKey($first[0]->id)->update([
        'reversed_at'    => $at,
        'reverse_reason' => 'fixture reversal',
    ]);

    $exits = $credit->refund(TestCase::USER_UUID_2, cents(1500), 'EUR', $at, PaymentMethod::BankTransfer);

    expect($exits)->toHaveCount(2)
        ->and($exits->pluck('id')->all())->toBe($exits->modelKeys())
        ->and($exits[0]->band_start)->toBe(0)
        ->and($exits[0]->amount->unscaledValue())->toBe(-1000)
        ->and($exits[1]->band_start)->toBe(2000)
        ->and($exits[1]->amount->unscaledValue())->toBe(-500)
        ->and($exits->pluck('consumes_movement_id')->unique()->all())->toBe([$entry->id])
        ->and($exits->pluck('allocation_id')->unique()->all())->toBe([null])
        ->and($entry->refresh()->remaining()->unscaledValue())->toBe(0);
});

it('propagates NULL band_start on an unresolvable entry without inventing a split', function () {
    [$payment, $entry] = aid1778Entry(10000);

    // A legacy-shaped active exit with NO band (the declaration of an
    // unresolvable entry, spec §5.7).
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

    $invoice = PaymentFixtures::sentInvoice(10000);
    $rows    = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1000));

    $exits = aid1778Exits($entry)->where('kind', CreditMovementKind::APPLIED->value)->values();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->applied_amount->unscaledValue())->toBe(1000)
        ->and($exits)->toHaveCount(1)
        ->and($exits[0]->band_start)->toBeNull()
        ->and($exits[0]->amount->unscaledValue())->toBe(-1000)
        // The LEDGER arithmetic is untouched by the missing band (spec §5.7).
        ->and($entry->refresh()->remaining()->unscaledValue())->toBe(7000);
});
