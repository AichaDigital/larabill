<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\DestinationVatService;

describe('DestinationVatService', function () {
    it('can be instantiated', function () {
        $service = new DestinationVatService;

        expect($service)->toBeInstanceOf(DestinationVatService::class);
    });

    it('identifies EU countries correctly', function () {
        $service = new DestinationVatService;

        expect($service->isEuCountry('ES'))->toBeTrue();
        expect($service->isEuCountry('DE'))->toBeTrue();
        expect($service->isEuCountry('FR'))->toBeTrue();
        expect($service->isEuCountry('IT'))->toBeTrue();
        expect($service->isEuCountry('PT'))->toBeTrue();
    });

    it('identifies non-EU countries correctly', function () {
        $service = new DestinationVatService;

        expect($service->isEuCountry('US'))->toBeFalse();
        expect($service->isEuCountry('GB'))->toBeFalse();
        expect($service->isEuCountry('CH'))->toBeFalse();
        expect($service->isEuCountry('MX'))->toBeFalse();
    });

    it('handles lowercase country codes', function () {
        $service = new DestinationVatService;

        expect($service->isEuCountry('es'))->toBeTrue();
        expect($service->isEuCountry('de'))->toBeTrue();
    });

    it('validates destination country', function () {
        $service = new DestinationVatService;

        expect($service->isValidDestinationCountry('DE'))->toBeTrue();
        expect($service->isValidDestinationCountry('FR'))->toBeTrue();
        expect($service->isValidDestinationCountry('US'))->toBeFalse();
        expect($service->isValidDestinationCountry(''))->toBeFalse();
    });

    it('returns fiscal year from date', function () {
        $service = new DestinationVatService;

        expect($service->getFiscalYear(new \DateTime('2024-06-15')))->toBe(2024);
        expect($service->getFiscalYear(new \DateTime('2025-01-01')))->toBe(2025);
    });

    it('returns current fiscal year when no date provided', function () {
        $service = new DestinationVatService;

        expect($service->getFiscalYear())->toBe((int) date('Y'));
    });

    it('returns configuration array', function () {
        $service = new DestinationVatService;

        $config = $service->getConfiguration();

        expect($config)->toBeArray();
    });
});
