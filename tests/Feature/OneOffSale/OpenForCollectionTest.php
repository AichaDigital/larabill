<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\InvoiceNotOpenableForCollectionException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * C1 — the named public transition `InvoiceService::openForCollection()`
 * (AID-1997 spec §3, D5, S2): an ISSUED document with collection DRAFT
 * becomes PENDING so a settlement allocation can land on it; every other
 * writer of the collection axis refuses it before that. Each test is RED
 * without the transition: today `receive()` on a collection-DRAFT invoice
 * throws notPayableStatus and there is no way to open it.
 */

it('opens an ISSUED collection-DRAFT invoice for collection', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);

    // The C1 precondition: document ISSUED, collection axis at DRAFT.
    $invoice->status = InvoiceStatus::DRAFT;
    $invoice->save();

    $opened = app(InvoiceService::class)->openForCollection($invoice->fresh());

    expect($opened->status)->toBe(InvoiceStatus::PENDING)
        ->and($opened->invoice_document_status->value)->toBe(InvoiceDocumentStatus::ISSUED->value);
});

it('is idempotent: an invoice already past DRAFT is returned unchanged', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);

    $again = app(InvoiceService::class)->openForCollection($invoice->fresh());

    expect($again->status)->toBe(InvoiceStatus::SENT)
        ->and($again->id)->toBe($invoice->id);
});

it('refuses a non-ISSUED document', function () {
    $invoice = Invoice::factory()->unissuedDraft()->create([
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount'     => FixedDecimal::ofUnscaled(12100, 2),
    ]);

    app(InvoiceService::class)->openForCollection($invoice->fresh());
})->throws(InvoiceNotOpenableForCollectionException::class);

it('refuses a proforma', function () {
    CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);

    $proforma = Invoice::factory()->proforma()->create([
        'serie'            => InvoiceSerieType::PROFORMA->value,
        'status'           => InvoiceStatus::SENT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_2,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
    ]);

    app(InvoiceService::class)->openForCollection($proforma->fresh());
})->throws(InvoiceNotOpenableForCollectionException::class);

it('refuses an invoice claimed by a voucher sale', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);

    // The claim marker is written by the sale only (S2); a model write of it
    // would be refused by the claim guards, so the row is marked directly.
    DB::table('invoices')
        ->where('id', $invoice->id)
        ->update(['sale_claim_id' => '0193b58e-0000-7000-8000-000000000001']);

    // Probe: the marker really persisted (the coherence guard would have
    // nulled it otherwise, and the test would pass for the wrong reason).
    expect($invoice->fresh()->getAttribute('sale_claim_id'))->toBe('0193b58e-0000-7000-8000-000000000001');

    app(InvoiceService::class)->openForCollection($invoice->fresh());
})->throws(InvoiceNotOpenableForCollectionException::class);

it('lets a settlement allocation land only after the transition (the C1 gap)', function () {
    $invoice         = PaymentFixtures::sentInvoice(12100);
    $invoice->status = InvoiceStatus::DRAFT;
    $invoice->save();

    $allocate = fn () => app(PaymentService::class)->receive(
        TestCase::USER_UUID_2,
        FixedDecimal::ofUnscaled(12100, 2),
        'EUR',
        Carbon::now(),
        PaymentMethod::Card,
        'c1-gap-probe',
        TestCase::USER_UUID_1,
        [new Allocation((string) $invoice->id, FixedDecimal::ofUnscaled(12100, 2))],
    );

    // Before the transition: a collection-DRAFT invoice is not payable —
    // the C1 gap. Nothing is written (the payment itself refuses).
    try {
        $allocate();
        $this->fail('receive() on a collection-DRAFT invoice must refuse');
    } catch (GroupedPaymentValidationException $e) {
        expect($e->getMessage())->toContain('only SENT/OVERDUE/PENDING/PARTIALLY_PAID');
    }

    // After the transition: the same allocation lands and settles the invoice.
    app(InvoiceService::class)->openForCollection($invoice->fresh());

    $payment = $allocate();

    expect($payment)->toBeInstanceOf(GroupedPayment::class)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
});
