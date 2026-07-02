<?php

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Tests\Integration\InstallMysql\InstallCommandMysqlTestCase;
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

// Production install path (larabill:install publishes stubs in $migrationOrder
// then migrate). Own dir + TestCase because it forces environment=production and
// redirects database_path; see InstallCommandMysqlTestCase (AID-287).
uses(InstallCommandMysqlTestCase::class)
    ->in('Integration/InstallMysql');

// Concurrency (fork) tests also need a real, committed MySQL connection — forked
// children read parent-committed rows on their own connections, which SQLite
// :memory: + RefreshDatabase cannot provide. They self-gate on RUN_CONCURRENCY_IT.
uses(MysqlIntegrationTestCase::class)
    ->in('Concurrency');

// NOTE: 'Integration' is NOT used as a recursive path here because Pest rejects
// rebinding a folder once any subpath has been bound. Add explicit paths for
// any non-Mysql integration test that should use the SQLite TestCase.
uses(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

// Alias an expectation name used in some tests (typo): toBeGreaterThanOrEqualTo
expect()->extend('toBeGreaterThanOrEqualTo', function ($expected) {
    return $this->toBeGreaterThanOrEqual($expected);
});

// Alias for toBeLessThanOrEqualTo used in tests
expect()->extend('toBeLessThanOrEqualTo', function ($expected) {
    return $this->toBeLessThanOrEqual($expected);
});

/*
|--------------------------------------------------------------------------
| Money helper (FixedDecimal migration)
|--------------------------------------------------------------------------
|
| `cents(1234)` builds a scale-2 FixedDecimal of 1234 unscaled cents (= €12.34).
| Terse sugar for factory overrides and assertions now that monetary attributes
| are FixedDecimal value objects instead of plain base-100 integers.
|
*/
function cents(int $unscaled): FixedDecimal
{
    return FixedDecimal::ofUnscaled($unscaled, 2);
}

/**
 * `pct(21.50)` builds a scale-2 FixedDecimal of the percentage 21.50 (stored as
 * 2150 unscaled base-100). Readable sugar for VAT-rate factory overrides and
 * assertions now that VatCategory/CountryVatRate rates are FixedDecimal:2.
 */
function pct(int|float $percentage): FixedDecimal
{
    return FixedDecimal::ofFloat((float) $percentage, 2);
}

/**
 * Wrap the integer base-100 money keys of an attribute array into FixedDecimal,
 * so test bodies can keep passing readable integer cents to factories/models.
 *
 * @param  array<string, mixed>  $attrs
 * @param  array<int, string>  $keys
 * @return array<string, mixed>
 */
function fdMoney(array $attrs, array $keys): array
{
    foreach ($keys as $key) {
        if (isset($attrs[$key]) && is_int($attrs[$key])) {
            $attrs[$key] = cents($attrs[$key]);
        }
    }

    return $attrs;
}
