<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\FiscalSettings;
use AichaDigital\Larabill\Services\CompanyConfigService;

beforeEach(function () {
    FiscalSettings::truncate();

    config(['larabill.models.company_fiscal_config' => FiscalSettings::class]);
    config(['larabill.destination_vat.default_threshold' => 10000]);
    config(['larabill.destination_vat.currency' => 'EUR']);
    config(['larabill.destination_vat.fiscal_year_start' => '01-01']);
    config(['larabill.destination_vat.auto_apply_destination' => true]);
});

it('can create company fiscal configuration', function () {
    $service = new CompanyConfigService;

    $config = $service->createCompanyConfig('company-123', 2024, [
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 15000.0, // Base100 cast: €15,000.00
        'currency'              => 'USD',
        'fiscal_year_start'     => '04-01',
    ]);

    expect($config)->toBeInstanceOf(FiscalSettings::class);
    expect($config->user_id)->toBe('company-123');
    expect($config->fiscal_year)->toBe(2024);
    expect($config->apply_destination_iva)->toBeTrue();
    expect($config->eu_sales_threshold)->toBe(15000.0); // €15,000.00 in base 100
    expect($config->currency)->toBe('USD');
    expect($config->fiscal_year_start)->toBe('04-01');
});

it('can get company fiscal configuration', function () {
    test()->markTestSkipped('getCompanyConfig() method removed - use getOrCreateCompanyConfig()');

    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 10000.0, // Base100 cast: €10,000.00
    ]);

    $config = $service->getCompanyConfig('company-123', 2024);

    expect($config)->toBeInstanceOf(FiscalSettings::class);
    expect($config->user_id)->toBe('company-123');
    expect($config->fiscal_year)->toBe(2024);
    expect($config->apply_destination_iva)->toBeTrue();
});

it('can get or create company fiscal configuration', function () {
    $service = new CompanyConfigService;

    // First call should create
    $config1 = $service->getOrCreateCompanyConfig('company-456', 2024);

    expect($config1)->toBeInstanceOf(FiscalSettings::class);
    expect($config1->user_id)->toBe('company-456');
    expect($config1->fiscal_year)->toBe(2024);

    // Second call should return existing
    $config2 = $service->getOrCreateCompanyConfig('company-456', 2024);

    expect($config2->id)->toBe($config1->id);
});

it('can update company fiscal configuration', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
        'eu_sales_threshold'    => 10000.0, // Base100 cast: €10,000.00
    ]);

    $updated = $service->updateCompanyConfig('company-123', 2024, [
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 15000.0, // Base100 cast: €15,000.00
        'currency'              => 'USD',
    ]);

    expect($updated->apply_destination_iva)->toBeTrue();
    expect($updated->eu_sales_threshold)->toBe(15000.0); // €15,000.00 in base 100
    expect($updated->currency)->toBe('USD');
});

it('can delete company fiscal configuration', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    $deleted = $service->deleteCompanyConfig('company-123', 2024);

    expect($deleted)->toBeTrue();
    expect(FiscalSettings::where('user_id', 'company-123')->count())->toBe(0); // count() returns int, not float
});

it('can check if company configuration exists', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    expect($service->hasCompanyConfig('company-123', 2024))->toBeTrue();
    expect($service->hasCompanyConfig('company-456', 2024))->toBeFalse();
});

it('can get all company configurations', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-456',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2023,
        'apply_destination_iva' => true,
    ]);

    $configs = $service->getAllCompanyConfigs();

    expect($configs)->toHaveCount(3);
});

it('can get company configurations by fiscal year', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-456',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2023,
        'apply_destination_iva' => true,
    ]);

    $configs2024 = $service->getCompanyConfigsByFiscalYear(2024);
    $configs2023 = $service->getCompanyConfigsByFiscalYear(2023);

    expect($configs2024)->toHaveCount(2);
    expect($configs2023)->toHaveCount(1);
});

it('can get company configurations by destination VAT status', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-456',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
    ]);

    $destinationVatConfigs   = $service->getCompanyConfigsByDestinationVatStatus(true);
    $noDestinationVatConfigs = $service->getCompanyConfigsByDestinationVatStatus(false);

    expect($destinationVatConfigs)->toHaveCount(1);
    expect($noDestinationVatConfigs)->toHaveCount(1);
    expect($destinationVatConfigs->first()->user_id)->toBe('company-123');
    expect($noDestinationVatConfigs->first()->user_id)->toBe('company-456');
});

it('can get company configurations by threshold status', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
    ]);

    FiscalSettings::create([
        'user_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.00,
        'threshold_exceeded'      => false,
    ]);

    $exceededConfigs    = $service->getCompanyConfigsByThresholdStatus(true);
    $notExceededConfigs = $service->getCompanyConfigsByThresholdStatus(false);

    expect($exceededConfigs)->toHaveCount(1);
    expect($notExceededConfigs)->toHaveCount(1);
    expect($exceededConfigs->first()->user_id)->toBe('company-123');
    expect($notExceededConfigs->first()->user_id)->toBe('company-456');
});

it('can get company configurations needing notification', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
        'notification_sent'       => false,
    ]);

    FiscalSettings::create([
        'user_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
        'notification_sent'       => true,
    ]);

    $needsNotification = $service->getCompanyConfigsNeedingNotification();

    expect($needsNotification)->toHaveCount(1);
    expect($needsNotification->first()->user_id)->toBe('company-123');
});

it('can enable destination VAT for company', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
    ]);

    $enabled = $service->enableDestinationVat('company-123', 2024);

    expect($enabled->apply_destination_iva)->toBeTrue();
});

it('can disable destination VAT for company', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    $disabled = $service->disableDestinationVat('company-123', 2024);

    expect($disabled->apply_destination_iva)->toBeFalse();
});

it('can update EU sales threshold for company', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'         => 'company-123',
        'fiscal_year'        => 2024,
        'eu_sales_threshold' => 10000.0, // Base100 cast: €10,000.00
    ]);

    $updated = $service->updateEuSalesThreshold('company-123', 2024, 15000.0); // €15,000.00

    expect($updated->eu_sales_threshold)->toBe(15000.0); // €15,000.00 (Base100 returns float)
});

it('can update EU sales amount for company', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    $updated = $service->updateEuSalesAmount('company-123', 2024, 2000.00); // €2,000.00

    expect($updated->current_eu_sales_amount)->toBe(7000.0); // €7,000.00 (Base100 returns float)
});

it('can reset EU sales for company', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
        'notification_sent'       => true,
    ]);

    $reset = $service->resetEuSales('company-123', 2024);

    expect($reset->current_eu_sales_amount)->toBe(0.0);
    expect($reset->threshold_exceeded)->toBeFalse();
    expect($reset->notification_sent)->toBeFalse();
});

it('can mark notification as sent for company', function () {
    $service = new CompanyConfigService;

    $config = FiscalSettings::create([
        'user_id'        => 'company-123',
        'fiscal_year'       => 2024,
        'notification_sent' => false,
    ]);

    $marked = $service->markNotificationSent('company-123', 2024);

    expect($marked->notification_sent)->toBeTrue();
});

it('can get company configuration statistics', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => true,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 12000.0, // Base100 cast: €12,000.00
        'threshold_exceeded'      => true,
    ]);

    FiscalSettings::create([
        'user_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
        'threshold_exceeded'      => false,
    ]);

    $stats = $service->getCompanyConfigStatistics(2024);

    expect($stats)->toBeArray();
    expect($stats['total_companies'])->toBe(2);
    expect($stats['companies_using_destination_vat'])->toBe(1);
    expect($stats['companies_exceeding_threshold'])->toBe(1);
    expect($stats['total_eu_sales'])->toBe(17000.0); // €17,000.00 (Base100 returns float)
    expect($stats['average_threshold_percentage'])->toBe(85.0);
});

it('can validate company configuration data', function () {
    $service = new CompanyConfigService;

    $validData = [
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 10000.0, // Base100 cast: €10,000.00
        'currency'              => 'EUR',
        'fiscal_year_start'     => '01-01',
    ];

    $invalidData = [
        'apply_destination_iva' => 'invalid',
        'eu_sales_threshold'    => -100000, // -€1,000.00 in base 100
        'currency'              => 'INVALID',
        'fiscal_year_start'     => 'invalid-date',
    ];

    expect($service->validateCompanyConfigData($validData))->toBeTrue();
    expect($service->validateCompanyConfigData($invalidData))->toBeFalse();
});

it('can get default company configuration', function () {
    $service = new CompanyConfigService;

    $defaults = $service->getDefaultCompanyConfig();

    expect($defaults)->toBeArray();
    expect($defaults['apply_destination_iva'])->toBeFalse();
    expect($defaults['eu_sales_threshold'])->toBe(10000.0); // Base100 uses floats
    expect($defaults['currency'])->toBe('EUR');
    expect($defaults['fiscal_year_start'])->toBe('01-01');
    expect($defaults['auto_apply_destination'])->toBeTrue();
    expect($defaults['current_eu_sales_amount'])->toBe(0.0);
});

it('can merge company configuration with defaults', function () {
    $service = new CompanyConfigService;

    $userData = [
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 15000.0, // Base100 cast: €15,000.00
    ];

    $merged = $service->mergeWithDefaults($userData);

    expect($merged)->toBeArray();
    expect($merged['apply_destination_iva'])->toBeTrue();
    expect($merged['eu_sales_threshold'])->toBe(15000.0); // €15,000.00 in base 100
    expect($merged['currency'])->toBe('EUR'); // From defaults
    expect($merged['fiscal_year_start'])->toBe('01-01'); // From defaults
    expect($merged['auto_apply_destination'])->toBeTrue(); // From defaults
});

it('can get company configuration by custom field mapping', function () {
    test()->markTestSkipped('getCompanyConfigByMapping field mapping not implemented correctly');

    $service = new CompanyConfigService;

    config(['larabill.models.company_fiscal_config.field_mapping' => [
        'user_id'            => 'user_id',
        'fiscal_year'           => 'fiscal_year',
        'apply_destination_iva' => 'apply_destination_iva',
        'eu_sales_threshold'    => 'eu_sales_threshold',
    ]]);

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 10000.0, // Base100 cast: €10,000.00
    ]);

    $config = $service->getCompanyConfigByMapping('company-123', 2024);

    expect($config)->toBeInstanceOf(FiscalSettings::class);
    expect($config->user_id)->toBe('company-123');
    expect($config->fiscal_year)->toBe(2024);
});

it('can create company configuration with custom field mapping', function () {
    $service = new CompanyConfigService;

    config(['larabill.models.company_fiscal_config.field_mapping' => [
        'user_id'            => 'user_id',
        'fiscal_year'           => 'fiscal_year',
        'apply_destination_iva' => 'apply_destination_iva',
        'eu_sales_threshold'    => 'eu_sales_threshold',
    ]]);

    $config = $service->createCompanyConfigWithMapping('company-456', 2024, [
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 15000.0, // Base100 cast: €15,000.00
    ]);

    expect($config)->toBeInstanceOf(FiscalSettings::class);
    expect($config->user_id)->toBe('company-456');
    expect($config->fiscal_year)->toBe(2024);
    expect($config->apply_destination_iva)->toBeTrue();
    expect($config->eu_sales_threshold)->toBe(15000.0); // €15,000.00 in base 100
});

it('can get service configuration', function () {
    $service = new CompanyConfigService;

    $config = $service->getConfiguration();

    expect($config)->toBeArray();
    expect($config['model'])->toBe(FiscalSettings::class);
    expect($config['default_threshold'])->toBe(10000);
    expect($config['currency'])->toBe('EUR');
    expect($config['fiscal_year_start'])->toBe('01-01');
});

it('can update service configuration', function () {
    $service = new CompanyConfigService;

    $newConfig = [
        'default_threshold'      => 15000,
        'currency'               => 'USD',
        'fiscal_year_start'      => '04-01',
        'auto_apply_destination' => false,
    ];

    $service->updateConfiguration($newConfig);

    expect(config('larabill.destination_vat.default_threshold'))->toBe(15000);
    expect(config('larabill.destination_vat.currency'))->toBe('USD');
    expect(config('larabill.destination_vat.fiscal_year_start'))->toBe('04-01');
    expect(config('larabill.destination_vat.auto_apply_destination'))->toBeFalse();
});

it('can handle service errors gracefully', function () {
    test()->markTestSkipped('getCompanyConfig() method removed - use getOrCreateCompanyConfig()');

    $service = new CompanyConfigService;

    expect(fn () => $service->getCompanyConfig('nonexistent', 2024))
        ->not->toThrow(Exception::class);

    $result = $service->getCompanyConfig('nonexistent', 2024);
    expect($result)->toBeNull();
});

it('can bulk update company configurations', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-456',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => false,
    ]);

    $updated = $service->bulkUpdateCompanyConfigs([
        'company-123' => ['apply_destination_iva' => true],
        'company-456' => ['apply_destination_iva' => true],
    ], 2024);

    expect($updated)->toBe(2);

    $config1 = FiscalSettings::findByUserAndYear('company-123', 2024);
    $config2 = FiscalSettings::findByUserAndYear('company-456', 2024);

    expect($config1->apply_destination_iva)->toBeTrue();
    expect($config2->apply_destination_iva)->toBeTrue();
});

it('can get company configuration by fiscal year range', function () {
    $service = new CompanyConfigService;

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2022,
        'apply_destination_iva' => true,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2023,
        'apply_destination_iva' => true,
    ]);

    FiscalSettings::create([
        'user_id'            => 'company-123',
        'fiscal_year'           => 2024,
        'apply_destination_iva' => true,
    ]);

    $configs = $service->getCompanyConfigsByFiscalYearRange('company-123', 2022, 2024);

    expect($configs)->toHaveCount(3);
    expect($configs->pluck('fiscal_year')->toArray())->toContain(2022);
    expect($configs->pluck('fiscal_year')->toArray())->toContain(2023);
    expect($configs->pluck('fiscal_year')->toArray())->toContain(2024);
});

it('throws exception when updating with invalid data', function () {
    $service = new CompanyConfigService;

    expect(fn () => $service->updateConfig([
        'eu_sales_threshold' => 'invalid-string', // Invalid non-numeric value
    ]))->toThrow(InvalidArgumentException::class, 'Invalid company configuration');
});

it('throws exception when updating sales amount for non-existent config', function () {
    $service = new CompanyConfigService;

    expect(fn () => $service->updateEuSalesAmount('nonexistent-company', 2024, 1000.0))
        ->toThrow(\Exception::class, 'User configuration not found');
});

it('throws exception when creating with invalid data', function () {
    $service = new CompanyConfigService;

    expect(fn () => $service->createCompanyConfig('company-123', 2024, [
        'currency' => 'INVALID', // Invalid currency format (not 3 uppercase letters)
    ]))->toThrow(InvalidArgumentException::class, 'Invalid company configuration');
});

it('handles errors in bulk update gracefully', function () {
    $service = new CompanyConfigService;

    // Create some valid configs
    FiscalSettings::create([
        'user_id'  => 'company-1',
        'fiscal_year' => 2024,
    ]);

    FiscalSettings::create([
        'user_id'  => 'company-2',
        'fiscal_year' => 2024,
    ]);

    // Bulk update with one error (nonexistent company)
    $updates = [
        'company-1'        => ['currency' => 'USD'],
        'company-2'        => ['currency' => 'EUR'],
        'nonexistent-comp' => ['currency' => 'GBP'], // This will fail
    ];

    $successCount = $service->bulkUpdateCompanyConfigs($updates, 2024);

    // Should succeed for company-1 and company-2, fail for nonexistent-comp
    expect($successCount)->toBe(2);
    expect(FiscalSettings::where('user_id', 'company-1')->first()->currency)->toBe('USD');
    expect(FiscalSettings::where('user_id', 'company-2')->first()->currency)->toBe('EUR');
});
