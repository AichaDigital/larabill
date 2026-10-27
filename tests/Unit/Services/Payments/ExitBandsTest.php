<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Services\Payments\ExitBands;
use Illuminate\Support\Str;

mutates(ExitBands::class);

/*
 * AID-1778 — ExitBands::segmentsFor() directly: the cent-tape placement rules
 * the two exit creators rely on (spec §5.3–§5.4), including the defensive
 * cannotPlaceExit guard, which is unreachable through the public creators
 * (the free tape is always at least the entry's remaining balance) and can
 * therefore only be exercised at this seam.
 */

/**
 * A persisted ENTRY of gross $grossCents (segmentsFor() reads the instance's
 * amount/id and queries the exits table).
 */
function aid1778TapeEntry(int $grossCents): CreditMovement
{
    return CreditMovement::factory()->create([
        'kind'     => CreditMovementKind::SURPLUS,
        'amount'   => cents($grossCents),
        'currency' => 'EUR',
    ]);
}

/**
 * One ACTIVE exit row persisted on $entry's tape.
 */
function aid1778SeedExit(CreditMovement $entry, int $takeCents, ?int $bandStart): CreditMovement
{
    return CreditMovement::factory()->create([
        'billable_user_id'     => $entry->billable_user_id,
        'currency'             => $entry->currency,
        'kind'                 => CreditMovementKind::APPLIED,
        'amount'               => cents(-$takeCents),
        'grouped_payment_id'   => $entry->grouped_payment_id,
        'consumes_movement_id' => $entry->id,
        'band_start'           => $bandStart,
    ]);
}

it('places a take on a fresh tape at [0, take)', function () {
    $entry = aid1778TapeEntry(10000);

    expect(ExitBands::segmentsFor($entry, 3000))->toBe([[0, 3000]]);
});

it('fills the LOWEST gap first — a refilled hole beats the frontier', function () {
    $entry = aid1778TapeEntry(10000);
    // Active bands [1000,2000): the hole [0,1000) and the frontier [2000,10000).
    $hole = aid1778SeedExit($entry, 1000, 0);
    aid1778SeedExit($entry, 1000, 1000);
    CreditMovement::query()->whereKey($hole->id)->update(['reversed_at' => now()]);

    // Only [1000,2000) is active: the free tape is the hole [0,1000) plus [2000,10000).
    expect(ExitBands::segmentsFor($entry, 500))->toBe([[0, 500]]);
});

it('splits a take spanning two gaps into one segment per gap, ascending', function () {
    $entry = aid1778TapeEntry(10000);
    // Active band [1000,2000): hole [0,1000) + frontier [2000,10000).
    aid1778SeedExit($entry, 1000, 1000);

    expect(ExitBands::segmentsFor($entry, 1500))->toBe([[0, 1000], [2000, 500]]);
});

it('fills gaps exactly and partially, ascending, until the take is placed', function () {
    $entry = aid1778TapeEntry(10000);
    aid1778SeedExit($entry, 6000, 0); // band [0,6000): frontier gap [6000,10000)

    expect(ExitBands::segmentsFor($entry, 4000))->toBe([[6000, 4000]])
        ->and(ExitBands::segmentsFor($entry, 2500))->toBe([[6000, 2500]]);
});

it('propagates NULL when an active exit already carries a NULL band', function () {
    $entry = aid1778TapeEntry(10000);
    aid1778SeedExit($entry, 2000, null);

    expect(ExitBands::segmentsFor($entry, 1000))->toBeNull();
});

it('throws cannotPlaceExit when the take exceeds the total free tape — zero segments returned', function () {
    $entry = aid1778TapeEntry(10000);
    // Band [8000,17000): extends beyond G — only [0,8000) is free (8000 cents).
    aid1778SeedExit($entry, 9000, 8000);

    expect(fn () => ExitBands::segmentsFor($entry, 9000))
        ->toThrow(InvalidAdvanceInvoiceException::class);
});

it('refuses a non-positive take or a non-entry tape', function () {
    $entry = aid1778TapeEntry(10000);

    expect(fn () => ExitBands::segmentsFor($entry, 0))->toThrow(LogicException::class)
        ->and(fn () => ExitBands::segmentsFor($entry, -5))->toThrow(LogicException::class);
});

it('is only defined for ENTRY tapes (an exit has no tape of its own)', function () {
    $exit = CreditMovement::factory()->make([
        'kind'                 => CreditMovementKind::APPLIED,
        'amount'               => cents(-1000),
        'consumes_movement_id' => (string) Str::orderedUuid(),
    ]);

    expect(fn () => ExitBands::segmentsFor($exit, 100))->toThrow(LogicException::class);
});
