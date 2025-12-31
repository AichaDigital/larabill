<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\FiscalChangeDetector;
use AichaDigital\Larabill\Tests\Models\User;

uses()->group('unit', 'services', 'fiscal-change-detector');

beforeEach(function () {
    $this->detector = new FiscalChangeDetector;

    // Create initial company config
    $this->companyConfig = CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'Original Company S.L.',
        'tax_id'        => 'ESB12345678',
        'country_code'  => 'ES',
    ]);

    // Create user
    $this->user = User::factory()->create();

    // Create initial tax profile
    $this->taxProfile = UserTaxProfile::factory()->create([
        'user_id'      => $this->user->id,
        'fiscal_name'  => 'Original Customer',
        'tax_id'       => '12345678A',
        'country_code' => 'ES',
        'is_active'    => true,
        'valid_from'   => now()->subYear(),
        'valid_until'  => null,
    ]);
});

// ==========================================
// No Changes Tests
// ==========================================

it('detects no changes when config and profile are the same', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_changes'])->toBeFalse();
    expect($changes['has_critical_changes'])->toBeFalse();
    expect($changes['company']['changed'])->toBeFalse();
    expect($changes['user']['changed'])->toBeFalse();
});

it('can convert safely when no changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    expect($this->detector->canConvertSafely($proforma))->toBeTrue();
});

// ==========================================
// Company Config Change Tests
// ==========================================

it('detects company config changed to new record', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new active config (closes old one)
    $newConfig = CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'New Company S.L.',
        'tax_id'        => 'ESB12345678', // Same tax_id
        'country_code'  => 'ES',
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_changes'])->toBeTrue();
    expect($changes['company']['changed'])->toBeTrue();
    expect($changes['company']['old_id'])->toBe($this->companyConfig->id);
    expect($changes['company']['new_id'])->toBe($newConfig->id);
});

it('detects critical change when company tax_id changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new config with different tax_id (CRITICAL)
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'New Company S.L.',
        'tax_id'        => 'ESB99999999', // Different tax_id = CRITICAL
        'country_code'  => 'ES',
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_critical_changes'])->toBeTrue();
    expect($changes['company']['critical'])->toBeTrue();
    expect($changes['company']['changes'])->toHaveKey('tax_id');
    expect($changes['company']['changes']['tax_id']['level'])->toBe('critical');
});

it('detects critical change when company country_code changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new config with different country (CRITICAL)
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'New Company S.L.',
        'tax_id'        => 'ESB12345678',
        'country_code'  => 'PT', // Different country = CRITICAL
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_critical_changes'])->toBeTrue();
    expect($changes['company']['changes'])->toHaveKey('country_code');
    expect($changes['company']['changes']['country_code']['level'])->toBe('critical');
});

it('detects warning change when company business_name changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new config with different name (WARNING)
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'Renamed Company S.L.', // Different name = WARNING
        'tax_id'        => 'ESB12345678',
        'country_code'  => 'ES',
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_changes'])->toBeTrue();
    expect($changes['has_critical_changes'])->toBeFalse(); // Not critical
    expect($changes['company']['changed'])->toBeTrue();
    expect($changes['company']['critical'])->toBeFalse();
    expect($changes['company']['changes'])->toHaveKey('business_name');
    expect($changes['company']['changes']['business_name']['level'])->toBe('warning');
});

// ==========================================
// User Tax Profile Change Tests
// ==========================================

it('detects user tax profile changed to new record', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new profile for user (closes old one)
    $newProfile = UserTaxProfile::factory()->create([
        'user_id'      => $this->user->id,
        'fiscal_name'  => 'New Customer Name',
        'tax_id'       => '12345678A', // Same tax_id
        'country_code' => 'ES',
        'is_active'    => true,
        'valid_from'   => now(),
        'valid_until'  => null,
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_changes'])->toBeTrue();
    expect($changes['user']['changed'])->toBeTrue();
    expect($changes['user']['old_id'])->toBe($this->taxProfile->id);
    expect($changes['user']['new_id'])->toBe($newProfile->id);
});

it('detects critical change when user tax_id changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new profile with different tax_id (CRITICAL)
    UserTaxProfile::factory()->create([
        'user_id'      => $this->user->id,
        'fiscal_name'  => 'Customer',
        'tax_id'       => '99999999Z', // Different tax_id = CRITICAL
        'country_code' => 'ES',
        'is_active'    => true,
        'valid_from'   => now(),
        'valid_until'  => null,
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_critical_changes'])->toBeTrue();
    expect($changes['user']['critical'])->toBeTrue();
    expect($changes['user']['changes'])->toHaveKey('tax_id');
});

it('detects critical change when user becomes EU VAT registered', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new profile with EU VAT registration (CRITICAL - affects reverse charge)
    UserTaxProfile::factory()->create([
        'user_id'              => $this->user->id,
        'fiscal_name'          => 'Customer',
        'tax_id'               => '12345678A',
        'country_code'         => 'ES',
        'is_eu_vat_registered' => true, // Now registered = CRITICAL
        'is_active'            => true,
        'valid_from'           => now(),
        'valid_until'          => null,
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_critical_changes'])->toBeTrue();
    expect($changes['user']['changes'])->toHaveKey('is_eu_vat_registered');
});

// ==========================================
// Both Changes Tests
// ==========================================

it('detects both company and user changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create new company config
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'New Company S.L.',
        'tax_id'        => 'ESB12345678',
        'country_code'  => 'ES',
    ]);

    // Create new user profile
    UserTaxProfile::factory()->create([
        'user_id'      => $this->user->id,
        'fiscal_name'  => 'New Customer Name',
        'tax_id'       => '12345678A',
        'country_code' => 'ES',
        'is_active'    => true,
        'valid_from'   => now(),
        'valid_until'  => null,
    ]);

    $changes = $this->detector->detectChanges($proforma);

    expect($changes['has_changes'])->toBeTrue();
    expect($changes['company']['changed'])->toBeTrue();
    expect($changes['user']['changed'])->toBeTrue();
});

// ==========================================
// Summary Tests
// ==========================================

it('returns empty summary when no changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    $summary = $this->detector->getChangeSummary($proforma);

    expect($summary)->toBeEmpty();
});

it('returns summary with critical label for critical changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create config with critical change
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'New Company',
        'tax_id'        => 'ESB99999999', // Critical
        'country_code'  => 'ES',
    ]);

    $summary = $this->detector->getChangeSummary($proforma);

    expect($summary)->not->toBeEmpty();
    expect($summary[0])->toContain('[CRITICAL]');
    expect($summary[0])->toContain('tax_id');
});

it('returns summary with warning label for non-critical changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create config with only warning change
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'Renamed Company', // Warning
        'tax_id'        => 'ESB12345678',
        'country_code'  => 'ES',
    ]);

    $summary = $this->detector->getChangeSummary($proforma);

    expect($summary)->not->toBeEmpty();
    expect($summary[0])->toContain('[WARNING]');
    expect($summary[0])->toContain('business_name');
});

// ==========================================
// canConvertSafely Tests
// ==========================================

it('cannot convert safely when critical changes exist', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create config with critical change
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'New Company',
        'tax_id'        => 'ESB99999999', // Critical change
        'country_code'  => 'ES',
    ]);

    expect($this->detector->canConvertSafely($proforma))->toBeFalse();
});

it('can convert safely with only warning changes', function () {
    $proforma = Invoice::factory()->create([
        'serie'                    => InvoiceSerieType::PROFORMA,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->user->id,
        'billable_user_id'         => $this->user->id,
        'company_fiscal_config_id' => $this->companyConfig->id,
        'user_tax_profile_id'      => $this->taxProfile->id,
    ]);

    // Create config with only warning change
    CompanyFiscalConfig::factory()->active()->create([
        'business_name' => 'Renamed Company', // Only warning
        'tax_id'        => 'ESB12345678',
        'country_code'  => 'ES',
    ]);

    expect($this->detector->canConvertSafely($proforma))->toBeTrue();
});
