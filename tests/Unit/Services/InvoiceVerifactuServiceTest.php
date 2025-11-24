<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Customer, Invoice, InvoiceItem, LegalEntityType, UserTaxProfile};
use AichaDigital\Larabill\Services\InvoiceVerifactuService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    LegalEntityType::factory()->create([
        'code'         => 'J',
        'name'         => 'Sociedad Anónima',
        'country_code' => 'ES',
        'is_company'   => true,
    ]);

    $this->customer = Customer::factory()->create([
        'display_name'           => 'Test Customer',
        'legal_entity_type_code' => 'J',
    ]);

    $fakeUserId = hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789012'));

    $this->taxProfile = UserTaxProfile::create([
        'user_id'       => $fakeUserId,
        'business_name' => 'Test Company',
        'address'       => 'Test Address',
        'city'          => 'Madrid',
        'postal_code'   => '28001',
        'country'       => 'ES',
        'tax_code'      => 'ESA12345678',
    ]);

    $this->invoice = Invoice::factory()->create([
        'user_id'          => $fakeUserId,
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'fiscal_number'    => 'FAC-2025-001',
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'is_immutable'     => true,
    ]);

    $this->service = new InvoiceVerifactuService;
});

it('validates invoice before registration', function () {
    $validation = $this->service->validateForVerifactu($this->invoice);

    expect($validation['valid'])->toBeTrue();
    expect($validation['errors'])->toBeEmpty();
});

it('detects invalid invoice (not immutable)', function () {
    $invoice = Invoice::factory()->create([
        'user_id'        => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'    => $this->customer->id,
        'tax_profile_id' => $this->taxProfile->id,
        'is_immutable'   => false, // Not immutable
    ]);

    $validation = $this->service->validateForVerifactu($invoice);

    expect($validation['valid'])->toBeFalse();
    expect($validation['errors'])->toContain('Invoice must be immutable before Verifactu registration');
});

it('detects invalid invoice (zero total)', function () {
    $invoice = Invoice::factory()->create([
        'user_id'        => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'    => $this->customer->id,
        'tax_profile_id' => $this->taxProfile->id,
        'total_amount'   => 0, // Zero amount
        'is_immutable'   => true,
    ]);

    $validation = $this->service->validateForVerifactu($invoice);

    expect($validation['valid'])->toBeFalse();
    expect($validation['errors'])->toContain('Invoice total amount must be greater than zero');
});

it('registers invoice with Verifactu', function () {
    $verifactuInvoice = $this->service->registerInvoice($this->invoice, withBreakdowns: false);

    expect($verifactuInvoice)->toBeInstanceOf(\AichaDigital\LaraVerifactu\Models\Invoice::class);
    expect($verifactuInvoice->base_amount)->toBe('100.00');
    expect($verifactuInvoice->tax_amount)->toBe('21.00');
    expect($verifactuInvoice->total_amount)->toBe('121.00');
});

it('detects if invoice is already registered', function () {
    expect($this->service->isRegistered($this->invoice))->toBeFalse();

    $this->service->registerInvoice($this->invoice, withBreakdowns: false);

    expect($this->service->isRegistered($this->invoice))->toBeTrue();
});

it('prevents double registration', function () {
    $this->service->registerInvoice($this->invoice, withBreakdowns: false);

    $validation = $this->service->validateForVerifactu($this->invoice);

    expect($validation['valid'])->toBeFalse();
    expect($validation['errors'])->toContain('Invoice is already registered with Verifactu');
});

it('registers invoice with breakdowns', function () {
    InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'description'      => 'Test Item',
        'quantity'         => 100,
        'unit_price'       => 10000,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
    ]);

    $verifactuInvoice = $this->service->registerInvoice($this->invoice, withBreakdowns: true);

    expect($verifactuInvoice->breakdowns)->toHaveCount(1);
    expect($verifactuInvoice->breakdowns[0]->description)->toBe('Test Item');
});

it('retrieves Verifactu invoice by Larabill invoice', function () {
    expect($this->service->getVerifactuInvoice($this->invoice))->toBeNull();

    $registered = $this->service->registerInvoice($this->invoice, withBreakdowns: false);

    $retrieved = $this->service->getVerifactuInvoice($this->invoice);

    expect($retrieved)->not->toBeNull();
    expect($retrieved->id)->toBe($registered->id);
});
