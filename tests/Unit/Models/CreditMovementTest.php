<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;

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
    $advanceA = PaymentFixtures::sentInvoice(2900);
    $advanceB = PaymentFixtures::sentInvoice(2900);

    $entry = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);
    $twin  = CreditMovement::find($entry->id); // loaded before the first link

    $entry->linkAdvanceInvoice($advanceA);

    expect($entry->advance_invoice_id)->toBe($advanceA->id)
        ->and($entry->advanceInvoice->id)->toBe($advanceA->id);

    expect(fn () => $twin->linkAdvanceInvoice($advanceB))->toThrow(CreditMovementAlreadyLinkedException::class)
        ->and($entry->fresh()->advance_invoice_id)->toBe($advanceA->id)
        ->and($twin->advance_invoice_id)->toBe($advanceA->id); // refreshed after the refusal
});

it('refuses to link an exit', function () {
    $advance = PaymentFixtures::sentInvoice(2900);
    $entry   = CreditMovement::factory()->create();
    $exit    = aid962Exit($entry, -1000);

    expect(fn () => $exit->linkAdvanceInvoice($advance))->toThrow(InvalidCreditMovementException::class)
        ->and($exit->fresh()->advance_invoice_id)->toBeNull();
});
