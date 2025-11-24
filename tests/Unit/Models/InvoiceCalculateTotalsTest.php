<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Customer, Invoice, InvoiceItem, LegalEntityType, UserTaxProfile};
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
        'taxable_amount'   => 0,
        'total_tax_amount' => 0,
        'total_amount'     => 0,
        'is_immutable'     => false,
    ]);
});

it('calculates totals from invoice items', function () {
    // Create items
    InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'taxable_amount'   => 10000, // €100.00
        'total_tax_amount' => 2100,  // €21.00
        'total_amount'     => 12100, // €121.00
    ]);

    InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'taxable_amount'   => 5000,  // €50.00
        'total_tax_amount' => 1050,  // €10.50
        'total_amount'     => 6050,  // €60.50
    ]);

    // Calculate totals
    $this->invoice->calculateTotals();

    expect($this->invoice->taxable_amount)->toBe(15000);    // €150.00
    expect($this->invoice->total_tax_amount)->toBe(3150);   // €31.50
    expect($this->invoice->total_amount)->toBe(18150);      // €181.50
});

it('calculates totals with zero items', function () {
    $this->invoice->calculateTotals();

    expect($this->invoice->taxable_amount)->toBe(0);
    expect($this->invoice->total_tax_amount)->toBe(0);
    expect($this->invoice->total_amount)->toBe(0);
});

it('calculates totals and saves', function () {
    InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
    ]);

    $this->invoice->calculateTotals()->save();

    $this->invoice->refresh();

    expect($this->invoice->taxable_amount)->toBe(10000);
    expect($this->invoice->total_tax_amount)->toBe(2100);
    expect($this->invoice->total_amount)->toBe(12100);
});

it('returns self for method chaining', function () {
    $result = $this->invoice->calculateTotals();

    expect($result)->toBe($this->invoice);
});
