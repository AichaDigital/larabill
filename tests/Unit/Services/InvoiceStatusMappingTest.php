<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvalidInvoiceStatusException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-838 — the documented `status` input is validated, not guessed.
 *
 * `createInvoice()` documents `status?: string|int`, and the mapping behind it
 * accepted four of the seven enum cases while silently turning ANY other
 * string into DRAFT: `'sent'`, `'overdue'`, `'converted'` and every typo
 * produced a draft the consumer never asked for. The integer branch had the
 * mirror defect — it returned whatever it was given without checking it was a
 * real case, so an out-of-range value blew up later inside Eloquent's enum
 * cast with a raw ValueError instead of a domain error.
 *
 * The accepted strings are now DERIVED from the enum rather than listed by
 * hand: a list maintained in parallel is what fell out of sync in the first
 * place, and adding a case to InvoiceStatus must never silently become a
 * fallback again. Note the labels are NOT the source — `InvoiceStatus::label()`
 * returns translations, so the case name is what maps.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
    $this->service  = app(InvoiceService::class);
});

it('accepts every enum case by name, including the three the old map forgot', function (InvoiceStatus $case) {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => mb_strtolower($case->name),
        'items'            => [],
    ]);

    expect($invoice->status)->toBe($case);
})->with(fn () => collect(InvoiceStatus::cases())->mapWithKeys(fn (InvoiceStatus $c) => [$c->name => $c])->all());

it('accepts every enum case by its integer value', function (InvoiceStatus $case) {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => $case->value,
        'items'            => [],
    ]);

    expect($invoice->status)->toBe($case);
})->with(fn () => collect(InvoiceStatus::cases())->mapWithKeys(fn (InvoiceStatus $c) => [$c->name => $c])->all());

it('is case insensitive, as it always was', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 'PeNdInG',
        'items'            => [],
    ]);

    expect($invoice->status)->toBe(InvoiceStatus::PENDING);
});

it('refuses an unknown status string instead of quietly issuing a draft', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 'porcessed',   // the typo a consumer actually makes
        'items'            => [],
    ]))->toThrow(InvalidInvoiceStatusException::class);
});

it('refuses an integer that is not a case, instead of leaking a raw ValueError', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 99,
        'items'            => [],
    ]))->toThrow(InvalidInvoiceStatusException::class);
});

it('stays catchable as InvalidArgumentException for consumers written against the old contract', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => 'nope',
        'items'            => [],
    ]))->toThrow(InvalidArgumentException::class);
});

it('names the offending value and the accepted ones in the message', function () {
    try {
        $this->service->createInvoice([
            'billable_user_id' => $this->customer->id,
            'status'           => 'porcessed',
            'items'            => [],
        ]);
        expect()->fail('expected InvalidInvoiceStatusException');
    } catch (InvalidInvoiceStatusException $e) {
        expect($e->getMessage())->toContain('porcessed')
            ->and($e->getMessage())->toContain('converted');   // an accepted value is listed
    }
});

it('keeps defaulting to DRAFT when no status is given at all', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    expect($invoice->status)->toBe(InvoiceStatus::DRAFT);
});
