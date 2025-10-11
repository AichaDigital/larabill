<?php

declare(strict_types=1);

use AichaDigital\Larabill\Database\Factories\{CompanyFiscalConfigFactory, InvoiceFactory};
use AichaDigital\Larabill\Services\{CompanyConfigService, EuSalesThresholdService};
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->companyConfigService = $this->mock(CompanyConfigService::class);
    $this->service              = new EuSalesThresholdService($this->companyConfigService);
});

it('can determine if notification should be sent', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss'             => false,
        'notification_sent'  => false,
        'threshold_exceeded' => true,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    // Act
    $result = $this->service->shouldSendNotification();

    // Assert
    expect($result)->toBeTrue();
});

it('should not send notification if company is OSS registered', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss'             => true,
        'notification_sent'  => false,
        'threshold_exceeded' => true,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    // Act
    $result = $this->service->shouldSendNotification();

    // Assert
    expect($result)->toBeFalse();
});

it('should not send notification if already sent', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss'             => false,
        'notification_sent'  => true,
        'threshold_exceeded' => true,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    // Act
    $result = $this->service->shouldSendNotification();

    // Assert
    expect($result)->toBeFalse();
});

it('should not send notification if threshold not exceeded', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss'             => false,
        'notification_sent'  => false,
        'threshold_exceeded' => false,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    // Act
    $result = $this->service->shouldSendNotification();

    // Assert
    expect($result)->toBeFalse();
});

it('can reset EU sales for new fiscal year', function () {
    // Arrange
    $newYear = 2025;

    $this->companyConfigService
        ->shouldReceive('resetEuSalesForNewYear')
        ->with($newYear)
        ->once();

    Log::shouldReceive('info')
        ->with('EU sales threshold reset for new fiscal year', ['new_year' => $newYear])
        ->once();

    // Act
    $this->service->resetForNewFiscalYear($newYear);

    // Assert - Mock expectations are verified automatically
});

it('can get threshold status', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'current_eu_sales_amount' => 8000,
        'eu_sales_threshold'      => 10000,
        'threshold_exceeded'      => false,
        'is_oss'                  => false,
        'fiscal_year'             => 2024,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->twice()
        ->andReturn($config);

    // Act
    $result = $this->service->getThresholdStatus();

    // Assert
    expect($result)->toBe([
        'current_amount'     => 8000.0, // Base100 returns float
        'threshold'          => 10000.0, // Base100 returns float
        'percentage'         => 80.0,
        'exceeded'           => false,
        'needs_notification' => false,
        'is_oss_registered'  => false,
        'fiscal_year'        => 2024,
    ]);
});

it('handles missing user tax info gracefully', function () {
    // Arrange
    $invoice = InvoiceFactory::new()->create([
        'is_roi_taxed'            => false,
        'user_tax_info_encrypted' => null,
    ]);

    // Act
    $this->service->processInvoice($invoice);

    // Assert - No calls to companyConfigService should be made
    $this->companyConfigService->shouldNotHaveReceived('getCurrentConfig');
});

it('handles invalid JSON in user tax info', function () {
    // Arrange
    $invoice = InvoiceFactory::new()->create([
        'is_roi_taxed'            => false,
        'user_tax_info_encrypted' => 'invalid-json',
    ]);

    // Act
    $this->service->processInvoice($invoice);

    // Assert - No calls to companyConfigService should be made
    $this->companyConfigService->shouldNotHaveReceived('getCurrentConfig');
});

it('handles missing country code in tax info', function () {
    // Arrange
    $invoice = InvoiceFactory::new()->create([
        'is_roi_taxed'            => false,
        'user_tax_info_encrypted' => json_encode(['name' => 'Test Company']), // No country_code
    ]);

    // Act
    $this->service->processInvoice($invoice);

    // Assert - No calls to companyConfigService should be made
    $this->companyConfigService->shouldNotHaveReceived('getCurrentConfig');
});

it('skips processing ROI taxed invoices', function () {
    // Arrange
    $invoice = InvoiceFactory::new()->create([
        'is_roi_taxed'            => true,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'DE']),
    ]);

    Log::shouldReceive('info')
        ->with('Invoice is ROI taxed, skipping EU sales threshold update', \Mockery::type('array'))
        ->once();

    // Act
    $this->service->processInvoice($invoice);

    // Assert - No calls to companyConfigService should be made
    $this->companyConfigService->shouldNotHaveReceived('getCurrentConfig');
});

it('skips processing non-EU sales', function () {
    // Arrange
    $invoice = InvoiceFactory::new()->create([
        'is_roi_taxed'            => false,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'US']), // Non-EU country
    ]);

    Log::shouldReceive('info')
        ->with('Invoice is not EU sale, skipping threshold update', \Mockery::type('array'))
        ->once();

    // Act
    $this->service->processInvoice($invoice);

    // Assert - No calls to companyConfigService should be made
    $this->companyConfigService->shouldNotHaveReceived('getCurrentConfig');
});

it('skips processing when company is already OSS registered', function () {
    // Arrange
    $invoice = InvoiceFactory::new()->create([
        'is_roi_taxed'            => false,
        'user_tax_info_encrypted' => json_encode(['country_code' => 'DE']),
    ]);

    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss' => true,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    Log::shouldReceive('info')
        ->with('Company is already OSS registered, skipping threshold update', \Mockery::type('array'))
        ->once();

    // Act
    $this->service->processInvoice($invoice);

    // Assert - No calls to updateEuSalesAmount should be made
    $this->companyConfigService->shouldNotHaveReceived('updateEuSalesAmount');
});

it('can send threshold notification', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss'                  => false,
        'notification_sent'       => false,
        'threshold_exceeded'      => true,
        'current_eu_sales_amount' => 12000,
        'eu_sales_threshold'      => 10000,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    $this->companyConfigService
        ->shouldReceive('markNotificationSent')
        ->once();

    Log::shouldReceive('warning')
        ->with('EU Sales Threshold Exceeded - OSS Registration Required', \Mockery::type('array'))
        ->once();

    // Act
    $this->service->sendThresholdNotification();

    // Assert - Mock expectations are verified automatically
});

it('does not send notification when conditions not met', function () {
    // Arrange
    $config = CompanyFiscalConfigFactory::new()->create([
        'is_oss'             => false,
        'notification_sent'  => false,
        'threshold_exceeded' => false,
    ]);

    $this->companyConfigService
        ->shouldReceive('getCurrentConfig')
        ->andReturn($config);

    // Act
    $this->service->sendThresholdNotification();

    // Assert - No calls to markNotificationSent should be made
    $this->companyConfigService->shouldNotHaveReceived('markNotificationSent');
});
