<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-558 (D8) — immutability must protect the lines, not only the header.
 *
 * Invoice::update() guards fiscal content on immutable invoices;
 * InvoiceItem had no guard at all, so a fiscally frozen invoice could have
 * its lines rewritten freely. Same pattern as the header guard: update() is
 * blocked, save() stays as the deliberate internal door. Raw SQL, query
 * builder mass updates and external writes remain outside this guarantee
 * (application-level guard — the DB-level half was cancelled in AID-468).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
});

function aid558InvoiceWithItem(bool $immutable): array
{
    $service = app(InvoiceService::class);
    $invoice = $service->createInvoice([
        'billable_user_id' => test()->customer->id,
        'items'            => [['description' => 'Original line', 'quantity' => 100, 'base_price' => 5000]],
    ]);

    if ($immutable) {
        $invoice->makeImmutable();
    }

    return [$invoice->fresh(), $invoice->items()->first()];
}

it('blocks updating a line of an immutable invoice', function () {
    [$invoice, $item] = aid558InvoiceWithItem(immutable: true);

    expect(fn () => $item->update(['description' => 'tampered']))
        ->toThrow(Exception::class, 'immutable');

    expect($item->fresh()->description)->toBe('Original line');
});

it('blocks rewriting the frozen amounts of an immutable invoice line', function () {
    [$invoice, $item] = aid558InvoiceWithItem(immutable: true);

    expect(fn () => $item->update(['total_amount' => cents(1)]))
        ->toThrow(Exception::class, 'immutable');
});

it('still allows updating lines while the invoice is mutable', function () {
    [$invoice, $item] = aid558InvoiceWithItem(immutable: false);

    $item->update(['description' => 'Edited line']);

    expect($item->fresh()->description)->toBe('Edited line');
});

it('leaves orphan items updatable (no parent invoice to freeze them)', function () {
    [$invoice, $item] = aid558InvoiceWithItem(immutable: true);

    // Simulate a line whose invoice row is gone (no FK cascade in play here):
    // the guard must not crash on a null relation.
    $orphan = InvoiceItem::query()->findOrFail($item->id);
    $orphan->setRelation('invoice', null);
    Invoice::query()->whereKey($invoice->id)->delete();

    $orphan->update(['description' => 'orphan edit']);

    expect($orphan->fresh()->description)->toBe('orphan edit');
});
