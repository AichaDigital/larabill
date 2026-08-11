<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\ReverseChargeWithTaxException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-929 (D4/D8) — reverse charge and real tax cannot coexist on a document.
 *
 * `TaxCalculationService` never reads `is_roi_taxed`, so the lines of a
 * proforma are calculated without knowing the qualification. Propagating the
 * flag (D1) therefore CREATES the possibility of a sealed invoice that both
 * claims reverse charge and charges VAT — today that combination is only
 * absent by accident, because the flag was being dropped.
 *
 * `VerifactuAdapter` already refuses an N2 breakdown carrying VAT (AEAT rule
 * 1237), but that happens at registration time, with the document already
 * born and sealed. This guard moves the refusal to issuance, inside the
 * transaction, so no row survives and no fiscal number is consumed.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
    $this->service  = app(InvoiceService::class);
});

/**
 * A frozen line (createInvoiceItem's `total_amount` branch) with the tax
 * figures stated verbatim — the same shape the conversion clones.
 *
 * @return array<string, mixed>
 */
function reverseChargeGuardLine(int $tax, ?array $taxesApplied = null): array
{
    return [
        'description'      => 'Guarded line',
        'quantity'         => 100,
        'base_price'       => 10000,
        'taxable_amount'   => 10000,
        'total_tax_amount' => $tax,
        'taxes_applied'    => $taxesApplied ?? ($tax !== 0 ? [['rate' => 2100, 'amount' => $tax]] : []),
        'total_amount'     => 10000 + $tax,
    ];
}

it('refuses to issue an invoice that claims reverse charge and carries real tax', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [reverseChargeGuardLine(2100)],
    ]))->toThrow(ReverseChargeWithTaxException::class);
});

it('leaves no row and no consumed number behind when it refuses', function () {
    // Warm the counter first: the steady state is what production looks like,
    // and a virgin series would have its control row created (and rolled back)
    // inside the same transaction, hiding whether the number was consumed.
    $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [reverseChargeGuardLine(0)],
    ]);

    $invoicesBefore = Invoice::count();
    $itemsBefore    = InvoiceItem::count();
    $numberBefore   = InvoiceSeriesControl::query()->max('last_number');

    try {
        $this->service->createInvoice([
            'billable_user_id' => $this->customer->id,
            'is_roi_taxed'     => true,
            'items'            => [reverseChargeGuardLine(2100)],
        ]);
    } catch (ReverseChargeWithTaxException) {
        // expected
    }

    expect(Invoice::count())->toBe($invoicesBefore)
        ->and(InvoiceItem::count())->toBe($itemsBefore)
        ->and(InvoiceSeriesControl::query()->max('last_number'))->toBe($numberBefore);
});

it('refuses the same combination on a proforma, where it can still be corrected cheaply', function () {
    expect(fn () => $this->service->createProforma([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [reverseChargeGuardLine(2100)],
    ]))->toThrow(ReverseChargeWithTaxException::class);
});

it('detects tax hidden in taxes_applied when the line total says zero', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [reverseChargeGuardLine(0, [['rate' => 2100, 'amount' => 2100]])],
    ]))->toThrow(ReverseChargeWithTaxException::class);
});

it('detects NEGATIVE tax, which the inherited > 0 test let through (AID-929 D8)', function () {
    expect(fn () => $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [reverseChargeGuardLine(-2100, [])],
    ]))->toThrow(ReverseChargeWithTaxException::class);
});

it('issues normally when reverse charge comes with no tax at all', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [reverseChargeGuardLine(0)],
    ]);

    expect($invoice->is_roi_taxed)->toBeTrue()
        ->and($invoice->hasRealTax())->toBeFalse();
});

it('leaves ordinary taxed invoices untouched', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [reverseChargeGuardLine(2100)],
    ]);

    expect($invoice->is_roi_taxed)->toBeFalse()
        ->and($invoice->hasRealTax())->toBeTrue();
});

it('owns the definition of real tax on the model, across the boundary cases', function () {
    $positive = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [reverseChargeGuardLine(2100)],
    ]);
    $negative = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [reverseChargeGuardLine(-2100, [])],
    ]);
    $jsonOnly = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [reverseChargeGuardLine(0, [['rate' => 2100, 'amount' => 2100]])],
    ]);
    $clean = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [reverseChargeGuardLine(0)],
    ]);

    expect($positive->hasRealTax())->toBeTrue()
        ->and($negative->hasRealTax())->toBeTrue()
        ->and($jsonOnly->hasRealTax())->toBeTrue()
        ->and($clean->hasRealTax())->toBeFalse();
});
