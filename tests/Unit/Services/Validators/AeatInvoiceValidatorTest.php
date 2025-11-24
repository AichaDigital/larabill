<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Customer, Invoice, LegalEntityType, UserTaxProfile};
use AichaDigital\Larabill\Services\Validators\AeatInvoiceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->validator = new AeatInvoiceValidator;

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
        'business_name' => 'Test Company SL',
        'address'       => 'Calle Test 123',
        'city'          => 'Madrid',
        'postal_code'   => '28001',
        'country'       => 'ES',
        'tax_code'      => 'ESA12345678',
    ]);

    $this->validInvoice = Invoice::factory()->create([
        'user_id'          => $fakeUserId,
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'fiscal_number'    => 'FAC-2025-001',
        'series_number'    => 1,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'is_immutable'     => true,
    ]);
});

it('validates a correct invoice', function () {
    $result = $this->validator->validate($this->validInvoice);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

it('detects non-immutable invoice', function () {
    $invoice = Invoice::factory()->create([
        'user_id'        => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'    => $this->customer->id,
        'tax_profile_id' => $this->taxProfile->id,
        'is_immutable'   => false,
    ]);

    $result = $this->validator->validate($invoice);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('Invoice must be immutable before AEAT submission');
});

it('detects missing fiscal_number', function () {
    $invoice = Invoice::factory()->make([
        'user_id'        => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'    => $this->customer->id,
        'tax_profile_id' => $this->taxProfile->id,
        'fiscal_number'  => '', // Empty string instead of null
        'is_immutable'   => true,
    ]);
    $invoice->save();

    $result = $this->validator->validate($invoice);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('fiscal_number is required for AEAT');
});

it('detects negative amounts', function () {
    $invoice = Invoice::factory()->create([
        'user_id'          => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'taxable_amount'   => -1000,
        'total_tax_amount' => 0,
        'total_amount'     => -1000,
        'is_immutable'     => true,
    ]);

    $result = $this->validator->validate($invoice);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('taxable_amount cannot be negative');
});

it('detects incorrect total calculation', function () {
    $invoice = Invoice::factory()->create([
        'user_id'          => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 99999, // Incorrect total
        'is_immutable'     => true,
    ]);

    $result = $this->validator->validate($invoice);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('total_amount (99999) must equal taxable_amount (10000) + total_tax_amount (2100) = 12100');
});

it('warns about ROI invoice with tax amount', function () {
    $invoice = Invoice::factory()->create([
        'user_id'          => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100, // Should be 0 for ROI
        'total_amount'     => 12100,
        'is_roi_taxed'     => true,
        'is_immutable'     => true,
    ]);

    $result = $this->validator->validate($invoice);

    expect($result['warnings'])->toContain('ROI invoices typically have zero tax amount (reverse charge)');
});

it('provides isValid quick check', function () {
    expect($this->validator->isValid($this->validInvoice))->toBeTrue();

    $invalidInvoice = Invoice::factory()->create([
        'user_id'        => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'    => $this->customer->id,
        'tax_profile_id' => $this->taxProfile->id,
        'is_immutable'   => false,
    ]);

    expect($this->validator->isValid($invalidInvoice))->toBeFalse();
});

it('gets errors only', function () {
    $invoice = Invoice::factory()->make([
        'user_id'        => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'    => $this->customer->id,
        'tax_profile_id' => $this->taxProfile->id,
        'fiscal_number'  => '', // Empty string
        'is_immutable'   => false,
    ]);
    $invoice->save();

    $errors = $this->validator->getErrors($invoice);

    expect($errors)->toBeArray();
    expect($errors)->toContain('Invoice must be immutable before AEAT submission');
    expect($errors)->toContain('fiscal_number is required for AEAT');
});

it('gets warnings only', function () {
    $invoice = Invoice::factory()->create([
        'user_id'          => hex2bin(str_replace('-', '', '018e1234-5678-7abc-def0-123456789099')),
        'customer_id'      => $this->customer->id,
        'tax_profile_id'   => $this->taxProfile->id,
        'taxable_amount'   => 10000,
        'total_tax_amount' => 2100,
        'total_amount'     => 12100,
        'is_roi_taxed'     => true,
        'is_immutable'     => true,
    ]);

    $warnings = $this->validator->getWarnings($invoice);

    expect($warnings)->toBeArray();
    expect($warnings)->toContain('ROI invoices typically have zero tax amount (reverse charge)');
});
