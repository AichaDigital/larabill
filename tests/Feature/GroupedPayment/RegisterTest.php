<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Pinned non-immutable, unpaid SENT fixture (Codex #2: factory randomizes both).
function makeSentInvoice(int $totalCents): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents($totalCents), 'is_immutable' => false, 'paid_at' => null,
    ]);
}

/**
 * A SENT invoice of $totalCents with one ACTIVE manual receive() allocation of
 * $paidCents — so its collection status is PARTIALLY_PAID and its outstanding
 * is ($totalCents - $paidCents), read from the pivot like amountPaid() does.
 */
function makePartiallyPaidInvoice(int $totalCents, int $paidCents): Invoice
{
    $invoice = makeSentInvoice($totalCents);

    app(PaymentService::class)->receive(
        billableUserId: TestCase::USER_UUID_2, amount: cents($paidCents), currency: 'EUR',
        receivedAt: now(), method: PaymentMethod::BankTransfer, reference: 'MANUAL-1',
        recordedBy: null, allocations: [new Allocation((string) $invoice->id, cents($paidCents))],
    );

    return $invoice->fresh();
}

/**
 * Runs register() for $invoice (total 10000) with a manual receive() of
 * $racingCents injected between register()'s two transactions — the Legacy
 * race window of the spec §3.4 — through a PaymentService test double.
 * Returns the thrown exception, or null when register() succeeded.
 */
function registerWithRacingReceive(Invoice $invoice, int $racingCents): ?Throwable
{
    $double = new class extends PaymentService
    {
        public ?Closure $between = null;

        public function process(ReceiveRequest $request, ReplayPolicy $policy): GroupedPayment
        {
            if ($this->between !== null) {
                $between       = $this->between;
                $this->between = null;
                $between();
            }

            return parent::process($request, $policy);
        }
    };

    $double->between = function () use ($invoice, $racingCents): void {
        // register()'s first transaction has committed; this receive() commits
        // before process()'s transaction opens. Same connection is exact: the
        // window between the two transactions is serial in-process.
        (new PaymentService)->receive(
            billableUserId: TestCase::USER_UUID_2, amount: cents($racingCents), currency: 'EUR',
            receivedAt: now(), method: PaymentMethod::BankTransfer, reference: 'MANUAL-RACE',
            recordedBy: null, allocations: [new Allocation((string) $invoice->id, cents($racingCents))],
        );
    };
    app()->instance(PaymentService::class, $double);

    try {
        app(GroupedPaymentService::class)->register(
            billableUserId: TestCase::USER_UUID_2, invoiceIds: [(string) $invoice->id],
            paidAt: now(), amount: cents(10000), currency: 'EUR',
        );
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('settles a set of issued invoices in one posted payment', function () {
    $a = makeSentInvoice(6000);
    $b = makeSentInvoice(4000);

    $payment = app(GroupedPaymentService::class)->register(
        billableUserId: TestCase::USER_UUID_2, invoiceIds: [$a->id, $b->id],
        paidAt: now(), amount: cents(10000), currency: 'EUR', reference: 'TRF-001',
    );

    expect($payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($payment->amount->unscaledValue())->toBe(10000)
        ->and($payment->invoices)->toHaveCount(2);
    expect($a->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($a->fresh()->paid_at)->not->toBeNull()
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PAID);

    $pivot = $payment->invoices()->where('invoice_id', $a->id)->first()->pivot;
    expect((int) $pivot->previous_status)->toBe(InvoiceStatus::SENT->value)
        ->and($pivot->active_invoice_id)->toBe($a->id);
});

it('rejects an empty invoice list', function () {
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [], now(), cents(0), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects duplicate invoice ids', function () {
    $a = makeSentInvoice(5000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id, $a->id], now(), cents(10000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a nonexistent invoice id', function () {
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [(string) Str::orderedUuid()], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects invoices belonging to different billable users', function () {
    $a = makeSentInvoice(5000);
    $b = Invoice::factory()->sent()->create([
        'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_3,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id, $b->id], now(), cents(10000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a currency that differs from the invoice fiscal config (D3)', function () {
    $config = CompanyFiscalConfig::factory()->create(['currency' => 'USD']);
    $usd    = Invoice::factory()->sent()->create([
        'user_id'                  => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount'             => cents(5000), 'is_immutable' => false, 'paid_at' => null,
        'company_fiscal_config_id' => $config->id,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$usd->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a proforma', function () {
    $p = Invoice::factory()->proforma()->create([
        'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$p->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a draft (not-payable status)', function () {
    $d = Invoice::factory()->draft()->create([
        'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$d->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects an amount that does not equal the sum of totals', function () {
    $a = makeSentInvoice(6000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5999), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects an invoice already covered by an active payment', function () {
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    $a       = makeSentInvoice(5000); // stays SENT — not paid, payable status

    DB::table('grouped_payment_invoice')->insert([
        'grouped_payment_id' => $payment->id,
        'invoice_id'         => $a->id,
        'applied_amount'     => 5000,
        'previous_status'    => InvoiceStatus::SENT->value,
        'previous_paid_at'   => null,
        'active_invoice_id'  => $a->id, // pivot marks this invoice as actively covered
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Invoice is still SENT (payable), so notPayableStatus is NOT thrown;
    // the alreadyActivelyPaid branch is reached and throws.
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class, 'already covered by an active grouped payment');

// D2: idempotency

it('returns the same posted payment when a provided key is replayed', function () {
    $a      = makeSentInvoice(5000);
    $svc    = app(GroupedPaymentService::class);
    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'idem-1');
    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'idem-1');
    expect($second->id)->toBe($first->id)->and(GroupedPayment::count())->toBe(1);
});

it('returns the same payment when the derived key matches', function () {
    $a      = makeSentInvoice(5000);
    $svc    = app(GroupedPaymentService::class);
    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR');
    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR');
    expect($second->id)->toBe($first->id)->and(GroupedPayment::count())->toBe(1);
});

it('ignores a differing reference on replay (reference is not identity)', function () {
    $a      = makeSentInvoice(5000);
    $svc    = app(GroupedPaymentService::class);
    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', reference: 'TRF-A', idempotencyKey: 'idem-2');
    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', reference: 'TRF-B', idempotencyKey: 'idem-2');
    expect($second->id)->toBe($first->id)->and($second->reference)->toBe('TRF-A');
});

it('throws on a reused posted key with a different payload', function () {
    $a   = makeSentInvoice(5000);
    $b   = makeSentInvoice(3000);
    $svc = app(GroupedPaymentService::class);
    $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'idem-3');
    $svc->register(TestCase::USER_UUID_2, [$b->id], now(), cents(3000), 'EUR', idempotencyKey: 'idem-3');
})->throws(IdempotencyConflictException::class);

// ---------------------------------------------------------------------------
// AID-1778 S6: register() accepts PARTIALLY_PAID, allocating exactly each
// invoice's amountOutstanding() (spec §3, tests §8.1 R1–R6b).
// ---------------------------------------------------------------------------

// R1
it('accepts a PARTIALLY_PAID invoice and allocates exactly its outstanding', function () {
    $a = makePartiallyPaidInvoice(10000, 4000);
    expect($a->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($a->amountOutstanding()->unscaledValue())->toBe(6000);

    $payment = app(GroupedPaymentService::class)->register(
        billableUserId: TestCase::USER_UUID_2, invoiceIds: [$a->id],
        paidAt: now(), amount: cents(6000), currency: 'EUR',
    );

    expect($payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($payment->amount->unscaledValue())->toBe(6000)
        ->and($payment->allocations)->toHaveCount(1)
        ->and($payment->allocations[0]->applied_amount->unscaledValue())->toBe(6000)
        ->and($payment->allocations[0]->active_invoice_id)->toBe($a->id)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($a->fresh()->amountOutstanding()->unscaledValue())->toBe(0);
});

// R2
it('refuses an amount that does not equal the under-lock sum of outstandings, like a total mismatch', function () {
    $a           = makePartiallyPaidInvoice(10000, 4000);
    $payments    = GroupedPayment::count();
    $allocations = DB::table('grouped_payment_invoice')->count();

    try {
        app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(10000), 'EUR');
        $this->fail('register() should have refused the amount mismatch');
    } catch (GroupedPaymentValidationException $e) {
        // Same exception type AND message shape a total mismatch raises today.
        expect($e->getMessage())->toContain('expected sum 6000, got 10000')
            ->and(GroupedPayment::count())->toBe($payments)
            ->and(DB::table('grouped_payment_invoice')->count())->toBe($allocations)
            ->and($a->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
            ->and($a->fresh()->amountOutstanding()->unscaledValue())->toBe(6000);
    }
});

// R3 (G4): the re-scoped guard still fires on every formerly payable status
// when an active allocation exists and the status was hand-stamped back.
it('keeps the alreadyActivelyPaid guard for a hand-stamped :dataset invoice with an active allocation', function (InvoiceStatus $status) {
    $a = makePartiallyPaidInvoice(10000, 4000);
    $a->forceFill(['status' => $status])->save();

    // Sum of outstandings (6000) MATCHES the amount — only the guard refuses.
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(6000), 'EUR');
})->throws(GroupedPaymentValidationException::class, 'already covered by an active grouped payment')
    ->with(['sent' => InvoiceStatus::SENT, 'overdue' => InvoiceStatus::OVERDUE, 'pending' => InvoiceStatus::PENDING]);

// R4a (J3 case 1): a PARTIAL receive() commits between register()'s two
// transactions — the declared NEW failure mode of the race.
it('surfaces a partial racing receive as AllocationExceedsInvoiceException with no partial write', function () {
    $a = makeSentInvoice(10000);

    $thrown = registerWithRacingReceive($a, 4000);

    expect($thrown)->toBeInstanceOf(AllocationExceedsInvoiceException::class)
        ->and($thrown->getMessage())->toContain('outstanding 6000')
        // No partial write: only the racing manual payment exists.
        ->and(GroupedPayment::count())->toBe(1)
        ->and(DB::table('grouped_payment_invoice')->count())->toBe(1)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID);
});

// R4b (J3 case 2): a FULLY-SETTLING receive() in the same window keeps the
// status refusal — byte-for-byte today's mode (status is checked before the
// outstanding in process()).
it('keeps the status refusal when a fully-settling receive races between the two transactions', function () {
    $a = makeSentInvoice(10000);

    $thrown = registerWithRacingReceive($a, 10000);

    expect($thrown)->toBeInstanceOf(GroupedPaymentValidationException::class)
        ->and($thrown->getMessage())->toContain('has status 2') // PAID — refused by the STATUS list
        ->and(GroupedPayment::count())->toBe(1)
        ->and(DB::table('grouped_payment_invoice')->count())->toBe(1)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::PAID);
});

// R5a (G5): a same-key replay with the same payload returns the original
// payment verbatim even though the invoice's outstanding changed between
// attempts — the replay path never reads invoices or outstandings.
it('returns the original payment on a same-key replay after the outstanding changed', function () {
    $a     = makeSentInvoice(10000);
    $svc   = app(GroupedPaymentService::class);
    $key   = 'aid1778-partial-replay';
    $first = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(10000), 'EUR', idempotencyKey: $key);

    // The outstanding changes between attempts: reverse the card allocation,
    // then allocate 4000 of the returned credit back onto the same invoice.
    app(PaymentService::class)->reverseAllocation((int) $first->allocations[0]->id, 'reprice');
    app(PaymentService::class)->allocate($first, new Allocation((string) $a->id, cents(4000)));

    expect($a->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($a->fresh()->amountOutstanding()->unscaledValue())->toBe(6000);

    $second = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(10000), 'EUR', idempotencyKey: $key);

    expect($second->id)->toBe($first->id)
        ->and(GroupedPayment::count())->toBe(1)
        // The replay moved no new money.
        ->and(DB::table('grouped_payment_invoice')->count())->toBe(2)
        ->and($a->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($a->fresh()->amountOutstanding()->unscaledValue())->toBe(6000);
});

// R5b (G5): same key, different payload → the same conflict a total mismatch
// of the key raises today.
it('throws a key conflict when a partially-paid registration key is reused with a different payload', function () {
    $a   = makePartiallyPaidInvoice(10000, 4000);
    $b   = makeSentInvoice(3000);
    $svc = app(GroupedPaymentService::class);

    $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(6000), 'EUR', idempotencyKey: 'idem-partial');
    $svc->register(TestCase::USER_UUID_2, [$b->id], now(), cents(3000), 'EUR', idempotencyKey: 'idem-partial');
})->throws(IdempotencyConflictException::class);

// R6: the derived key for wholly unpaid invoices stays 8.3.0's formula —
// sha256(billableUserId|ids|currency|amountCents) — because outstanding ===
// total when no active allocations exist (§3.5 proof, as a test).
it('derives the same idempotency key as 8.3.0 for wholly unpaid invoices', function () {
    $a = makeSentInvoice(6000);
    $b = makeSentInvoice(4000);
    expect($a->amountOutstanding()->unscaledValue())->toBe(6000)
        ->and($b->amountOutstanding()->unscaledValue())->toBe(4000);

    $ids = [(string) $a->id, (string) $b->id];
    sort($ids);
    $expected = hash('sha256', implode('|', [TestCase::USER_UUID_2, implode(',', $ids), 'EUR', '10000']));

    $payment = app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, $ids, now(), cents(10000), 'EUR');

    expect($payment->idempotency_key)->toBe($expected);
});

// R6b (H7): an invoice with an ACTIVE pivot whose status stays OUTSIDE the
// widened list is refused by the STATUS check, not waved through the
// re-scoped alreadyActivelyPaid guard.
it('refuses by STATUS an active-pivot invoice hand-stamped :dataset', function (InvoiceStatus $status) {
    $a = makePartiallyPaidInvoice(10000, 4000);
    $a->forceFill(['status' => $status])->save();

    try {
        app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(6000), 'EUR');
        $this->fail('register() should have refused the invoice by status');
    } catch (GroupedPaymentValidationException $e) {
        expect($e->getMessage())->toContain("has status {$status->value}")
            ->and(GroupedPayment::count())->toBe(1) // only the manual payment
            ->and(DB::table('grouped_payment_invoice')->count())->toBe(1)
            ->and($a->fresh()->status)->toBe($status);
    }
})->with(['paid' => InvoiceStatus::PAID, 'draft' => InvoiceStatus::DRAFT]);
