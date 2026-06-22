<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceVerifactuService;
use AichaDigital\Larabill\Tests\Models\User;
use AichaDigital\LaraVerifactu\Models\Invoice as VerifactuInvoice;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->service = new InvoiceVerifactuService;
    $this->user    = User::factory()->create();

    $this->invoice = Invoice::factory()->create(fdMoney([
        'user_id'          => $this->user->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'is_roi_taxed'     => false,
        'issued_at'        => '2026-06-01 10:30:00',
        'invoice_date'     => '2026-06-01',
    ], ['taxable_amount', 'total_tax_amount', 'total_amount']));

    InvoiceItem::factory()->create(fdMoney([
        'invoice_id'       => $this->invoice->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'taxes_applied'    => [
            ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100],
        ],
    ], ['taxable_amount', 'total_tax_amount', 'total_amount']));
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

    it('does not leave an orphan verifactu invoice when a breakdown guard rejects the invoice', function () {
        // AID-136: a guard that throws after VerifactuInvoice::create would leave an
        // orphan row, making isRegistered() return true and blocking the retry.
        // Build-before-persist (+ transaction) must roll the registration back.
        $invoice = Invoice::factory()->create(fdMoney([
            'user_id'          => $this->user->id,
            'taxable_amount'   => 10000,
            'total_tax_amount' => 0,
            'total_amount'     => 10000,
            'is_roi_taxed'     => true, // reverse-charge signalled but recipient is incomplete
            'issued_at'        => '2026-06-01 10:30:00',
            'invoice_date'     => '2026-06-01',
        ], ['taxable_amount', 'total_tax_amount', 'total_amount']));

        InvoiceItem::factory()->create(fdMoney([
            'invoice_id'       => $invoice->id,
            'taxable_amount'   => 10000,
            'total_tax_amount' => 0,
            'total_amount'     => 10000,
            'item_type'        => ItemType::SERVICE,
            'taxes_applied'    => [],
        ], ['taxable_amount', 'total_tax_amount', 'total_amount']));

        expect(fn () => $this->service->registerInvoice($invoice))
            ->toThrow(ValidationException::class);

        expect($this->service->isRegistered($invoice))->toBeFalse();
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
        $draft = Invoice::factory()->create(fdMoney([
            'user_id'      => $this->user->id,
            'is_immutable' => false,
            'total_amount' => 0,
        ], ['total_amount']));

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

    it('accepts a fully fit invoice', function () {
        // Regression: the check resolved the non-existent `taxProfile`
        // relation (the model defines `userTaxProfile`), so every invoice
        // failed with "must have a tax profile" regardless of its data.
        $taxProfile = UserTaxProfile::factory()->create([
            'owner_user_id' => $this->user->id,
        ]);

        $invoice = Invoice::factory()->create(fdMoney([
            'user_id'             => $this->user->id,
            'billable_user_id'    => $this->user->id,
            'user_tax_profile_id' => $taxProfile->id,
            'is_immutable'        => true,
            'immutable_at'        => now(),
            'taxable_amount'      => 10000,
            'total_tax_amount'    => 2100,
            'total_amount'        => 12100,
        ], ['taxable_amount', 'total_tax_amount', 'total_amount']));

        $result = $this->service->validateForVerifactu($invoice);

        expect($result['errors'])->toBe([])
            ->and($result['valid'])->toBeTrue();
    });

    it('accepts a fit invoice whose tax profile has been soft-deleted', function () {
        // AID-222: soft-deleting the referenced UserTaxProfile is fiscal-history
        // closure, not erasure. The immutable invoice keeps its snapshot, so
        // validation must not fail with "Invoice must have a tax profile".
        $taxProfile = UserTaxProfile::factory()->create([
            'owner_user_id' => $this->user->id,
        ]);

        $invoice = Invoice::factory()->create(fdMoney([
            'user_id'             => $this->user->id,
            'billable_user_id'    => $this->user->id,
            'user_tax_profile_id' => $taxProfile->id,
            'is_immutable'        => true,
            'immutable_at'        => now(),
            'taxable_amount'      => 10000,
            'total_tax_amount'    => 2100,
            'total_amount'        => 12100,
        ], ['taxable_amount', 'total_tax_amount', 'total_amount']));

        $taxProfile->delete();

        $result = $this->service->validateForVerifactu(Invoice::findOrFail($invoice->id));

        expect($result['errors'])->toBe([])
            ->and($result['valid'])->toBeTrue();
    });
});
