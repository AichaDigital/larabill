<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// AID-1688 (from clientes AID-1670): 8.0 introduced three unissued document
// states (DRAFT phase 1, PREPARED conversion-born, CANCELLED before issue)
// whose rows no factory state could produce — every consumer had to build
// them by hand against assertDocumentAxisCoherence(). These states produce
// rows the §8.2 enforcer accepts: each create() below runs the saving hook,
// so a new coherence clause that one of these shapes violates fails HERE,
// in the package, not in the consumer.

const FIVE_ISSUANCE_FIELDS = ['fiscal_number', 'series_number', 'fiscal_year', 'invoice_date', 'issued_at'];

it('defaults to SENT — a deterministic collection status on the issued fiscal shape (AID-558 discipline)', function () {
    // The default row IS the issued shape (a fiscal fixture IS a numbered
    // row); its collection status must never be a dice roll (AID-581 class:
    // the factory deciding for the test what the test believes it decides).
    $invoice = Invoice::factory()->create([
        'user_id' => TestCase::USER_UUID_1,
    ]);

    expect($invoice->status)->toBe(InvoiceStatus::SENT)
        ->and($invoice->isIssued())->toBeTrue();
});

it('builds an unissued fiscal draft: five fields NULL, axis DRAFT, collection DRAFT', function () {
    $invoice = Invoice::factory()->unissuedDraft()->create([
        'user_id' => TestCase::USER_UUID_1,
    ]);

    // create() passed the §8.2 enforcer (saving hook) — the shape is coherent.
    foreach (FIVE_ISSUANCE_FIELDS as $field) {
        expect($invoice->{$field})->toBeNull();
    }

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($invoice->fiscal_submission_status)->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::DRAFT)
        ->and($invoice->paid_at)->toBeNull()
        ->and($invoice->due_date)->toBeNull()
        // The prefix is stamped at creation even for drafts (§8.1) — only
        // the number waits for issue().
        ->and($invoice->prefix)->toBe('FAC')
        ->and($invoice->isIssued())->toBeFalse()
        ->and($invoice->isUnissued())->toBeTrue();
});

it('builds a conversion-born PREPARED row linked to its proforma', function () {
    $proforma = Invoice::factory()->proforma()->create([
        'user_id' => TestCase::USER_UUID_1,
    ]);

    $invoice = Invoice::factory()->prepared($proforma)->create([
        'user_id' => TestCase::USER_UUID_1,
    ]);

    foreach (FIVE_ISSUANCE_FIELDS as $field) {
        expect($invoice->{$field})->toBeNull();
    }

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($invoice->fiscal_submission_status)->toBeNull()
        // §5.2: the collection axis stays DRAFT until issuance and beyond.
        ->and($invoice->status)->toBe(InvoiceStatus::DRAFT)
        ->and($invoice->proforma_id)->toBe($proforma->id)
        ->and($invoice->isIssued())->toBeFalse()
        ->and($invoice->isUnissued())->toBeTrue();
});

it('builds a draft cancelled before issuance: CANCELLED axis with its instant, no actor, no number', function () {
    $invoice = Invoice::factory()->cancelledBeforeIssue()->create([
        'user_id' => TestCase::USER_UUID_1,
    ]);

    foreach (FIVE_ISSUANCE_FIELDS as $field) {
        expect($invoice->{$field})->toBeNull();
    }

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::CANCELLED)
        // C5: the cancellation instant is carried.
        ->and($invoice->document_cancelled_at)->not->toBeNull()
        // S4-r is one-directional: the actor is optional, the instant is not.
        ->and($invoice->document_cancelled_by)->toBeNull()
        ->and($invoice->fiscal_submission_status)->toBeNull()
        // A cancelled draft is no longer isDraft(): the collection axis
        // moved with the cancellation.
        ->and($invoice->status)->toBe(InvoiceStatus::CANCELLED)
        ->and($invoice->isIssued())->toBeFalse()
        ->and($invoice->isUnissued())->toBeTrue();
});
