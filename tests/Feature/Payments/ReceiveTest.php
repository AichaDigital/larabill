<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

mutates(PaymentService::class);

/*
 * PaymentService::receive() (AID-962, spec §5.1) — flows 1-5 and 8 of the
 * wish list (§9), the §5.1.3 validation list, and the replay semantics.
 * Dates are pinned to the second: the datetime columns drop microseconds.
 */

function aid962Receive(
    FixedDecimal $amount,
    array $allocations,
    CreditMovementKind $creditKind = CreditMovementKind::SURPLUS,
    ?string $key = null,
    PaymentMethod $method = PaymentMethod::BankTransfer,
    ?DateTimeInterface $at = null,
    ?string $reference = 'TRF-1',
    string $currency = 'EUR',
    string $billable = TestCase::USER_UUID_2,
): GroupedPayment {
    return app(PaymentService::class)->receive(
        $billable, $amount, $currency, $at ?? now()->subDay()->startOfSecond(), $method, $reference,
        TestCase::USER_UUID_1, $allocations, $creditKind, $key,
    );
}

it('flow 1: one transfer pays one invoice', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $p   = app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(12100), 'EUR', $at = now()->subDay()->startOfSecond(), PaymentMethod::BankTransfer, 'TRF-1', TestCase::USER_UUID_1, [new Allocation($inv->id, cents(12100))]);

    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID)->and($inv->fresh()->paid_at->equalTo($at))->toBeTrue()
        ->and($p->creditMovements)->toHaveCount(0)->and($p->unallocatedAmount()->unscaledValue())->toBe(0)
        ->and($p->allocations->first()->previous_status)->toBe(InvoiceStatus::SENT->value);

    $row = $p->allocations->first();
    expect($p->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($p->method)->toBe(PaymentMethod::BankTransfer)
        ->and($p->recorded_by)->toBe(TestCase::USER_UUID_1)
        ->and($p->paid_at->equalTo($at))->toBeTrue()
        ->and($row->status)->toBe(AllocationStatus::ACTIVE)
        ->and($row->active_invoice_id)->toBe($inv->id)
        ->and($row->applied_at->equalTo($at))->toBeTrue()
        ->and($row->previous_paid_at)->toBeNull()
        ->and($p->relationLoaded('invoices'))->toBeTrue();
});

it('flow 2: one transfer pays two invoices in one transaction', function () {
    $a = PaymentFixtures::sentInvoice(6000);
    $b = PaymentFixtures::sentInvoice(4000);

    $p = aid962Receive(cents(10000), [new Allocation($a->id, cents(6000)), new Allocation($b->id, cents(4000))]);

    expect($a->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($p->allocations)->toHaveCount(2)
        ->and($p->creditMovements)->toHaveCount(0);
});

it('flow 2 atomicity: a CANCELLED second invoice leaves the first untouched and creates no payment', function () {
    $a = PaymentFixtures::sentInvoice(6000);
    $b = PaymentFixtures::sentInvoice(4000, overrides: ['status' => InvoiceStatus::CANCELLED]);

    expect(fn () => aid962Receive(cents(10000), [new Allocation($a->id, cents(6000)), new Allocation($b->id, cents(4000))]))
        ->toThrow(GroupedPaymentValidationException::class, 'has status');

    expect($a->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($a->fresh()->paid_at)->toBeNull()
        ->and(GroupedPayment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(CreditMovement::count())->toBe(0);
});

it('flow 3: a partial payment leaves PARTIALLY_PAID with the outstanding', function () {
    $inv = PaymentFixtures::sentInvoice(12100);

    $p = aid962Receive(cents(5000), [new Allocation($inv->id, cents(5000))]);

    $fresh = $inv->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($fresh->amountPaid()->unscaledValue())->toBe(5000)
        ->and($fresh->amountOutstanding()->unscaledValue())->toBe(7100)
        ->and($fresh->paid_at)->toBeNull()
        ->and($p->creditMovements)->toHaveCount(0);
});

it('flow 4: an overpayment leaves a SURPLUS entry', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = now()->subDays(2)->startOfSecond();

    $p = aid962Receive(cents(15000), [new Allocation($inv->id, cents(12100))], at: $at);

    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($p->creditMovements)->toHaveCount(1);

    $entry = $p->creditMovements->first();
    expect($entry->kind)->toBe(CreditMovementKind::SURPLUS)
        ->and($entry->amount->unscaledValue())->toBe(2900)
        ->and($entry->occurred_at->equalTo($at))->toBeTrue()
        ->and($entry->billable_user_id)->toBe($p->billable_user_id)
        ->and($entry->currency)->toBe($p->currency)
        ->and($entry->grouped_payment_id)->toBe($p->id)
        ->and($entry->allocation_id)->toBeNull()
        ->and($p->availableCredit()->unscaledValue())->toBe(2900)
        ->and($p->unallocatedAmount()->unscaledValue())->toBe(2900);
});

it('flow 5: a payment with no allocations is a TOP_UP', function () {
    $p = aid962Receive(cents(30000), [], CreditMovementKind::TOP_UP);

    expect($p->allocations)->toHaveCount(0)
        ->and($p->creditMovements)->toHaveCount(1)
        ->and($p->creditMovements->first()->kind)->toBe(CreditMovementKind::TOP_UP)
        ->and($p->creditMovements->first()->amount->unscaledValue())->toBe(30000);

    expect(fn () => app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [], now(), cents(30000), 'EUR'))
        ->toThrow(GroupedPaymentValidationException::class, 'at least one invoice');
});

dataset('receive-rejections', [
    'foreign invoice' => [function () {
        $inv = PaymentFixtures::sentInvoice(12100, TestCase::USER_UUID_3);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);
    }, GroupedPaymentValidationException::class, 'same billable_user_id'],
    'not issued (PREPARED)' => [function () {
        $proforma = Invoice::factory()->proforma()->create(['user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2]);
        $inv      = Invoice::factory()->prepared($proforma)->create([
            'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
            'total_amount' => cents(12100), 'is_immutable' => false,
        ]);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);
    }, GroupedPaymentValidationException::class, 'is not issued'],
    'proforma' => [function () {
        $inv = Invoice::factory()->proforma()->create([
            'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
            'total_amount' => cents(12100), 'is_immutable' => false,
        ]);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);
    }, GroupedPaymentValidationException::class, 'is a proforma'],
    'DRAFT' => [function () {
        $inv = Invoice::factory()->draft()->create([
            'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
            'total_amount' => cents(12100), 'is_immutable' => false,
        ]);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);
    }, GroupedPaymentValidationException::class, 'has status'],
    'CANCELLED' => [function () {
        $inv = PaymentFixtures::sentInvoice(12100, overrides: ['status' => InvoiceStatus::CANCELLED]);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);
    }, GroupedPaymentValidationException::class, 'has status'],
    'total <= 0' => [function () {
        $inv = PaymentFixtures::sentInvoice(0);

        return fn () => aid962Receive(cents(1000), [new Allocation($inv->id, cents(1000))]);
    }, GroupedPaymentValidationException::class, 'positive total'],
    'currency mismatch' => [function () {
        $config = CompanyFiscalConfig::factory()->create(['currency' => 'USD']);
        $inv    = PaymentFixtures::sentInvoice(12100, overrides: ['company_fiscal_config_id' => $config->id]);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);
    }, GroupedPaymentValidationException::class, 'does not match the payment currency'],
    'sum > amount' => [function () {
        $a = PaymentFixtures::sentInvoice(6000);
        $b = PaymentFixtures::sentInvoice(6000);

        return fn () => aid962Receive(cents(10000), [new Allocation($a->id, cents(6000)), new Allocation($b->id, cents(6000))]);
    }, AllocationExceedsPaymentException::class, 'requested 12000, available 10000'],
    'allocation > outstanding' => [function () {
        $inv = PaymentFixtures::sentInvoice(12100);

        return fn () => aid962Receive(cents(13000), [new Allocation($inv->id, cents(13000))]);
    }, AllocationExceedsInvoiceException::class, 'requested 13000, outstanding 12100'],
    'sub-cent amount' => [function () {
        return fn () => aid962Receive(FixedDecimal::ofUnscaled(12345, 3), [], CreditMovementKind::TOP_UP);
    }, GroupedPaymentValidationException::class, 'sub-cent'],
    'non-positive amount' => [function () {
        return fn () => aid962Receive(cents(0), [], CreditMovementKind::TOP_UP);
    }, GroupedPaymentValidationException::class, 'strictly positive'],
    'exit creditKind' => [function () {
        return fn () => aid962Receive(cents(1000), [], CreditMovementKind::APPLIED);
    }, GroupedPaymentValidationException::class, 'is not an entry'],
    'duplicate ids' => [function () {
        $inv = PaymentFixtures::sentInvoice(12100);

        return fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(6000)), new Allocation($inv->id, cents(6100))]);
    }, GroupedPaymentValidationException::class, 'duplicate ids'],
]);

it('rejects: foreign invoice, not issued (PREPARED), proforma, DRAFT, CANCELLED, total <= 0, currency mismatch, sum > amount, allocation > outstanding, sub-cent amount, non-positive amount, exit creditKind, duplicate ids', function (Closure $setup, string $exception, string $message) {
    $call = $setup();

    expect($call)->toThrow($exception, $message);

    expect(GroupedPayment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(CreditMovement::count())->toBe(0);
})->with('receive-rejections');

it('accepts an invoice whose fiscal config is null (currency parity with register)', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    expect($inv->company_fiscal_config_id)->toBeNull();

    // Any currency: a missing fiscal config is not a mismatch, exactly as register() (D3).
    $p = aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], currency: 'USD');

    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID)->and($p->currency)->toBe('USD');
});

it('writes collection state on an is_immutable invoice', function () {
    $inv = PaymentFixtures::sentInvoice(12100, overrides: ['is_immutable' => true]);
    expect($inv->fresh()->is_immutable)->toBeTrue();

    aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))]);

    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID)->and($inv->fresh()->paid_at)->not->toBeNull();
});

it('replays an explicit key exactly → same payment', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = now()->subDay()->startOfSecond();

    $first  = aid962Receive(cents(15000), [new Allocation($inv->id, cents(12100))], key: 'k-1', at: $at);
    $second = aid962Receive(cents(15000), [new Allocation($inv->id, cents(12100))], key: 'k-1', at: $at);

    expect($second->id)->toBe($first->id)
        ->and(GroupedPayment::count())->toBe(1)
        ->and(PaymentAllocation::count())->toBe(1)
        ->and(CreditMovement::count())->toBe(1)
        ->and($second->relationLoaded('allocations'))->toBeTrue()
        ->and($second->relationLoaded('creditMovements'))->toBeTrue();
});

it('replays a derived key exactly → same payment', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = now()->subDay()->startOfSecond();

    $first  = aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], at: $at);
    $second = aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], at: $at->copy()->setTimezone('Europe/Madrid'));

    expect($second->id)->toBe($first->id)->and(GroupedPayment::count())->toBe(1);
});

dataset('replay-variants', [
    'amount per invoice'                     => [['alloc' => 12000]],
    'method'                                 => [['method' => PaymentMethod::Cash]],
    'receivedAt'                             => [['shift' => 3600]],
    'reference'                              => [['reference' => 'TRF-OTHER']],
    'creditKind on a payment with remainder' => [['kind' => CreditMovementKind::TOP_UP]],
]);

it('rejects a replay that differs in one field', function (array $variant) {
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = now()->subDay()->startOfSecond();

    aid962Receive(cents(15000), [new Allocation($inv->id, cents(12100))], key: 'k-1', at: $at);

    expect(fn () => aid962Receive(
        cents(15000),
        [new Allocation($inv->id, cents($variant['alloc'] ?? 12100))],
        $variant['kind'] ?? CreditMovementKind::SURPLUS,
        'k-1',
        $variant['method'] ?? PaymentMethod::BankTransfer,
        $at->copy()->addSeconds($variant['shift'] ?? 0),
        $variant['reference'] ?? 'TRF-1',
    ))->toThrow(IdempotencyConflictException::class, 'different payload');

    expect(GroupedPayment::count())->toBe(1)->and(PaymentAllocation::count())->toBe(1)->and(CreditMovement::count())->toBe(1);
})->with('replay-variants');

it('treats a different creditKind as the same payload when there was no remainder', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = now()->subDay()->startOfSecond();

    $first  = aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], CreditMovementKind::SURPLUS, 'k-1', at: $at);
    $second = aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], CreditMovementKind::TOP_UP, 'k-1', at: $at);

    expect($second->id)->toBe($first->id)->and(CreditMovement::count())->toBe(0);
});

it('replays after one allocation was reversed → same payment, nothing created', function () {
    $a  = PaymentFixtures::sentInvoice(6000);
    $b  = PaymentFixtures::sentInvoice(4000);
    $at = now()->subDay()->startOfSecond();

    $first = aid962Receive(cents(10000), [new Allocation($a->id, cents(6000)), new Allocation($b->id, cents(4000))], key: 'k-1', at: $at);

    // reverseAllocation() lands in Task 8: mark the row REVERSED raw, as it would.
    DB::table('grouped_payment_invoice')
        ->where('grouped_payment_id', $first->id)->where('invoice_id', $a->id)
        ->update(['status' => AllocationStatus::REVERSED->value, 'active_invoice_id' => null, 'reversed_at' => now()]);

    $second = aid962Receive(cents(10000), [new Allocation($a->id, cents(6000)), new Allocation($b->id, cents(4000))], key: 'k-1', at: $at);

    expect($second->id)->toBe($first->id)
        ->and(GroupedPayment::count())->toBe(1)
        ->and(PaymentAllocation::count())->toBe(2)
        ->and(CreditMovement::count())->toBe(0);
});

it('throws keySpentByReversal on a reversed key', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $at  = now()->subDay()->startOfSecond();

    $first = aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], key: 'k-1', at: $at);
    DB::table('grouped_payments')->where('id', $first->id)->update(['status' => GroupedPaymentStatus::REVERSED->value, 'reversed_at' => now()]);

    expect(fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], key: 'k-1', at: $at))
        ->toThrow(IdempotencyConflictException::class, 'maps to a reversed payment');
});

it('turns a key-unique violation on insert into a controlled conflict, writing nothing', function () {
    $inv = PaymentFixtures::sentInvoice(12100);

    // Simulate losing the insert race: the unique key fires on the INSERT and
    // the re-read finds no matching POSTED row → forKey, never a raw QueryException.
    GroupedPayment::creating(function (): void {
        $pdo = new class('UNIQUE constraint failed: grouped_payments.idempotency_key') extends PDOException
        {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '23000'; // PDO reports SQLSTATE as a string
            }
        };

        throw new QueryException('testing', 'insert into "grouped_payments"', [], $pdo);
    });

    expect(fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], key: 'k-race'))
        ->toThrow(IdempotencyConflictException::class, "'k-race' was reused with a different payload");

    expect(GroupedPayment::count())->toBe(0)->and($inv->fresh()->status)->toBe(InvoiceStatus::SENT);
});

it('flow 8: register() records method Card and replays its legacy key with another paidAt/reference', function () {
    $a   = PaymentFixtures::sentInvoice(6000);
    $b   = PaymentFixtures::sentInvoice(4000);
    $svc = app(GroupedPaymentService::class);

    $first  = $svc->register(TestCase::USER_UUID_2, [$a->id, $b->id], now()->subDay(), cents(10000), 'EUR', 'TRF-A');
    $second = $svc->register(TestCase::USER_UUID_2, [$b->id, $a->id], now(), cents(10000), 'EUR', 'TRF-B');

    expect($second->id)->toBe($first->id)
        ->and($first->method)->toBe(PaymentMethod::Card)
        ->and($first->recorded_by)->toBeNull()
        ->and($first->allocations)->toHaveCount(2)
        ->and($first->creditMovements)->toHaveCount(0)
        ->and($second->reference)->toBe('TRF-A')
        ->and(GroupedPayment::count())->toBe(1)
        ->and(PaymentAllocation::count())->toBe(2);
});

it('register() rejects a PARTIALLY_PAID invoice with notPayableStatus', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    aid962Receive(cents(5000), [new Allocation($inv->id, cents(5000))]);
    expect($inv->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID);

    expect(fn () => app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$inv->id], now(), cents(12100), 'EUR'))
        ->toThrow(GroupedPaymentValidationException::class, 'has status '.InvoiceStatus::PARTIALLY_PAID->value);
});

/*
 * Final-review A1 (spec §2 anti-goal): register() checks eligibility in one
 * transaction and creates through process() in another. A partial receive()
 * committed in between leaves the invoice PARTIALLY_PAID; under
 * ReplayPolicy::Legacy process() must refuse it as notPayableStatus (the 8.2
 * contract), never as AllocationExceedsInvoiceException. SQLite has no row
 * locks, so the rival commit is simulated in-process from a once-hook on
 * register()'s last eligibility read (the actively-paid lookup), which runs
 * AFTER register() loaded the invoice as SENT and BEFORE process() locks it.
 */
function aid962ArmPartialPaymentBetweenRegisterAndProcess(Invoice $invoice): void
{
    $rival = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2, 'amount' => cents(1)]);
    $fired = false;

    DB::listen(function ($query) use (&$fired, $invoice, $rival): void {
        if ($fired || ! str_contains($query->sql, 'grouped_payment_invoice') || ! str_contains($query->sql, '"active_invoice_id" in')) {
            return;
        }
        $fired = true;

        DB::table('grouped_payment_invoice')->insert([
            'grouped_payment_id' => $rival->id,
            'invoice_id'         => $invoice->id,
            'applied_amount'     => 1,
            'status'             => AllocationStatus::ACTIVE->value,
            'active_invoice_id'  => $invoice->id,
            'previous_status'    => InvoiceStatus::SENT->value,
            'applied_at'         => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => InvoiceStatus::PARTIALLY_PAID->value]);
    });
}

it('register(): a partial receive() between its eligibility check and process() surfaces as notPayableStatus, never as an outstanding overflow', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    aid962ArmPartialPaymentBetweenRegisterAndProcess($inv);

    expect(fn () => app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$inv->id], now(), cents(12100), 'EUR'))
        ->toThrow(GroupedPaymentValidationException::class, "Invoice {$inv->id} has status ".InvoiceStatus::PARTIALLY_PAID->value);

    // The race really happened (the hook fired), and register() wrote nothing of its own.
    expect($inv->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and(GroupedPayment::count())->toBe(1)
        ->and(PaymentAllocation::count())->toBe(1);
});

it('receive() (Full) still pays a PARTIALLY_PAID invoice, and only an amount above the outstanding is AllocationExceedsInvoiceException', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    aid962Receive(cents(1), [new Allocation($inv->id, cents(1))], key: 'k-first-cent');
    expect($inv->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID);

    expect(fn () => aid962Receive(cents(12100), [new Allocation($inv->id, cents(12100))], key: 'k-over'))
        ->toThrow(AllocationExceedsInvoiceException::class, 'requested 12100, outstanding 12099');

    aid962Receive(cents(12099), [new Allocation($inv->id, cents(12099))], key: 'k-rest');

    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and(GroupedPayment::count())->toBe(2);
});

it('rejects a negative amount with nonPositiveAmount, writing nothing', function () {
    $inv = PaymentFixtures::sentInvoice(12100);

    expect(fn () => aid962Receive(cents(-100), [], CreditMovementKind::TOP_UP))
        ->toThrow(GroupedPaymentValidationException::class, 'Payment amount -100 (base-100) must be strictly positive.');

    expect(GroupedPayment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(CreditMovement::count())->toBe(0)
        ->and($inv->fresh()->status)->toBe(InvoiceStatus::SENT);
});
