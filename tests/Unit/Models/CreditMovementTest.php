<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;

/*
 * AID-962 §4.2/§4.5 — the credit ledger row: entries, exits, the remaining
 * of an entry, and the once-only advance-invoice link.
 */

function aid962Exit(CreditMovement $entry, int $cents, bool $reversed = false): CreditMovement
{
    return CreditMovement::factory()->create([
        'kind'                 => CreditMovementKind::APPLIED,
        'amount'               => cents($cents),
        'billable_user_id'     => $entry->billable_user_id,
        'grouped_payment_id'   => $entry->grouped_payment_id,
        'consumes_movement_id' => $entry->id,
        'reversed_at'          => $reversed ? now() : null,
        'reverse_reason'       => $reversed ? 'test reversal' : null,
    ]);
}

/**
 * An unnumbered DRAFT advance (AID-1778 §5.6, 9.0 narrowing): the ONLY document
 * `linkAdvanceInvoice()` accepts — the shape `AdvanceInvoiceBuilder::
 * forCreditEntry()` leaves behind before `issue()`. Fixture-grade: no fiscal
 * config, no items — the link contract reads only the document axis.
 */
function aid962DraftAdvance(int $totalCents = 2900): Invoice
{
    return Invoice::factory()->unissuedDraft()->create([
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount'     => cents($totalCents),
        'is_immutable'     => false,
        'paid_at'          => null,
        'due_date'         => null,
    ]);
}

it('creates a deterministic entry from the factory defaults', function () {
    $entry = CreditMovement::factory()->create();

    expect($entry->kind)->toBe(CreditMovementKind::SURPLUS)
        ->and($entry->amount)->toBeInstanceOf(FixedDecimal::class)
        ->and($entry->amount->unscaledValue())->toBe(2900)
        ->and($entry->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 10:00:00')
        ->and($entry->method)->toBeNull()
        ->and($entry->payment)->toBeInstanceOf(GroupedPayment::class)
        ->and($entry->isEntry())->toBeTrue()
        ->and($entry->isExit())->toBeFalse()
        ->and($entry->isReversed())->toBeFalse();
});

it('computes remaining as amount plus active exits', function () {
    $entry = CreditMovement::factory()->create(['amount' => cents(2900)]);
    $live  = aid962Exit($entry, -1400);
    aid962Exit($entry, -1500, reversed: true);

    expect($entry->remaining()->unscaledValue())->toBe(1500)
        ->and($entry->consumedBy)->toHaveCount(2)
        ->and($live->consumes->id)->toBe($entry->id)
        ->and($live->isExit())->toBeTrue()
        ->and(CreditMovement::query()->active()->count())->toBe(2)
        ->and(CreditMovement::query()->entries()->pluck('id')->all())->toBe([$entry->id]);
});

it('reads the remaining with a fresh query, not the loaded relation', function () {
    $entry = CreditMovement::factory()->create(['amount' => cents(2900)]);
    $entry->load('consumedBy'); // empty, cached

    aid962Exit($entry, -900);

    expect($entry->remaining()->unscaledValue())->toBe(2000);
});

it('links the advance invoice once and refuses a second link', function () {
    $advanceA = aid962DraftAdvance(2900);
    $advanceB = aid962DraftAdvance(2900);

    $entry = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);
    $twin  = CreditMovement::find($entry->id); // loaded before the first link

    $entry->linkAdvanceInvoice($advanceA);

    expect($entry->advance_invoice_id)->toBe($advanceA->id)
        ->and($entry->advanceInvoice->id)->toBe($advanceA->id);

    expect(fn () => $twin->linkAdvanceInvoice($advanceB))->toThrow(CreditMovementAlreadyLinkedException::class)
        ->and($entry->fresh()->advance_invoice_id)->toBe($advanceA->id)
        ->and($twin->advance_invoice_id)->toBe($advanceA->id); // refreshed after the refusal
});

it('links an unnumbered DRAFT advance — the only document the 9.0 link accepts', function () {
    $advance = aid962DraftAdvance(2900);
    $entry   = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);

    $entry->linkAdvanceInvoice($advance);

    expect($entry->fresh()->advance_invoice_id)->toBe($advance->id)
        ->and($entry->fresh()->advanceInvoice->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT);
});

it('refuses to link a proforma advance — the document KIND check names the real cause (J7)', function () {
    $advance = Invoice::factory()->proforma()->create([
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount'     => cents(2900),
        'is_immutable'     => false,
        'paid_at'          => null,
        'due_date'         => null,
    ]);
    $entry = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);

    // The KIND check runs BEFORE the draft check: a proforma carries no
    // document status, so `advanceNotDraft` alone would refuse it without
    // naming the real cause — the refusal must be `advanceNotAnInvoice`.
    try {
        $entry->linkAdvanceInvoice($advance);
        test()->fail('The proforma link should have been refused.');
    } catch (InvalidAdvanceInvoiceException $e) {
        expect($e->getMessage())->toContain((string) $advance->id)
            ->and($e->getMessage())->toContain('not a fiscal INVOICE');
    }

    // No write on the loser's row — the refusal happens before any UPDATE.
    expect($entry->fresh()->advance_invoice_id)->toBeNull();
});

it('refuses to link an advance that is no longer a DRAFT (L9) — the 8.3.0 permissive link of a SENT advance is now a refusal', function () {
    $advance = PaymentFixtures::sentInvoice(2900);
    $entry   = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);

    expect(fn () => $entry->linkAdvanceInvoice($advance))
        ->toThrow(InvalidAdvanceInvoiceException::class, (string) $advance->id)
        ->and($entry->fresh()->advance_invoice_id)->toBeNull();
});

it('refuses the advance-side conflict from the materialized set and leaves the loser NULL without an UPDATE (O2/J1)', function () {
    $advance = aid962DraftAdvance(2900);
    $winner  = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);
    $loser   = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);

    $winner->linkAdvanceInvoice($advance);

    // The refusal names the WINNING entry and the loser's own row stays NULL
    // (J1: the conflict set throws BEFORE any UPDATE is issued).
    expect(fn () => $loser->linkAdvanceInvoice($advance))
        ->toThrow(CreditMovementAlreadyLinkedException::class, (string) $winner->id)
        ->and($loser->fresh()->advance_invoice_id)->toBeNull()
        ->and($winner->fresh()->advance_invoice_id)->toBe($advance->id);
});

it('refuses to link an exit', function () {
    $advance = aid962DraftAdvance(2900);
    $entry   = CreditMovement::factory()->create();
    $exit    = aid962Exit($entry, -1000);

    expect(fn () => $exit->linkAdvanceInvoice($advance))->toThrow(InvalidCreditMovementException::class)
        ->and($exit->fresh()->advance_invoice_id)->toBeNull();
});
