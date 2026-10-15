<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ADR-016 §7.3 (PR-2, round 2 B3) — the settlement adoption-resume branch on
 * KEYED routes: a phase-2 casualty (a durable unnumbered DRAFT carrying the
 * settlement operation key) is resumed by `issue()` instead of being treated
 * as a completed artifact or minted twice; `settleUnconsumed()` gains the
 * optional key input (additive). Unkeyed routes carry NO resume promise.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->settlement = app(SettlementService::class);
    $this->service    = app(InvoiceService::class);
});

function larabillSettleableOriginal(InvoiceService $service): Invoice
{
    $draft = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 10000]],
    ]);

    $issued = $service->issue($draft);
    $issued->markAsPaidViaGroupedPayment(now());

    return $issued;
}

it('stamps the optional settlement key and issues through settleUnconsumed (additive input)', function () {
    $original = larabillSettleableOriginal($this->service);

    $artifact = $this->settlement->settleUnconsumed(
        $original,
        5000,
        settlementOperationKey: 'settle_ops:claim-42',
    );

    expect($artifact->settlement_operation_key)->toBe('settle_ops:claim-42')
        ->and($artifact->fiscal_number)->not->toBeNull()
        ->and($artifact->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($artifact->status)->toBe(InvoiceStatus::SENT);

    // The claim semantics are untouched: a second creation for the same key
    // would collide on the UNIQUE index.
    expect(Invoice::where('settlement_operation_key', 'settle_ops:claim-42')->count())->toBe(1);
});

it('keeps settleUnconsumed byte-compatible without a key (NO resume promise, documented B3)', function () {
    $original = larabillSettleableOriginal($this->service);

    $artifact = $this->settlement->settleUnconsumed($original, 5000);

    expect($artifact->settlement_operation_key)->toBeNull()
        ->and($artifact->fiscal_number)->not->toBeNull();
});

it('resumes a durable unnumbered DRAFT by key instead of minting a second artifact (§7.3)', function () {
    $original = larabillSettleableOriginal($this->service);

    // Simulate the two-phase casualty: the keyed draft committed, issuance
    // never completed. Materialized through the canonical birth path.
    $this->service->createInvoice([
        'billable_user_id'         => TestCase::USER_UUID_1,
        'type'                     => 'invoice',
        'status'                   => 'draft',
        'items'                    => [['description' => 'Credit', 'quantity' => 100, 'base_price' => -5000]],
        'settlement_operation_key' => 'settle_ops:claim-7',
    ]);

    $draft = Invoice::where('settlement_operation_key', 'settle_ops:claim-7')->sole();
    expect($draft->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($draft->fiscal_number)->toBeNull();

    // The adopting retry finds the draft by key and RESUMES issuance.
    $resumed = $this->settlement->settleUnconsumed(
        $original,
        5000,
        settlementOperationKey: 'settle_ops:claim-7',
    );

    expect($resumed->id)->toBe($draft->id)
        ->and($resumed->fiscal_number)->not->toBeNull()
        ->and($resumed->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($resumed->status)->toBe(InvoiceStatus::SENT)
        // Never a second artifact for the same claim.
        ->and(Invoice::where('settlement_operation_key', 'settle_ops:claim-7')->count())->toBe(1);
});

it('adopts an already-issued artifact by key without touching it (collision-and-adoption semantics intact)', function () {
    $original = larabillSettleableOriginal($this->service);

    $first = $this->settlement->rectify($original, -5000, 'guarantee_ops:op-1');
    expect($first->fiscal_number)->not->toBeNull();

    // The retry of the same keyed claim adopts verbatim.
    $second = $this->settlement->rectify($original, -5000, 'guarantee_ops:op-1');

    expect($second->id)->toBe($first->id)
        ->and(Invoice::where('rectifies_invoice_id', $original->id)->count())->toBe(1);
});

it('carries no resume promise on unkeyed routes (a phase-2 casualty there stays unfindable)', function () {
    $original = larabillSettleableOriginal($this->service);

    // Two unkeyed artifacts are two independent documents — the documented
    // limitation of §7.3/round 2 B3, verbatim.
    $one = $this->settlement->settleUnconsumed($original, 1000);
    $two = $this->settlement->settleUnconsumed($original, 2000);

    expect($one->id)->not->toBe($two->id)
        ->and($one->settlement_operation_key)->toBeNull()
        ->and($two->settlement_operation_key)->toBeNull();
});
