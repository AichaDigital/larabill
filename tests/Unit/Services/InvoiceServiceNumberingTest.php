<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-390 PR2 + ADR-016 §7.1/§6.1 (PR-2) — InvoiceService derives its
 * numbering from the hardened InvoiceNumberingService (single owner) instead
 * of its legacy rand()-based fiscal_number + MAX+1 series_number, which were
 * non-correlative and could collide.
 *
 * PR-2 re-points this file to the flipped lifecycle: the FISCAL contract does
 * NOT number at birth — the counter moves only inside issue() (§6.1, scoped
 * to fiscal documents by round 12/13 V17; the PROFORMA branch keeps its birth
 * call, §3.1).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
});

it('derives fiscal_number, prefix, series_number and fiscal_year from the series control AT ISSUANCE', function () {
    $service = app(InvoiceService::class);

    // §6.1: the documents under test are born as explicit drafts (§7.2) so
    // the counter movement can be observed at issuance alone.
    $first  = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => [], 'status' => 'draft']);
    $second = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => [], 'status' => 'draft']);

    // §7.1: explicit-draft births are unnumbered — the prefix is stamped (§8.1), nothing else.
    expect($first->fiscal_number)->toBeNull()
        ->and($first->prefix)->toBe('FAC')
        ->and($first->series_number)->toBeNull()
        ->and($second->series_number)->toBeNull();

    // No counter row exists yet: on FISCAL documents, invoice_series_control
    // moves only at issuance (§6.1).
    expect(InvoiceSeriesControl::count())->toBe(0);

    // issue() burns exactly one number per document, from the series control.
    $issuedFirst  = $service->issue($first);
    $issuedSecond = $service->issue($second);

    expect($issuedFirst->fiscal_number)->toBe('FAC-'.now()->year.'-000001')
        ->and($issuedFirst->series_number)->toBe(1)
        ->and($issuedSecond->fiscal_number)->toBe('FAC-'.now()->year.'-000002')
        ->and($issuedSecond->series_number)->toBe(2);

    $control = InvoiceSeriesControl::where('prefix', 'FAC')->sole();
    expect($control->last_number)->toBe(2)
        ->and($control->user_id)->toBe(InvoiceSeriesControl::GLOBAL_SCOPE);
});

it('numbers proformas at birth on their own PRO series, fiscal births consuming nothing', function () {
    $service = app(InvoiceService::class);

    $draft    = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => [], 'status' => 'draft']);
    $proforma = $service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'type'             => 'proforma',
        'items'            => [],
    ]);

    expect($proforma->fiscal_number)->toBe('PRO-'.now()->year.'-000001')
        ->and($proforma->prefix)->toBe('PRO')
        ->and($proforma->series_number)->toBe(1);

    // §6.1 (rounds 12/13, V17): the ONLY counter row so far is the PROFORMA
    // one — the fiscal birth consumed nothing.
    expect(InvoiceSeriesControl::count())->toBe(1)
        ->and(InvoiceSeriesControl::sole()->prefix)->toBe('PRO')
        ->and($draft->fiscal_number)->toBeNull();
});
