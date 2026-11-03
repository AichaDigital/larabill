<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\VoucherSaleClaim;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Support\Str;

mutates(PaymentService::class, CustomerCredit::class);

/*
 * AID-1825 minimal scope §1a rule 2 — no collection by another path, enforced
 * on the ALLOCATION WRITERS (round 14 B1): PaymentService::process() (shared
 * by receive() and GroupedPaymentService::register()), CustomerCredit::apply()
 * and PaymentService::reverseAllocation(), plus allocate(). An invoice that
 * carries `sale_claim_id` is a voucher-sale invoice: its only collection is
 * the one S3b writes. Invoices WITHOUT a claim keep the 9.1 behaviour — each
 * refusal has its 9.1 twin on the same fixture without the claim.
 *
 * Also here: the S1 recording mode — a payment recorded with no allocation
 * and NO credit entry («recorded, not spendable»), which today's receive()
 * cannot express (it always creates the remainder entry).
 */

/** A POSTED payment of $cents with one consumable TOP_UP entry (9.1 shape). */
function aid1825Payment(int $cents, string $billable = TestCase::USER_UUID_2): GroupedPayment
{
    return app(PaymentService::class)->receive(
        $billable, cents($cents), 'EUR', now()->subDay(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
}

/**
 * An issued, SENT invoice of $cents that carries a sale claim at $stage —
 * the shape of a voucher-sale invoice between S2 and S3b. The claim is
 * written directly (sellVoucher() writes it in tranche B); the rule must hold
 * for the shape whoever writes it.
 *
 * @return array{0: Invoice, 1: VoucherSaleClaim, 2: GroupedPayment}
 */
function aid1825ClaimedInvoice(int $cents, VoucherSaleStage $stage = VoucherSaleStage::Issuing, string $billable = TestCase::USER_UUID_2): array
{
    $proforma = Invoice::factory()->proforma()->create(['billable_user_id' => $billable, 'user_id' => TestCase::USER_UUID_1, 'total_amount' => cents($cents)]);
    $payment  = aid1825Payment($cents, $billable);
    $invoice  = PaymentFixtures::sentInvoice($cents, $billable);

    $claim = VoucherSaleClaim::query()->create([
        'proforma_id'               => $proforma->id,
        'grouped_payment_id'        => $payment->id,
        'operation_key'             => (string) Str::uuid(),
        'stage'                     => $stage,
        'expected_amount'           => cents($cents),
        'expected_currency'         => 'EUR',
        'expected_billable_user_id' => $billable,
    ]);

    Invoice::query()->whereKey($invoice->id)->update(['sale_claim_id' => $claim->id]);

    return [$invoice->refresh(), $claim, $payment];
}

describe('rule 2 — process()', function () {
    it('9.1: receive() allocates a payment to an unclaimed sent invoice', function () {
        $z = PaymentFixtures::sentInvoice(600);

        app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(600), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [new Allocation($z->id, cents(600))]);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(600);
    });

    it('receive() refuses to allocate to a claimed sale invoice, writing nothing', function () {
        [$z] = aid1825ClaimedInvoice(600);

        expect(fn () => app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(600), 'EUR', now(), PaymentMethod::BankTransfer, null, 'k-rule2', [new Allocation($z->id, cents(600))]))
            ->toThrow(SaleClaimedException::class);

        expect(GroupedPayment::query()->where('reference', 'k-rule2')->exists())->toBeFalse()
            ->and($z->fresh()->amountPaid()->unscaledValue())->toBe(0);
    });

    it('register() (the card path, through process() under the Legacy policy) refuses a claimed sale invoice', function () {
        [$z] = aid1825ClaimedInvoice(600);

        expect(fn () => app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$z->id], now(), cents(600), 'EUR', 'card-ref', (string) Str::uuid()))
            ->toThrow(SaleClaimedException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0)
            ->and(PaymentAllocation::query()->where('invoice_id', $z->id)->count())->toBe(0);
    });

    it('a deprecated setter making the claimed sale PENDING does not open it to process()', function () {
        [$z] = aid1825ClaimedInvoice(600);
        $z->restoreStateViaGroupedPaymentReversal(InvoiceStatus::PENDING, null);

        expect(fn () => app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(600), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [new Allocation($z->id, cents(600))]))
            ->toThrow(SaleClaimedException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0);
    });
});

describe('rule 2 — apply()', function () {
    it('9.1: legacy credit applies to an unclaimed sent invoice', function () {
        aid1825Payment(1000);
        $z = PaymentFixtures::sentInvoice(600);

        app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(600));

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(600);
    });

    it('legacy credit is never applied TO a claimed sale invoice', function () {
        aid1825Payment(1000);
        [$z] = aid1825ClaimedInvoice(600);

        expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(600)))
            ->toThrow(SaleClaimedException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0);
    });
});

describe('rule 2 — allocate()', function () {
    it('refuses to allocate a payment\'s credit to a claimed sale invoice', function () {
        $p   = aid1825Payment(1000);
        [$z] = aid1825ClaimedInvoice(600);

        expect(fn () => app(PaymentService::class)->allocate($p, new Allocation($z->id, cents(600))))
            ->toThrow(SaleClaimedException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0);
    });
});

describe('rule 2 — reverseAllocation()', function () {
    it('9.1: reverses a direct allocation of an unclaimed invoice into a SURPLUS entry', function () {
        $z = PaymentFixtures::sentInvoice(600);
        $p = app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(600), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [new Allocation($z->id, cents(600))]);

        app(PaymentService::class)->reverseAllocation($p->allocations()->sole()->id, 'reassign');

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0)
            ->and($p->creditMovements()->where('kind', CreditMovementKind::SURPLUS->value)->count())->toBe(1);
    });

    it('refuses to reverse the sale allocation of a claimed sale invoice (it would mint a SURPLUS from the same money)', function () {
        // The S3b shape, written directly: the claimed payment allocated in full to the sale invoice.
        [$z, $claim, $p] = aid1825ClaimedInvoice(600, VoucherSaleStage::Backed);
        $row             = PaymentAllocation::query()->create([
            'grouped_payment_id' => $p->id,
            'invoice_id'         => $z->id,
            'applied_amount'     => cents(600),
            'status'             => AllocationStatus::ACTIVE,
            'applied_at'         => $p->paid_at,
            'active_invoice_id'  => $z->id,
            'previous_status'    => InvoiceStatus::SENT->value,
            'previous_paid_at'   => null,
        ]);

        expect(fn () => app(PaymentService::class)->reverseAllocation($row->id, 'oops'))
            ->toThrow(SaleClaimedException::class);

        expect($row->fresh()->isActive())->toBeTrue()
            ->and($p->creditMovements()->where('kind', CreditMovementKind::SURPLUS->value)->count())->toBe(0);
    });
});

describe('S1 recording mode — recorded, not spendable', function () {
    it('records a POSTED payment with no allocation and NO credit entry, leaving balance() unchanged', function () {
        $request = ReceiveRequest::forVoucherSale(
            TestCase::USER_UUID_2, cents(1000), 'EUR', now()->subMinute(), PaymentMethod::Card, 'pi_123', null, 'sale-key-1',
        );

        $payment = app(PaymentService::class)->process($request, ReplayPolicy::Full);

        expect($payment->isPosted())->toBeTrue()
            ->and($payment->amount->unscaledValue())->toBe(1000)
            ->and($payment->allocations()->count())->toBe(0)
            ->and($payment->creditMovements()->count())->toBe(0)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(0)
            ->and($payment->availableCredit()->unscaledValue())->toBe(0);
    });

    it('replays the same key to the same payment and still creates no entry', function () {
        $request = ReceiveRequest::forVoucherSale(TestCase::USER_UUID_2, cents(1000), 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_123', null, 'sale-key-2');

        $first  = app(PaymentService::class)->process($request, ReplayPolicy::Full);
        $second = app(PaymentService::class)->process($request, ReplayPolicy::Full);

        expect((string) $second->id)->toBe((string) $first->id)
            ->and(GroupedPayment::query()->count())->toBe(1)
            ->and(CreditMovement::query()->count())->toBe(0);
    });

    it('nothing can consume or allocate the recorded money before S3b', function () {
        $request = ReceiveRequest::forVoucherSale(TestCase::USER_UUID_2, cents(1000), 'EUR', now()->subMinute(), PaymentMethod::Card, 'pi_123', null, 'sale-key-3');
        $payment = app(PaymentService::class)->process($request, ReplayPolicy::Full);
        $z       = PaymentFixtures::sentInvoice(100);

        expect(fn () => app(PaymentService::class)->allocate($payment, new Allocation($z->id, cents(100))))
            ->toThrow(AllocationExceedsPaymentException::class);
        expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $z, cents(100)))
            ->toThrow(InsufficientCreditException::class);
        expect(fn () => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(1), 'EUR', now(), PaymentMethod::BankTransfer))
            ->toThrow(InsufficientCreditException::class);

        expect($z->fresh()->amountPaid()->unscaledValue())->toBe(0);
    });
});
