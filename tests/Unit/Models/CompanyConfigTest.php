<?php

declare(strict_types=1);

/**
 * @deprecated v0.4.0 - CompanyConfig was renamed to IssuerConfig
 * @see IssuerConfigTest for updated tests using v0.4.0 architecture
 *
 * This test file uses the legacy CompanyConfig model which no longer exists.
 * Tests have been migrated to IssuerConfigTest using IssuerConfig + IssuerTaxProfile.
 */

use AichaDigital\Larabill\Models\CompanyConfig;
use Carbon\Carbon;

// Skip all tests in this file - legacy code
beforeEach(function () {
    $this->markTestSkipped('CompanyConfig deprecated in v0.4.0 - See IssuerConfigTest');
});

it('can create a company config', function () {
    $config = CompanyConfig::create([
        'is_oss'                  => false,
        'is_roi'                  => false,
        'eu_sales_threshold'      => CompanyConfig::amountToBase100(10000.0),
        'current_eu_sales_amount' => 0,
        'threshold_exceeded'      => false,
        'fiscal_year'             => 2024,
        'auto_apply_destination'  => true,
        'notification_sent'       => false,
        'fiscal_year_start'       => '01-01',
        'currency'                => 'EUR',
    ]);

    expect($config->exists)->toBeTrue();
    expect($config->id)->not->toBeNull();
    expect($config->is_oss)->toBeFalse();
    expect($config->is_roi)->toBeFalse();
    expect($config->eu_sales_threshold)->toBe(1000000.0);
    expect($config->current_eu_sales_amount)->toBe(0.0);
    expect($config->fiscal_year)->toBe(2024);
    expect($config->currency)->toBe('EUR');
});

it('can get current company config (singleton pattern)', function () {
    // First call should create default config
    $config1 = CompanyConfig::current();
    expect($config1)->toBeInstanceOf(CompanyConfig::class);
    expect($config1->exists)->toBeTrue();

    // Second call should return the same config
    $config2 = CompanyConfig::current();
    expect($config2->id)->toBe($config1->id);
});

it('can create default company config', function () {
    $config = CompanyConfig::createDefault();

    expect($config)->toBeInstanceOf(CompanyConfig::class);
    expect($config->is_oss)->toBeFalse();
    expect($config->is_roi)->toBeFalse();
    expect($config->eu_sales_threshold)->toBe(1000000.0);
    expect($config->current_eu_sales_amount)->toBe(0.0);
    expect($config->fiscal_year)->toBe(now()->year);
    expect($config->auto_apply_destination)->toBeTrue();
    expect($config->notification_sent)->toBeFalse();
    expect($config->fiscal_year_start)->toBe('01-01');
    expect($config->currency)->toBe('EUR');
});

it('can convert amounts to and from base 100', function () {
    expect(CompanyConfig::amountToBase100(12.34))->toBe(1234);
    expect(CompanyConfig::amountToBase100(0.01))->toBe(1);
    expect(CompanyConfig::amountToBase100(1000.0))->toBe(100000);

    expect(CompanyConfig::base100ToAmount(1234))->toBe(12.34);
    expect(CompanyConfig::base100ToAmount(1))->toBe(0.01);
    expect(CompanyConfig::base100ToAmount(100000))->toBe(1000.0);
});

it('can check if threshold has been exceeded', function () {
    $config = CompanyConfig::create([
        'eu_sales_threshold'      => CompanyConfig::amountToBase100(1000.0),
        'current_eu_sales_amount' => CompanyConfig::amountToBase100(500.0),
        'threshold_exceeded'      => false,
        'fiscal_year'             => 2024,
    ]);

    expect($config->checkThreshold())->toBeFalse();

    // Update to exceed threshold
    $config->update(['current_eu_sales_amount' => CompanyConfig::amountToBase100(1200.0)]);
    expect($config->checkThreshold())->toBeTrue();
    expect($config->threshold_exceeded_at)->not->toBeNull();
});

it('can update EU sales amount and check threshold', function () {
    $config = CompanyConfig::create([
        'eu_sales_threshold'      => 1000.0, // Let mutator convert to base-100
        'current_eu_sales_amount' => 0,
        'fiscal_year'             => 2024,
    ]);

    $config->updateEuSales(500.0);
    expect($config->current_eu_sales_amount)->toBe(500.0);

    $config->updateEuSales(600.0);
    expect($config->current_eu_sales_amount)->toBe(1100.0);

    // Refresh from database to get updated threshold_exceeded_at
    $config->refresh();
    expect($config->threshold_exceeded_at)->not->toBeNull();
});

it('can reset EU sales for new fiscal year', function () {
    $config = CompanyConfig::create([
        'current_eu_sales_amount' => CompanyConfig::amountToBase100(1500.0),
        'threshold_exceeded'      => true,
        'threshold_exceeded_at'   => now(),
        'notification_sent'       => true,
        'fiscal_year'             => 2024,
    ]);

    $config->resetEuSales();

    expect($config->current_eu_sales_amount)->toBe(0.0);
    expect($config->threshold_exceeded_at)->toBeNull();
    expect($config->notification_sent)->toBeFalse();
});

it('can determine if destination VAT should be applied', function () {
    $config = CompanyConfig::create([
        'is_oss'                  => false,
        'auto_apply_destination'  => true,
        'eu_sales_threshold'      => CompanyConfig::amountToBase100(1000.0),
        'current_eu_sales_amount' => CompanyConfig::amountToBase100(500.0),
        'fiscal_year'             => 2024,
    ]);

    // Should not apply when threshold not exceeded
    expect($config->shouldApplyDestinationVat())->toBeFalse();

    // Should apply when OSS is enabled
    $config->enableOSS();
    expect($config->shouldApplyDestinationVat())->toBeTrue();

    // Should apply when threshold exceeded and auto-apply enabled
    $config->disableOSS();
    $config->update(['current_eu_sales_amount' => CompanyConfig::amountToBase100(1200.0)]);
    expect($config->shouldApplyDestinationVat())->toBeTrue();

    // Should not apply when auto-apply disabled and threshold exceeded
    $config->update(['auto_apply_destination' => false]);
    expect($config->shouldApplyDestinationVat())->toBeFalse();
});

it('can enable and disable OSS registration', function () {
    $config = CompanyConfig::create([
        'fiscal_year' => 2024,
        'is_oss'      => false,
    ]);

    expect($config->is_oss)->toBeFalse();

    $config->enableOSS();
    expect($config->is_oss)->toBeTrue();

    $config->disableOSS();
    expect($config->is_oss)->toBeFalse();
});

it('can enable and disable ROI status', function () {
    $config = CompanyConfig::create([
        'fiscal_year' => 2024,
        'is_roi'      => false,
    ]);

    expect($config->is_roi)->toBeFalse();

    $config->enableROI();
    expect($config->is_roi)->toBeTrue();

    $config->disableROI();
    expect($config->is_roi)->toBeFalse();
});

it('can mark notification as sent', function () {
    $config = CompanyConfig::create([
        'notification_sent' => false,
        'fiscal_year'       => 2024,
    ]);

    $config->markNotificationSent();
    expect($config->notification_sent)->toBeTrue();
});

it('can get fiscal year dates', function () {
    $config = CompanyConfig::create([
        'fiscal_year'       => 2024,
        'fiscal_year_start' => '01-01',
    ]);

    $startDate = $config->getFiscalYearStartDate();
    $endDate   = $config->getFiscalYearEndDate();

    expect($startDate)->toBeInstanceOf(Carbon::class);
    expect($endDate)->toBeInstanceOf(Carbon::class);
    expect($startDate->year)->toBe(2024);
    expect($endDate->year)->toBe(2024);
    expect($endDate->month)->toBe(12);
    expect($endDate->day)->toBe(31);
});

it('can check if date is within fiscal year', function () {
    $currentYear = now()->year;

    $config = CompanyConfig::create([
        'fiscal_year'       => $currentYear,
        'fiscal_year_start' => '01-01',
    ]);

    // Date within fiscal year
    $dateWithin = Carbon::create($currentYear, 6, 15);
    expect($config->isWithinFiscalYear($dateWithin))->toBeTrue();

    // Date outside fiscal year
    $dateOutside = Carbon::create($currentYear - 1, 12, 31);
    expect($config->isWithinFiscalYear($dateOutside))->toBeFalse();

    // Current date (default) - should always be within the current fiscal year
    expect($config->isWithinFiscalYear())->toBeTrue();
});

it('can get threshold percentage', function () {
    $config = CompanyConfig::create([
        'eu_sales_threshold'      => CompanyConfig::amountToBase100(1000.0),
        'current_eu_sales_amount' => CompanyConfig::amountToBase100(250.0),
        'fiscal_year'             => 2024,
    ]);

    expect($config->getThresholdPercentage())->toBe(25.0);

    // Test 100% threshold
    $config->update(['current_eu_sales_amount' => CompanyConfig::amountToBase100(1000.0)]);
    expect($config->getThresholdPercentage())->toBe(100.0);

    // Test over 100% (should be capped at 100%)
    $config->update(['current_eu_sales_amount' => CompanyConfig::amountToBase100(1500.0)]);
    expect($config->getThresholdPercentage())->toBe(100.0);

    // Test zero threshold
    $config->update(['eu_sales_threshold' => 0]);
    expect($config->getThresholdPercentage())->toBe(0.0);
});

it('can get remaining threshold amount', function () {
    $config = CompanyConfig::create([
        'eu_sales_threshold'      => CompanyConfig::amountToBase100(1000.0),
        'current_eu_sales_amount' => CompanyConfig::amountToBase100(300.0),
        'fiscal_year'             => 2024,
    ]);

    expect($config->getRemainingThresholdAmount())->toBe(70000.0);

    // Test when threshold exceeded
    $config->update(['current_eu_sales_amount' => CompanyConfig::amountToBase100(1200.0)]);
    expect($config->getRemainingThresholdAmount())->toBe(0.0);
});

it('can check if company needs threshold notification', function () {
    $config = CompanyConfig::create([
        'threshold_exceeded' => false,
        'notification_sent'  => false,
        'fiscal_year'        => 2024,
    ]);

    expect($config->needsNotification())->toBeFalse();

    // Threshold exceeded but notification not sent
    $config->update(['threshold_exceeded' => true]);
    expect($config->needsNotification())->toBeTrue();

    // Notification already sent
    $config->update(['notification_sent' => true]);
    expect($config->needsNotification())->toBeFalse();
});

it('can manage custom threshold rules', function () {
    $config = CompanyConfig::create([
        'custom_threshold_rules' => null,
        'fiscal_year'            => 2024,
    ]);

    // Get non-existent rule
    expect($config->getCustomThresholdRule('DE'))->toBeNull();

    // Set custom rule
    $rule = ['threshold' => 500.0, 'enabled' => true];
    $config->setCustomThresholdRule('DE', $rule);

    expect($config->getCustomThresholdRule('DE'))->toBe(['threshold' => 500, 'enabled' => true]);
    expect($config->custom_threshold_rules['DE'])->toBe(['threshold' => 500, 'enabled' => true]);
});

it('can reset for new fiscal year', function () {
    $config = CompanyConfig::create([
        'fiscal_year'             => 2024,
        'current_eu_sales_amount' => CompanyConfig::amountToBase100(1500.0),
        'threshold_exceeded'      => true,
        'threshold_exceeded_at'   => now(),
        'notification_sent'       => true,
    ]);

    $config->resetForNewYear(2025);

    expect($config->fiscal_year)->toBe(2025);
    expect($config->current_eu_sales_amount)->toBe(0.0);
    expect($config->threshold_exceeded)->toBeFalse();
    expect($config->threshold_exceeded_at)->toBeNull();
    expect($config->notification_sent)->toBeFalse();
});
