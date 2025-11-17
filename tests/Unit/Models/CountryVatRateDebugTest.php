<?php

declare(strict_types=1);

/**
 * @deprecated v0.4.0 - CountryVatRate table removed
 * These tests use legacy API/tables that no longer exist in v0.4.0.
 */

// Skip all tests - legacy code
beforeEach(function () {
    $this->markTestSkipped('Legacy test - deprecated in v0.4.0');
});

use AichaDigital\Larabill\Models\CountryVatRate;

describe('CountryVatRate Debug', function () {
    beforeEach(function () {
        // Clear any existing data
        CountryVatRate::truncate();
    });

    it('can create a simple country VAT rate manually', function () {
        $vatRate = CountryVatRate::create([
            'country_code'  => 'ES',
            'country_name'  => 'Spain',
            'standard_rate' => 2100, // 21% in base 100
            'reduced_rates' => json_encode([
                'general'       => 1000, // 10% in base 100
                'super_reduced' => 400, // 4% in base 100
            ]),
            'exempt_categories' => json_encode([
                'medical_services',
                'education',
            ]),
            'is_active'    => true,
            'data_source'  => 'manual',
            'last_updated' => now(),
        ]);

        expect($vatRate->exists)->toBeTrue();
        expect($vatRate->country_code)->toBe('ES');
        expect($vatRate->standard_rate)->toBe(2100);
        expect($vatRate->reduced_rates)->toBe([
            'general'       => 1000,
            'super_reduced' => 400,
        ]);
    });

    it('can create a country VAT rate with minimal data', function () {
        $vatRate = CountryVatRate::create([
            'country_code'  => 'FR',
            'country_name'  => 'France',
            'standard_rate' => 2000, // 20% in base 100
            'is_active'     => true,
            'data_source'   => 'manual',
            'last_updated'  => now(),
        ]);

        expect($vatRate->exists)->toBeTrue();
        expect($vatRate->country_code)->toBe('FR');
        expect($vatRate->standard_rate)->toBe(2000);
        expect($vatRate->reduced_rates)->toBeNull();
        expect($vatRate->exempt_categories)->toBeNull();
    });

    it('can create a country VAT rate with empty arrays', function () {
        $vatRate = CountryVatRate::create([
            'country_code'      => 'DE',
            'country_name'      => 'Germany',
            'standard_rate'     => 1900, // 19% in base 100
            'reduced_rates'     => [],
            'exempt_categories' => [],
            'is_active'         => true,
            'data_source'       => 'manual',
            'last_updated'      => now(),
        ]);

        expect($vatRate->exists)->toBeTrue();
        expect($vatRate->country_code)->toBe('DE');
        expect($vatRate->standard_rate)->toBe(1900);
        expect($vatRate->reduced_rates)->toBe([]);
        expect($vatRate->exempt_categories)->toBe([]);
    });
});
