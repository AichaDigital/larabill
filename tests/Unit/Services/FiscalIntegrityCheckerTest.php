<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\FiscalIntegrityException;
use AichaDigital\Larabill\Models\{CompanyFiscalConfig, UserTaxProfile};
use AichaDigital\Larabill\Services\FiscalIntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->checker = new FiscalIntegrityChecker;
    Notification::fake();
});

/**
 * Helper to create multiple active company configs bypassing model events.
 * This simulates direct DB manipulation or data corruption scenarios.
 */
function createActiveCompanyConfigsWithoutEvents(int $count): \Illuminate\Support\Collection
{
    return CompanyFiscalConfig::withoutEvents(function () use ($count) {
        return collect(range(1, $count))->map(function () {
            return CompanyFiscalConfig::factory()->active()->create();
        });
    });
}

/**
 * Helper to create multiple active user profiles bypassing model events.
 */
function createActiveUserProfilesWithoutEvents(string $userId, int $count): \Illuminate\Support\Collection
{
    return UserTaxProfile::withoutEvents(function () use ($userId, $count) {
        return collect(range(1, $count))->map(function () use ($userId) {
            return UserTaxProfile::factory()->active()->forUser($userId)->create();
        });
    });
}

// =============================================================================
// Company Fiscal Config Integrity Tests
// =============================================================================

it('passes company integrity when no configs exist', function () {
    $exception = $this->checker->checkCompanyIntegrity();

    expect($exception)->toBeNull();
});

it('passes company integrity with single active config', function () {
    CompanyFiscalConfig::factory()->active()->create();

    $exception = $this->checker->checkCompanyIntegrity();

    expect($exception)->toBeNull();
});

it('passes company integrity with multiple historical configs', function () {
    CompanyFiscalConfig::withoutEvents(function () {
        CompanyFiscalConfig::factory()->historical()->create();
        CompanyFiscalConfig::factory()->historical()->create();
    });
    CompanyFiscalConfig::factory()->active()->create();

    $exception = $this->checker->checkCompanyIntegrity();

    expect($exception)->toBeNull();
});

it('fails company integrity with multiple active configs', function () {
    // Bypass model events to simulate data corruption/direct DB manipulation
    createActiveCompanyConfigsWithoutEvents(2);

    $exception = $this->checker->checkCompanyIntegrity();

    expect($exception)
        ->toBeInstanceOf(FiscalIntegrityException::class)
        ->and($exception->isGlobal())->toBeTrue()
        ->and($exception->getSeverity())->toBe(FiscalIntegrityException::SEVERITY_GLOBAL)
        ->and($exception->getDuplicateConfigs())->toHaveCount(2);
});

it('treats config with valid_until as not current active', function () {
    CompanyFiscalConfig::withoutEvents(function () {
        // One active without valid_until (current)
        CompanyFiscalConfig::factory()->active()->create();

        // One active with valid_until set (future expiration - still counts as limited)
        CompanyFiscalConfig::factory()->create([
            'is_active'   => true,
            'valid_until' => now()->addYear(),
        ]);
    });

    $exception = $this->checker->checkCompanyIntegrity();

    // Should pass because only one has null valid_until
    expect($exception)->toBeNull();
});

// =============================================================================
// User Tax Profile Integrity Tests
// =============================================================================

it('passes user integrity when no profiles exist', function () {
    $exception = $this->checker->checkUserIntegrity('user-123');

    expect($exception)->toBeNull();
});

it('passes user integrity with single active profile', function () {
    UserTaxProfile::factory()->active()->forUser('user-123')->create();

    $exception = $this->checker->checkUserIntegrity('user-123');

    expect($exception)->toBeNull();
});

it('passes user integrity with multiple profiles for different users', function () {
    UserTaxProfile::factory()->active()->forUser('user-1')->create();
    UserTaxProfile::factory()->active()->forUser('user-2')->create();

    expect($this->checker->checkUserIntegrity('user-1'))->toBeNull()
        ->and($this->checker->checkUserIntegrity('user-2'))->toBeNull();
});

it('passes user integrity with historical profiles', function () {
    UserTaxProfile::withoutEvents(function () {
        UserTaxProfile::factory()->historical()->forUser('user-123')->create();
        UserTaxProfile::factory()->historical()->forUser('user-123')->create();
    });
    UserTaxProfile::factory()->active()->forUser('user-123')->create();

    $exception = $this->checker->checkUserIntegrity('user-123');

    expect($exception)->toBeNull();
});

it('fails user integrity with multiple active profiles for same user', function () {
    // Bypass model events to simulate data corruption
    createActiveUserProfilesWithoutEvents('user-123', 2);

    $exception = $this->checker->checkUserIntegrity('user-123');

    expect($exception)
        ->toBeInstanceOf(FiscalIntegrityException::class)
        ->and($exception->isAtomic())->toBeTrue()
        ->and($exception->getSeverity())->toBe(FiscalIntegrityException::SEVERITY_ATOMIC)
        ->and($exception->getAffectedUserId())->toBe('user-123')
        ->and($exception->getDuplicateConfigs())->toHaveCount(2);
});

// =============================================================================
// Check All Users Integrity Tests
// =============================================================================

it('returns empty array when no user integrity issues', function () {
    UserTaxProfile::factory()->active()->forUser('user-1')->create();
    UserTaxProfile::factory()->active()->forUser('user-2')->create();

    $issues = $this->checker->checkAllUsersIntegrity();

    expect($issues)->toBeEmpty();
});

it('returns all users with duplicate profiles', function () {
    // User 1 has duplicate (bypass events)
    createActiveUserProfilesWithoutEvents('user-1', 2);

    // User 2 is OK
    UserTaxProfile::factory()->active()->forUser('user-2')->create();

    // User 3 has duplicate (bypass events)
    createActiveUserProfilesWithoutEvents('user-3', 3);

    $issues = $this->checker->checkAllUsersIntegrity();

    expect($issues)
        ->toHaveCount(2)
        ->toHaveKey('user-1')
        ->toHaveKey('user-3')
        ->not->toHaveKey('user-2');
});

// =============================================================================
// Can Invoice Tests
// =============================================================================

it('allows invoicing when no company config exists', function () {
    expect($this->checker->canInvoice())->toBeTrue();
});

it('allows invoicing with single active company config', function () {
    CompanyFiscalConfig::factory()->active()->create();

    expect($this->checker->canInvoice())->toBeTrue();
});

it('blocks invoicing with multiple active company configs', function () {
    // Bypass model events
    createActiveCompanyConfigsWithoutEvents(2);

    expect($this->checker->canInvoice())->toBeFalse();
});

it('allows invoicing user when both company and user are valid', function () {
    CompanyFiscalConfig::factory()->active()->create();
    UserTaxProfile::factory()->active()->forUser('user-123')->create();

    expect($this->checker->canInvoiceUser('user-123'))->toBeTrue();
});

it('blocks invoicing user when company has integrity issue', function () {
    // Bypass model events for company
    createActiveCompanyConfigsWithoutEvents(2);
    UserTaxProfile::factory()->active()->forUser('user-123')->create();

    expect($this->checker->canInvoiceUser('user-123'))->toBeFalse();
});

it('blocks invoicing user when user has integrity issue', function () {
    CompanyFiscalConfig::factory()->active()->create();
    // Bypass model events for user
    createActiveUserProfilesWithoutEvents('user-123', 2);

    expect($this->checker->canInvoiceUser('user-123'))->toBeFalse();
});

// =============================================================================
// Assert Can Invoice Tests (Exception Throwing)
// =============================================================================

it('does not throw when invoicing allowed', function () {
    CompanyFiscalConfig::factory()->active()->create();

    $this->checker->assertCanInvoice();

    expect(true)->toBeTrue(); // If we get here, no exception was thrown
});

it('throws FiscalIntegrityException when company has duplicates', function () {
    // Bypass model events
    createActiveCompanyConfigsWithoutEvents(2);

    $this->checker->assertCanInvoice();
})->throws(FiscalIntegrityException::class);

it('throws FiscalIntegrityException when user has duplicates', function () {
    CompanyFiscalConfig::factory()->active()->create();
    // Bypass model events for user
    createActiveUserProfilesWithoutEvents('user-123', 2);

    $this->checker->assertCanInvoiceUser('user-123');
})->throws(FiscalIntegrityException::class);

it('sends notification when integrity violation occurs', function () {
    // Bypass model events
    createActiveCompanyConfigsWithoutEvents(2);

    config(['larabill.admin.email' => 'admin@test.com']);

    try {
        $this->checker->assertCanInvoice();
    } catch (FiscalIntegrityException $e) {
        // Expected
    }

    // Verify notification was sent (using assertCount since it's sent to anonymous class)
    Notification::assertCount(1);
});

// =============================================================================
// Check All Tests
// =============================================================================

it('returns all issues in checkAll', function () {
    // Company issue (bypass events)
    createActiveCompanyConfigsWithoutEvents(2);

    // User issues (bypass events)
    createActiveUserProfilesWithoutEvents('user-1', 2);

    $result = $this->checker->checkAll();

    expect($result['company'])->toBeInstanceOf(FiscalIntegrityException::class)
        ->and($result['users'])->toHaveCount(1)
        ->and($result['users'])->toHaveKey('user-1');
});

it('returns null company and empty users when all valid', function () {
    CompanyFiscalConfig::factory()->active()->create();
    UserTaxProfile::factory()->active()->forUser('user-1')->create();

    $result = $this->checker->checkAll();

    expect($result['company'])->toBeNull()
        ->and($result['users'])->toBeEmpty();
});

// =============================================================================
// Get Status Tests
// =============================================================================

it('returns ok status when no issues', function () {
    CompanyFiscalConfig::factory()->active()->create();
    UserTaxProfile::factory()->active()->forUser('user-1')->create();

    $status = $this->checker->getStatus();

    expect($status['ok'])->toBeTrue()
        ->and($status['company_ok'])->toBeTrue()
        ->and($status['users_ok'])->toBeTrue()
        ->and($status['issues_count'])->toBe(0)
        ->and($status['affected_users'])->toBeEmpty();
});

it('returns not ok status when company has issues', function () {
    // Bypass model events
    createActiveCompanyConfigsWithoutEvents(2);

    $status = $this->checker->getStatus();

    expect($status['ok'])->toBeFalse()
        ->and($status['company_ok'])->toBeFalse()
        ->and($status['users_ok'])->toBeTrue()
        ->and($status['issues_count'])->toBe(1);
});

it('returns not ok status when users have issues', function () {
    CompanyFiscalConfig::factory()->active()->create();
    // Bypass model events for users
    createActiveUserProfilesWithoutEvents('user-1', 2);
    createActiveUserProfilesWithoutEvents('user-2', 2);

    $status = $this->checker->getStatus();

    expect($status['ok'])->toBeFalse()
        ->and($status['company_ok'])->toBeTrue()
        ->and($status['users_ok'])->toBeFalse()
        ->and($status['issues_count'])->toBe(2)
        ->and($status['affected_users'])->toContain('user-1', 'user-2');
});

// =============================================================================
// FiscalIntegrityException Tests
// =============================================================================

it('creates global exception for company duplicates', function () {
    // Bypass events and create directly
    $configs   = createActiveCompanyConfigsWithoutEvents(2);
    $exception = FiscalIntegrityException::duplicateCompanyConfig($configs);

    expect($exception->isGlobal())->toBeTrue()
        ->and($exception->isAtomic())->toBeFalse()
        ->and($exception->getAffectedUserId())->toBeNull()
        ->and($exception->getDuplicateConfigs())->toHaveCount(2);
});

it('creates atomic exception for user duplicates', function () {
    // Bypass events and create directly
    $profiles  = createActiveUserProfilesWithoutEvents('user-456', 3);
    $exception = FiscalIntegrityException::duplicateUserTaxProfile('user-456', $profiles);

    expect($exception->isAtomic())->toBeTrue()
        ->and($exception->isGlobal())->toBeFalse()
        ->and($exception->getAffectedUserId())->toBe('user-456')
        ->and($exception->getDuplicateConfigs())->toHaveCount(3);
});

it('provides context for logging', function () {
    // Bypass events and create directly
    $configs   = createActiveCompanyConfigsWithoutEvents(2);
    $exception = FiscalIntegrityException::duplicateCompanyConfig($configs);

    $context = $exception->context();

    expect($context)
        ->toHaveKey('severity')
        ->toHaveKey('affected_user_id')
        ->toHaveKey('duplicate_config_ids')
        ->toHaveKey('duplicate_count')
        ->and($context['duplicate_count'])->toBe(2);
});

// =============================================================================
// Model Protection Tests (verifies boot() hooks work correctly)
// =============================================================================

it('model automatically closes previous active config on create', function () {
    // First config - created normally
    $first = CompanyFiscalConfig::factory()->active()->create();
    expect($first->is_active)->toBeTrue();
    expect($first->valid_until)->toBeNull();

    // Second config - should auto-close the first
    $second = CompanyFiscalConfig::factory()->active()->create();

    // Refresh from DB
    $first->refresh();

    // First should be closed now
    expect($first->is_active)->toBeFalse();
    expect($first->valid_until)->not->toBeNull();

    // Second should be active
    expect($second->is_active)->toBeTrue();
    expect($second->valid_until)->toBeNull();

    // Integrity check should pass
    expect($this->checker->checkCompanyIntegrity())->toBeNull();
});

it('model automatically closes previous user profile on create', function () {
    // First profile - created normally
    $first = UserTaxProfile::factory()->active()->forUser('user-test')->create();
    expect($first->is_active)->toBeTrue();
    expect($first->valid_until)->toBeNull();

    // Second profile - should auto-close the first
    $second = UserTaxProfile::factory()->active()->forUser('user-test')->create();

    // Refresh from DB
    $first->refresh();

    // First should be closed now
    expect($first->is_active)->toBeFalse();
    expect($first->valid_until)->not->toBeNull();

    // Second should be active
    expect($second->is_active)->toBeTrue();
    expect($second->valid_until)->toBeNull();

    // Integrity check should pass
    expect($this->checker->checkUserIntegrity('user-test'))->toBeNull();
});
