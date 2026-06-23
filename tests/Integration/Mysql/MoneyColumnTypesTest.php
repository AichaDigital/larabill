<?php

declare(strict_types=1);

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

/**
 * AID-240 — "nada decimal en BD" contract for the parallel money/rate systems
 * that AID-237 (Base100Int → FixedDecimal) left untouched.
 *
 * Every monetary/rate column that the package owns must be an integer type
 * (base-100 minor units), never `decimal`. SQLite collapses both to NUMERIC,
 * so this invariant can only be proven against real MySQL.
 */
describe('AID-240 — no decimal columns in package money/rate fields (MySQL)', function () {

    it('stores commissions.rate as an integer, not decimal', function () {
        $this->bootstrap();

        expect($this->getMysqlColumnType('commissions', 'rate'))->toBe('int');
    });

    it('stores eu_sales_thresholds amounts as integers, not decimals', function () {
        $this->bootstrap();

        expect($this->getMysqlColumnType('eu_sales_thresholds', 'total_amount'))->toBe('int');
        expect($this->getMysqlColumnType('eu_sales_thresholds', 'threshold_amount'))->toBe('int');
    });

});
