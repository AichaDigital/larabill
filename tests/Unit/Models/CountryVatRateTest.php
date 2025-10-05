<?php

declare(strict_types=1);

use AichaDigital\Larabill\Database\Factories\CountryVatRateFactory;
use AichaDigital\Larabill\Models\CountryVatRate;

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
        expect($vatRate->standard_rate)->toBeInt(); // Base 100 format
        expect($vatRate->reduced_rates)->toBeArray();
        expect($vatRate->exempt_categories)->toBeArray();
        expect($vatRate->is_active)->toBeTrue();
    });

    it('can create Spanish VAT rates using factory state', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->country_code)->toBe('ES');
        expect($vatRate->country_name)->toBe('Spain');
        expect($vatRate->standard_rate)->toBe(2100); // 21% in base 100
        expect($vatRate->reduced_rates)->toBe([
            'general'       => 1000, // 10% in base 100
            'super_reduced' => 400, // 4% in base 100
        ]);
        expect($vatRate->exempt_categories)->toContain('medical_services');
    });

    it('can convert percentage to base 100', function () {
        expect(CountryVatRate::percentageToBase100(21.50))->toBe(2150);
        expect(CountryVatRate::percentageToBase100(12.34))->toBe(1234);
        expect(CountryVatRate::percentageToBase100(0))->toBe(0);
        expect(CountryVatRate::percentageToBase100(100))->toBe(10000);
    });

    it('can convert base 100 to percentage', function () {
        expect(CountryVatRate::base100ToPercentage(2150))->toBe(21.50);
        expect(CountryVatRate::base100ToPercentage(1234))->toBe(12.34);
        expect(CountryVatRate::base100ToPercentage(0))->toBe(0.0);
        expect(CountryVatRate::base100ToPercentage(10000))->toBe(100.0);
    });

    it('can get rate for category in base 100 format', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        // Test standard rate
        expect($vatRate->getRateForCategory('standard'))->toBe(2100); // 21% in base 100

        // Test reduced rate
        expect($vatRate->getRateForCategory('general'))->toBe(1000); // 10% in base 100
        expect($vatRate->getRateForCategory('super_reduced'))->toBe(400); // 4% in base 100

        // Test exempt category
        expect($vatRate->getRateForCategory('medical_services'))->toBe(0); // 0% in base 100

        // Test non-existent category (should return standard rate)
        expect($vatRate->getRateForCategory('non_existent'))->toBe(2100);
    });

    it('can get rate for category as percentage', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        // Test standard rate
        expect($vatRate->getRateForCategoryAsPercentage('standard'))->toBe(21.0);

        // Test reduced rate
        expect($vatRate->getRateForCategoryAsPercentage('general'))->toBe(10.0);
        expect($vatRate->getRateForCategoryAsPercentage('super_reduced'))->toBe(4.0);

        // Test exempt category
        expect($vatRate->getRateForCategoryAsPercentage('medical_services'))->toBe(0.0);
    });

    it('can get standard rate as percentage', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->getStandardRateAsPercentage())->toBe(21.0);
    });

    it('can set standard rate from percentage', function () {
        $vatRate = CountryVatRateFactory::new()->create();

        $vatRate->setStandardRateFromPercentage(22.5);

        expect($vatRate->fresh()->standard_rate)->toBe(2250); // 22.5% in base 100
        expect($vatRate->getStandardRateAsPercentage())->toBe(22.5);
    });

    it('can get reduced rates in base 100 format', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        $reducedRates = $vatRate->getReducedRates();

        expect($reducedRates)->toBe([
            'general'       => 1000, // 10% in base 100
            'super_reduced' => 400, // 4% in base 100
        ]);

        // Ensure all values are integers
        foreach ($reducedRates as $rate) {
            expect($rate)->toBeInt();
        }
    });

    it('can get reduced rates as percentages', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        $reducedRates = $vatRate->getReducedRatesAsPercentages();

        expect($reducedRates)->toBe([
            'general'       => 10.0,
            'super_reduced' => 4.0,
        ]);
    });

    it('can get reduced rate for category in base 100 format', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->getReducedRate('general'))->toBe(1000); // 10% in base 100
        expect($vatRate->getReducedRate('super_reduced'))->toBe(400); // 4% in base 100
        expect($vatRate->getReducedRate('non_existent'))->toBeNull();
    });

    it('can get reduced rate for category as percentage', function () {
        $vatRate = CountryVatRateFactory::new()->spanish()->create();

        expect($vatRate->getReducedRateAsPercentage('general'))->toBe(10.0);
        expect($vatRate->getReducedRateAsPercentage('super_reduced'))->toBe(4.0);
        expect($vatRate->getReducedRateAsPercentage('non_existent'))->toBeNull();
    });

    it('can set reduced rate using base 100 format', function () {
        $vatRate = CountryVatRateFactory::new()->create();

        $vatRate->setReducedRate('books', 500); // 5% in base 100

        expect($vatRate->fresh()->getReducedRate('books'))->toBe(500);
        expect($vatRate->getReducedRateAsPercentage('books'))->toBe(5.0);
    });

    it('can set reduced rate from percentage', function () {
        $vatRate = CountryVatRateFactory::new()->create();

        $vatRate->setReducedRateFromPercentage('books', 5.5);

        expect($vatRate->fresh()->getReducedRate('books'))->toBe(550); // 5.5% in base 100
        expect($vatRate->getReducedRateAsPercentage('books'))->toBe(5.5);
    });

    it('can validate rate data integrity with base 100 format', function () {
        // Valid data
        $validVatRate = CountryVatRateFactory::new()->spanish()->create();
        expect($validVatRate->isValidRateData())->toBeTrue();

        // Invalid standard rate (too high)
        $invalidVatRate = CountryVatRateFactory::new()->create([
            'standard_rate' => 15000, // 150% - too high
        ]);
        expect($invalidVatRate->isValidRateData())->toBeFalse();

        // Invalid reduced rate (higher than standard)
        $invalidVatRate2 = CountryVatRateFactory::new()->create([
            'standard_rate' => 2000, // 20%
            'reduced_rates' => ['books' => 2500], // 25% - higher than standard
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
});
