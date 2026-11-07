<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\AdvanceInvoiceBuilder;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\Payments\Ledger;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

mutates(CustomerCredit::class, PaymentService::class);

/*
 * AID-1856 (spec 2026-11-06, §7): credit backed by an advance invoice is not
 * spendable as an ordinary payment. CustomerCredit::apply() and
 * PaymentService::allocate() never take an entry linked with
 * linkAdvanceInvoice(); CustomerCredit::refund() keeps taking it, exactly as
 * before (the published 9.0 flow: refund, then the rectificative).
 *
 * Two kinds of test (spec §7). RED-FIRST: fails on v9.3.2 for the defect.
 * GUARD: passed on v9.3.2 and must not move — it pins retained behaviour.
 */

/** @param  array<int, Allocation>  $allocations */
function aid1856TopUp(int $cents, int $daysAgo, array $allocations = [], string $currency = 'EUR'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents($cents), $currency, now()->subDays($daysAgo)->startOfSecond(),
        PaymentMethod::BankTransfer, null, null, $allocations, CreditMovementKind::TOP_UP,
    );
}

/** An unnumbered DRAFT fiscal invoice of the customer: linkable as an advance. */
function aid1856AdvanceDraft(): Invoice
{
    return Invoice::factory()->unissuedDraft()->create(['billable_user_id' => TestCase::USER_UUID_2, 'user_id' => TestCase::USER_UUID_1]);
}

/** A SENT invoice of the customer whose fiscal currency is EUR. */
function aid1856EurInvoice(int $cents): Invoice
{
    return PaymentFixtures::sentInvoice($cents, overrides: [
        'company_fiscal_config_id' => CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id,
    ]);
}

/**
 * A payment of $topUpCents + $surplusCents holding TWO entries: the TOP_UP
 * remainder, and a SURPLUS minted by reversing a direct allocation.
 *
 * @return array{0: GroupedPayment, 1: CreditMovement, 2: CreditMovement} payment, TOP_UP entry, SURPLUS entry
 */
function aid1856TwoEntryPayment(int $topUpCents, int $surplusCents, int $daysAgo): array
{
    $paid    = PaymentFixtures::sentInvoice($surplusCents);
    $payment = aid1856TopUp($topUpCents + $surplusCents, $daysAgo, [new Allocation($paid->id, cents($surplusCents))]);
    app(PaymentService::class)->reverseAllocation($payment->allocations()->sole()->id, 'reassign');

    return [
        $payment->refresh(),
        $payment->creditMovements()->where('kind', CreditMovementKind::TOP_UP->value)->sole(),
        $payment->creditMovements()->where('kind', CreditMovementKind::SURPLUS->value)->sole(),
    ];
}

/**
 * An ISSUED advance of $grossCents @ 21 % linked to $entry: the 9.0 sequence
 * build → link (as a DRAFT) → issue.
 */
function aid1856IssuedAdvance(CreditMovement $entry, int $grossCents): Invoice
{
    CompanyFiscalConfig::query()->where('is_active', true)->exists()
        || CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $rate  = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $group = TaxGroup::factory()->create(['name' => 'IVA General 21%']);
    $group->taxRates()->attach($rate->id);

    $tax  = (int) round($grossCents * 2100 / 12100);
    $base = $grossCents - $tax;

    $advance = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_2,
        'type'             => 'invoice',
        'series'           => 'FAC',
        'status'           => InvoiceStatus::DRAFT->value,
        'items'            => [[
            'description'      => 'Advance collected',
            'quantity'         => 100,
            'base_price'       => $base,
            'taxable_amount'   => $base,
            'total_tax_amount' => $tax,
            'taxes_applied'    => [['source_rate_id' => $rate->id, 'name' => $rate->name, 'rate' => 2100, 'amount' => $tax]],
            'total_amount'     => $grossCents,
        ]],
    ]);

    $entry->linkAdvanceInvoice($advance);
    $advance = app(InvoiceService::class)->issue($advance);

    $advance->status = InvoiceStatus::SENT; // issue() leaves the collection axis DRAFT by design
    $advance->save();

    return $advance->refresh();
}

function aid1856Breakdown(string $currency = 'EUR'): array
{
    $breakdown = CustomerCredit::for(TestCase::USER_UUID_2, $currency)->breakdown();

    return [$breakdown->consumable->unscaledValue(), $breakdown->advanceBacked->unscaledValue()];
}

function aid1856ActiveExitsOn(CreditMovement $entry): int
{
    return CreditMovement::query()->where('consumes_movement_id', $entry->id)->active()->count();
}

// ── T1–T3 — CustomerCredit::apply() (RED-FIRST) ─────────────────────────────

it('skips an older advance-backed entry and applies the newer consumable one (T1)', function () {
    $linked = aid1856TopUp(5000, 3)->creditMovements()->sole();
    $linked->linkAdvanceInvoice(aid1856AdvanceDraft());
    $free = aid1856TopUp(5000, 1)->creditMovements()->sole();

    $row = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, aid1856EurInvoice(3000), cents(3000))->sole();

    $exit = CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->sole();

    expect($exit->consumes_movement_id)->toBe($free->id)
        ->and($exit->allocation_id)->toBe($row->id)
        ->and((string) $row->grouped_payment_id)->toBe((string) $free->grouped_payment_id)
        ->and(aid1856ActiveExitsOn($linked))->toBe(0)
        ->and(aid1856Breakdown())->toBe([2000, 5000]);
});

it('refuses to apply when the customer holds only advance-backed credit, writing nothing (T2)', function () {
    $linked = aid1856TopUp(5000, 3)->creditMovements()->sole();
    $linked->linkAdvanceInvoice(aid1856AdvanceDraft());
    $invoice = aid1856EurInvoice(1000);

    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1000)))
        ->toThrow(InsufficientCreditException::class, 'requested 1000, available 0');

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and(aid1856Breakdown())->toBe([0, 5000]);
});

it('caps apply() at the consumable part, not at balance() (T3)', function () {
    aid1856TopUp(5000, 3)->creditMovements()->sole()->linkAdvanceInvoice(aid1856AdvanceDraft());
    aid1856TopUp(2000, 1);
    $invoice = aid1856EurInvoice(3000);

    expect(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(7000);

    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(3000)))
        ->toThrow(InsufficientCreditException::class, 'requested 3000, available 2000');

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
        ->and(aid1856Breakdown())->toBe([2000, 5000]);
});

// ── T4–T5, T7 — PaymentService::allocate() and availableCredit() (RED-FIRST) ─

it('refuses to allocate from a payment whose only entry is advance-backed, writing nothing (T4)', function () {
    $payment = aid1856TopUp(5000, 3);
    $payment->creditMovements()->sole()->linkAdvanceInvoice(aid1856AdvanceDraft());
    $invoice = aid1856EurInvoice(3000);

    expect(fn () => app(PaymentService::class)->allocate($payment, new Allocation($invoice->id, cents(3000))))
        ->toThrow(AllocationExceedsPaymentException::class, 'requested 3000, available 0');

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and(aid1856Breakdown())->toBe([0, 5000]);
});

it('funds an allocation only from the unlinked entry of a payment that also holds a linked one (T5)', function () {
    [$payment, $topUp, $surplus] = aid1856TwoEntryPayment(5000, 3000, 3);
    $topUp->linkAdvanceInvoice(aid1856AdvanceDraft());

    $row = app(PaymentService::class)->allocate($payment, new Allocation(aid1856EurInvoice(2000)->id, cents(2000)));

    $exit = CreditMovement::query()->where('allocation_id', $row->id)->sole();
    expect($exit->consumes_movement_id)->toBe($surplus->id)
        ->and(aid1856ActiveExitsOn($topUp))->toBe(0)
        ->and(aid1856Breakdown())->toBe([1000, 5000]);

    $next = aid1856EurInvoice(2000);
    expect(fn () => app(PaymentService::class)->allocate($payment, new Allocation($next->id, cents(2000))))
        ->toThrow(AllocationExceedsPaymentException::class, 'requested 2000, available 1000');

    expect(aid1856ActiveExitsOn($topUp))->toBe(0)
        ->and(aid1856Breakdown())->toBe([1000, 5000]);
});

it('reports as available credit of a payment only what allocate() accepts (T7)', function () {
    [$payment, $topUp] = aid1856TwoEntryPayment(5000, 3000, 3);

    expect($payment->availableCredit()->unscaledValue())->toBe(8000);

    $topUp->linkAdvanceInvoice(aid1856AdvanceDraft());

    expect($payment->availableCredit()->unscaledValue())->toBe(3000);

    app(PaymentService::class)->allocate($payment, new Allocation(aid1856EurInvoice(3000)->id, cents(3000)));

    expect($payment->availableCredit()->unscaledValue())->toBe(0);

    $over = aid1856EurInvoice(1);
    expect(fn () => app(PaymentService::class)->allocate($payment, new Allocation($over->id, cents(1))))
        ->toThrow(AllocationExceedsPaymentException::class, 'requested 1, available 0');
});

// ── T6 — currency inference of apply() (RED-FIRST) ──────────────────────────

it('does not take advance-backed credit as a currency hint: none left means no credit (T6)', function () {
    aid1856TopUp(5000, 3)->creditMovements()->sole()->linkAdvanceInvoice(aid1856AdvanceDraft());
    $invoice = PaymentFixtures::sentInvoice(1000); // no fiscal currency

    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1000)))
        ->toThrow(InsufficientCreditException::class, 'holds no credit in any currency');

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(aid1856Breakdown())->toBe([0, 5000]);
});

it('infers the currency of the consumable credit when another currency is only advance-backed (T6)', function () {
    $linked = aid1856TopUp(5000, 3)->creditMovements()->sole();
    $linked->linkAdvanceInvoice(aid1856AdvanceDraft());
    $usd     = aid1856TopUp(4000, 1, currency: 'USD');
    $invoice = PaymentFixtures::sentInvoice(1000); // no fiscal currency

    $row = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1000))->sole();

    expect((string) $row->grouped_payment_id)->toBe((string) $usd->id)
        ->and(aid1856ActiveExitsOn($linked))->toBe(0)
        ->and(aid1856Breakdown('EUR'))->toBe([0, 5000])
        ->and(aid1856Breakdown('USD'))->toBe([3000, 0]);
});

// ── T9 — an entry partly applied, then linked (RED-FIRST) ───────────────────

it('keeps what was applied before the link and stops spending the rest after it (T9)', function () {
    $entry = aid1856TopUp(5000, 3)->creditMovements()->sole();
    $first = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, aid1856EurInvoice(2000), cents(2000))->sole();

    $entry->linkAdvanceInvoice(aid1856AdvanceDraft());

    expect(aid1856Breakdown())->toBe([0, 3000]);

    $next = aid1856EurInvoice(1000);
    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $next, cents(1000)))
        ->toThrow(InsufficientCreditException::class, 'requested 1000, available 0');

    expect(aid1856ActiveExitsOn($entry))->toBe(1)
        ->and($first->fresh()->status)->toBe(AllocationStatus::ACTIVE)
        ->and(aid1856Breakdown())->toBe([0, 3000]);

    // Undoing the earlier application gives the amount back to the entry.
    app(PaymentService::class)->reverseAllocation($first->id, 'undo');

    expect($entry->fresh()->remaining()->unscaledValue())->toBe(5000)
        ->and(aid1856Breakdown())->toBe([0, 5000]);
});

// ── T11 — the invariant (RED-FIRST) ─────────────────────────────────────────

it('never lowers the advance-backed part through apply() or allocate() (T11)', function (array $steps) {
    // Oldest first: a linked TOP_UP, then a payment with a linked TOP_UP and a free SURPLUS, then a free TOP_UP.
    aid1856TopUp(4000, 5)->creditMovements()->sole()->linkAdvanceInvoice(aid1856AdvanceDraft());
    [$mixed, $mixedTopUp] = aid1856TwoEntryPayment(3000, 2500, 4);
    $mixedTopUp->linkAdvanceInvoice(aid1856AdvanceDraft());
    $free = aid1856TopUp(1500, 1);

    $credit = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR');
    expect(aid1856Breakdown())->toBe([4000, 7000]);

    foreach ($steps as [$operation, $cents]) {
        $invoice = aid1856EurInvoice($cents);

        try {
            match ($operation) {
                'apply'          => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents($cents)),
                'allocate-mixed' => app(PaymentService::class)->allocate($mixed, new Allocation($invoice->id, cents($cents))),
                'allocate-free'  => app(PaymentService::class)->allocate($free, new Allocation($invoice->id, cents($cents))),
            };
        } catch (InsufficientCreditException|AllocationExceedsPaymentException) {
            // a refusal is a legitimate step of the sequence: it must write nothing
        }

        $breakdown = $credit->breakdown();

        expect($breakdown->advanceBacked->unscaledValue())->toBe(7000)
            ->and($breakdown->total()->unscaledValue())->toBe($credit->balance()->unscaledValue())
            ->and($breakdown->consumable->unscaledValue())->toBeGreaterThanOrEqual(0);
    }

    // Every APPLIED exit sits on an entry that is not linked to an advance.
    $onLinked = CreditMovement::query()
        ->where('kind', CreditMovementKind::APPLIED->value)
        ->active()
        ->whereIn('consumes_movement_id', CreditMovement::query()->select('id')->whereNotNull('advance_invoice_id'))
        ->count();

    expect($onLinked)->toBe(0);
})->with([
    'one apply within the consumable part'                        => [[['apply', 3000]]],
    'apply up to the consumable part, then one more cent'         => [[['apply', 4000], ['apply', 1]]],
    'apply beyond the consumable part'                            => [[['apply', 4001]]],
    'allocate from the mixed payment, then beyond its free entry' => [[['allocate-mixed', 2000], ['allocate-mixed', 501]]],
    'interleaved'                                                 => [[['allocate-free', 500], ['apply', 2500], ['allocate-mixed', 1000], ['apply', 1], ['allocate-free', 1]]],
]);

// ── T8a — CustomerCredit::refund() is untouched (GUARD) ─────────────────────

it('refunds FIFO across linked and unlinked entries, with the rows 9.3.2 writes (T8a)', function () {
    $oldest    = aid1856TopUp(10000, 5)->creditMovements()->sole();
    [$payment] = aid1856TwoEntryPayment(10000, 10000, 3);

    // The two entries of that payment share `occurred_at`; `id` breaks the tie, in the
    // engine's own order. Ask it, instead of assuming which kind sorts first.
    [$middle, $newest] = $payment->creditMovements()->orderBy('occurred_at')->orderBy('id')->get()->all();

    $oldestAdvance = aid1856IssuedAdvance($oldest, 10000);
    $newestAdvance = aid1856IssuedAdvance($newest, 10000);

    $at    = now()->subHour()->startOfSecond();
    $exits = app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(22000), 'EUR', $at, PaymentMethod::BankTransfer, 'REF-1', TestCase::USER_UUID_1);

    expect($exits)->toHaveCount(3)
        ->and($exits->map(fn (CreditMovement $exit) => [
            $exit->consumes_movement_id, $exit->amount->unscaledValue(), $exit->band_start, $exit->kind,
            (string) $exit->grouped_payment_id, $exit->method, $exit->reference, $exit->recorded_by, $exit->allocation_id,
            $exit->occurred_at->toDateTimeString(),
        ])->all())->toBe([
            [$oldest->id, -10000, 0, CreditMovementKind::REFUNDED, (string) $oldest->grouped_payment_id, PaymentMethod::BankTransfer, 'REF-1', TestCase::USER_UUID_1, null, $at->toDateTimeString()],
            [$middle->id, -10000, 0, CreditMovementKind::REFUNDED, (string) $middle->grouped_payment_id, PaymentMethod::BankTransfer, 'REF-1', TestCase::USER_UUID_1, null, $at->toDateTimeString()],
            [$newest->id, -2000, 0, CreditMovementKind::REFUNDED, (string) $newest->grouped_payment_id, PaymentMethod::BankTransfer, 'REF-1', TestCase::USER_UUID_1, null, $at->toDateTimeString()],
        ])
        ->and(aid1856Breakdown())->toBe([0, 8000]);

    // The published flow: each exit on a linked entry yields its rectificative lines.
    $builder = app(AdvanceInvoiceBuilder::class);
    $sum     = fn (array $lines): int => (int) array_sum(array_column($lines, 'total_amount'));

    expect($sum($builder->rectificativeLines($exits[0])))->toBe(-10000)
        ->and($sum($builder->rectificativeLines($exits[2])))->toBe(-2000)
        ->and($oldestAdvance->isIssued())->toBeTrue()
        ->and($newestAdvance->isIssued())->toBeTrue();
});

// ── T10 — rows inherited from ≤ 9.3 (GUARD) ─────────────────────────────────

/**
 * An APPLIED exit on an entry ALREADY linked to an issued advance, as ≤ 9.3
 * wrote it: an allocation of the entry's payment, funded by one exit.
 *
 * @return array{0: CreditMovement, 1: PaymentAllocation, 2: CreditMovement} entry, allocation, exit
 */
function aid1856InheritedExit(int $takeCents, ?int $bandStart): array
{
    $payment = aid1856TopUp(10000, 3);
    $entry   = $payment->creditMovements()->sole();
    aid1856IssuedAdvance($entry, 10000);

    $invoice    = aid1856EurInvoice($takeCents);
    $allocation = Ledger::createAllocation((string) $payment->id, $invoice, cents($takeCents), now()->startOfSecond());
    $exit       = CreditMovement::factory()->create([
        'billable_user_id'     => TestCase::USER_UUID_2,
        'currency'             => 'EUR',
        'kind'                 => CreditMovementKind::APPLIED->value,
        'amount'               => cents(-$takeCents),
        'grouped_payment_id'   => $payment->id,
        'allocation_id'        => $allocation->id,
        'consumes_movement_id' => $entry->id,
        'band_start'           => $bandStart,
        'occurred_at'          => now()->startOfSecond(),
    ]);
    $invoice->recomputeCollectionState();

    return [$entry, $allocation, $exit];
}

it('still reads and reverses an inherited APPLIED exit with a resolved band (T10)', function () {
    [$entry, $allocation, $exit] = aid1856InheritedExit(3000, 0);

    $line = app(AdvanceInvoiceBuilder::class)->deductionLine($exit);

    expect($line['total_amount'])->toBe(-3000)
        ->and($line['deducts_invoice_id'])->toBe((string) $entry->advance_invoice_id)
        ->and(aid1856Breakdown())->toBe([0, 7000]);

    app(PaymentService::class)->reverseAllocation($allocation->id, 'undo');

    expect($exit->fresh()->isReversed())->toBeTrue()
        ->and($entry->fresh()->remaining()->unscaledValue())->toBe(10000)
        ->and(aid1856Breakdown())->toBe([0, 10000]);
});

it('still refuses the deduction line of an inherited exit whose band is unresolved, and still reverses it (T10)', function () {
    [$entry, $allocation, $exit] = aid1856InheritedExit(3000, null);

    expect(fn () => app(AdvanceInvoiceBuilder::class)->deductionLine($exit))
        ->toThrow(InvalidAdvanceInvoiceException::class);

    app(PaymentService::class)->reverseAllocation($allocation->id, 'undo');

    expect($exit->fresh()->isReversed())->toBeTrue()
        ->and($entry->fresh()->remaining()->unscaledValue())->toBe(10000)
        ->and(aid1856Breakdown())->toBe([0, 10000]);
});

// ── Ledger::lockFundingEntries() — the predicate, term by term ──────────────

it('returns only the entries that can fund a payment, once each, in the order given', function () {
    $free     = aid1856TopUp(1000, 5)->creditMovements()->sole();
    $linked   = aid1856TopUp(1000, 4)->creditMovements()->sole();
    $reversed = aid1856TopUp(1000, 3);
    $other    = aid1856TopUp(1000, 2)->creditMovements()->sole();

    $linked->linkAdvanceInvoice(aid1856AdvanceDraft());

    $reversedEntry = $reversed->creditMovements()->sole();
    app(PaymentService::class)->reverse($reversed, 'bounced');

    // A voucher-backed entry and an exit: neither is ever a candidate, and neither funds a payment.
    $voucher = aid1856TopUp(1000, 1)->creditMovements()->sole();
    $voucher->forceFill(['sale_invoice_id' => PaymentFixtures::sentInvoice(1000)->id])->save();

    app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, aid1856EurInvoice(300), cents(300));
    $exit = CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->sole();

    $ids = [$other->id, $linked->id, $free->id, $reversedEntry->id, $voucher->id, $exit->id, '00000000-0000-7000-8000-00000000dead', $other->id];

    $funding = Ledger::lockFundingEntries($ids);

    expect($funding->map(fn (CreditMovement $entry): string => $entry->id)->all())->toBe([$other->id, $free->id])
        ->and(Ledger::lockFundingEntries([]))->toBeEmpty();
});
