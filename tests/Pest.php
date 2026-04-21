<?php

use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Test Configuration for Larabill Package
|--------------------------------------------------------------------------
|
| Configure Pest to use our custom TestCase for all tests in this package.
| Use RefreshDatabase for tests that need database operations.
|
*/

uses(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Integration', 'Unit');

// Alias an expectation name used in some tests (typo): toBeGreaterThanOrEqualTo
expect()->extend('toBeGreaterThanOrEqualTo', function ($expected) {
    return $this->toBeGreaterThanOrEqual($expected);
});

// Alias for toBeLessThanOrEqualTo used in tests
expect()->extend('toBeLessThanOrEqualTo', function ($expected) {
    return $this->toBeLessThanOrEqual($expected);
});
