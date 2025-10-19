<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};
use AichaDigital\Larabill\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can have items relationship', function () {
    $user    = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id]);

    InvoiceItem::factory()->count(3)->create(['invoice_id' => $invoice->id]);

    expect($invoice->items)->toHaveCount(3);
});

it('can have user relationship', function () {
    $user    = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id]);

    expect($invoice->user)->toBeInstanceOf(User::class)
        ->and($invoice->user->id)->toBe($user->id);
});

it('can have proforma relationship', function () {
    $user     = User::factory()->create();
    $proforma = Invoice::factory()->create(['user_id' => $user->id]);
    $invoice  = Invoice::factory()->create([
        'user_id'     => $user->id,
        'proforma_id' => $proforma->id,
    ]);

    expect($invoice->proforma)->toBeInstanceOf(Invoice::class)
        ->and($invoice->proforma->id)->toBe($proforma->id);
});

it('can have rectificative relationship', function () {
    $user     = User::factory()->create();
    $original = Invoice::factory()->create(['user_id' => $user->id]);
    $rectif   = Invoice::factory()->create([
        'user_id'              => $user->id,
        'rectifies_invoice_id' => $original->id,
    ]);

    expect($rectif->rectifiesInvoice)->toBeInstanceOf(Invoice::class)
        ->and($rectif->rectifiesInvoice->id)->toBe($original->id);
});

it('can have rectificatives collection', function () {
    $user     = User::factory()->create();
    $original = Invoice::factory()->create(['user_id' => $user->id]);

    Invoice::factory()->count(2)->create([
        'user_id'              => $user->id,
        'rectifies_invoice_id' => $original->id,
    ]);

    expect($original->rectificatives)->toHaveCount(2);
});

it('can have converted invoices collection', function () {
    $user     = User::factory()->create();
    $proforma = Invoice::factory()->create(['user_id' => $user->id]);

    Invoice::factory()->count(2)->create([
        'user_id'     => $user->id,
        'proforma_id' => $proforma->id,
    ]);

    expect($proforma->convertedInvoices)->toHaveCount(2);
});
