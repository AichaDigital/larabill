<?php

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Exceptions\NonProportionableLineException;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\SettlementArithmetic;

/**
 * AID-971 (ADR-014 eje 2) — SettlementArithmetic contract: the proportional
 * unconsumed-time arithmetic over an EMITTED line.
 *
 * ADR-014 §3 eje 2: the base is "proporcional al tiempo no consumido de la
 * línea emitida" — the ONLY arithmetic larabill offers. The base is what was
 * ACTUALLY BILLED (the emitted line's `taxable_amount`, ADR-013 doctrine),
 * never the contract price or the live catalogue. The service period comes
 * from the line's own `service_date_from` / `service_date_to`; larabill
 * never invents a period (AID-589 doctrine).
 *
 * Pure arithmetic: the tests instantiate `InvoiceItem` directly with the
 * model's real casts (FixedDecimal base-100, `date` service dates) — no DB,
 * no emission, no catalogue. Day counting is INCLUSIVE on both ends at day
 * granularity; `$consumedUntil` is the LAST consumed day (inclusive).
 */
/**
 * Build an emitted service line with the model's real casts. `$taxableUnscaled`
 * is base-100 unscaled cents (e.g. 10000 = €100.00). Direct instantiation —
 * pure arithmetic, no persistence needed.
 *
 * Helper name UNIQUE across the suite (AID-502): file-level Pest helpers
 * share the global namespace of a combined run and a duplicate declaration
 * fatals the whole run.
 */
function makeSettlementArithmeticLine(int $taxableUnscaled, ?string $from, ?string $to): InvoiceItem
{
    return new InvoiceItem([
        'item_type'         => ItemType::SERVICE,
        'description'       => 'Línea de servicio emitida',
        'quantity'          => FixedDecimal::ofUnscaled(100, 2),
        'unit_price'        => FixedDecimal::ofUnscaled($taxableUnscaled, 2),
        'taxable_amount'    => FixedDecimal::ofUnscaled($taxableUnscaled, 2),
        'total_tax_amount'  => FixedDecimal::ofUnscaled(0, 2),
        'total_amount'      => FixedDecimal::ofUnscaled($taxableUnscaled, 2),
        'taxes_applied'     => [],
        'service_date_from' => $from,
        'service_date_to'   => $to,
    ]);
}

it('returns 0 when the whole service period was consumed', function () {
    // 20 days (Jan 1..Jan 20 inclusive); consumed through the last day.
    $line = makeSettlementArithmeticLine(10000, '2026-01-01', '2026-01-20');

    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-20'));

    expect($base)->toBe(0);
});

it('returns the full base when nothing was consumed', function () {
    $line = makeSettlementArithmeticLine(10000, '2026-01-01', '2026-01-20');

    // Until strictly BEFORE the first service day: full refund.
    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2025-12-31'));

    expect($base)->toBe(10000);
});

it('returns 0 when the consumption date extends beyond the service period', function () {
    $line = makeSettlementArithmeticLine(10000, '2026-01-01', '2026-01-20');

    // At or after service_date_to the line is fully consumed — never negative.
    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-02-15'));

    expect($base)->toBe(0);
});

it('returns the exact proportion of unconsumed days', function () {
    // 10 of 20 days unconsumed over a €100.00 base → €50.00.
    $line = makeSettlementArithmeticLine(10000, '2026-01-01', '2026-01-20');

    // Consumed through Jan 10 (inclusive) → 10 consumed days, 10 unconsumed.
    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-10'));

    expect($base)->toBe(5000);
});

it('counts the boundary day as consumed, not zero', function () {
    $line = makeSettlementArithmeticLine(10000, '2026-01-01', '2026-01-20');

    // until == service_date_from: the FIRST day was consumed (inclusive
    // counting) → 1 consumed day, 19 unconsumed → 10000 × 19/20 = 9500.
    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-01'));

    expect($base)->toBe(9500);
});

it('rounds the exact half-cent UP (half-up pin)', function () {
    // 16-day period, base €10.00, 1 unconsumed day → exactly 10.00 × 1/16
    // = 0.625 € = 62.5 cents. Half-up must round UP to 63 — never truncate
    // to 62 and never drift through float math.
    $line = makeSettlementArithmeticLine(1000, '2026-01-01', '2026-01-16');

    // Consumed through Jan 15 (15 days) → 1 unconsumed day.
    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-15'));

    expect($base)->toBe(63);
});

it('refuses a line without service_date_from — larabill never invents a period', function () {
    $line = makeSettlementArithmeticLine(10000, null, '2026-01-20');

    (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-10'));
})->throws(NonProportionableLineException::class);

it('refuses a line without service_date_to — larabill never invents a period', function () {
    $line = makeSettlementArithmeticLine(10000, '2026-01-01', null);

    (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-10'));
})->throws(NonProportionableLineException::class);

it('refuses an inverted service period', function () {
    $line = makeSettlementArithmeticLine(10000, '2026-02-10', '2026-02-01');

    (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-02-05'));
})->throws(NonProportionableLineException::class);

it('refuses a negative base — a credit line is not proportionable', function () {
    $line = makeSettlementArithmeticLine(-5000, '2026-01-01', '2026-01-20');

    (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-10'));
})->throws(NonProportionableLineException::class);

it('returns 0 for a zero base — nothing to distribute', function () {
    $line = makeSettlementArithmeticLine(0, '2026-01-01', '2026-01-20');

    $base = (new SettlementArithmetic)->unconsumedBase($line, new DateTimeImmutable('2026-01-10'));

    expect($base)->toBe(0);
});
