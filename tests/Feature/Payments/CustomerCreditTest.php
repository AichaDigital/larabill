<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Support\Str;

mutates(CustomerCredit::class);

/*
 * CustomerCredit (AID-962, spec §5.7): the customer's credit balance per
 * currency, read FIFO across ALL of the customer's payments, applied to an
 * invoice (one allocation per source payment, one APPLIED exit per entry) or
 * refunded (one REFUNDED exit per entry).
 */

/**
 * A TOP_UP payment with no allocations: one TOP_UP entry of $cents.
 *
 * @param  array<int, Allocation>  $allocations
 */
function aid962TopUp(int $cents, DateTimeInterface $at, array $allocations = [], string $billable = TestCase::USER_UUID_2, string $currency = 'EUR'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        $billable, cents($cents), $currency, $at, PaymentMethod::BankTransfer, null, null, $allocations, CreditMovementKind::TOP_UP,
    );
}

/**
 * Flow 6 fixture. P1 (day 1) TOP_UP 1500; P2 (day 2) pays 1000 to Z and
 * leaves a TOP_UP 1400; reverseAllocation() of the Z row gives P2 a SURPLUS
 * 1000 with occurred_at = P2.paid_at and a later id.
 *
 * @return array{0: GroupedPayment, 1: GroupedPayment, 2: CreditMovement, 3: CreditMovement, 4: CreditMovement}
 */
function aid962ThreeEntries(): array
{
    $day1 = now()->subDays(3)->startOfSecond();
    $day2 = now()->subDays(2)->startOfSecond();

    $p1 = aid962TopUp(1500, $day1);
    $z  = PaymentFixtures::sentInvoice(1000);
    $p2 = aid962TopUp(2400, $day2, [new Allocation($z->id, cents(1000))]);
    app(PaymentService::class)->reverseAllocation($p2->allocations()->sole()->id, 'reassign');

    $e1      = $p1->creditMovements()->sole();
    $e2      = $p2->creditMovements()->where('kind', CreditMovementKind::TOP_UP->value)->sole();
    $surplus = $p2->creditMovements()->where('kind', CreditMovementKind::SURPLUS->value)->sole();

    return [$p1, $p2, $e1, $e2, $surplus];
}

function aid962AssertAllFunding(): void
{
    PaymentAllocation::all()->each(fn (PaymentAllocation $row) => aid962AssertFundingInvariant($row));
}

it('flow 6: applies credit FIFO across three entries of two payments', function () {
    [$p1, $p2, $e1, $e2, $surplus] = aid962ThreeEntries();
    expect($surplus->occurred_at->equalTo($p2->paid_at))->toBeTrue()
        ->and(strcmp((string) $surplus->id, (string) $e2->id))->toBeGreaterThan(0);

    // Noise that must not be touched: same customer in USD, another customer in EUR.
    $usd   = aid962TopUp(9900, now()->subDays(5)->startOfSecond(), currency: 'USD');
    $other = aid962TopUp(8800, now()->subDays(5)->startOfSecond(), billable: TestCase::USER_UUID_3);

    // X carries an EUR fiscal config: the currency of the apply is the invoice's.
    $x  = PaymentFixtures::sentInvoice(5000, overrides: ['company_fiscal_config_id' => CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id]);
    $at = now()->subHour()->startOfSecond();

    $rows = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $x, cents(3900), TestCase::USER_UUID_1, $at);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->grouped_payment_id)->toBe($p1->id)
        ->and($rows[0]->applied_amount->unscaledValue())->toBe(1500)
        ->and($rows[1]->grouped_payment_id)->toBe($p2->id)
        ->and($rows[1]->applied_amount->unscaledValue())->toBe(2400);

    foreach ($rows as $row) {
        expect($row->status)->toBe(AllocationStatus::ACTIVE)
            ->and($row->invoice_id)->toBe($x->id)
            ->and($row->active_invoice_id)->toBe($x->id)
            ->and($row->previous_status)->toBe(InvoiceStatus::SENT->value)
            ->and($row->applied_at->equalTo($at))->toBeTrue();
    }

    $exits = CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->orderBy('id')->get();
    expect($exits)->toHaveCount(3)
        ->and($exits->pluck('consumes_movement_id')->all())->toBe([$e1->id, $e2->id, $surplus->id])
        ->and($exits->map(fn (CreditMovement $m) => $m->amount->unscaledValue())->all())->toBe([-1500, -1400, -1000])
        ->and($exits->pluck('allocation_id')->all())->toBe([$rows[0]->id, $rows[1]->id, $rows[1]->id])
        ->and($exits->pluck('grouped_payment_id')->all())->toBe([$p1->id, $p2->id, $p2->id]);

    foreach ($exits as $exit) {
        expect($exit->billable_user_id)->toBe(TestCase::USER_UUID_2)
            ->and($exit->currency)->toBe('EUR')
            ->and($exit->recorded_by)->toBe(TestCase::USER_UUID_1)
            ->and($exit->occurred_at->equalTo($at))->toBeTrue();
    }

    $fresh = $x->fresh();
    expect(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(0)
        ->and($fresh->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($fresh->amountOutstanding()->unscaledValue())->toBe(1100)
        ->and($usd->availableCredit()->unscaledValue())->toBe(9900)
        ->and($other->availableCredit()->unscaledValue())->toBe(8800);

    aid962AssertAllFunding();
});

it('follows the real money date, not the posting order', function () {
    // Posted first, received later: the smaller id, the younger money.
    $late = aid962TopUp(1000, now()->subDay()->startOfSecond());
    $old  = aid962TopUp(1000, now()->subDays(10)->startOfSecond());
    expect(strcmp((string) $late->id, (string) $old->id))->toBeLessThan(0);

    $x    = PaymentFixtures::sentInvoice(5000);
    $rows = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $x, cents(600));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->grouped_payment_id)->toBe($old->id)
        ->and($old->availableCredit()->unscaledValue())->toBe(400)
        ->and($late->availableCredit()->unscaledValue())->toBe(1000);

    aid962AssertAllFunding();
});

it('rejects apply above the balance and above the outstanding', function () {
    aid962TopUp(1500, now()->subDays(2)->startOfSecond());
    aid962TopUp(1400, now()->subDay()->startOfSecond());   // balance 2900
    $credit = app(CustomerCredit::class);
    $big    = PaymentFixtures::sentInvoice(5000);
    $small  = PaymentFixtures::sentInvoice(2000);

    expect(fn () => $credit->apply(TestCase::USER_UUID_2, $big, cents(3000)))
        ->toThrow(InsufficientCreditException::class, 'requested 3000, available 2900');
    expect(fn () => $credit->apply(TestCase::USER_UUID_2, $small, cents(2500)))
        ->toThrow(AllocationExceedsInvoiceException::class, 'requested 2500, outstanding 2000');
    // An EUR invoice of a customer with no credit of their own: the EUR balance is 0.
    $theirs = PaymentFixtures::sentInvoice(5000, TestCase::USER_UUID_3, ['company_fiscal_config_id' => CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id]);
    expect(fn () => $credit->apply(TestCase::USER_UUID_3, $theirs, cents(1000)))
        ->toThrow(InsufficientCreditException::class, 'requested 1000, available 0');

    expect(PaymentAllocation::count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
        ->and($big->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($small->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(2900);
});

it('rejects apply on an invoice that is not payable by the customer (§5.1.3)', function () {
    aid962TopUp(2900, now()->subDay()->startOfSecond());
    $credit  = app(CustomerCredit::class);
    $foreign = PaymentFixtures::sentInvoice(5000, TestCase::USER_UUID_3);
    $usd     = PaymentFixtures::sentInvoice(5000, overrides: ['company_fiscal_config_id' => CompanyFiscalConfig::factory()->create(['currency' => 'USD'])->id]);

    expect(fn () => $credit->apply(TestCase::USER_UUID_2, $foreign, cents(1000)))->toThrow(GroupedPaymentValidationException::class);
    // The currency of an apply() is the invoice's: EUR credit never pays a USD invoice.
    expect(fn () => $credit->apply(TestCase::USER_UUID_2, $usd, cents(1000)))
        ->toThrow(InsufficientCreditException::class, 'insufficient USD credit: requested 1000, available 0');

    expect(PaymentAllocation::count())->toBe(0);
});

it('applies to an invoice with no fiscal currency from the only currency with credit, and refuses when ambiguous', function () {
    aid962TopUp(1000, now()->subDays(2)->startOfSecond(), currency: 'USD');
    $x = PaymentFixtures::sentInvoice(5000);   // null fiscal config (D3 parity)
    expect($x->company_fiscal_config_id)->toBeNull();

    $row = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $x, cents(400))->sole();
    expect($row->applied_amount->unscaledValue())->toBe(400)
        ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->balance()->unscaledValue())->toBe(600);

    aid962TopUp(1000, now()->subDay()->startOfSecond());   // now EUR credit too
    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $x, cents(100)))
        ->toThrow(InsufficientCreditException::class, 'holds credit in EUR, USD and the invoice has no fiscal currency');

    expect(PaymentAllocation::count())->toBe(1);
    aid962AssertAllFunding();
});

it('refuses an invoice with no fiscal currency for a customer with no credit in any currency', function () {
    $x = PaymentFixtures::sentInvoice(5000);
    $p = aid962TopUp(1000, now()->subDay()->startOfSecond());
    app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(1000), 'EUR', now(), PaymentMethod::BankTransfer);
    expect($p->availableCredit()->unscaledValue())->toBe(0);   // an entry with nothing left is no credit

    foreach ([TestCase::USER_UUID_2, TestCase::USER_UUID_3] as $customer) {
        $invoice = $customer === TestCase::USER_UUID_2 ? $x : PaymentFixtures::sentInvoice(5000, $customer);
        expect(fn () => app(CustomerCredit::class)->apply($customer, $invoice, cents(100)))
            ->toThrow(InsufficientCreditException::class, "User {$customer} holds no credit in any currency.");
    }

    expect(PaymentAllocation::count())->toBe(0);
});

it('runs the payability checks before deciding the currency of an invoice without fiscal config (§5.7 order)', function () {
    aid962TopUp(1000, now()->subDays(2)->startOfSecond(), currency: 'USD');
    aid962TopUp(1000, now()->subDay()->startOfSecond());   // credit in two currencies
    $foreign = PaymentFixtures::sentInvoice(5000, TestCase::USER_UUID_3);
    expect($foreign->company_fiscal_config_id)->toBeNull();

    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $foreign, cents(100)))
        ->toThrow(GroupedPaymentValidationException::class, GroupedPaymentValidationException::mixedUsers()->getMessage());

    $missing     = new Invoice;
    $missing->id = (string) Str::uuid7();   // never persisted
    expect(Invoice::query()->find($missing->id))->toBeNull();
    expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $missing, cents(100)))
        ->toThrow(GroupedPaymentValidationException::class, GroupedPaymentValidationException::invoicesNotFound([(string) $missing->id])->getMessage());

    expect(PaymentAllocation::count())->toBe(0);
});

it('flow 6b: refunds FIFO with one REFUNDED exit per entry and rejects when short', function () {
    [$p1, $p2, $e1, $e2, $surplus] = aid962ThreeEntries();   // balance 3900
    $credit                        = app(CustomerCredit::class);
    $at                            = now()->subHour()->startOfSecond();

    expect(fn () => $credit->refund(TestCase::USER_UUID_2, cents(4000), 'EUR', $at, PaymentMethod::BankTransfer))
        ->toThrow(InsufficientCreditException::class, 'requested 4000, available 3900');
    expect(CreditMovement::query()->where('kind', CreditMovementKind::REFUNDED->value)->count())->toBe(0);

    $exits = $credit->refund(TestCase::USER_UUID_2, cents(2000), 'EUR', $at, PaymentMethod::Cash, 'REF-9', TestCase::USER_UUID_1);

    expect($exits)->toHaveCount(2)
        ->and($exits->pluck('consumes_movement_id')->all())->toBe([$e1->id, $e2->id])
        ->and($exits->map(fn (CreditMovement $m) => $m->amount->unscaledValue())->all())->toBe([-1500, -500])
        ->and($exits->pluck('grouped_payment_id')->all())->toBe([$p1->id, $p2->id]);

    foreach ($exits as $exit) {
        expect($exit->kind)->toBe(CreditMovementKind::REFUNDED)
            ->and($exit->allocation_id)->toBeNull()
            ->and($exit->method)->toBe(PaymentMethod::Cash)
            ->and($exit->reference)->toBe('REF-9')
            ->and($exit->recorded_by)->toBe(TestCase::USER_UUID_1)
            ->and($exit->billable_user_id)->toBe(TestCase::USER_UUID_2)
            ->and($exit->currency)->toBe('EUR')
            ->and($exit->occurred_at->equalTo($at))->toBeTrue();
    }

    $rest = $credit->refund(TestCase::USER_UUID_2, cents(1900), 'EUR', $at, PaymentMethod::BankTransfer);
    expect($rest->pluck('consumes_movement_id')->all())->toBe([$e2->id, $surplus->id])
        ->and($rest->map(fn (CreditMovement $m) => $m->amount->unscaledValue())->all())->toBe([-900, -1000])
        ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(0);

    expect(fn () => $credit->refund(TestCase::USER_UUID_2, cents(1), 'EUR', $at, PaymentMethod::BankTransfer))
        ->toThrow(InsufficientCreditException::class, 'requested 1, available 0');

    aid962AssertAllFunding();
});

it('reports balance, outstanding (rectificatives with sign) and position', function () {
    $eur = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
    $usd = CompanyFiscalConfig::factory()->create(['currency' => 'USD']);

    // B 12100 PARTIALLY_PAID with 5000 paid; the 7900 transfer leaves a SURPLUS 2900.
    $b = PaymentFixtures::sentInvoice(12100, overrides: ['company_fiscal_config_id' => $eur->id]);
    app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents(7900), 'EUR', now()->subDay()->startOfSecond(), PaymentMethod::BankTransfer, null, null,
        [new Allocation($b->id, cents(5000))],
    );
    // A rectificative of an earlier invoice: total stored negative (ADR-014), never payable.
    $original = PaymentFixtures::sentInvoice(4000, overrides: ['status' => InvoiceStatus::PAID->value]);
    PaymentFixtures::sentInvoice(-2000, overrides: ['rectifies_invoice_id' => $original->id, 'serie' => InvoiceSerieType::RECTIFICATIVE->value, 'company_fiscal_config_id' => $eur->id]);

    // Not open or not this customer/currency: none of these counts.
    PaymentFixtures::sentInvoice(3000, overrides: ['company_fiscal_config_id' => $usd->id]);
    PaymentFixtures::sentInvoice(3000, TestCase::USER_UUID_3);
    Invoice::factory()->proforma()->create(['billable_user_id' => TestCase::USER_UUID_2, 'user_id' => TestCase::USER_UUID_1, 'total_amount' => cents(3000)]);
    PaymentFixtures::sentInvoice(3000, overrides: ['status' => InvoiceStatus::CANCELLED->value]);
    $proforma = Invoice::factory()->proforma()->create(['billable_user_id' => TestCase::USER_UUID_3, 'user_id' => TestCase::USER_UUID_1]);
    Invoice::factory()->prepared($proforma)->create(['billable_user_id' => TestCase::USER_UUID_2, 'user_id' => TestCase::USER_UUID_1, 'total_amount' => cents(3000), 'status' => InvoiceStatus::SENT->value]);

    $credit = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR');

    expect($b->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($credit->movements())->toHaveCount(1)
        ->and($credit->movements()->first()->kind)->toBe(CreditMovementKind::SURPLUS)
        ->and($credit->balance()->unscaledValue())->toBe(2900)
        ->and($credit->outstanding()->unscaledValue())->toBe(5100)
        ->and($credit->position()->unscaledValue())->toBe(-2200)
        ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->outstanding()->unscaledValue())->toBe(3000)
        ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->balance()->unscaledValue())->toBe(0);
});

it('lists the active movements in FIFO order, reversed ones excluded', function () {
    [, , $e1, $e2, $surplus] = aid962ThreeEntries();
    $x                       = PaymentFixtures::sentInvoice(5000);
    $row                     = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $x, cents(500))->sole();
    app(PaymentService::class)->reverseAllocation($row->id, 'undo');

    $ids = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->movements()->pluck('id')->all();

    expect($ids)->toBe([$e1->id, $e2->id, $surplus->id]);
});

it('throws LogicException on the read methods of an unbound instance', function (string $method) {
    expect(fn () => app(CustomerCredit::class)->{$method}())->toThrow(LogicException::class, 'not bound');
})->with(['movements', 'balance', 'outstanding', 'position']);

it('rejects a non-positive or sub-cent amount in apply and refund', function (FixedDecimal $amount, string $message) {
    aid962TopUp(2900, now()->subDay()->startOfSecond());
    $credit = app(CustomerCredit::class);
    $x      = PaymentFixtures::sentInvoice(5000);

    expect(fn () => $credit->apply(TestCase::USER_UUID_2, $x, $amount))->toThrow(GroupedPaymentValidationException::class, $message);
    expect(fn () => $credit->refund(TestCase::USER_UUID_2, $amount, 'EUR', now(), PaymentMethod::BankTransfer))
        ->toThrow(GroupedPaymentValidationException::class, $message);

    expect(PaymentAllocation::count())->toBe(0)
        ->and(CreditMovement::query()->whereIn('kind', [CreditMovementKind::APPLIED->value, CreditMovementKind::REFUNDED->value])->count())->toBe(0);
})->with([
    'zero'     => [fn () => cents(0), 'strictly positive'],
    'negative' => [fn () => cents(-100), 'strictly positive'],
    'sub-cent' => [fn () => FixedDecimal::ofUnscaled(12345, 3), 'sub-cent'],
]);
