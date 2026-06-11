<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\InvoiceVerifactuService;
use AichaDigital\Larabill\Tests\Models\User;
use AichaDigital\LaraVerifactu\Models\Invoice as VerifactuInvoice;

beforeEach(function () {
    $this->service = new InvoiceVerifactuService;
    $this->user    = User::factory()->create();

    $this->invoice = Invoice::factory()->create([
        'user_id'          => $this->user->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'is_roi_taxed'     => false,
        'issued_at'        => '2026-06-01 10:30:00',
        'invoice_date'     => '2026-06-01',
    ]);

    InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'taxes_applied'    => [
            ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100],
        ],
    ]);
});

describe('InvoiceVerifactuService::registerInvoice', function () {
    it('creates a native verifactu invoice with grouped tax breakdowns', function () {
        $verifactuInvoice = $this->service->registerInvoice($this->invoice);

        expect($verifactuInvoice)->toBeInstanceOf(VerifactuInvoice::class)
            ->and($verifactuInvoice->exists)->toBeTrue()
            ->and($verifactuInvoice->issue_datetime)->not->toBeNull()
            ->and($verifactuInvoice->breakdowns)->toHaveCount(1);

        $breakdown = $verifactuInvoice->breakdowns->first();

        expect((float) $breakdown->tax_rate)->toBe(21.0)
            ->and((float) $breakdown->base_amount)->toBe(100.0)
            ->and((float) $breakdown->tax_amount)->toBe(21.0);
    });

    it('links the verifactu invoice back to the larabill invoice through metadata', function () {
        $verifactuInvoice = $this->service->registerInvoice($this->invoice);

        expect($verifactuInvoice->metadata['larabill_invoice_id'])->toBe($this->invoice->id);
    });

    it('can skip breakdown creation when requested', function () {
        $verifactuInvoice = $this->service->registerInvoice($this->invoice, withBreakdowns: false);

        expect($verifactuInvoice->breakdowns)->toHaveCount(0);
    });
});

describe('InvoiceVerifactuService::isRegistered', function () {
    it('reports registration state for a larabill invoice', function () {
        expect($this->service->isRegistered($this->invoice))->toBeFalse();

        $this->service->registerInvoice($this->invoice);

        expect($this->service->isRegistered($this->invoice))->toBeTrue();
    });

    it('returns the verifactu invoice for a registered larabill invoice', function () {
        $created = $this->service->registerInvoice($this->invoice);

        $found = $this->service->getVerifactuInvoice($this->invoice);

        expect($found?->id)->toBe($created->id);
    });
});

describe('InvoiceVerifactuService::validateForVerifactu', function () {
    it('rejects invoices that are not ready for fiscal registration', function () {
        $draft = Invoice::factory()->create([
            'user_id'      => $this->user->id,
            'is_immutable' => false,
            'total_amount' => 0,
        ]);

        $result = $this->service->validateForVerifactu($draft);

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'])->not->toBeEmpty();
    });

    it('flags already registered invoices as invalid', function () {
        $this->service->registerInvoice($this->invoice);

        $result = $this->service->validateForVerifactu($this->invoice);

        expect($result['valid'])->toBeFalse()
            ->and($result['errors'])->toContain('Invoice is already registered with Verifactu');
    });
});
