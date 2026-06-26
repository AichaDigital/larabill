<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\CountryVatRateFactory;
use AichaDigital\Larabill\Models\CountryVatRate;
use Carbon\Carbon;

describe('CountryVatRate Model', function () {
    beforeEach(function () {
        // Clear any existing data
        CountryVatRate::truncate();
    });

    it('can create a country VAT rate using factory', function () {
        $vatRate = CountryVatRateFactory::new()->create();

        expect($vatRate)->toBeInstanceOf(CountryVatRate::class);
        expect($vatRate->country_code)->toBeString();
        expect($vatRate->country_name)->toBeString();
        expect($vatRate->standard_rate)->toBeInstanceOf(FixedDecimal::class); // FixedDecimal:2 over base-100
        expect($vatRate->reduced_rates)->toBeArray();
        expect($vatRate->exempt_categories)->toBeArray();
        expect($vatRate->is_active)->toBeTrue();
    });

    it('can create Spanish VAT rates using factory state', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->country_code)->toBe('ES');
        expect($vatRate->country_name)->toBe('Spain');
        expect($vatRate->standard_rate->unscaledValue())->toBe(2100); // 21% in base 100
        expect($vatRate->reduced_rates)->toBe([
            'general'       => 1000, // 10% in base 100 (raw JSON int)
            'super_reduced' => 400, // 4% in base 100
        ]);
        expect($vatRate->exempt_categories)->toContain('medical_services');
    });

    it('can get rate for category as FixedDecimal', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        // Standard rate (default fallback)
        expect($vatRate->getRateForCategory('standard')->unscaledValue())->toBe(2100);

        // Reduced rates
        expect($vatRate->getRateForCategory('general')->unscaledValue())->toBe(1000);
        expect($vatRate->getRateForCategory('super_reduced')->unscaledValue())->toBe(400);

        // Exempt category
        expect($vatRate->getRateForCategory('medical_services')->isZero())->toBeTrue();

        // Non-existent category falls back to standard rate
        expect($vatRate->getRateForCategory('non_existent')->unscaledValue())->toBe(2100);
    });

    it('can get standard rate as a FixedDecimal', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->standard_rate->toFloat())->toBe(21.0);
        expect($vatRate->standard_rate->toDecimalString())->toBe('21.00');
    });

    it('can get reduced rates as FixedDecimal map', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        $reducedRates = $vatRate->getReducedRates();

        expect($reducedRates['general']->unscaledValue())->toBe(1000); // 10% in base 100
        expect($reducedRates['super_reduced']->unscaledValue())->toBe(400); // 4% in base 100

        foreach ($reducedRates as $rate) {
            expect($rate)->toBeInstanceOf(FixedDecimal::class);
        }
    });

    it('can get reduced rate for category as FixedDecimal', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->getReducedRate('general')->unscaledValue())->toBe(1000); // 10% in base 100
        expect($vatRate->getReducedRate('super_reduced')->unscaledValue())->toBe(400); // 4% in base 100
        expect($vatRate->getReducedRate('non_existent'))->toBeNull();
    });

    it('can set reduced rate from a FixedDecimal', function () {
        $vatRate = CountryVatRateFactory::new()->create();

        $vatRate->setReducedRate('books', cents(500)); // 5% in base 100

        expect($vatRate->fresh()->getReducedRate('books')->unscaledValue())->toBe(500);
    });

    it('can validate rate data integrity with base 100 bounds', function () {
        // Valid data
        $validVatRate = CountryVatRateFactory::new()->spanish()->create();
        expect($validVatRate->isValidRateData())->toBeTrue();

        // Invalid standard rate (too high)
        $invalidVatRate = CountryVatRateFactory::new()->create([
            'country_code'  => 'IL',
            'standard_rate' => cents(15000), // 150% - too high
        ]);
        expect($invalidVatRate->isValidRateData())->toBeFalse();

        // Invalid reduced rate (higher than standard)
        $invalidVatRate2 = CountryVatRateFactory::new()->create([
            'country_code'  => 'IT',
            'standard_rate' => cents(2000), // 20%
            'reduced_rates' => ['books' => 2500], // 25% - higher than standard (raw JSON int)
        ]);
        expect($invalidVatRate2->isValidRateData())->toBeFalse();
    });

    it('can check if category is exempt', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->isCategoryExempt('medical_services'))->toBeTrue();
        expect($vatRate->isCategoryExempt('education'))->toBeTrue();
        expect($vatRate->isCategoryExempt('books'))->toBeTrue();
        expect($vatRate->isCategoryExempt('food_basic'))->toBeTrue();
        expect($vatRate->isCategoryExempt('non_existent'))->toBeFalse();
    });

    it('can check if category has reduced rate', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->hasReducedRate('general'))->toBeTrue();
        expect($vatRate->hasReducedRate('super_reduced'))->toBeTrue();
        expect($vatRate->hasReducedRate('non_existent'))->toBeFalse();
    });

    it('can find VAT rate by country', function () {
        CountryVatRateFactory::new()->spanish()->create();
        CountryVatRateFactory::new()->french()->create();

        $spanishRate = CountryVatRate::findByCountry('ES');
        $frenchRate  = CountryVatRate::findByCountry('FR');
        $nonExistent = CountryVatRate::findByCountry('XX');

        expect($spanishRate)->not->toBeNull();
        expect($spanishRate->country_code)->toBe('ES');
        expect($frenchRate)->not->toBeNull();
        expect($frenchRate->country_code)->toBe('FR');
        expect($nonExistent)->toBeNull();
    });

    it('can create inactive VAT rate', function () {
        $vatRate = CountryVatRateFactory::new()->inactive()->create();

        expect($vatRate->is_active)->toBeFalse();

        // Should not be found by findByCountry when inactive
        expect(CountryVatRate::findByCountry($vatRate->country_code))->toBeNull();
    });

    it('can add exempt category', function () {
        $vatRate = CountryVatRateFactory::new()->create();

        $vatRate->addExemptCategory('new_category');

        expect($vatRate->fresh()->isCategoryExempt('new_category'))->toBeTrue();
    });

    it('can remove reduced rate', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->hasReducedRate('general'))->toBeTrue();

        $vatRate->removeReducedRate('general');

        expect($vatRate->fresh()->hasReducedRate('general'))->toBeFalse();
    });

    it('can remove exempt category', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->isCategoryExempt('medical_services'))->toBeTrue();

        $vatRate->removeExemptCategory('medical_services');

        expect($vatRate->fresh()->isCategoryExempt('medical_services'))->toBeFalse();
    });

    it('can get all categories', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        $categories = $vatRate->getAllCategories();

        expect($categories)->toBeArray();
        expect($categories)->toHaveKey('general');
        expect($categories)->toHaveKey('super_reduced');
        expect($categories)->toHaveKey('medical_services');
        expect($categories['general']['type'])->toBe('reduced');
        expect($categories['general']['rate'])->toBe(1000); // raw base-100 int from JSON structure
        expect($categories['medical_services']['type'])->toBe('exempt');
        expect($categories['medical_services']['rate'])->toBe(0);
    });

    it('can get formatted standard rate', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->getFormattedStandardRate())->toBe('21.00%');
    });

    it('can get formatted reduced rates', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        $formatted = $vatRate->getFormattedReducedRates();

        expect($formatted)->toBeArray();
        expect($formatted)->toHaveKey('general');
        expect($formatted['general'])->toBe('10.00%');
        expect($formatted['super_reduced'])->toBe('4.00%');
    });

    it('can scope active VAT rates', function () {
        CountryVatRateFactory::new()->create(['is_active' => true]);
        CountryVatRateFactory::new()->create(['is_active' => false]);

        $activeRates = CountryVatRate::active()->get();

        expect($activeRates)->toHaveCount(1);
        expect($activeRates->first()->is_active)->toBeTrue();
    });

    it('can scope inactive VAT rates', function () {
        CountryVatRateFactory::new()->create(['is_active' => true]);
        CountryVatRateFactory::new()->create(['is_active' => false]);

        $inactiveRates = CountryVatRate::inactive()->get();

        expect($inactiveRates)->toHaveCount(1);
        expect($inactiveRates->first()->is_active)->toBeFalse();
    });

    it('can scope by country', function () {
        CountryVatRateFactory::new()->spanish()->create();
        CountryVatRateFactory::new()->french()->create();

        $spanishRates = CountryVatRate::byCountry('ES')->get();

        expect($spanishRates)->toHaveCount(1);
        expect($spanishRates->first()->country_code)->toBe('ES');
    });

    it('can scope by rate range (percentage args)', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1500)]); // 15%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2000)]); // 20%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2500)]); // 25%

        $midRangeRates = CountryVatRate::byRate(18.0, 23.0)->get();

        expect($midRangeRates)->toHaveCount(1);
        expect($midRangeRates->first()->standard_rate->unscaledValue())->toBe(2000);
    });

    it('includes the boundary rates in scopeByRate (inclusive)', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1800)]); // 18.00% lower bound
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1850)]); // 18.50%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2300)]); // 23.00% upper bound
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2301)]); // 23.01% just outside

        $inRange = CountryVatRate::byRate(18.0, 23.0)->get();

        expect($inRange)->toHaveCount(3); // 18.00, 18.50, 23.00 — 23.01 excluded
    });

    it('can scope by min rate only', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1500)]); // 15%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2000)]); // 20%

        $highRates = CountryVatRate::byRate(18.0, null)->get();

        expect($highRates)->toHaveCount(1);
    });

    it('can scope by max rate only', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1500)]); // 15%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2000)]); // 20%

        $lowRates = CountryVatRate::byRate(null, 18.0)->get();

        expect($lowRates)->toHaveCount(1);
    });

    it('can scope by updated after date', function () {
        $oldDate    = now()->subDays(10);
        $recentDate = now()->subDays(2);

        CountryVatRateFactory::new()->create(['last_updated' => $oldDate]);
        CountryVatRateFactory::new()->create(['last_updated' => $recentDate]);

        $recentRates = CountryVatRate::updatedAfter(now()->subDays(5))->get();

        expect($recentRates)->toHaveCount(1);
    });

    it('can scope by data source', function () {
        CountryVatRateFactory::new()->create(['data_source' => 'api']);
        CountryVatRateFactory::new()->create(['data_source' => 'manual']);

        $apiRates = CountryVatRate::byDataSource('api')->get();

        expect($apiRates)->toHaveCount(1);
        expect($apiRates->first()->data_source)->toBe('api');
    });

    it('can get EU countries VAT rates', function () {
        CountryVatRateFactory::new()->spanish()->create();
        CountryVatRateFactory::new()->french()->create();
        CountryVatRateFactory::new()->german()->create();
        CountryVatRateFactory::new()->create(['country_code' => 'US', 'is_active' => true]); // Non-EU

        $euRates = CountryVatRate::getEuCountries();

        expect($euRates)->toHaveCount(3); // Only EU countries
        expect($euRates->pluck('country_code')->toArray())->not->toContain('US');
    });

    it('can import VAT rates from data source', function () {
        $data = [
            [
                'country_code'      => 'ES',
                'country_name'      => 'Spain',
                'standard_rate'     => 2100, // external source: raw base-100 int
                'reduced_rates'     => ['general' => 1000],
                'exempt_categories' => ['medical'],
            ],
            [
                'country_code'  => 'FR',
                'country_name'  => 'France',
                'standard_rate' => 2000,
            ],
            [
                // Missing required fields - should be skipped
                'country_code' => 'DE',
            ],
        ];

        $imported = CountryVatRate::importFromDataSource($data, 'test-import');

        expect($imported)->toBe(2); // Only 2 valid records
        expect(CountryVatRate::where('data_source', 'test-import')->count())->toBe(2);
        expect(CountryVatRate::findByCountry('ES')->standard_rate->unscaledValue())->toBe(2100);
    });

    it('can find by country code', function () {
        CountryVatRateFactory::new()->spanish()->create();

        $found      = CountryVatRate::findByCountryCode('ES');
        $foundLower = CountryVatRate::findByCountryCode('es'); // Test case insensitivity

        expect($found)->not->toBeNull();
        expect($found->country_code)->toBe('ES');
        expect($foundLower)->not->toBeNull();
    });

    it('can find by country name', function () {
        CountryVatRateFactory::new()->spanish()->create();

        $found      = CountryVatRate::findByCountryName('Spain');
        $foundLower = CountryVatRate::findByCountryName('spain'); // Test case insensitivity

        expect($found)->not->toBeNull();
        expect($found->country_name)->toBe('Spain');
        expect($foundLower)->not->toBeNull();
    });

    it('can get default rate for known country', function () {
        expect(CountryVatRate::getDefaultRateForCountry('ES')->unscaledValue())->toBe(2100);
        expect(CountryVatRate::getDefaultRateForCountry('FR')->unscaledValue())->toBe(2000);
        expect(CountryVatRate::getDefaultRateForCountry('DE')->unscaledValue())->toBe(1900);
    });

    it('returns zero for unknown country default rate', function () {
        expect(CountryVatRate::getDefaultRateForCountry('XX')->isZero())->toBeTrue();
    });

    it('can find by country code or fail', function () {
        CountryVatRateFactory::new()->spanish()->create();

        $found = CountryVatRate::findByCountryCodeOrFail('ES');

        expect($found)->not->toBeNull();
        expect($found->country_code)->toBe('ES');
    });

    it('can find by standard rate range (percentage args)', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1500), 'is_active' => true]); // 15%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2000), 'is_active' => true]); // 20%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2500), 'is_active' => true]); // 25%

        $midRange = CountryVatRate::findByStandardRateRange(18.0, 23.0)->get();

        expect($midRange)->toHaveCount(1);
        expect($midRange->first()->standard_rate->unscaledValue())->toBe(2000);
    });

    it('can find similar rates within tolerance (percentage args)', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2000), 'is_active' => true]); // 20%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2100), 'is_active' => true]); // 21%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1500), 'is_active' => true]); // 15%

        $similar = CountryVatRate::findSimilarRates(20.0, 2.0)->get();

        // 20% ± 2% exclusive => (18.00, 22.00): captures 20% and 21%, not 15%
        expect($similar)->toHaveCount(2);
    });

    it('updates last_updated timestamp on update', function () {
        $vatRate      = CountryVatRateFactory::new()->create(['last_updated' => now()->subDays(5)]);
        $oldTimestamp = $vatRate->last_updated;

        sleep(1);
        $vatRate->update(['country_name' => 'Updated Name']);

        expect($vatRate->fresh()->last_updated->gt($oldTimestamp))->toBeTrue();
    });

    it('can get standard rate getter as FixedDecimal', function () {
        $vatRate = CountryVatRateFactory::new()->create(['standard_rate' => cents(2100)]);

        expect($vatRate->getStandardRate()->unscaledValue())->toBe(2100);
    });

    it('can check if category is exempt using alias method', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->isExemptCategory('medical_services'))->toBeTrue();
        expect($vatRate->isExemptCategory('non_existent'))->toBeFalse();
    });

    it('can get all rates', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        $allRates = $vatRate->getAllRates();

        expect($allRates)->toBeArray();
        expect($allRates)->toHaveKey('standard');
        expect($allRates)->toHaveKey('reduced');
        expect($allRates['standard']->unscaledValue())->toBe(2100);
        expect($allRates['reduced']['general']->unscaledValue())->toBe(1000);
    });

    it('can activate VAT rate', function () {
        $vatRate = CountryVatRateFactory::new()->create(['is_active' => false]);

        expect($vatRate->is_active)->toBeFalse();

        $activated = $vatRate->activate();

        expect($activated->is_active)->toBeTrue();
    });

    it('can deactivate VAT rate', function () {
        $vatRate = CountryVatRateFactory::new()->create(['is_active' => true]);

        expect($vatRate->is_active)->toBeTrue();

        $deactivated = $vatRate->deactivate();

        expect($deactivated->is_active)->toBeFalse();
    });

    it('can update VAT rates', function () {
        $vatRate = CountryVatRateFactory::new()->create(['standard_rate' => cents(2000)]);

        $updated = $vatRate->updateVatRates([
            'standard_rate' => cents(2100),
            'reduced_rates' => ['books' => 500],
        ]);

        expect($updated->standard_rate->unscaledValue())->toBe(2100);
        expect($updated->reduced_rates)->toHaveKey('books');
    });

    it('can get data source', function () {
        $vatRate = CountryVatRateFactory::new()->create(['data_source' => 'api-source']);

        expect($vatRate->getDataSource())->toBe('api-source');
    });

    it('can check if has data source', function () {
        $withSource    = CountryVatRateFactory::new()->create(['data_source' => 'api']);
        $withoutSource = CountryVatRateFactory::new()->create(['data_source' => '']);

        expect($withSource->hasDataSource())->toBeTrue();
        expect($withoutSource->hasDataSource())->toBeFalse();
    });

    it('can check if rates are up to date', function () {
        $recent = CountryVatRateFactory::new()->create(['last_updated' => now()->subDays(5)]);
        $old    = CountryVatRateFactory::new()->create(['last_updated' => now()->subDays(40)]);

        expect($recent->isRatesUpToDate(30))->toBeTrue();
        expect($old->isRatesUpToDate(30))->toBeFalse();
    });

    it('can get countries needing rate update', function () {
        CountryVatRateFactory::new()->create(['last_updated' => now()->subDays(5), 'is_active' => true]);
        CountryVatRateFactory::new()->create(['last_updated' => now()->subDays(40), 'is_active' => true]);
        CountryVatRateFactory::new()->create(['last_updated' => now()->subDays(50), 'is_active' => false]); // Inactive, should be excluded

        $needingUpdate = CountryVatRate::getCountriesNeedingRateUpdate(30);

        expect($needingUpdate)->toHaveCount(1);
        expect($needingUpdate->first()->last_updated->lt(now()->subDays(30)))->toBeTrue();
    });

    it('sets last_updated automatically on creation', function () {
        $vatRate = CountryVatRateFactory::new()->create(['last_updated' => null]);

        expect($vatRate->fresh()->last_updated)->not->toBeNull();
        expect($vatRate->fresh()->last_updated)->toBeInstanceOf(Carbon::class);
    });

    it('sets is_active to true by default on creation', function () {
        $vatRate = CountryVatRateFactory::new()->create(['is_active' => null]);

        expect($vatRate->fresh()->is_active)->toBeTrue();
    });

    it('ensures data_source is not null on creation', function () {
        $vatRate = CountryVatRateFactory::new()->create(['data_source' => null]);

        expect($vatRate->fresh()->data_source)->toBe('');
    });

    it('ensures data_source is not null on update', function () {
        $vatRate = CountryVatRateFactory::new()->create(['data_source' => 'api']);

        $vatRate->data_source = null;
        $vatRate->save();

        expect($vatRate->fresh()->data_source)->toBe('');
    });

    it('can get VAT rate statistics', function () {
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2100), 'is_active' => true]); // 21%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(2000), 'is_active' => true]); // 20%
        CountryVatRateFactory::new()->create(['standard_rate' => cents(1900), 'is_active' => false]); // 19% inactive

        $stats = CountryVatRate::getVatRateStatistics();

        expect($stats)->toBeArray();
        expect($stats)->toHaveKey('total');
        expect($stats)->toHaveKey('active');
        expect($stats)->toHaveKey('inactive');
        expect($stats)->toHaveKey('average_standard_rate');
        expect($stats)->toHaveKey('highest_standard_rate');
        expect($stats)->toHaveKey('lowest_standard_rate');
        expect($stats['total'])->toBe(3);
        expect($stats['active'])->toBe(2);
        expect($stats['inactive'])->toBe(1);
    });

    it('handles empty collection in statistics', function () {
        CountryVatRate::truncate();

        $stats = CountryVatRate::getVatRateStatistics();

        expect($stats['total'])->toBe(0);
        expect($stats['average_standard_rate'])->toBe(0.0);
        expect($stats['highest_standard_rate'])->toBe(0.0);
        expect($stats['lowest_standard_rate'])->toBe(0.0);
    });
});
