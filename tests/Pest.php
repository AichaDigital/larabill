<?php

use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
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
| Integration/Mysql is bound to its own TestCase first (more specific path),
| so its tests opt out of RefreshDatabase and SQLite — they manage their own
| MySQL bootstrap, see MysqlIntegrationTestCase.
|
*/

uses(MysqlIntegrationTestCase::class)
    ->in('Integration/Mysql');

// NOTE: 'Integration' is NOT used as a recursive path here because Pest rejects
// rebinding a folder once any subpath has been bound. Add explicit paths for
// any non-Mysql integration test that should use the SQLite TestCase.
uses(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Integration/VatVerificationIntegrationTest.php');

// Alias an expectation name used in some tests (typo): toBeGreaterThanOrEqualTo
expect()->extend('toBeGreaterThanOrEqualTo', function ($expected) {
    return $this->toBeGreaterThanOrEqual($expected);
});

// Alias for toBeLessThanOrEqualTo used in tests
expect()->extend('toBeLessThanOrEqualTo', function ($expected) {
    return $this->toBeLessThanOrEqual($expected);
});
