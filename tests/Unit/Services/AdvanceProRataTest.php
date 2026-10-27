<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\AdvanceInvoiceBuilder;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-1778 spec §8.3 (S4) — the positional pro-rata helper serving both
 * exits: `AdvanceInvoiceBuilder::deductionLine()` (APPLIED exit → frozen
 * deduction line for the later invoice) and `::rectificativeLines()`
 * (REFUNDED exit → frozen lines for `SettlementService::rectify(items:)`).
 *
 * The helper is a PURE read of the exit's STORED band
 * (`credit_movements.band_start`, T5a) plus the advance's frozen totals:
 * tax = f(b) − f(a), base = (b − a) − tax, with f(c) = HalfUp(c·T/G) for
 * 0 ≤ c < G and f(G) = T exactly (spec §5.2). Call timing is irrelevant —
 * a repeated call reads the same stored column — and every refusal is
 * fail-loud with ZERO writes.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->builder = app(AdvanceInvoiceBuilder::class);

    $this->customer = TestUser::factory()->create();

    $this->group = TaxGroup::factory()->create(['name' => 'IVA General 21%']);
    $this->rate  = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $this->group->taxRates()->attach($this->rate->id);

    // A TOP_UP entry of €100.00 collected from a payment; its exits split
    // against this entry's cent tape.
    $this->payment = app(PaymentService::class)->receive(
        (string) $this->customer->id,
        cents(10000),
        'EUR',
        now()->subHour()->startOfSecond(),
        PaymentMethod::BankTransfer,
        null,
        null,
        [],
        CreditMovementKind::TOP_UP,
    );
    $this->entry = $this->payment->creditMovements()->entries()->sole();

    // The 9.0 sequence (spec §5.6, L9/L14): the advance is linked as an
    // unnumbered DRAFT and issued afterwards — an ISSUED advance can no
    // longer be linked.
    $this->advance = aid1778MakeAdvance($this->customer, $this->rate);
    $this->entry->linkAdvanceInvoice($this->advance);
    $this->advance = app(InvoiceService::class)->issue($this->advance);

    // issue() leaves the collection axis DRAFT by design — the consumer moves
    // it as today (Invoice::isDraft()). The helpers and rectify() below read
    // the issued, in-collection shape the consumer reaches right after.
    $this->advance->status = InvoiceStatus::SENT;
    $this->advance->save();
    $this->advance->refresh();

    // Only the advance (one invoice, one item) exists before a helper call.
    $this->assertNoProRataWrites = function (): void {
        expect(Invoice::query()->count())->toBe(1)
            ->and(InvoiceItem::query()->count())->toBe(1);
    };
});

/**
 * The §4-builder-shaped advance: an unnumbered DRAFT carrying the frozen
 * per-rate breakdown (the shape `forCreditEntry()` leaves behind). Under the
 * 9.0 link contract the DRAFT is what gets linked; the caller issues it
 * afterwards (`InvoiceService::issue()`) when it needs the ISSUED shape.
 * Defaults to the reverse-base line of 100,00 € @ 21 % (82,64 + 17,36).
 *
 * @param  list<array<string, mixed>>  $lines
 */
function aid1778MakeAdvance(TestUser $customer, TaxRate $rate, ?array $lines = null): Invoice
{
    $lines ??= [[
        'description'        => 'Advance collected',
        'quantity'           => 100,
        'base_price'         => 8264,
        'taxable_amount'     => 8264,
        'total_tax_amount'   => 1736,
        'taxes_applied'      => [[
            'source_rate_id' => $rate->id,
            'name'           => $rate->name,
            'rate'           => 2100,
            'amount'         => 1736,
        ]],
        'total_amount'       => 10000,
    ]];

    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => (string) $customer->id,
        'type'             => 'invoice',
        'series'           => 'FAC',
        'status'           => InvoiceStatus::DRAFT->value,
        'items'            => $lines,
    ]);
}

/**
 * A hand-built exit of $entry with an explicit stored band (the pure-unit
 * fixture: the real creation paths are exercised by the ledger test below).
 */
function aid1778Exit(CreditMovement $entry, CreditMovementKind $kind, int $takeCents, int $bandStart, bool $reversed = false): CreditMovement
{
    return CreditMovement::factory()->create([
        'billable_user_id'     => $entry->billable_user_id,
        'currency'             => $entry->currency,
        'kind'                 => $kind->value,
        'amount'               => cents(-$takeCents),
        'grouped_payment_id'   => $entry->grouped_payment_id,
        'consumes_movement_id' => $entry->id,
        'band_start'           => $bandStart,
        'reversed_at'          => $reversed ? now()->subMinutes(5)->startOfSecond() : null,
    ]);
}

/**
 * The ACTIVE exits of $entry ordered by band position.
 */
function aid1778LiveExits(CreditMovement $entry): Collection
{
    return CreditMovement::query()
        ->where('consumes_movement_id', $entry->id)
        ->whereNull('reversed_at')
        ->orderBy('band_start')
        ->orderBy('id')
        ->get();
}

/**
 * Σ (|tax|, |base|) of the helper's output over the entry's LIVE exits,
 * both artifact kinds included — the telescoping identity Σ tax = T and
 * Σ base = G − T.
 *
 * @return array{0: int, 1: int}
 */
function aid1778Telescope(AdvanceInvoiceBuilder $builder, CreditMovement $entry): array
{
    $taxSum  = 0;
    $baseSum = 0;

    foreach (aid1778LiveExits($entry) as $exit) {
        $line = $exit->kind === CreditMovementKind::APPLIED
            ? $builder->deductionLine($exit)
            : $builder->rectificativeLines($exit)[0];

        $taxSum  += abs((int) $line['total_tax_amount']);
        $baseSum += abs((int) $line['taxable_amount']);
    }

    return [$taxSum, $baseSum];
}

it('builds the frozen deduction line for a single full deduction (R14)', function () {
    $exit = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 10000, 0);

    $line = $this->builder->deductionLine($exit);

    expect($line['taxable_amount'])->toBe(-8264)
        ->and($line['total_tax_amount'])->toBe(-1736)
        ->and($line['total_amount'])->toBe(-10000)
        ->and($line['quantity'])->toBe(100)
        ->and($line['deducts_invoice_id'])->toBe((string) $this->advance->id)
        ->and($line['taxes_applied'])->toBe([[
            'source_rate_id' => $this->rate->id,
            'name'           => $this->rate->name,
            'rate'           => 2100,
            'amount'         => -1736,
        ]])
        ->and($line['description'])->toContain($this->advance->fiscal_number);
});

it('absorbs the cent on the last deduction: partial then last closes to the frozen figures (R15)', function () {
    $first = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 3000, 0);
    $last  = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 7000, 3000);

    $firstLine = $this->builder->deductionLine($first);
    $lastLine  = $this->builder->deductionLine($last);

    // f(3000) − f(0) = HalfUp(520.8) = 521; the LAST exit absorbs the cent:
    // f(G) − f(3000) = 1736 − 521 = 1215.
    expect($firstLine['taxable_amount'])->toBe(-2479)
        ->and($firstLine['total_tax_amount'])->toBe(-521)
        ->and($firstLine['total_amount'])->toBe(-3000)
        ->and($lastLine['taxable_amount'])->toBe(-5785)
        ->and($lastLine['total_tax_amount'])->toBe(-1215)
        ->and($lastLine['total_amount'])->toBe(-7000)
        // Σ deductions equal the advance exactly (base 8264, tax 1736).
        ->and($firstLine['taxable_amount'] + $lastLine['taxable_amount'])->toBe(-8264)
        ->and($firstLine['total_tax_amount'] + $lastLine['total_tax_amount'])->toBe(-1736);
});

it('reads the STORED bands of previous exits of BOTH artifact kinds, never the artifacts (R16)', function () {
    // Previous APPLIED exit (its deduction line recorded on a later invoice)
    // and previous REFUNDED exit (its partial rectificative recorded on the
    // advance), then the last exit — the helper must close it to the frozen
    // figures from the bands alone.
    $applied  = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 3000, 0);
    $refunded = aid1778Exit($this->entry, CreditMovementKind::REFUNDED, 3000, 3000);
    $last     = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 4000, 6000);

    $laterInvoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => (string) $this->customer->id,
        'type'             => 'invoice',
        'series'           => 'FAC',
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            ['article_id' => null, 'quantity' => 100, 'base_price' => 6000, 'description' => 'Final charges'],
            $this->builder->deductionLine($applied),
        ],
    ], ['make_immutable' => true]);

    app(SettlementService::class)->rectify(
        $this->advance,
        null,
        null,
        $this->builder->rectificativeLines($refunded),
    );

    $lastLine = $this->builder->deductionLine($last);

    // f(10000) − f(6000) = 1736 − 1042 = 694; base 4000 − 694 = 3306.
    expect($lastLine['taxable_amount'])->toBe(-3306)
        ->and($lastLine['total_tax_amount'])->toBe(-694)
        ->and($lastLine['total_amount'])->toBe(-4000)
        ->and($lastLine['deducts_invoice_id'])->toBe((string) $this->advance->id)
        // The recorded artifact closed its exit at its stored band: the later
        // invoice's tax is its own tax-free charge (0) net of the deduction (-521).
        ->and($laterInvoice->total_tax_amount->unscaledValue())->toBe(-521);
});

it('refuses wrong kinds, reversed exits and entries without an advance, writing nothing (R17)', function () {
    $applied  = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 3000, 0);
    $refunded = aid1778Exit($this->entry, CreditMovementKind::REFUNDED, 3000, 0);

    // An APPLIED exit is not a refund source; a REFUNDED exit is not a
    // deduction source.
    expect(fn () => $this->builder->rectificativeLines($applied))
        ->toThrow(InvalidCreditMovementException::class)
        ->and(fn () => $this->builder->deductionLine($refunded))
        ->toThrow(InvalidCreditMovementException::class);

    // A reversed exit has no artifact to compute (its rectification duty
    // applies only to an already-computed artifact).
    $reversed = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 3000, 3000, reversed: true);
    expect(fn () => $this->builder->deductionLine($reversed))
        ->toThrow(InvalidCreditMovementException::class);

    // An exit whose entry has no advance linked: nothing to compute from.
    $otherPayment = app(PaymentService::class)->receive(
        (string) $this->customer->id,
        cents(5000),
        'EUR',
        now()->subMinutes(30)->startOfSecond(),
        PaymentMethod::BankTransfer,
        null,
        null,
        [],
        CreditMovementKind::TOP_UP,
    );
    $otherEntry = $otherPayment->creditMovements()->entries()->sole();
    $orphanExit = CreditMovement::factory()->create([
        'billable_user_id'     => $otherEntry->billable_user_id,
        'currency'             => $otherEntry->currency,
        'kind'                 => CreditMovementKind::APPLIED->value,
        'amount'               => cents(-1000),
        'grouped_payment_id'   => $otherPayment->id,
        'consumes_movement_id' => $otherEntry->id,
        'band_start'           => 0,
    ]);

    expect(fn () => $this->builder->deductionLine($orphanExit))
        ->toThrow(InvalidAdvanceInvoiceException::class);

    ($this->assertNoProRataWrites)();
});

it('validates the advance snapshot before anything else (R21, G6)', function () {
    $deductionExit = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 3000, 0);
    $refundExit    = aid1778Exit($this->entry, CreditMovementKind::REFUNDED, 3000, 0);

    // (a) a taxes_applied entry missing source_rate_id.
    $noRateId = aid1778MakeAdvance($this->customer, $this->rate, [
        ['taxable_amount'   => 8264, 'total_tax_amount' => 1736, 'total_amount' => 10000,
            'taxes_applied' => [['name' => 'IVA', 'rate' => 2100, 'amount' => 1736]]],
    ]);
    // (b) the same source_rate_id repeated across two lines.
    $repeated = aid1778MakeAdvance($this->customer, $this->rate, [
        ['taxable_amount'   => 4132, 'total_tax_amount' => 868, 'total_amount' => 5000,
            'taxes_applied' => [['source_rate_id' => $this->rate->id, 'name' => 'IVA', 'rate' => 2100, 'amount' => 868]]],
        ['taxable_amount'   => 4132, 'total_tax_amount' => 868, 'total_amount' => 5000,
            'taxes_applied' => [['source_rate_id' => $this->rate->id, 'name' => 'IVA', 'rate' => 2100, 'amount' => 868]]],
    ]);
    // (c) two distinct rates — no single f exists.
    $otherRate = TaxRate::factory()->create(['name' => 'IVA 10%', 'rate' => 1000]);
    $multiRate = aid1778MakeAdvance($this->customer, $this->rate, [
        ['taxable_amount'   => 5000, 'total_tax_amount' => 1050, 'total_amount' => 6050,
            'taxes_applied' => [['source_rate_id' => $this->rate->id, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 1050]]],
        ['taxable_amount'   => 4132, 'total_tax_amount' => 686, 'total_amount' => 4818,
            'taxes_applied' => [['source_rate_id' => $otherRate->id, 'name' => 'IVA 10%', 'rate' => 1000, 'amount' => 686]]],
    ]);
    // (d) the taxes_applied amounts do not sum to the line's tax.
    $sumMismatch = aid1778MakeAdvance($this->customer, $this->rate, [
        ['taxable_amount'   => 8264, 'total_tax_amount' => 1736, 'total_amount' => 10000,
            'taxes_applied' => [['source_rate_id' => $this->rate->id, 'name' => 'IVA', 'rate' => 2100, 'amount' => 1700]]],
    ]);

    // Point the entry's link at each broken advance in turn (re-linking the
    // row directly — the model guard is once-only).
    $invoiceCount  = Invoice::query()->count();
    $itemCount     = InvoiceItem::query()->count();
    foreach ([$noRateId, $repeated, $multiRate, $sumMismatch] as $broken) {
        CreditMovement::query()->whereKey($this->entry->getKey())
            ->update(['advance_invoice_id' => $broken->id]);

        expect(fn () => $this->builder->deductionLine($deductionExit))
            ->toThrow(InvalidAdvanceInvoiceException::class)
            ->and(fn () => $this->builder->rectificativeLines($refundExit))
            ->toThrow(InvalidAdvanceInvoiceException::class);
    }

    // The helpers wrote NOTHING beyond the fixtures themselves.
    expect(Invoice::query()->count())->toBe($invoiceCount)
        ->and(InvoiceItem::query()->count())->toBe($itemCount);
});

it('refuses the HalfUp tie upward: take 625 at 21 % prices tax 109, not 108 (R22, G12)', function () {
    $exit = aid1778Exit($this->entry, CreditMovementKind::APPLIED, 625, 0);

    $line = $this->builder->deductionLine($exit);

    // f(625) = HalfUp(625 · 1736 / 10000) = HalfUp(108.5) = 109 (half away from zero).
    expect($line['total_tax_amount'])->toBe(-109)
        ->and($line['taxable_amount'])->toBe(-516)
        ->and($line['total_amount'])->toBe(-625);
});

it('pins the exit-sum identity over rectificative lines of several sizes (R26, H8)', function () {
    foreach ([[1000, 0], [625, 1000], [3000, 1625], [5375, 4625]] as [$take, $start]) {
        $exit  = aid1778Exit($this->entry, CreditMovementKind::REFUNDED, $take, $start);
        $lines = $this->builder->rectificativeLines($exit);

        $grossSum = array_sum(array_map(
            static fn (array $line): int => abs((int) $line['total_amount']),
            $lines,
        ));
        $taxSum = array_sum(array_map(
            static fn (array $line): int => array_sum(array_map(
                static fn (array $entry): int => (int) $entry['amount'],
                $line['taxes_applied'],
            )),
            $lines,
        ));

        expect($grossSum)->toBe(abs($exit->amount->unscaledValue()))
            ->and($taxSum)->toBeLessThanOrEqualTo(0);
    }
});

it('refuses an unresolvable entry (active NULL-band exit) loudly on every helper (R29b)', function () {
    $deductionExit = CreditMovement::factory()->create([
        'billable_user_id'     => $this->entry->billable_user_id,
        'currency'             => $this->entry->currency,
        'kind'                 => CreditMovementKind::APPLIED->value,
        'amount'               => cents(-3000),
        'grouped_payment_id'   => $this->entry->grouped_payment_id,
        'consumes_movement_id' => $this->entry->id,
        'band_start'           => null, // the legacy-unresolved declaration
    ]);
    $refundExit = CreditMovement::factory()->create([
        'billable_user_id'     => $this->entry->billable_user_id,
        'currency'             => $this->entry->currency,
        'kind'                 => CreditMovementKind::REFUNDED->value,
        'amount'               => cents(-2000),
        'grouped_payment_id'   => $this->entry->grouped_payment_id,
        'consumes_movement_id' => $this->entry->id,
        'band_start'           => null,
    ]);

    expect(fn () => $this->builder->deductionLine($deductionExit))
        ->toThrow(InvalidAdvanceInvoiceException::class)
        ->and(fn () => $this->builder->rectificativeLines($refundExit))
        ->toThrow(InvalidAdvanceInvoiceException::class);

    ($this->assertNoProRataWrites)();
});

it('telescopes over mixed APPLIED/REFUNDED live exits and after a reversal + refill (R20, telescoping proof)', function () {
    $invoice = PaymentFixtures::sentInvoice(10000, (string) $this->customer->id);
    $credit  = app(CustomerCredit::class);
    $service = app(PaymentService::class);

    // Mixed kinds on one entry: an APPLIED deduction, a REFUNDED partial
    // rectificative, then the rest APPLIED — the tape tiles [0, G).
    $service->allocate($this->payment, new Allocation($invoice->id, cents(1000)));
    $credit->refund((string) $this->customer->id, cents(1000), 'EUR', now()->subMinutes(5)->startOfSecond(), PaymentMethod::BankTransfer);
    $service->allocate($this->payment, new Allocation($invoice->id, cents(8000)));

    // f(1000) = 174, f(2000) − f(1000) = 173, f(G) − f(2000) = 1389 → 1736 = T.
    expect(aid1778Telescope($this->builder, $this->entry))->toBe([1736, 8264]);

    // Reversal in the middle + refill (R20): the freed hole is refilled at
    // its position, never priced twice at the frontier.
    $firstExit = aid1778LiveExits($this->entry)->first();
    $service->reverseAllocation($firstExit->allocation_id, 'R20 refill test');
    $service->allocate($this->payment, new Allocation($invoice->id, cents(1000)));

    // Live set after the reversal: [1000,2000) 173, refill [0,1000) 174,
    // [2000,10000) 1389 → 1736 = T; frontier-continuation would yield 1735.
    expect(aid1778Telescope($this->builder, $this->entry))->toBe([1736, 8264]);
});
