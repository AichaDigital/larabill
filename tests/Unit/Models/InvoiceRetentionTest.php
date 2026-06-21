<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Invoice retention contract', function () {
    it('implements the LegallyRetainable privacy contract', function () {
        expect(Invoice::factory()->make())->toBeInstanceOf(LegallyRetainable::class);
    });

    it('retains a fiscal invoice until fiscal-year-end of invoice_date plus the configured years', function () {
        $invoice = Invoice::factory()->make([
            'serie'        => InvoiceSerieType::INVOICE->value,
            'invoice_date' => '2023-05-15',
        ]);

        // Anchor = end of the fiscal year of the invoice date (conservative),
        // plus the default 6 commercial-accounting years.
        expect($invoice->retainedUntil())
            ->toBeInstanceOf(DateTimeInterface::class)
            ->and($invoice->retainedUntil()->format('Y-m-d'))->toBe('2029-12-31');
    });

    it('treats simplified and rectificative invoices as fiscal too', function () {
        foreach ([InvoiceSerieType::SIMPLIFIED, InvoiceSerieType::RECTIFICATIVE] as $serie) {
            $invoice = Invoice::factory()->make([
                'serie'        => $serie->value,
                'invoice_date' => '2023-05-15',
            ]);

            expect($invoice->retainedUntil()?->format('Y-m-d'))->toBe('2029-12-31');
        }
    });

    it('carries no retention for a proforma (not a fiscal document)', function () {
        $invoice = Invoice::factory()->make([
            'serie'        => InvoiceSerieType::PROFORMA->value,
            'invoice_date' => '2023-05-15',
        ]);

        expect($invoice->retainedUntil())->toBeNull();
    });

    it('carries no retention when a fiscal invoice has no legal date yet (decision B)', function () {
        $invoice = Invoice::factory()->make([
            'serie'        => InvoiceSerieType::INVOICE->value,
            'invoice_date' => null,
        ]);

        expect($invoice->retainedUntil())->toBeNull();
    });

    it('reads the retention duration from larabill config (decision A)', function () {
        config(['larabill.retention.fiscal_years' => 10]);

        $invoice = Invoice::factory()->make([
            'serie'        => InvoiceSerieType::INVOICE->value,
            'invoice_date' => '2023-05-15',
        ]);

        expect($invoice->retainedUntil()?->format('Y-m-d'))->toBe('2033-12-31');
    });
});
