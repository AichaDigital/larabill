<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * AID-962 spec §7 (DECISION 4): the final F1 of an advance-paid sale carries a
 * NEGATIVE line that deducts the advance. It is an ordinary F1 (not an R1), the
 * line is persisted VERBATIM (frozen path) and records which advance it
 * deducts in `invoice_items.deducts_invoice_id`. Declared departure from the
 * ADR-014 credit-line rule; the AEAT sandbox acceptance is unverified.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->customer = TestUser::factory()->create();

    $group      = TaxGroup::factory()->create(['name' => 'IVA General']);
    $this->rate = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $group->taxRates()->attach($this->rate->id);

    $this->article = Article::factory()->create(['tax_group_id' => $group->id]);
    $this->advance = PaymentFixtures::sentInvoice(6050, (string) $this->customer->id);

    $this->finalInvoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [
            [
                'article_id'  => $this->article->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Full sale',
            ],
            [
                // FROZEN: no article_id, amounts stated verbatim.
                'description'        => 'Advance deduction',
                'quantity'           => 100,
                'base_price'         => -5000,
                'taxable_amount'     => -5000,
                'total_tax_amount'   => -1050,
                'taxes_applied'      => [['rate' => 2100, 'amount' => -1050]],
                'total_amount'       => -6050,
                'deducts_invoice_id' => (string) $this->advance->id,
            ],
        ],
    ]);
});

it('persists deducts_invoice_id and resolves the deducted advance invoice', function () {
    $line = $this->finalInvoice->items()->whereNotNull('deducts_invoice_id')->first();

    expect($line)->not->toBeNull()
        ->and((string) $line->deducts_invoice_id)->toBe((string) $this->advance->id)
        ->and($line->deductsInvoice?->is($this->advance))->toBeTrue()
        ->and($this->finalInvoice->items->whereNull('deducts_invoice_id'))->toHaveCount(1);
});

it('totals the F1 net of the deduction and reports real tax', function () {
    $invoice = Invoice::query()->with('items')->findOrFail($this->finalInvoice->id);

    expect($invoice->total_amount->unscaledValue())->toBe(6050)
        ->and($invoice->taxable_amount->unscaledValue())->toBe(5000)
        ->and($invoice->total_tax_amount->unscaledValue())->toBe(1050)
        ->and($invoice->hasRealTax())->toBeTrue();
});

it('maps the invoice to F1 for Verifactu, not to a rectificative', function () {
    // An F1 is "a full invoice to an identified recipient" (AEAT rule 1190);
    // the Feature TestCase has no UserTaxProfile snapshot, so the recipient
    // snapshot is stamped straight in the table, as VerifactuAdapterTest does.
    DB::table('invoices')->where('id', $this->finalInvoice->id)->update([
        'customer_snapshot' => Crypt::encryptString(json_encode([
            'tax_id' => 'B75685883', 'fiscal_name' => 'ACME SL', 'country_code' => 'ES',
        ])),
    ]);

    $invoice = Invoice::query()->with('items')->findOrFail($this->finalInvoice->id);

    expect(VerifactuAdapter::toVerifactuInvoice($invoice)['type'])->toBe('F1');
});

it('keeps the frozen deduction VAT when the catalogue rate changes before issuance', function () {
    // Rate changed BEFORE createInvoice(): the recalculated line must pick up
    // 10 %, the frozen deduction line (no article_id) must not read it at all.
    $this->rate->update(['rate' => 1000]);

    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [
            ['article_id' => $this->article->id, 'quantity' => 100, 'base_price' => 10000, 'description' => 'Full sale'],
            [
                'description'        => 'Advance deduction',
                'quantity'           => 100,
                'base_price'         => -5000,
                'taxable_amount'     => -5000,
                'total_tax_amount'   => -1050,
                'taxes_applied'      => [['rate' => 2100, 'amount' => -1050]],
                'total_amount'       => -6050,
                'deducts_invoice_id' => (string) $this->advance->id,
            ],
        ],
    ]);

    $deduction  = $invoice->items()->whereNotNull('deducts_invoice_id')->firstOrFail();
    $recomputed = $invoice->items()->whereNull('deducts_invoice_id')->firstOrFail();

    expect($recomputed->total_tax_amount->unscaledValue())->toBe(1000)
        ->and($deduction->article_id)->toBeNull()
        ->and($deduction->total_tax_amount->unscaledValue())->toBe(-1050)
        ->and($deduction->total_amount->unscaledValue())->toBe(-6050)
        ->and($deduction->taxes_applied[0]['amount'])->toBe(-1050);
});

it('persists deducts_invoice_id on a recalculated line too', function () {
    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [[
            'article_id'         => $this->article->id,
            'quantity'           => 100,
            'base_price'         => 10000,
            'description'        => 'Recalculated, tagged',
            'deducts_invoice_id' => (string) $this->advance->id,
        ]],
    ]);

    $line = $invoice->items()->firstOrFail();

    expect((string) $line->deducts_invoice_id)->toBe((string) $this->advance->id)
        ->and($line->total_tax_amount->unscaledValue())->toBe(2100);
});
