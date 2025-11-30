<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\Enums\RelationshipType;
use AichaDigital\Larabill\Models\{Customer, Invoice, IssuerConfig};
use AichaDigital\Larabill\Services\FiscalVerification\FakeFiscalVerification;
use AichaDigital\Larabill\Services\InvoiceService;

beforeEach(function () {
    // Clean singleton IssuerConfig
    IssuerConfig::query()->delete();

    // Bind fake fiscal verification
    app()->bind(FiscalVerificationContract::class, FakeFiscalVerification::class);

    $this->invoiceService = app(InvoiceService::class);
});

it('can complete full direct billing flow', function () {
    // 1. Setup: Create issuer and customer
    $issuer   = IssuerConfig::factory()->create();
    $customer = Customer::factory()->create([
        'display_name' => 'Test Customer Ltd',
        'is_active'    => true,
    ]);

    // 2. Create final invoice directly (no proforma)
    $invoice = $this->invoiceService->createInvoice([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 1,
        'type'        => 'final',
        'status'      => 1,
    ]);

    // 3. Add line items via invoice creation (protected method in service)
    $invoice = $this->invoiceService->createInvoice([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 1,
        'type'        => 'final',
        'status'      => 1,
        'items'       => [
            ['quantity' => 1, 'base_price' => 50.00, 'description' => 'Web Hosting - Monthly'],
            ['quantity' => 1, 'base_price' => 15.00, 'description' => 'Domain Registration'],
        ],
    ]);

    // 4. Verify invoice structure
    expect($invoice->exists)->toBeTrue()
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->serie)->toBe(\AichaDigital\Larabill\Enums\InvoiceSerieType::INVOICE)
        ->and($invoice->issuer_snapshot)->not->toBeNull()
        ->and($invoice->customer_snapshot)->not->toBeNull()
        ->and($invoice->fiscal_snapshot)->not->toBeNull();

    // 5. Verify items exist
    expect($invoice->items()->count())->toBe(2);

    // 6. Verify snapshots (use helpers to decrypt)
    $issuerSnapshot   = $invoice->getIssuerSnapshotData();
    $customerSnapshot = $invoice->getCustomerSnapshotData();
    $fiscalSnapshot   = $invoice->getFiscalSnapshotData();

    expect($issuerSnapshot)->toBeArray()
        ->and($customerSnapshot)->toBeArray()
        ->and($fiscalSnapshot)->toBeArray();
});

it('can complete full proforma to invoice flow', function () {
    // 1. Setup
    $issuer   = IssuerConfig::factory()->create();
    $customer = Customer::factory()->create();

    // 2. Create proforma
    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'P',
        'number'      => 1,
        'items'       => [
            ['quantity' => 2, 'base_price' => 100.00, 'description' => 'Service A'],
        ],
    ]);

    // 4. Verify proforma state
    expect($proforma->serie)->toBe(\AichaDigital\Larabill\Enums\InvoiceSerieType::PROFORMA)
        ->and($proforma->status)->toBe(\AichaDigital\Larabill\Enums\InvoiceStatus::DRAFT) // DRAFT
        ->and($proforma->is_immutable)->toBeFalse();

    // 5. Convert to final invoice
    $finalInvoice = $this->invoiceService->convertProformaToInvoice($proforma);

    // 6. Verify conversion
    expect($finalInvoice->serie)->toBe(\AichaDigital\Larabill\Enums\InvoiceSerieType::INVOICE)
        ->and($finalInvoice->id)->not->toBe($proforma->id)
        ->and($finalInvoice->items()->count())->toBe(1);

    // 7. Verify proforma is locked
    $proforma->refresh();
    expect($proforma->is_immutable)->toBeTrue()
        ->and($proforma->status)->toBe(\AichaDigital\Larabill\Enums\InvoiceStatus::CONVERTED) // CONVERTED
        ->and($proforma->converted_invoice_id)->toBe($finalInvoice->id);
});

it('can handle multi-customer billing for same user', function () {
    // Scenario: User manages multiple customers (e.g., personal + company)
    $issuer = IssuerConfig::factory()->create();
    $userId = 1;

    // Customer 1: Personal
    $customer1 = Customer::factory()->create([
        'user_id'           => $userId,
        'relationship_type' => RelationshipType::SELF,
        'display_name'      => 'John Doe (Personal)',
    ]);

    // Customer 2: Company
    $customer2 = Customer::factory()->create([
        'user_id'           => $userId,
        'relationship_type' => RelationshipType::SELF_COMPANY,
        'display_name'      => 'John Doe Ltd',
    ]);

    // Create invoice for personal
    $invoice1 = $this->invoiceService->createInvoice([
        'customer_id' => $customer1->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 1,
        'type'        => 'final',
        'status'      => 1,
    ]);

    // Create invoice for company
    $invoice2 = $this->invoiceService->createInvoice([
        'customer_id' => $customer2->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 2,
        'type'        => 'final',
        'status'      => 1,
    ]);

    // Verify both invoices exist and are linked to correct customers
    expect($invoice1->customer_id)->toBe($customer1->id)
        ->and($invoice2->customer_id)->toBe($customer2->id)
        ->and($invoice1->customer_snapshot)->not->toBe($invoice2->customer_snapshot);
});

it('can handle fiscal verification integration', function () {
    $issuer   = IssuerConfig::factory()->create();
    $customer = Customer::factory()->create();

    $invoice = $this->invoiceService->createInvoice([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 1,
        'type'        => 'final',
        'status'      => 1,
    ], ['verify_fiscally' => true]);

    // Fake should set verification fields
    $invoice->refresh();

    expect($invoice->fiscal_verification_id)->not->toBeNull()
        ->and($invoice->fiscal_verification_qr)->not->toBeNull()
        ->and($invoice->fiscal_verification_hash)->not->toBeNull();
});
