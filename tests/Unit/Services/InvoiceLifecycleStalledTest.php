<?php

declare(strict_types=1);

use AichaDigital\Larabill\Console\StalledPreparationsCommand;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ADR-016 §11.3 (PR-2, v7 Codex 58 adopted) — the stalled CONVERTING+PREPARED
 * state is NEW failure territory R1 creates: a queryable scope plus an
 * artisan report listing the aggregates awaiting issue() or cancellation.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    UserTaxProfile::factory()->create([
        'owner_user_id' => TestCase::USER_UUID_1,
        'tax_id'        => 'B12345678',
        'valid_until'   => null,
    ]);
});

it('queries the stalled preparations: conversion-born documents sitting at PREPARED', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    // Nothing stalled yet.
    expect(Invoice::stalledPreparations()->count())->toBe(0);

    $prepared = $service->convertProformaToInvoice($proforma)->invoice;

    // The legal resumable aggregate IS the stalled population.
    $stalled = Invoice::stalledPreparations()->get();
    expect($stalled)->toHaveCount(1)
        ->and($stalled->first()->id)->toBe($prepared->id)
        ->and($stalled->first()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTING);

    // Issuance clears the population; cancellation does too.
    $service->issue($prepared);
    expect(Invoice::stalledPreparations()->count())->toBe(0);
});

it('reports the stalled aggregates through larabill:stalled-preparations (table and JSON)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);
    $prepared = $service->convertProformaToInvoice($proforma)->invoice;

    // Table mode: the aggregate is listed, read-only (exit 0 in any state).
    $this->artisan(StalledPreparationsCommand::class)
        ->expectsOutputToContain('1 stalled preparation(s)')
        ->expectsOutputToContain((string) $prepared->id)
        ->assertSuccessful();

    // JSON mode: machine-readable, with the proforma status.
    Artisan::call('larabill:stalled-preparations', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($json['count'])->toBe(1)
        ->and($json['items'][0]['invoice_id'])->toBe((string) $prepared->id)
        ->and($json['items'][0]['proforma_id'])->toBe((string) $proforma->id);

    // A clean installation reports zero, loud and green.
    $service->issue($prepared);
    $this->artisan(StalledPreparationsCommand::class)
        ->expectsOutputToContain('No stalled preparations')
        ->assertSuccessful();
});

it('registers the larabill:stalled-preparations command', function () {
    expect(array_key_exists('larabill:stalled-preparations', app('Illuminate\Contracts\Console\Kernel')->all()))
        ->toBeTrue();
});
