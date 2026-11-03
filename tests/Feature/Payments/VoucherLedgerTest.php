<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

mutates(CustomerCredit::class, PaymentService::class);

/*
 * AID-1825 minimal scope §1 item 4 — the voucher filter. An entry backed by
 * a voucher-sale invoice (`sale_invoice_id`) is balance contracted to consume
 * services: it is never selected as a payment (apply(), allocate()), never
 * refunded (refund()), never counted when the currency of a payment is
 * inferred (inferCurrency()), and never linked to an advance. Entries
 * WITHOUT `sale_invoice_id` keep the 9.1 behaviour byte for byte — every
 * test below runs its 9.1 twin on the same fixture shape without the voucher.
 */

/** A TOP_UP payment of $cents with no allocation: one consumable TOP_UP entry. */
function aid1825TopUp(int $cents, DateTimeInterface $at, string $billable = TestCase::USER_UUID_2, string $currency = 'EUR'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        $billable, cents($cents), $currency, $at, PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
}

/**
 * The S3b shape of a backed voucher sale, built directly on the ledger: a
 * POSTED payment of $cents whose single TOP_UP entry carries
 * `sale_invoice_id` = an issued, PAID voucher-sale invoice of the customer.
 * (sellVoucher() builds this in tranche B; the ledger rules must hold for
 * the shape whoever writes it.)
 *
 * @return array{0: GroupedPayment, 1: CreditMovement, 2: Invoice}
 */
function aid1825BackedVoucher(int $cents, DateTimeInterface $at, string $billable = TestCase::USER_UUID_2, string $currency = 'EUR'): array
{
    $payment = aid1825TopUp($cents, $at, $billable, $currency);
    $entry   = $payment->creditMovements()->sole();
    $sale    = PaymentFixtures::sentInvoice($cents, $billable, ['status' => InvoiceStatus::PAID->value, 'paid_at' => $at]);

    CreditMovement::query()->whereKey($entry->id)->update(['sale_invoice_id' => $sale->id]);

    return [$payment, $entry->refresh(), $sale];
}

describe('apply()', function () {
    it('9.1: applies a consumable TOP_UP entry to a sent invoice', function () {
        aid1825TopUp(1000, now()->subDay());
        $z = PaymentFixtures::sentInvoice(600);

        $rows = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(600));

        expect($rows)->toHaveCount(1)
            ->and($z->fresh()->amountPaid()->unscaledValue())->toBe(600);
    });

    it('never selects a voucher-backed entry as a payment', function () {
        aid1825BackedVoucher(1000, now()->subDay());
        $z = PaymentFixtures::sentInvoice(600);

        expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(600)))
            ->toThrow(InsufficientCreditException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0);
    });

    it('applies the consumable part only when consumable and voucher entries coexist', function () {
        aid1825BackedVoucher(1000, now()->subDays(2));
        aid1825TopUp(300, now()->subDay());
        $z = PaymentFixtures::sentInvoice(600);

        expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(400)))
            ->toThrow(InsufficientCreditException::class);

        $rows = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(300));

        expect($rows)->toHaveCount(1)
            ->and($z->fresh()->amountPaid()->unscaledValue())->toBe(300)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->whereNotNull('sale_invoice_id')->count())->toBe(0);
    });
});

describe('inferCurrency()', function () {
    /** An issued SENT invoice with NO fiscal config (D3 parity), so apply() infers the currency from the credit. */
    function aid1825InvoiceWithoutCurrency(int $cents): Invoice
    {
        $invoice = PaymentFixtures::sentInvoice($cents);
        expect($invoice->company_fiscal_config_id)->toBeNull();

        return $invoice;
    }

    it('9.1: a customer with consumable EUR credit only applies it to an invoice without fiscal currency', function () {
        aid1825TopUp(1000, now()->subDay());
        $z = aid1825InvoiceWithoutCurrency(600);

        app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(600));

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(600);
    });

    it('ignores a voucher in another currency when inferring the currency of a payment', function () {
        aid1825TopUp(1000, now()->subDays(2));                       // consumable EUR
        aid1825BackedVoucher(5000, now()->subDay(), currency: 'USD'); // voucher USD — must not make the currency ambiguous
        $z = aid1825InvoiceWithoutCurrency(600);

        app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(600));

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(600);
    });
});

describe('refund()', function () {
    it('9.1: refunds a consumable TOP_UP entry', function () {
        aid1825TopUp(1000, now()->subDay());

        $exits = app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(400), 'EUR', now(), PaymentMethod::BankTransfer);

        expect($exits)->toHaveCount(1)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(600);
    });

    it('never refunds voucher-backed balance (no ordinary refund of a voucher in this version)', function () {
        aid1825BackedVoucher(1000, now()->subDay());

        expect(fn () => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(1), 'EUR', now(), PaymentMethod::BankTransfer))
            ->toThrow(InsufficientCreditException::class);

        expect(CreditMovement::query()->where('kind', CreditMovementKind::REFUNDED->value)->count())->toBe(0)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(1000);
    });
});

describe('allocate()', function () {
    it('9.1: funds a new allocation from the payment\'s consumable entry', function () {
        $p = aid1825TopUp(1000, now()->subDay());
        $z = PaymentFixtures::sentInvoice(600);

        app(PaymentService::class)->allocate($p, new Allocation($z->id, cents(600)));

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(600)
            ->and($p->availableCredit()->unscaledValue())->toBe(400);
    });

    it('never funds an allocation from a voucher-backed entry, and availableCredit() excludes it', function () {
        [$p] = aid1825BackedVoucher(1000, now()->subDay());
        $z   = PaymentFixtures::sentInvoice(600);

        expect($p->availableCredit()->unscaledValue())->toBe(0);

        expect(fn () => app(PaymentService::class)->allocate($p, new Allocation($z->id, cents(1))))
            ->toThrow(AllocationExceedsPaymentException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0);
    });
});

describe('breakdown()', function () {
    it('9.1: a database without vouchers reports the same consumable and advance-backed figures and a zero voucher part', function () {
        aid1825TopUp(1000, now()->subDay());
        $credit    = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR');
        $breakdown = $credit->breakdown();

        expect($breakdown->consumable->unscaledValue())->toBe(1000)
            ->and($breakdown->advanceBacked->unscaledValue())->toBe(0)
            ->and($breakdown->voucherBacked->unscaledValue())->toBe(0)
            ->and($breakdown->total()->unscaledValue())->toBe($credit->balance()->unscaledValue());
    });

    it('reports voucher-backed balance apart, and consumable + advance-backed + voucher-backed equals balance()', function () {
        aid1825TopUp(300, now()->subDays(2));
        aid1825BackedVoucher(1000, now()->subDay());
        $credit    = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR');
        $breakdown = $credit->breakdown();

        expect($breakdown->consumable->unscaledValue())->toBe(300)
            ->and($breakdown->advanceBacked->unscaledValue())->toBe(0)
            ->and($breakdown->voucherBacked->unscaledValue())->toBe(1000)
            ->and($breakdown->consumable->plus($breakdown->advanceBacked)->plus($breakdown->voucherBacked)->unscaledValue())->toBe($credit->balance()->unscaledValue())
            ->and($breakdown->total()->unscaledValue())->toBe(1300);
    });
});

describe('linkAdvanceInvoice()', function () {
    it('9.1: links an unnumbered DRAFT advance to a consumable entry', function () {
        $p       = aid1825TopUp(1000, now()->subDay());
        $advance = Invoice::factory()->unissuedDraft()->create(['billable_user_id' => TestCase::USER_UUID_2, 'user_id' => TestCase::USER_UUID_1]);

        $p->creditMovements()->sole()->linkAdvanceInvoice($advance);

        expect($p->creditMovements()->sole()->advance_invoice_id)->toBe((string) $advance->id);
    });

    it('refuses to link an advance to a voucher-backed entry, writing nothing', function () {
        [, $entry] = aid1825BackedVoucher(1000, now()->subDay());
        $advance   = Invoice::factory()->unissuedDraft()->create(['billable_user_id' => TestCase::USER_UUID_2, 'user_id' => TestCase::USER_UUID_1]);

        expect(fn () => $entry->linkAdvanceInvoice($advance))->toThrow(InvalidCreditMovementException::class);

        expect($entry->fresh()->advance_invoice_id)->toBeNull();
    });
});

describe('refund() with mixed balance (the plan() filter proven on its own)', function () {
    it('refunds the consumable part and never a cent of the voucher that shares the customer and currency', function () {
        aid1825BackedVoucher(1000, now()->subDays(2));
        aid1825TopUp(300, now()->subDay());

        expect(fn () => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(301), 'EUR', now(), PaymentMethod::BankTransfer))
            ->toThrow(InsufficientCreditException::class);

        $exits = app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(300), 'EUR', now(), PaymentMethod::BankTransfer);

        expect($exits)->toHaveCount(1)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown()->voucherBacked->unscaledValue())->toBe(1000)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown()->consumable->unscaledValue())->toBe(0);
    });
});
