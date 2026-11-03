<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\VoucherSaleClaim;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;

mutates(VoucherSaleService::class);

/*
 * AID-1825 minimal scope §1 — the voucher sale: accepted proforma → exact
 * payment → voucher-sale invoice → balance. One payment settles the invoice
 * AND gives rise to the voucher entry (operator B5, 19:32): after SOLD on a
 * 100 € proforma the payment has ONE active allocation of 100 € to the sale
 * invoice, the invoice reads PAID with 100 € collected and 0 € outstanding,
 * and exactly ONE voucher entry of 100 € is linked to that payment and that
 * invoice. Money that is not a sale is refused with a named outcome and zero
 * writes (§2).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

/** An accepted (SENT) top-up proforma of the customer with one line; returns it with its exact total in cents. */
function aid1825Proforma(string $customerId, int $basePriceCents = 10000): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => $basePriceCents]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

function aid1825SaleRequest(Invoice $proforma, string $customerId, ?FixedDecimal $amount = null, string $currency = 'EUR', string $key = 'sale-1'): VoucherSaleRequest
{
    return new VoucherSaleRequest(
        proformaId: (string) $proforma->id,
        billableUserId: $customerId,
        amount: $amount ?? $proforma->total_amount,
        currency: $currency,
        receivedAt: now()->subMinute()->startOfSecond(),
        method: PaymentMethod::Card,
        reference: 'pi_'.$key,
        recordedBy: null,
        operationKey: $key,
    );
}

describe('SOLD — one payment, one collection, one entry (test 4)', function () {
    it('sells the voucher: invoice issued and PAID by one allocation of the full payment, one voucher entry linked to both', function () {
        $proforma = aid1825Proforma($this->customer->id);
        $total    = $proforma->total_amount->unscaledValue();
        expect($total)->toBeGreaterThan(0);

        $result = app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id));

        expect($result->outcome)->toBe(VoucherSaleOutcome::Sold);

        $invoice = $result->invoice->fresh();
        $payment = $result->payment->fresh();
        $entry   = $result->entry->fresh();
        $claim   = $invoice->saleClaim;

        // The invoice: numbered, ISSUED, PAID with paid_at = the payment's paid_at, 100 % collected.
        expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
            ->and($invoice->fiscal_number)->not->toBeNull()
            ->and($invoice->status)->toBe(InvoiceStatus::PAID)
            ->and($invoice->paid_at?->equalTo($payment->paid_at))->toBeTrue()
            ->and($invoice->amountPaid()->unscaledValue())->toBe($total)
            ->and($invoice->amountOutstanding()->unscaledValue())->toBe(0)
            ->and((string) $invoice->sale_claim_id)->toBe((string) $claim->id);

        // The payment: POSTED, exactly ONE active allocation, to the sale invoice, for the full amount.
        $allocations = PaymentAllocation::query()->where('invoice_id', $invoice->id)->get();
        expect($payment->isPosted())->toBeTrue()
            ->and($allocations)->toHaveCount(1)
            ->and($allocations->sole()->status)->toBe(AllocationStatus::ACTIVE)
            ->and((string) $allocations->sole()->grouped_payment_id)->toBe((string) $payment->id)
            ->and($allocations->sole()->applied_amount->unscaledValue())->toBe($total)
            ->and($payment->unallocatedAmount()->unscaledValue())->toBe(0)
            ->and($payment->availableCredit()->unscaledValue())->toBe(0); // the voucher is not available credit of the payment

        // The entry: exactly ONE, TOP_UP, linked to the payment and to the sale invoice, worth the full amount.
        expect(CreditMovement::query()->count())->toBe(1)
            ->and($entry->kind)->toBe(CreditMovementKind::TOP_UP)
            ->and($entry->amount->unscaledValue())->toBe($total)
            ->and((string) $entry->grouped_payment_id)->toBe((string) $payment->id)
            ->and((string) $entry->sale_invoice_id)->toBe((string) $invoice->id)
            ->and($entry->advance_invoice_id)->toBeNull();

        // The balance: the voucher part only.
        $breakdown = CustomerCredit::for($this->customer->id, 'EUR')->breakdown();
        expect($breakdown->voucherBacked->unscaledValue())->toBe($total)
            ->and($breakdown->consumable->unscaledValue())->toBe(0)
            ->and(CustomerCredit::for($this->customer->id, 'EUR')->balance()->unscaledValue())->toBe($total);

        // The claim: backed, marked issued-by-sale, frozen expectation kept; the proforma CONVERTED.
        expect($claim->stage)->toBe(VoucherSaleStage::Backed)
            ->and($claim->issued_by_sale)->toBeTrue()
            ->and($claim->expected_amount->unscaledValue())->toBe($total)
            ->and((string) $claim->grouped_payment_id)->toBe((string) $payment->id)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED);
    });
});

describe('idempotency by key (test 6)', function () {
    it('returns the same invoice on a same-key re-call, never a second allocation or entry', function () {
        $proforma = aid1825Proforma($this->customer->id);
        $request  = aid1825SaleRequest($proforma, $this->customer->id);

        $first  = app(VoucherSaleService::class)->sellVoucher($request);
        $second = app(VoucherSaleService::class)->sellVoucher($request);

        expect($second->outcome)->toBe(VoucherSaleOutcome::Sold)
            ->and((string) $second->invoice->id)->toBe((string) $first->invoice->id)
            ->and(GroupedPayment::query()->count())->toBe(1)
            ->and(PaymentAllocation::query()->count())->toBe(1)
            ->and(CreditMovement::query()->count())->toBe(1)
            ->and(Invoice::query()->where('serie', '!=', 0)->count())->toBe(1); // one fiscal invoice
    });

    it('refuses the same key with another payload', function () {
        $proforma = aid1825Proforma($this->customer->id);
        app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id));

        $other = aid1825Proforma($this->customer->id, 5000);

        expect(fn () => app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($other, $this->customer->id)))
            ->toThrow(IdempotencyConflictException::class);

        expect(GroupedPayment::query()->count())->toBe(1)
            ->and(VoucherSaleClaim::query()->count())->toBe(1);
    });
});

describe('money that is not a sale is refused with a named outcome and zero writes (test 7)', function () {
    it('refuses an amount different from the proforma total', function () {
        $proforma = aid1825Proforma($this->customer->id);
        $wrong    = $proforma->total_amount->plus(cents(1));

        $result = app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id, $wrong));

        expect($result->outcome)->toBe(VoucherSaleOutcome::AmountMismatch)
            ->and($result->invoice)->toBeNull()
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0)
            ->and(CreditMovement::query()->count())->toBe(0)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::SENT);
    });

    it('refuses a currency different from the proforma\'s', function () {
        $proforma = aid1825Proforma($this->customer->id);

        $result = app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id, currency: 'USD'));

        expect($result->outcome)->toBe(VoucherSaleOutcome::CurrencyMismatch)
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    });

    it('refuses a cancelled proforma', function () {
        $proforma = aid1825Proforma($this->customer->id);
        $proforma->update(['status' => InvoiceStatus::CANCELLED->value]);

        $result = app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id));

        expect($result->outcome)->toBe(VoucherSaleOutcome::ProformaNotInForce)
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    });

    it('refuses a second key on an already claimed proforma as a duplicate', function () {
        $proforma = aid1825Proforma($this->customer->id);
        app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id, key: 'sale-A'));

        $result = app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $this->customer->id, key: 'sale-B'));

        expect($result->outcome)->toBe(VoucherSaleOutcome::Duplicate)
            ->and(GroupedPayment::query()->count())->toBe(1)
            ->and(VoucherSaleClaim::query()->count())->toBe(1)
            ->and(CreditMovement::query()->count())->toBe(1);
    });

    it('refuses a proforma of another customer', function () {
        $proforma = aid1825Proforma($this->customer->id);
        $other    = TestUser::factory()->create();

        $result = app(VoucherSaleService::class)->sellVoucher(aid1825SaleRequest($proforma, $other->id));

        expect($result->outcome)->toBe(VoucherSaleOutcome::PayerMismatch)
            ->and(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    });
});
