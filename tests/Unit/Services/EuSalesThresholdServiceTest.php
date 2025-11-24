<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{FiscalSettings, Invoice};
use AichaDigital\Larabill\Services\EuSalesThresholdService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = new EuSalesThresholdService;
    Log::spy();
});

it('processes EU sale invoice and updates threshold', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000, // €500 in base100
        'user_tax_info_encrypted' => json_encode(['country_code' => 'DE']), // Germany
        'fiscal_year'             => now()->year,
    ]);

    $userId         = (string) ($invoice->user_id ?? config('larabill.company.id', '1'));
    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, now()->year);
    expect($fiscalSettings->current_eu_sales_amount)->toBe(0); // Base100 integer

    $this->service->processInvoice($invoice);

    // Fetch a fresh instance from DB
    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, now()->year);
    expect($fiscalSettings->current_eu_sales_amount)->toBe(50000); // €500.00 in base100
});

it('skips ROI taxed invoices', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => true,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'DE']),
    ]);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    $initialAmount  = $fiscalSettings->current_eu_sales_amount;

    $this->service->processInvoice($invoice);

    $fiscalSettings->refresh();
    expect($fiscalSettings->current_eu_sales_amount)->toBe($initialAmount);
});

it('skips non-EU invoices', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'US']), // USA - not EU
    ]);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    $initialAmount  = $fiscalSettings->current_eu_sales_amount;

    $this->service->processInvoice($invoice);

    $fiscalSettings->refresh();
    expect($fiscalSettings->current_eu_sales_amount)->toBe($initialAmount);
});

it('skips domestic (Spain) invoices', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'ES']), // Spain - domestic
    ]);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    $initialAmount  = $fiscalSettings->current_eu_sales_amount;

    $this->service->processInvoice($invoice);

    $fiscalSettings->refresh();
    expect($fiscalSettings->current_eu_sales_amount)->toBe($initialAmount);
});

it('skips invoices when company is already OSS registered', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'DE']),
    ]);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    $fiscalSettings->update(['is_oss' => true]);
    $initialAmount = $fiscalSettings->current_eu_sales_amount;

    $this->service->processInvoice($invoice);

    $fiscalSettings->refresh();
    expect($fiscalSettings->current_eu_sales_amount)->toBe($initialAmount);
});

it('processes invoice refund and decreases EU sales', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'FR']),
        'fiscal_year'             => now()->year,
    ]);

    // First, process normal invoice
    $this->service->processInvoice($invoice);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    expect($fiscalSettings->current_eu_sales_amount)->toBe(50000); // €500.00 in base100

    // Now process refund
    $this->service->processInvoiceRefund($invoice);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    expect($fiscalSettings->current_eu_sales_amount)->toBe(0); // Back to zero after refund
});

it('should send notification when threshold exceeded', function () {
    $userId     = '1';
    $fiscalYear = now()->year;

    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
    $fiscalSettings->update([
        'is_oss'                     => false,
        'threshold_exceeded'         => true,
        'notification_sent'          => false,
        'current_eu_sales_amount'    => 11000,
        'eu_sales_threshold'         => 10000,
    ]);

    expect($this->service->shouldSendNotification($userId, $fiscalYear))->toBeTrue();
});

it('should not send notification when already sent', function () {
    $userId     = '1';
    $fiscalYear = now()->year;

    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
    $fiscalSettings->update([
        'is_oss'                  => false,
        'threshold_exceeded'      => true,
        'notification_sent'       => true,
        'current_eu_sales_amount' => 11000,
    ]);

    expect($this->service->shouldSendNotification($userId, $fiscalYear))->toBeFalse();
});

it('should not send notification when OSS registered', function () {
    $userId     = '1';
    $fiscalYear = now()->year;

    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
    $fiscalSettings->update([
        'is_oss'                  => true,
        'threshold_exceeded'      => true,
        'notification_sent'       => false,
        'current_eu_sales_amount' => 11000,
    ]);

    expect($this->service->shouldSendNotification($userId, $fiscalYear))->toBeFalse();
});

it('should not send notification when threshold not exceeded', function () {
    $userId     = '1';
    $fiscalYear = now()->year;

    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
    $fiscalSettings->update([
        'is_oss'                  => false,
        'threshold_exceeded'      => false,
        'notification_sent'       => false,
        'current_eu_sales_amount' => 5000,
    ]);

    expect($this->service->shouldSendNotification($userId, $fiscalYear))->toBeFalse();
});

it('sends threshold notification and marks as sent', function () {
    $userId     = '1';
    $fiscalYear = now()->year;

    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
    $fiscalSettings->update([
        'is_oss'                  => false,
        'threshold_exceeded'      => true,
        'notification_sent'       => false,
        'current_eu_sales_amount' => 11000,
    ]);

    $this->service->sendThresholdNotification($userId, $fiscalYear);

    $fiscalSettings->refresh();
    expect($fiscalSettings->notification_sent)->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('EU Sales Threshold Exceeded - OSS Registration Required', \Mockery::type('array'));
});

it('does not send notification if should not send', function () {
    $userId     = '1';
    $fiscalYear = now()->year;

    $fiscalSettings = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
    $fiscalSettings->update([
        'is_oss'                  => false,
        'threshold_exceeded'      => false,
        'notification_sent'       => false,
        'current_eu_sales_amount' => 5000,
    ]);

    $this->service->sendThresholdNotification($userId, $fiscalYear);

    $fiscalSettings->refresh();
    expect($fiscalSettings->notification_sent)->toBeFalse();
});

it('handles invoice without user tax info', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => null,
    ]);

    $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
    $initialAmount  = $fiscalSettings->current_eu_sales_amount;

    $this->service->processInvoice($invoice);

    $fiscalSettings->refresh();
    expect($fiscalSettings->current_eu_sales_amount)->toBe($initialAmount);
});

it('recognizes various EU countries', function () {
    $euCountries = ['DE', 'FR', 'IT', 'NL', 'BE'];
    $testUserId  = 999; // Fixed user ID for all invoices

    foreach ($euCountries as $index => $country) {
        $invoice = Invoice::factory()->create([
            'user_id'                 => $testUserId, // Same user for all
            'is_roi_taxed'            => false,
            'taxable_amount'          => 10000, // €100 in base100
            'user_tax_info_encrypted' => json_encode(['country_code' => $country]),
            'fiscal_year'             => now()->year,
        ]);

        $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
        $expectedAmount = ($index) * 10000.0; // Previous invoices (base100)
        expect($fiscalSettings->current_eu_sales_amount)->toBe($expectedAmount);

        $this->service->processInvoice($invoice);

        $fiscalSettings = FiscalSettings::getOrCreateForUser((string) ($invoice->user_id ?? '1'), now()->year);
        expect($fiscalSettings->current_eu_sales_amount)->toBe($expectedAmount + 10000.0); // +€100 in base100
    }
});

it('logs EU sales threshold updates', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'DE']),
    ]);

    $this->service->processInvoice($invoice);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('EU sales threshold updated', \Mockery::type('array'));
});

it('logs refund processing', function () {
    $invoice = Invoice::factory()->create([
        'is_roi_taxed'            => false,
        'taxable_amount'          => 50000,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'FR']),
    ]);

    $this->service->processInvoiceRefund($invoice);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('EU sales threshold updated (refund)', \Mockery::type('array'));
});
