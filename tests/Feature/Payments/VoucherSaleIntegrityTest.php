<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Contracts\Services\VoucherSaleIssuanceHookContract;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleIntegrityReason;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use AichaDigital\Larabill\Exceptions\SaleStageException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\VoucherSaleClaim;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Support\Facades\DB;

mutates(VoucherSaleService::class, InvoiceService::class);

/*
 * AID-1825 minimal scope §1a — the sale-integrity boundary on CLAIMED rows
 * (rules 1, 3, 4, 5) and §3 — recovery: an incoherent sale is identifiable,
 * returns a clear error with zero writes, and the two reads list it. Every
 * fixture here is a sale stopped between its stages, built the way a crash
 * would leave it.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

function aid1825iProforma(string $customerId, int $basePriceCents = 10000): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => $basePriceCents]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

function aid1825iRequest(Invoice $proforma, string $customerId, ?string $key = null): VoucherSaleRequest
{
    $key ??= 'sale-'.$proforma->id; // one key per payment event; a second event on the same proforma names its own

    return new VoucherSaleRequest((string) $proforma->id, $customerId, $proforma->total_amount, 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_'.$key, null, $key);
}

/** Numbered FISCAL invoices (proformas carry their own PRO number and do not count). */
function aid1825iNumberedInvoices(): int
{
    return Invoice::query()->where('serie', '!=', 0)->whereNotNull('fiscal_number')->count();
}

/**
 * The state right after S1: claim PENDING, payment recorded with no entry,
 * proforma still SENT. Built through the real S1 by making S2 fail once.
 *
 * @return array{0: Invoice, 1: VoucherSaleClaim, 2: GroupedPayment, 3: VoucherSaleRequest}
 */
function aid1825iAfterS1(string $customerId): array
{
    $proforma = aid1825iProforma($customerId);
    $request  = aid1825iRequest($proforma, $customerId);

    // Stop the sale after S1: S2's conversion fails on a thrown saving listener for the PREPARED child.
    $stop = Invoice::saving(function (Invoice $invoice): void {
        if ($invoice->invoice_document_status === InvoiceDocumentStatus::PREPARED && ! $invoice->exists) {
            throw new RuntimeException('crash after S1');
        }
    });

    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped after S1');
    } catch (RuntimeException) {
    } finally {
        Invoice::flushEventListeners();
        Invoice::clearBootedModels();
    }

    $claim = VoucherSaleClaim::query()->where('proforma_id', $proforma->id)->firstOrFail();

    return [$proforma->fresh(), $claim, $claim->payment, $request];
}

/**
 * The state right after S2: claim PENDING, PREPARED invoice carrying
 * `sale_claim_id`, proforma CONVERTING. Built from the S1 state by running
 * the real S2 only (the issue step is stopped before numbering).
 *
 * @return array{0: Invoice, 1: VoucherSaleClaim, 2: GroupedPayment, 3: VoucherSaleRequest, 4: Invoice}
 */
function aid1825iAfterS2(string $customerId): array
{
    [$proforma, $claim, $payment, $request] = aid1825iAfterS1($customerId);

    $stop = VoucherSaleClaim::updating(function (VoucherSaleClaim $claim): void {
        if ($claim->stage === VoucherSaleStage::Issuing) {
            throw new RuntimeException('crash before S3a');
        }
    });

    try {
        app(VoucherSaleService::class)->sellVoucher($request);
        test()->fail('the sale should have stopped before S3a');
    } catch (RuntimeException) {
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }

    $invoice = Invoice::query()->where('sale_claim_id', $claim->id)->firstOrFail();

    return [$proforma->fresh(), $claim->fresh(), $payment->fresh(), $request, $invoice];
}

describe('rule 1 — no fiscal-content edit during the claim (test 8)', function () {
    it('9.1: an unclaimed SENT proforma can still be edited and its line deleted', function () {
        $proforma = aid1825iProforma($this->customer->id);

        $proforma->update(['notes' => 'edited']);
        $proforma->items()->first()->delete();

        expect($proforma->fresh()->notes)->toBe('edited')
            ->and($proforma->items()->count())->toBe(0);
    });

    it('refuses an update and a line delete on a claimed proforma, outside the sale', function () {
        [$proforma] = aid1825iAfterS1($this->customer->id);
        $total      = $proforma->total_amount->unscaledValue();

        expect(fn () => $proforma->update(['total_amount' => cents($total + 10000)]))->toThrow(SaleClaimedException::class);
        expect(fn () => $proforma->items()->first()->delete())->toThrow(SaleClaimedException::class);
        expect(fn () => $proforma->items()->first()->update(['description' => 'changed']))->toThrow(SaleClaimedException::class);

        expect($proforma->fresh()->total_amount->unscaledValue())->toBe($total)
            ->and($proforma->items()->count())->toBe(1);
    });

    it('refuses an update, a line delete that keeps the header total, and a line update on the claimed PREPARED invoice', function () {
        [, , , , $invoice] = aid1825iAfterS2($this->customer->id);
        $total             = $invoice->total_amount->unscaledValue();

        expect(fn () => $invoice->update(['total_amount' => cents($total + 10000)]))->toThrow(SaleClaimedException::class);
        expect(fn () => $invoice->items()->first()->delete())->toThrow(SaleClaimedException::class);
        expect(fn () => $invoice->items()->first()->update(['description' => 'changed']))->toThrow(SaleClaimedException::class);
        expect(fn () => $invoice->delete())->toThrow(Exception::class);

        expect($invoice->fresh()->total_amount->unscaledValue())->toBe($total)
            ->and($invoice->items()->count())->toBe(1);
    });

    it('refuses the four service paths on claimed rows outside the sale', function () {
        [$proforma] = aid1825iAfterS1($this->customer->id);
        $service    = app(InvoiceService::class);

        expect(fn () => $service->convertProformaToInvoice($proforma))->toThrow(SaleClaimedException::class);
        expect(fn () => $service->convertAndIssue($proforma))->toThrow(SaleClaimedException::class);

        [, , , , $invoice] = aid1825iAfterS2(TestUser::factory()->create()->id);

        expect(fn () => $service->issue($invoice))->toThrow(SaleClaimedException::class);
        expect(fn () => $service->cancelPreparedInvoice($invoice, 'staff'))->toThrow(SaleClaimedException::class);

        expect($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
            ->and($invoice->fresh()->fiscal_number)->toBeNull();
    });
});

describe('rule 4 — revalidation before numbering (test 11)', function () {
    it('refuses amountDrift when the PREPARED invoice was rewritten to 200 € by a direct write after S2, numbering nothing', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        DB::table('invoices')->where('id', $invoice->id)->update(['total_amount' => 20000]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::AmountDrift)
                ->and($e->claimId)->toBe((string) $claim->id);
        }

        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending)
            ->and(CreditMovement::query()->count())->toBe(0);
    });

    it('a proforma header rewritten after S1 does not change the sale: the frozen lines and expectation govern', function () {
        [$proforma, , , $request] = aid1825iAfterS1($this->customer->id);
        $total                    = $proforma->total_amount->unscaledValue();
        DB::table('invoices')->where('id', $proforma->id)->update(['total_amount' => $total + 10000]);

        $result = app(VoucherSaleService::class)->sellVoucher($request);

        expect($result->outcome)->toBe(VoucherSaleOutcome::Sold)
            ->and($result->invoice->fresh()->total_amount->unscaledValue())->toBe($total)
            ->and($result->invoice->fresh()->amountPaid()->unscaledValue())->toBe($total);
    });

    it('refuses payerDrift when the payment payer was changed by a direct write', function () {
        [, $claim, $payment, $request] = aid1825iAfterS2($this->customer->id);
        DB::table('grouped_payments')->where('id', $payment->id)->update(['billable_user_id' => TestUser::factory()->create()->id]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::PayerDrift);
        }

        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending);
    });

    it('refuses currencyDrift and paymentNotPosted on the payment', function () {
        [, , $payment, $request] = aid1825iAfterS2($this->customer->id);

        DB::table('grouped_payments')->where('id', $payment->id)->update(['currency' => 'USD']);
        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CurrencyDrift);
        }

        DB::table('grouped_payments')->where('id', $payment->id)->update(['currency' => 'EUR', 'status' => 1]);
        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::PaymentNotPosted);
        }

        expect(aid1825iNumberedInvoices())->toBe(0);
    });
});

describe('rule 3 — no balance without the confirmed S3b tuple (test 10)', function () {
    it('rolls S3b back when a saving listener vetoes only the PAID transition', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        Invoice::saving(fn (Invoice $i) => $i->status === InvoiceStatus::PAID && $i->sale_claim_id !== null ? false : null);

        try {
            try {
                app(VoucherSaleService::class)->sellVoucher($request);
                test()->fail('expected SaleIntegrityException');
            } catch (SaleIntegrityException $e) {
                expect($e->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
            }
        } finally {
            Invoice::flushEventListeners();
            Invoice::clearBootedModels();
        }

        $fresh = $invoice->fresh();
        expect($fresh->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED) // S3a committed on its own
            ->and($fresh->status)->toBe(InvoiceStatus::DRAFT)                        // S3b rolled back whole
            ->and(PaymentAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
            ->and(CreditMovement::query()->count())->toBe(0)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Issuing);
    });

    it('rolls S3b back when a saving listener vetoes only the voucher entry', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        CreditMovement::saving(fn (CreditMovement $m) => $m->sale_invoice_id !== null ? false : null);

        try {
            // A vetoed package write is detected at the write itself (gate round 1 B1) and rolls the stage back.
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::DRAFT)
            ->and(PaymentAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
            ->and(CreditMovement::query()->count())->toBe(0)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Issuing);
    });

    it('rolls S3b back when a saving listener vetoes only the BACKED stage', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        VoucherSaleClaim::saving(fn (VoucherSaleClaim $c) => $c->stage === VoucherSaleStage::Backed ? false : null);

        try {
            // A vetoed package write is detected at the write itself (gate round 1 B1) and rolls the stage back.
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            VoucherSaleClaim::flushEventListeners();
            VoucherSaleClaim::clearBootedModels();
        }

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::DRAFT)
            ->and(PaymentAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
            ->and(CreditMovement::query()->count())->toBe(0)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Issuing);
    });
});

describe('rule 5 — reversal coordinated with the sale (test 12)', function () {
    it('reverse() at PENDING before S2 succeeds and releases the claim; the proforma can be sold again', function () {
        [$proforma, $claim, $payment] = aid1825iAfterS1($this->customer->id);

        app(PaymentService::class)->reverse($payment, 'customer withdrew');

        expect($payment->fresh()->isReversed())->toBeTrue()
            ->and(VoucherSaleClaim::query()->whereKey($claim->id)->exists())->toBeFalse()
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::SENT);

        $again = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest($proforma->fresh(), $this->customer->id, 'sale-2'));
        expect($again->outcome)->toBe(VoucherSaleOutcome::Sold);
    });

    it('reverse() at PENDING after S2 also cancels the PREPARED child and returns the proforma to DRAFT', function () {
        [$proforma, $claim, $payment, , $invoice] = aid1825iAfterS2($this->customer->id);

        app(PaymentService::class)->reverse($payment, 'customer withdrew');

        expect($payment->fresh()->isReversed())->toBeTrue()
            ->and(VoucherSaleClaim::query()->whereKey($claim->id)->exists())->toBeFalse()
            ->and($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::CANCELLED)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::DRAFT)
            ->and($proforma->fresh()->converted_invoice_id)->toBeNull();
    });

    it('reverse() is refused at ISSUING and at BACKED', function () {
        [, $claim, $payment, $request] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]);

        expect(fn () => app(PaymentService::class)->reverse($payment, 'late'))->toThrow(SaleStageException::class);
        expect($payment->fresh()->isPosted())->toBeTrue();

        $claim->update(['stage' => VoucherSaleStage::Pending]);
        $sold = app(VoucherSaleService::class)->sellVoucher($request);
        expect($sold->outcome)->toBe(VoucherSaleOutcome::Sold);

        expect(fn () => app(PaymentService::class)->reverse($payment, 'late'))->toThrow(SaleStageException::class);
        expect($payment->fresh()->isPosted())->toBeTrue()
            ->and(CreditMovement::query()->count())->toBe(1);
    });
});

describe('recovery — identification, reads and the mark by document state (tests 13, 14)', function () {
    it('resumes a crash right after the ISSUING stage write (PREPARED, no mark) — not an incoherence', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]);

        $service = app(VoucherSaleService::class);
        expect($service->salesIncoherent())->toHaveCount(0)
            ->and($service->salesAwaitingBalance())->toHaveCount(0);

        $result = $service->sellVoucher($request);

        expect($result->outcome)->toBe(VoucherSaleOutcome::Sold)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    });

    it('lists an issued, marked sale without balance in salesAwaitingBalance() and completes it on the re-call', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        // Crash between S3a and S3b: issue under the sale authorization, then stop.
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]);
        VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice));
        expect($claim->fresh()->issued_by_sale)->toBeTrue();

        $service = app(VoucherSaleService::class);
        $rows    = $service->salesAwaitingBalance();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->invoiceId)->toBe((string) $invoice->id)
            ->and($rows->first()->proformaId)->toBe((string) $claim->proforma_id)
            ->and($rows->first()->paymentId)->toBe((string) $claim->grouped_payment_id)
            ->and($rows->first()->stage)->toBe(VoucherSaleStage::Issuing)
            ->and($service->salesIncoherent())->toHaveCount(0);

        expect($service->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold)
            ->and($service->salesAwaitingBalance())->toHaveCount(0);
    });

    it('an ISSUED sale invoice without the mark → markMissing with zero writes and no fiscal call, listed by salesIncoherent()', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);
        $sold     = app(VoucherSaleService::class)->sellVoucher($request);
        DB::table('voucher_sale_claims')->where('id', $sold->claim->id)->update(['issued_by_sale' => false]);

        $service = app(VoucherSaleService::class);
        $rows    = $service->salesIncoherent();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->reason)->toBe(SaleIntegrityReason::MarkMissing);

        try {
            $service->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::MarkMissing);
        }

        expect(CreditMovement::query()->count())->toBe(1)
            ->and(PaymentAllocation::query()->count())->toBe(1);
    });

    it('lists every detectable incoherence with its reason: claim deleted, CANCELLED by the setter, paid_at changed, competing allocation, document axis altered', function () {
        $service = app(VoucherSaleService::class);

        // (a) claim deleted → claimMissing, found through the invoice.
        $a = $service->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id, 'a'));
        DB::table('invoices')->where('id', $a->invoice->id)->update(['sale_claim_id' => $a->claim->id]); // keep the link
        DB::table('voucher_sale_claims')->where('id', $a->claim->id)->delete();

        // (b) collection status CANCELLED through the deprecated setter → collectionStateUnexpected.
        $b = $service->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id, 'b'));
        $b->invoice->fresh()->restoreStateViaGroupedPaymentReversal(InvoiceStatus::CANCELLED, null);

        // (c) paid_at changed while PAID → collectionStateUnexpected.
        $c = $service->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id, 'c'));
        $c->invoice->fresh()->markAsPaidViaGroupedPayment(now()->subYear());

        // (d) competing allocation inserted directly with the expected status restored → competingAllocation.
        $d     = $service->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id, 'd'));
        $other = app(PaymentService::class)->receive($this->customer->id, cents(100), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP);
        DB::table('grouped_payment_invoice')->insert([
            'grouped_payment_id' => $other->id, 'invoice_id' => $d->invoice->id, 'applied_amount' => 100, 'status' => 0,
            'applied_at'         => now(), 'active_invoice_id' => $d->invoice->id, 'previous_status' => InvoiceStatus::PAID->value,
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        // (e) document axis altered by a direct write → documentAxisIncoherent.
        $e = $service->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id, 'e'));
        DB::table('invoices')->where('id', $e->invoice->id)->update(['invoice_document_status' => InvoiceDocumentStatus::CANCELLED->value]);

        $rows = $service->salesIncoherent()->keyBy('invoiceId');

        expect($rows)->toHaveCount(5)
            ->and($rows[(string) $a->invoice->id]->reason)->toBe(SaleIntegrityReason::ClaimMissing)
            ->and($rows[(string) $b->invoice->id]->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected)
            ->and($rows[(string) $c->invoice->id]->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected)
            ->and($rows[(string) $d->invoice->id]->reason)->toBe(SaleIntegrityReason::CompetingAllocation)
            ->and($rows[(string) $e->invoice->id]->reason)->toBe(SaleIntegrityReason::DocumentAxisIncoherent)
            ->and($service->salesAwaitingBalance())->toHaveCount(0);

        // The re-call on (a) returns the same reason with zero writes.
        $before = CreditMovement::query()->count();
        try {
            $service->sellVoucher(aid1825iRequest($a->claim->proforma()->first(), $this->customer->id, 'a'));
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $ex) {
            expect($ex->reason)->toBe(SaleIntegrityReason::ClaimMissing);
        }
        expect(CreditMovement::query()->count())->toBe($before);
    });
});

describe('implementation gate round 1 — reproduced defects', function () {
    it('S1: a vetoed claim save leaves no payment behind (the whole S1 transaction rolls back)', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);

        VoucherSaleClaim::saving(fn (VoucherSaleClaim $claim) => $claim->exists ? null : false);

        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            VoucherSaleClaim::flushEventListeners();
            VoucherSaleClaim::clearBootedModels();
        }

        expect(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    });

    it('ISSUING: a vetoed stage write stops S3a before issue(); an ISSUED invoice with a PENDING claim is listed as incoherent', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        VoucherSaleClaim::saving(fn (VoucherSaleClaim $c) => $c->stage === VoucherSaleStage::Issuing ? false : null);
        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            VoucherSaleClaim::flushEventListeners();
            VoucherSaleClaim::clearBootedModels();
        }
        expect($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending);

        // The state the reads must still list if a write outside the package produced it.
        VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice->fresh()));
        DB::table('voucher_sale_claims')->where('id', $claim->id)->update(['stage' => VoucherSaleStage::Pending->value]);

        $rows = app(VoucherSaleService::class)->salesIncoherent();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->reason)->toBe(SaleIntegrityReason::DocumentAxisIncoherent);
    });

    it('a same-key re-call with another payload on the same proforma is refused', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);
        app(VoucherSaleService::class)->sellVoucher($request);

        $changed = new VoucherSaleRequest((string) $proforma->id, $this->customer->id, $proforma->total_amount->plus(cents(100)), 'EUR', $request->receivedAt, $request->method, $request->reference, null, $request->operationKey);

        expect(fn () => app(VoucherSaleService::class)->sellVoucher($changed))->toThrow(IdempotencyConflictException::class);
        expect(CreditMovement::query()->count())->toBe(1);
    });

    it('a line cannot be moved out of a claimed proforma or PREPARED sale invoice into another document', function () {
        [$proforma] = aid1825iAfterS1($this->customer->id);
        $other      = aid1825iProforma($this->customer->id);
        $line       = $proforma->items()->firstOrFail();

        expect(fn () => $line->update(['invoice_id' => $other->id]))->toThrow(SaleClaimedException::class);
        expect($proforma->items()->count())->toBe(1);

        [, , , , $prepared] = aid1825iAfterS2(TestUser::factory()->create()->id);
        $preparedLine       = $prepared->items()->firstOrFail();
        expect(fn () => $preparedLine->update(['invoice_id' => $other->id]))->toThrow(SaleClaimedException::class);
        expect($prepared->items()->count())->toBe(1);
    });

    it('the sale authorization is scoped to the claim and switched off inside the consumer hook', function () {
        [$otherProforma] = aid1825iAfterS1(TestUser::factory()->create()->id); // a DIFFERENT claimed proforma
        $proforma        = aid1825iProforma($this->customer->id);
        $request         = aid1825iRequest($proforma, $this->customer->id);

        $leaked = null;
        app()->instance(VoucherSaleIssuanceHookContract::class, new class($otherProforma, $leaked) implements VoucherSaleIssuanceHookContract
        {
            public function __construct(private Invoice $other, public mixed &$leaked) {}

            public function afterIssue(Invoice $invoice): void
            {
                try {
                    $this->other->items()->firstOrFail()->delete();
                    $this->leaked = 'deleted';
                } catch (SaleClaimedException $e) {
                    $this->leaked = 'refused';
                }
            }
        });

        expect(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold)
            ->and($leaked)->toBe('refused')
            ->and($otherProforma->items()->count())->toBe(1);
    });

    it('revalidates the INVOICE currency: a fiscal config changed to USD after S2 → currencyDrift, listed and refused with zero writes', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        DB::table('company_fiscal_configs')->where('id', $invoice->company_fiscal_config_id)->update(['currency' => 'USD']);

        $rows = app(VoucherSaleService::class)->salesIncoherent();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->reason)->toBe(SaleIntegrityReason::CurrencyDrift);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CurrencyDrift);
        }
        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending);
    });

    it('a PENDING claim whose PREPARED invoice was cancelled by a direct write is refused BEFORE the stage advances', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        DB::table('invoices')->where('id', $invoice->id)->update(['invoice_document_status' => InvoiceDocumentStatus::CANCELLED->value]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::DocumentAxisIncoherent);
        }

        expect($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending)
            ->and(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::DocumentAxisIncoherent);
    });

    it('the reads use the same tuple predicates as the re-call: an entry from another payment is competingAllocation-grade incoherence', function () {
        $sold  = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $other = app(PaymentService::class)->receive($this->customer->id, cents(100), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP);
        DB::table('credit_movements')->where('id', $sold->entry->id)->update(['grouped_payment_id' => $other->id]);

        $rows = app(VoucherSaleService::class)->salesIncoherent();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
    });

    it('annul() refuses an ISSUED, unbacked sale invoice even after the deprecated setter made it PENDING', function () {
        [, $claim, , , $invoice] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]);
        VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice));
        $invoice->fresh()->restoreStateViaGroupedPaymentReversal(InvoiceStatus::PENDING, null);

        expect(fn () => app(SettlementService::class)->annul($invoice->fresh()))
            ->toThrow(InvoiceNotAnnullableException::class);
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::PENDING);
    });
});

describe('implementation gate round 2 — reproduced defects', function () {
    it('a same-key re-call with another receipt instant is refused (the instant is part of the payload, as in receive())', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);
        app(VoucherSaleService::class)->sellVoucher($request);

        $shifted = new VoucherSaleRequest((string) $proforma->id, $this->customer->id, $request->amount, 'EUR', $request->receivedAt->copy()->addMinutes(5), $request->method, $request->reference, null, $request->operationKey);

        expect(fn () => app(VoucherSaleService::class)->sellVoucher($shifted))->toThrow(IdempotencyConflictException::class);
        expect(CreditMovement::query()->count())->toBe(1);
    });

    it('reverse() at PENDING: a vetoed claim delete aborts the whole reversal — payment still POSTED, claim still there', function () {
        [, $claim, $payment] = aid1825iAfterS1($this->customer->id);

        VoucherSaleClaim::deleting(fn () => false);
        try {
            expect(fn () => app(PaymentService::class)->reverse($payment, 'veto', 'test'))->toThrow(LogicException::class);
        } finally {
            VoucherSaleClaim::flushEventListeners();
            VoucherSaleClaim::clearBootedModels();
        }

        expect($payment->fresh()->isPosted())->toBeTrue()
            ->and(VoucherSaleClaim::query()->whereKey($claim->id)->exists())->toBeTrue();
    });

    it('cancelProforma(): a vetoed status write throws instead of returning the still-open proforma as cancelled', function () {
        $proforma = aid1825iProforma($this->customer->id);

        Invoice::saving(fn (Invoice $i) => $i->isDirty('status') && $i->status === InvoiceStatus::CANCELLED ? false : null);
        try {
            expect(fn () => app(VoucherSaleService::class)->cancelProforma($proforma, 'staff'))->toThrow(LogicException::class);
        } finally {
            Invoice::flushEventListeners();
            Invoice::clearBootedModels();
        }

        expect($proforma->fresh()->status)->not->toBe(InvoiceStatus::CANCELLED);
    });

    it('issue() under the sale: a vetoed ISSUED write rolls the number, the proforma mirror and the mark back; the re-call then completes', function () {
        [$proforma, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        Invoice::saving(fn (Invoice $i) => $i->isDirty('invoice_document_status') && $i->invoice_document_status === InvoiceDocumentStatus::ISSUED ? false : null);
        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            Invoice::flushEventListeners();
            Invoice::clearBootedModels();
        }

        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTING)
            ->and($claim->fresh()->issued_by_sale)->toBeFalse();

        // The crash window (ISSUING, PREPARED) resumes.
        expect(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold)
            ->and(aid1825iNumberedInvoices())->toBe(1);
    });

    it('a PENDING sale whose PREPARED invoice already carries a foreign allocation is refused BEFORE the stage advances and before any fiscal call', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        $other                           = app(PaymentService::class)->receive($this->customer->id, cents(100), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP);
        PaymentAllocation::query()->create(['grouped_payment_id' => $other->id, 'invoice_id' => $invoice->id, 'applied_amount' => cents(100), 'status' => AllocationStatus::ACTIVE, 'applied_at' => now(), 'previous_status' => InvoiceStatus::DRAFT->value]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CompetingAllocation);
        }

        expect($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending)
            ->and(aid1825iNumberedInvoices())->toBe(0)
            ->and(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::CompetingAllocation);
    });

    it('a BACKED sale that lost its entry is refused on re-call and listed with the SAME reason', function () {
        $sold = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $req  = new VoucherSaleRequest((string) $sold->claim->proforma_id, $this->customer->id, $sold->claim->expected_amount, 'EUR', $sold->payment->paid_at, $sold->payment->method, $sold->payment->reference, null, $sold->claim->operation_key);
        DB::table('credit_movements')->where('id', $sold->entry->id)->delete();

        try {
            app(VoucherSaleService::class)->sellVoucher($req);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
        }
        expect(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
    });

    it('a BACKED sale is never rebuilt: entry and allocation gone and status reset to DRAFT → refused with zero writes', function () {
        $sold = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $req  = new VoucherSaleRequest((string) $sold->claim->proforma_id, $this->customer->id, $sold->claim->expected_amount, 'EUR', $sold->payment->paid_at, $sold->payment->method, $sold->payment->reference, null, $sold->claim->operation_key);
        DB::table('credit_movements')->where('id', $sold->entry->id)->delete();
        DB::table('grouped_payment_invoice')->where('invoice_id', $sold->invoice->id)->delete();
        DB::table('invoices')->where('id', $sold->invoice->id)->update(['status' => InvoiceStatus::DRAFT->value, 'paid_at' => null]);

        expect(fn () => app(VoucherSaleService::class)->sellVoucher($req))->toThrow(SaleIntegrityException::class);
        expect(CreditMovement::query()->count())->toBe(0)
            ->and(PaymentAllocation::query()->where('invoice_id', $sold->invoice->id)->count())->toBe(0)
            ->and($sold->invoice->fresh()->status)->toBe(InvoiceStatus::DRAFT);
    });
});

describe('implementation gate round 3 — reproduced defects', function () {
    it('S2: a vetoed line clone aborts the whole conversion — no PREPARED invoice, proforma still in force, the sale resumable', function () {
        [$proforma, $claim, , $request] = aid1825iAfterS1($this->customer->id);

        InvoiceItem::saving(fn (InvoiceItem $i) => $i->exists ? null : false);
        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            InvoiceItem::flushEventListeners();
            InvoiceItem::clearBootedModels();
        }

        expect(Invoice::query()->whereNotNull('sale_claim_id')->count())->toBe(0)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending);

        expect(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold);
    });

    it('a BACKED sale whose invoice was reverted to a PREPARED shape is refused before any fiscal call — never numbered twice', function () {
        $sold = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $req  = new VoucherSaleRequest((string) $sold->claim->proforma_id, $this->customer->id, $sold->claim->expected_amount, 'EUR', $sold->payment->paid_at, $sold->payment->method, $sold->payment->reference, null, $sold->claim->operation_key);
        DB::table('invoices')->where('id', $sold->invoice->id)->update([
            'invoice_document_status' => InvoiceDocumentStatus::PREPARED->value, 'fiscal_number' => null, 'series_number' => null,
            'fiscal_year'             => null, 'invoice_date' => null, 'issued_at' => null, 'fiscal_submission_status' => null,
        ]);

        try {
            app(VoucherSaleService::class)->sellVoucher($req);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::DocumentAxisIncoherent);
        }

        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($sold->claim->fresh()->stage)->toBe(VoucherSaleStage::Backed)
            ->and(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::DocumentAxisIncoherent);
    });

    it('a funding payment carrying a reversal instant under a POSTED status is paymentNotPosted for both the re-call and the reads', function () {
        [, , $payment, $request] = aid1825iAfterS2($this->customer->id);
        DB::table('grouped_payments')->where('id', $payment->id)->update(['reversed_at' => now()]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::PaymentNotPosted);
        }
        expect(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::PaymentNotPosted);
    });

    it('a PREPARED sale invoice with a paid_at before settlement is collectionStateUnexpected for both the re-call and the reads', function () {
        [, , , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        DB::table('invoices')->where('id', $invoice->id)->update(['paid_at' => now()]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
        }
        expect(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected)
            ->and(aid1825iNumberedInvoices())->toBe(0);
    });
});

describe('implementation gate round 4 — reproduced defects', function () {
    it('S2 refuses a drifted S1 payment BEFORE converting: amount changed after S1 → amountDrift, no PREPARED invoice, proforma still SENT', function () {
        [$proforma, $claim, $payment, $request] = aid1825iAfterS1($this->customer->id);
        DB::table('grouped_payments')->where('id', $payment->id)->update(['amount' => $payment->amount->unscaledValue() + 100]);

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::AmountDrift);
        }

        expect(Invoice::query()->whereNotNull('sale_claim_id')->count())->toBe(0)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and((bool) $proforma->fresh()->is_immutable)->toBeFalse()
            ->and($claim->fresh()->stage)->toBe(VoucherSaleStage::Pending);
    });

    it('S1: a vetoed payment save aborts the step — no payment, no claim', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);

        GroupedPayment::saving(fn () => false);
        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            GroupedPayment::flushEventListeners();
            GroupedPayment::clearBootedModels();
        }

        expect(GroupedPayment::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->count())->toBe(0);
    });

    it('after SOLD: a line of the ISSUED sale invoice cannot be moved to a draft (the ORIGINAL parent is protected too)', function () {
        $sold  = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $draft = aid1825iProforma($this->customer->id);
        $line  = $sold->invoice->items()->firstOrFail();

        // The attribute+save() path (the update() override checks the original parent on its own; the model guard must too).
        $line->invoice_id = $draft->id;
        expect(fn () => $line->save())->toThrow(Exception::class);
        expect($sold->invoice->items()->count())->toBe(1)
            ->and($draft->items()->count())->toBe(1);
    });

    it('after SOLD: the CONVERTED proforma cannot be unfrozen, so no later fiscal edit can pass the immutable guard', function () {
        $sold     = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $proforma = Invoice::query()->whereKey($sold->claim->proforma_id)->firstOrFail();
        $total    = $proforma->total_amount->unscaledValue();
        expect($proforma->status)->toBe(InvoiceStatus::CONVERTED)->and((bool) $proforma->is_immutable)->toBeTrue();

        expect(fn () => $proforma->update(['is_immutable' => false]))->toThrow(SaleClaimedException::class);
        expect((bool) $proforma->fresh()->is_immutable)->toBeTrue();

        expect(fn () => Invoice::query()->whereKey($proforma->id)->firstOrFail()->update(['total_amount' => cents(99900)]))->toThrow(Exception::class);
        expect($proforma->fresh()->total_amount->unscaledValue())->toBe($total);
    });

    it('a CONVERTED proforma that no sale ever claimed cannot be unfrozen either (9.1 hole closed, named)', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $invoice  = app(InvoiceService::class)->convertProformaToInvoice($proforma)->invoice;
        app(InvoiceService::class)->issue($invoice);
        expect($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED);

        expect(fn () => $proforma->fresh()->update(['is_immutable' => false]))->toThrow(Exception::class);
        expect((bool) $proforma->fresh()->is_immutable)->toBeTrue();
    });
});

describe('implementation gate round 4 — currency evidence', function () {
    it('after SOLD the currency evidence is the FROZEN snapshot: changing the referenced config later neither lists the sale nor breaks its replay', function () {
        $sold = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id));
        $req  = new VoucherSaleRequest((string) $sold->claim->proforma_id, $this->customer->id, $sold->claim->expected_amount, 'EUR', $sold->payment->paid_at, $sold->payment->method, $sold->payment->reference, null, $sold->claim->operation_key);
        DB::table('company_fiscal_configs')->where('id', $sold->invoice->company_fiscal_config_id)->update(['currency' => 'USD']);

        expect(app(VoucherSaleService::class)->salesIncoherent())->toHaveCount(0)
            ->and(app(VoucherSaleService::class)->sellVoucher($req)->outcome)->toBe(VoucherSaleOutcome::Sold);
    });

    it('a config changed to another currency between S3a and issue() is refused inside issue(), before the number is consumed', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);
        // Stage ISSUING already written (S3a passed with EUR), then the config changes before issue() runs.
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]);
        DB::table('company_fiscal_configs')->where('id', $invoice->company_fiscal_config_id)->update(['currency' => 'USD']);

        try {
            VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice->fresh()));
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CurrencyDrift);
        }
        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED);
    });
});

describe('implementation gate round 5 — reproduced defects', function () {
    it('issue() under the sale locks and re-checks the funding payment: a payment that drifted after S3a is refused before the number is consumed', function () {
        [, $claim, $payment, , $invoice] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]); // S3a passed
        DB::table('grouped_payments')->where('id', $payment->id)->update(['amount' => $payment->amount->unscaledValue() + 100]);

        try {
            VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice->fresh()));
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::AmountDrift);
        }
        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($claim->fresh()->issued_by_sale)->toBeFalse();
    });

    it('a consumer hook that touches the funding payment rolls the whole issuance back: no number, no mark, payment untouched', function () {
        [, $claim, $payment, $request, $invoice] = aid1825iAfterS2($this->customer->id);
        $amount                                  = $payment->amount->unscaledValue();

        app()->instance(VoucherSaleIssuanceHookContract::class, new class($payment) implements VoucherSaleIssuanceHookContract
        {
            public function __construct(private GroupedPayment $payment) {}

            public function afterIssue(Invoice $invoice): void
            {
                DB::table('grouped_payments')->where('id', $this->payment->id)->update(['amount' => 1]);
            }
        });

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::AmountDrift);
        } finally {
            app()->forgetInstance(VoucherSaleIssuanceHookContract::class);
        }

        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
            ->and($claim->fresh()->issued_by_sale)->toBeFalse()
            ->and($payment->fresh()->amount->unscaledValue())->toBe($amount);
    });

    it('S2: a vetoed PREPARED-row creation aborts the conversion with the typed LogicException — no invoice, proforma still SENT', function () {
        [$proforma, , , $request] = aid1825iAfterS1($this->customer->id);

        Invoice::saving(fn (Invoice $i) => ! $i->exists && $i->invoice_document_status === InvoiceDocumentStatus::PREPARED ? false : null);
        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class);
        } finally {
            Invoice::flushEventListeners();
            Invoice::clearBootedModels();
        }

        expect(Invoice::query()->whereNotNull('sale_claim_id')->count())->toBe(0)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::SENT);
    });
});

describe('implementation gate round 6 — reproduced defect', function () {
    it('issue() under the sale checks the LOCKED invoice against the claim: a total rewritten after S3a is refused before the number', function () {
        [, $claim, , , $invoice] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]); // S3a passed
        DB::table('invoices')->where('id', $invoice->id)->update(['total_amount' => 20000]);

        try {
            VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice->fresh()));
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::AmountDrift);
        }
        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($claim->fresh()->issued_by_sale)->toBeFalse();
    });

    it('… and a payer rewritten after S3a is refused the same way', function () {
        [, $claim, , , $invoice] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]);
        $other = TestUser::factory()->create();
        DB::table('invoices')->where('id', $invoice->id)->update(['billable_user_id' => $other->id]);

        try {
            VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice->fresh()));
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::PayerDrift);
        }
        expect(aid1825iNumberedInvoices())->toBe(0);
    });
});

describe('implementation gate round 7 — reproduced defects', function () {
    it('issue() under the sale repeats the pre-settlement collection predicates: a PENDING set after S3a is refused before the number', function () {
        [, $claim, , , $invoice] = aid1825iAfterS2($this->customer->id);
        $claim->update(['stage' => VoucherSaleStage::Issuing, 'issuing_at' => now()]); // S3a passed
        $invoice->fresh()->restoreStateViaGroupedPaymentReversal(InvoiceStatus::PENDING, null);

        try {
            VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn () => app(InvoiceService::class)->issue($invoice->fresh()));
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
        }
        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($claim->fresh()->issued_by_sale)->toBeFalse();
    });

    it('a consumer hook that moves the collection state rolls the whole issuance back', function () {
        [, $claim, , $request, $invoice] = aid1825iAfterS2($this->customer->id);

        app()->instance(VoucherSaleIssuanceHookContract::class, new class implements VoucherSaleIssuanceHookContract
        {
            public function afterIssue(Invoice $invoice): void
            {
                $invoice->restoreStateViaGroupedPaymentReversal(InvoiceStatus::PENDING, null);
            }
        });

        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
        } finally {
            app()->forgetInstance(VoucherSaleIssuanceHookContract::class);
        }

        expect(aid1825iNumberedInvoices())->toBe(0)
            ->and($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::DRAFT)
            ->and($claim->fresh()->issued_by_sale)->toBeFalse();
    });

    it('S3b refuses an entry whose payer was rewritten by a listener: nothing is backed, no balance is credited to anyone', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);
        $other    = TestUser::factory()->create();

        CreditMovement::saving(function (CreditMovement $m) use ($other): void {
            if (! $m->exists && $m->sale_invoice_id !== null) {
                $m->billable_user_id = $other->id;
            }
        });
        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }

        expect(CreditMovement::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->firstOrFail()->stage)->not->toBe(VoucherSaleStage::Backed);
    });

    it('… and an entry whose currency was rewritten is refused the same way; the reads list a BACKED entry with a wrong payer', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);

        CreditMovement::saving(function (CreditMovement $m): void {
            if (! $m->exists && $m->sale_invoice_id !== null) {
                $m->currency = 'USD';
            }
        });
        try {
            expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(SaleIntegrityException::class);
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }
        expect(CreditMovement::query()->count())->toBe(0);

        // Reads: a sale backed correctly, then its entry's payer changed by a direct write.
        $sold = app(VoucherSaleService::class)->sellVoucher(aid1825iRequest(aid1825iProforma($this->customer->id), $this->customer->id, 'k-reads'));
        DB::table('credit_movements')->where('id', $sold->entry->id)->update(['billable_user_id' => TestUser::factory()->create()->id]);
        expect(app(VoucherSaleService::class)->salesIncoherent()->first()?->reason)->toBe(SaleIntegrityReason::CollectionStateUnexpected);
    });
});

describe('implementation gate round 8 — reproduced defects', function () {
    it('S3b re-reads the funding payment AFTER its writes: a listener that rewrites the payment during the entry save makes S3b roll back', function () {
        $proforma = aid1825iProforma($this->customer->id);
        $request  = aid1825iRequest($proforma, $this->customer->id);

        CreditMovement::saving(function (CreditMovement $m): void {
            if (! $m->exists && $m->sale_invoice_id !== null) {
                DB::table('grouped_payments')->where('id', $m->grouped_payment_id)->update(['amount' => 1]);
            }
        });
        try {
            app(VoucherSaleService::class)->sellVoucher($request);
            test()->fail('expected SaleIntegrityException');
        } catch (SaleIntegrityException $e) {
            expect($e->reason)->toBe(SaleIntegrityReason::AmountDrift);
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }

        expect(CreditMovement::query()->count())->toBe(0)
            ->and(PaymentAllocation::query()->count())->toBe(0)
            ->and(VoucherSaleClaim::query()->firstOrFail()->stage)->not->toBe(VoucherSaleStage::Backed);
    });

    it('a same-key re-call with a sub-cent amount is not an alias of the recorded cents: the request is refused at construction', function () {
        expect(fn () => new VoucherSaleRequest('p', $this->customer->id, FixedDecimal::ofUnscaled(100001, 3), 'EUR', now(), PaymentMethod::Card, 'ref', null, 'k-subcent'))
            ->toThrow(InvalidArgumentException::class);
    });
});
