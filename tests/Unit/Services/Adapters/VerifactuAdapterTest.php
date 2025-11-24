<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Customer, Invoice, InvoiceItem, LegalEntityType, UserTaxProfile};
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
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
        'display_name'           => 'Test Customer SA',
        'legal_entity_type_code' => 'J',
    ]);

    // Create a fake user ID (UUID binary - 16 bytes)
    $fakeUserId = hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789012'));

    $this->taxProfile = UserTaxProfile::create([
        'user_id'               => $fakeUserId,
        'business_name'         => 'Test Customer SA',
        'address'               => 'Test Address 123',
        'city'                  => 'Madrid',
        'postal_code'           => '28001',
        'country'               => 'ES',
        'tax_code'              => 'ESB12345678',
        'country_code'          => 'ES',
        'vat_number'            => 'ESB12345678',
        'vat_number_verified'   => true,
    ]);

    $this->invoice = Invoice::factory()->create([
        'user_id'          => $fakeUserId,
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'series_number'    => 1,
        'fiscal_number'    => 'INV-2025-001',
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'is_roi_taxed'     => false,
        'is_immutable'     => false,
    ]);
});

it('converts base100 to decimal correctly', function () {
    expect(VerifactuAdapter::base100ToDecimal(10000))->toBe(100.00);
    expect(VerifactuAdapter::base100ToDecimal(2100))->toBe(21.00);
    expect(VerifactuAdapter::base100ToDecimal(12100))->toBe(121.00);
});

it('converts Larabill invoice to Verifactu format', function () {
    // Debug: Ensure taxProfile is loaded
    expect($this->invoice->taxProfile)->not->toBeNull();
    expect($this->invoice->taxProfile->tax_code)->toBe('ESB12345678');

    $data = VerifactuAdapter::toVerifactuInvoice($this->invoice);

    expect($data['number'])->toBe('1');
    expect($data['base_amount'])->toBe(100.00);
    expect($data['tax_amount'])->toBe(21.00);
    expect($data['total_amount'])->toBe(121.00);
    expect($data['recipient_nif'])->toBe('ESB12345678');
});

it('maps operation key for reverse charge', function () {
    $fakeUserId = hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099'));

    $roiInvoice = Invoice::factory()->create([
        'user_id'          => $fakeUserId,
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'is_roi_taxed'     => true,
        'is_immutable'     => false,
    ]);

    $data = VerifactuAdapter::toVerifactuInvoice($roiInvoice);

    expect($data['operation_key'])->toBe('09');
});

it('converts invoice item to breakdown', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'quantity'         => 200,
        'unit_price'       => 5000,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
    ]);

    $breakdown = VerifactuAdapter::toVerifactuBreakdown($item);

    expect($breakdown['quantity'])->toBe(2.00);
    expect($breakdown['unit_price'])->toBe(50.00);
    expect($breakdown['base_amount'])->toBe(100.00);
});
