<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\RegionalContext;

it('can get configured country', function () {
    config(['larabill.region.country' => 'ES']);
    
    expect(RegionalContext::getCountryCode())->toBe('ES');
});

it('returns default country when not configured', function () {
    config(['larabill.region.country' => null]);
    
    expect(RegionalContext::getCountryCode())->toBe('ES'); // Default
});

it('can get configured region', function () {
    config(['larabill.region.region' => 'US-CA']);
    
    expect(RegionalContext::getRegionCode())->toBe('US-CA');
});

it('returns null region when not configured', function () {
    config(['larabill.region.region' => null]);
    
    expect(RegionalContext::getRegionCode())->toBeNull();
});

it('can get configured tax system', function () {
    config(['larabill.region.tax_system' => 'sales_tax']);
    
    expect(RegionalContext::getTaxSystemType())->toBe('sales_tax');
});

it('returns default tax system when not configured', function () {
    config(['larabill.region.tax_system' => null]);
    
    expect(RegionalContext::getTaxSystemType())->toBe('vat'); // Default
});

it('can get configured fiscal zone', function () {
    config(['larabill.region.fiscal_zone' => 'us']);
    
    expect(RegionalContext::getFiscalZone())->toBe('us');
});

it('returns default fiscal zone when not configured', function () {
    config(['larabill.region.fiscal_zone' => null]);
    
    expect(RegionalContext::getFiscalZone())->toBe('eu'); // Default
});

it('can check if correlative numbering is required', function () {
    config(['larabill.compliance.requires_correlative_numbering' => true]);
    
    expect(RegionalContext::requiresCorrelativeNumbering())->toBeTrue();
    
    config(['larabill.compliance.requires_correlative_numbering' => false]);
    
    expect(RegionalContext::requiresCorrelativeNumbering())->toBeFalse();
});

it('returns default for correlative numbering when not configured', function () {
    config(['larabill.compliance.requires_correlative_numbering' => null]);
    
    expect(RegionalContext::requiresCorrelativeNumbering())->toBeTrue(); // Default
});

it('can check if service dates are required', function () {
    config(['larabill.compliance.requires_service_dates' => true]);
    
    expect(RegionalContext::requiresServiceDates())->toBeTrue();
    
    config(['larabill.compliance.requires_service_dates' => false]);
    
    expect(RegionalContext::requiresServiceDates())->toBeFalse();
});

it('returns default for service dates when not configured', function () {
    config(['larabill.compliance.requires_service_dates' => null]);
    
    expect(RegionalContext::requiresServiceDates())->toBeTrue(); // Default
});

it('can check if tax verification is required', function () {
    config(['larabill.compliance.requires_tax_verification' => true]);
    
    expect(RegionalContext::requiresTaxVerification())->toBeTrue();
    
    config(['larabill.compliance.requires_tax_verification' => false]);
    
    expect(RegionalContext::requiresTaxVerification())->toBeFalse();
});

it('returns default for tax verification when not configured', function () {
    config(['larabill.compliance.requires_tax_verification' => null]);
    
    expect(RegionalContext::requiresTaxVerification())->toBeFalse(); // Default
});

it('can check if fiscal QR is required', function () {
    config(['larabill.compliance.requires_fiscal_qr' => true]);
    
    expect(RegionalContext::requiresFiscalQr())->toBeTrue();
    
    config(['larabill.compliance.requires_fiscal_qr' => false]);
    
    expect(RegionalContext::requiresFiscalQr())->toBeFalse();
});

it('returns default for fiscal QR when not configured', function () {
    config(['larabill.compliance.requires_fiscal_qr' => null]);
    
    expect(RegionalContext::requiresFiscalQr())->toBeFalse(); // Default
});

it('can get fiscal year start month', function () {
    config(['larabill.fiscal_year.start_month' => 4]);
    
    expect(RegionalContext::getFiscalYearStartMonth())->toBe(4);
});

it('returns default fiscal year start month when not configured', function () {
    config(['larabill.fiscal_year.start_month' => null]);
    
    expect(RegionalContext::getFiscalYearStartMonth())->toBe(1); // Default: January
});

it('can get fiscal year start day', function () {
    config(['larabill.fiscal_year.start_day' => 15]);
    
    expect(RegionalContext::getFiscalYearStartDay())->toBe(15);
});

it('returns default fiscal year start day when not configured', function () {
    config(['larabill.fiscal_year.start_day' => null]);
    
    expect(RegionalContext::getFiscalYearStartDay())->toBe(1); // Default: 1st day
});

it('can configure for CEE compliance', function () {
    config([
        'larabill.region.country'                            => 'ES',
        'larabill.region.tax_system'                         => 'vat',
        'larabill.region.fiscal_zone'                        => 'eu',
        'larabill.compliance.requires_correlative_numbering' => true,
        'larabill.compliance.requires_service_dates'         => true,
        'larabill.compliance.requires_tax_verification'      => false,
        'larabill.compliance.requires_fiscal_qr'             => false,
    ]);
    
    expect(RegionalContext::getCountryCode())->toBe('ES');
    expect(RegionalContext::getTaxSystemType())->toBe('vat');
    expect(RegionalContext::getFiscalZone())->toBe('eu');
    expect(RegionalContext::requiresCorrelativeNumbering())->toBeTrue();
    expect(RegionalContext::requiresServiceDates())->toBeTrue();
    expect(RegionalContext::requiresTaxVerification())->toBeFalse();
    expect(RegionalContext::requiresFiscalQr())->toBeFalse();
});

it('can configure for US Sales Tax compliance', function () {
    config([
        'larabill.region.country'                            => 'US',
        'larabill.region.region'                             => 'US-CA',
        'larabill.region.tax_system'                         => 'sales_tax',
        'larabill.region.fiscal_zone'                        => 'us',
        'larabill.compliance.requires_correlative_numbering' => false,
        'larabill.compliance.requires_service_dates'         => false,
        'larabill.compliance.requires_tax_verification'      => false,
        'larabill.compliance.requires_fiscal_qr'             => false,
    ]);
    
    expect(RegionalContext::getCountryCode())->toBe('US');
    expect(RegionalContext::getRegionCode())->toBe('US-CA');
    expect(RegionalContext::getTaxSystemType())->toBe('sales_tax');
    expect(RegionalContext::getFiscalZone())->toBe('us');
    expect(RegionalContext::requiresCorrelativeNumbering())->toBeFalse();
});

it('can configure for Australia GST compliance', function () {
    config([
        'larabill.region.country'     => 'AU',
        'larabill.region.tax_system'  => 'gst',
        'larabill.region.fiscal_zone' => 'au',
    ]);
    
    expect(RegionalContext::getCountryCode())->toBe('AU');
    expect(RegionalContext::getTaxSystemType())->toBe('gst');
    expect(RegionalContext::getFiscalZone())->toBe('au');
});

it('can configure custom fiscal year', function () {
    config([
        'larabill.fiscal_year.start_month' => 7,  // July
        'larabill.fiscal_year.start_day'   => 1,  // 1st
    ]);
    
    expect(RegionalContext::getFiscalYearStartMonth())->toBe(7);
    expect(RegionalContext::getFiscalYearStartDay())->toBe(1);
});

