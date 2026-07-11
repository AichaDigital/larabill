<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Exceptions\FiscalIntegrityException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Notifications\FiscalIntegrityAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * FiscalIntegrityChecker Service
 *
 * Validates fiscal configuration integrity and handles violations.
 *
 * Checks for:
 * - Multiple active CompanyFiscalConfig (CRITICAL GLOBAL)
 * - Multiple active UserTaxProfile for same user (CRITICAL ATOMIC)
 *
 * @see ADR-001 for architectural decisions
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class FiscalIntegrityChecker
{
    /**
     * Check all fiscal integrity and return any issues found.
     *
     * @return array{company: ?FiscalIntegrityException, users: array<string, FiscalIntegrityException>}
     */
    public function checkAll(): array
    {
        return [
            'company' => $this->checkCompanyIntegrity(),
            'users'   => $this->checkAllUsersIntegrity(),
        ];
    }

    /**
     * Check if system can invoice (no global integrity issues).
     */
    public function canInvoice(): bool
    {
        return $this->checkCompanyIntegrity() === null;
    }

    /**
     * Check if system can invoice a specific user.
     */
    public function canInvoiceUser(string|int $userId): bool
    {
        // First check global integrity
        if (! $this->canInvoice()) {
            return false;
        }

        // Then check user-specific integrity
        return $this->checkUserIntegrity($userId) === null;
    }

    /**
     * Assert invoicing is possible, throwing exception if not.
     *
     * @throws FiscalIntegrityException
     */
    public function assertCanInvoice(): void
    {
        $exception = $this->checkCompanyIntegrity();

        if ($exception !== null) {
            $this->handleIntegrityViolation($exception);
            throw $exception;
        }
    }

    /**
     * Assert invoicing is possible for a specific user.
     *
     * @throws FiscalIntegrityException
     */
    public function assertCanInvoiceUser(string|int $userId): void
    {
        // First check global
        $this->assertCanInvoice();

        // Then check user-specific
        $exception = $this->checkUserIntegrity($userId);

        if ($exception !== null) {
            $this->handleIntegrityViolation($exception);
            throw $exception;
        }
    }

    /**
     * Check CompanyFiscalConfig integrity.
     *
     * Returns exception if multiple active configs found, null if OK.
     */
    public function checkCompanyIntegrity(): ?FiscalIntegrityException
    {
        $activeConfigs = CompanyFiscalConfig::query()
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->get();

        if ($activeConfigs->count() > 1) {
            return FiscalIntegrityException::duplicateCompanyConfig($activeConfigs);
        }

        return null;
    }

    /**
     * Check UserTaxProfile integrity for a specific user.
     *
     * Returns exception if multiple active profiles found, null if OK.
     */
    public function checkUserIntegrity(string|int $userId): ?FiscalIntegrityException
    {
        $activeProfiles = UserTaxProfile::query()
            ->where('owner_user_id', $userId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->get();

        if ($activeProfiles->count() > 1) {
            return FiscalIntegrityException::duplicateUserTaxProfile($userId, $activeProfiles);
        }

        return null;
    }

    /**
     * Check all users for integrity issues.
     *
     * @return array<string, FiscalIntegrityException> User ID => Exception
     */
    public function checkAllUsersIntegrity(): array
    {
        $issues = [];

        // Find users with multiple active profiles using raw query for efficiency
        $duplicates = UserTaxProfile::query()
            ->select('owner_user_id')
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->groupBy('owner_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('owner_user_id');

        foreach ($duplicates as $userId) {
            $exception = $this->checkUserIntegrity($userId);
            if ($exception !== null) {
                $issues[$userId] = $exception;
            }
        }

        return $issues;
    }

    /**
     * Get summary of current integrity status.
     *
     * @return array{ok: bool, company_ok: bool, users_ok: bool, issues_count: int, affected_users: list<string>}
     */
    public function getStatus(): array
    {
        $companyIssue = $this->checkCompanyIntegrity();
        $userIssues   = $this->checkAllUsersIntegrity();

        return [
            'ok'             => $companyIssue === null && empty($userIssues),
            'company_ok'     => $companyIssue === null,
            'users_ok'       => empty($userIssues),
            'issues_count'   => ($companyIssue !== null ? 1 : 0) + count($userIssues),
            'affected_users' => array_keys($userIssues),
        ];
    }

    /**
     * Handle an integrity violation: log and notify.
     */
    public function handleIntegrityViolation(FiscalIntegrityException $exception): void
    {
        // Log critical error
        Log::critical('Fiscal integrity violation detected', $exception->context());

        // Send notification to admins
        $this->notifyAdmins($exception);
    }

    /**
     * Send notification to admin users.
     */
    protected function notifyAdmins(FiscalIntegrityException $exception): void
    {
        $adminNotifiable = $this->getAdminNotifiable();

        if ($adminNotifiable !== null) {
            Notification::send($adminNotifiable, new FiscalIntegrityAlert($exception));
        }
    }

    /**
     * Get the notifiable entity for admin notifications.
     *
     * Uses config to determine admin email(s).
     */
    protected function getAdminNotifiable(): ?object
    {
        $adminEmail = config('larabill.admin.email');

        if (empty($adminEmail)) {
            Log::warning('No admin email configured for fiscal integrity alerts. Set LARABILL_ADMIN_EMAIL in .env');

            return null;
        }

        // Support multiple emails separated by comma
        $emails = is_array($adminEmail)
            ? $adminEmail
            : array_map('trim', explode(',', $adminEmail));

        return new class($emails)
        {
            /**
             * @param  array<int, string>  $emails
             */
            public function __construct(protected array $emails) {}

            /**
             * @return array<int, string>
             */
            public function routeNotificationForMail(): array
            {
                return $this->emails;
            }

            public function getKey(): string
            {
                return implode(',', $this->emails);
            }
        };
    }
}
