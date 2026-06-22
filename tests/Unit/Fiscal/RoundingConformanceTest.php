<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\InvoiceItem;

/*
|--------------------------------------------------------------------------
| EU/Spain rounding conformance (AID-237)
|--------------------------------------------------------------------------
|
| Monetary amounts settle to the nearest cent using HalfUp (round half away
| from zero), NOT truncation. The line taxable amount is quantity × unit_price
| settled to 2 decimals; a fractional quantity can yield a half-cent that MUST
| round up. These tests assert the NORM-correct value, not the legacy truncated
| one. AID-237.
|
*/

it('rounds the line taxable amount half-up per EU/Spain norm', function () {
    // 1.50 units × €12.33 = €18.4950 → €18.50 (legacy truncation gave €18.49).
    $item = InvoiceItem::factory()->make([
        'quantity'   => cents(150),
        'unit_price' => cents(1233),
    ]);

    expect($item->calculateTaxableAmount()->unscaledValue())->toBe(1850);
});

it('leaves an exact line taxable amount unchanged', function () {
    // 2.50 units × €10.00 = €25.00 exactly.
    $item = InvoiceItem::factory()->make([
        'quantity'   => cents(250),
        'unit_price' => cents(1000),
    ]);

    expect($item->calculateTaxableAmount()->unscaledValue())->toBe(2500);
});
