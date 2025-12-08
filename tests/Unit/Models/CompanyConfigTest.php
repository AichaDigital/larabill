<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyConfig;

describe('CompanyConfig Model', function () {
    it('converts amount to base 100', function () {
        $base100 = CompanyConfig::amountToBase100(123.45);

        expect($base100)->toBe(12345);
    });

    it('converts base 100 to amount', function () {
        $amount = CompanyConfig::base100ToAmount(12345);

        expect($amount)->toBe(123.45);
    });

    it('converts zero correctly', function () {
        expect(CompanyConfig::amountToBase100(0.0))->toBe(0);
        expect(CompanyConfig::base100ToAmount(0))->toBe(0.0);
    });

    it('converts large amounts correctly', function () {
        $base100 = CompanyConfig::amountToBase100(10000.00);
        expect($base100)->toBe(1000000);

        $amount = CompanyConfig::base100ToAmount(1000000);
        expect($amount)->toBe(10000.0);
    });

    it('has correct table name', function () {
        $model = new CompanyConfig;

        expect($model->getTable())->toBe('company_config');
    });

    it('has correct fillable attributes', function () {
        $model    = new CompanyConfig;
        $fillable = $model->getFillable();

        expect($fillable)->toContain('is_oss');
        expect($fillable)->toContain('is_roi');
        expect($fillable)->toContain('eu_sales_threshold');
        expect($fillable)->toContain('current_eu_sales_amount');
        expect($fillable)->toContain('fiscal_year');
        expect($fillable)->toContain('currency');
    });

    it('has correct casts', function () {
        $model = new CompanyConfig;
        $casts = $model->casts();

        expect($casts['is_oss'])->toBe('boolean');
        expect($casts['is_roi'])->toBe('boolean');
        expect($casts['auto_apply_destination'])->toBe('boolean');
        expect($casts['notification_sent'])->toBe('boolean');
        expect($casts['threshold_exceeded'])->toBe('boolean');
        expect($casts['eu_sales_threshold'])->toBe('integer');
        expect($casts['current_eu_sales_amount'])->toBe('integer');
    });

    it('handles eu_sales_threshold accessor with null value', function () {
        $model = new CompanyConfig;

        // Use reflection to call the accessor directly
        $result = $model->getEuSalesThresholdAttribute(null);

        expect($result)->toBe(0.0);
    });

    it('handles current_eu_sales_amount accessor with null value', function () {
        $model = new CompanyConfig;

        $result = $model->getCurrentEuSalesAmountAttribute(null);

        expect($result)->toBe(0.0);
    });

    it('handles eu_sales_threshold mutator', function () {
        $model = new CompanyConfig;

        $model->setEuSalesThresholdAttribute(100.50);

        expect($model->getAttributes()['eu_sales_threshold'])->toBe(10050);
    });

    it('handles current_eu_sales_amount mutator', function () {
        $model = new CompanyConfig;

        $model->setCurrentEuSalesAmountAttribute(250.75);

        expect($model->getAttributes()['current_eu_sales_amount'])->toBe(25075);
    });

    it('handles null value in mutators', function () {
        $model = new CompanyConfig;

        $model->setEuSalesThresholdAttribute(null);
        expect($model->getAttributes()['eu_sales_threshold'])->toBe(0);

        $model->setCurrentEuSalesAmountAttribute(null);
        expect($model->getAttributes()['current_eu_sales_amount'])->toBe(0);
    });
});
