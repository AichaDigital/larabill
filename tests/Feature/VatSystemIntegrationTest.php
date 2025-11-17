<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Feature;

/**
 * @deprecated v0.4.0 - Integration tests need full v0.4.0 rewrite
 * These tests use legacy API/tables (CountryVatRate, VatCategory, etc.) that no longer exist.
 */

use AichaDigital\Larabill\Models\{CountryVatRate, VatCategory};

// Skip all tests - legacy code
beforeEach(function () {
    $this->markTestSkipped('Legacy test - deprecated in v0.4.0. Requires full rewrite for v0.4.0 architecture.');
});

it('can perform complete destination VAT workflow', function () {
    // Test skipped - see beforeEach
});

it('can perform complete ROI verification workflow', function () {
    // Test skipped - see beforeEach
});

it('can perform complete EU sales threshold monitoring workflow', function () {
    // Test skipped - see beforeEach
});

it('can perform complete multi-company workflow', function () {
    // Test skipped - see beforeEach
});

it('can perform complete legal compliance workflow', function () {
    // Test skipped - see beforeEach
});
