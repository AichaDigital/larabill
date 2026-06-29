<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UserTaxProfile;
use Exception;
use Illuminate\Support\Collection;

/**
 * FiscalIntegrityException
 *
 * Thrown when fiscal configuration integrity is compromised.
 * This is a CRITICAL exception that blocks invoicing operations.
 *
 * Two severity levels:
 * - GLOBAL: CompanyFiscalConfig has duplicates → blocks ALL invoicing
 * - ATOMIC: UserTaxProfile has duplicates → blocks invoicing for THAT user
 *
 * @see ADR-001 for architectural decisions
 */
class FiscalIntegrityException extends Exception
{
    public const SEVERITY_GLOBAL = 'global';

    public const SEVERITY_ATOMIC = 'atomic';

    protected string $severity;

    protected ?string $affectedUserId;

    /** @var Collection<int, CompanyFiscalConfig>|Collection<int, UserTaxProfile>|Collection<int|string, mixed> */
    protected Collection $duplicateConfigs;

    /**
     * @param  Collection<int, CompanyFiscalConfig>|Collection<int, UserTaxProfile>|Collection<int|string, mixed>|null  $duplicateConfigs
     */
    public function __construct(
        string $message,
        string $severity = self::SEVERITY_GLOBAL,
        ?string $affectedUserId = null,
        ?Collection $duplicateConfigs = null
    ) {
        parent::__construct($message);

        $this->severity         = $severity;
        $this->affectedUserId   = $affectedUserId;
        $this->duplicateConfigs = $duplicateConfigs ?? collect();
    }

    /**
     * Create exception for duplicate CompanyFiscalConfig.
     *
     * @param  Collection<int, CompanyFiscalConfig>  $configs
     */
    public static function duplicateCompanyConfig(Collection $configs): self
    {
        $ids = $configs->pluck('id')->implode(', ');

        return new self(
            message: __('larabill::exceptions.fiscal_integrity.company_duplicate', ['ids' => $ids]),
            severity: self::SEVERITY_GLOBAL,
            affectedUserId: null,
            duplicateConfigs: $configs
        );
    }

    /**
     * Create exception for duplicate UserTaxProfile.
     *
     * @param  Collection<int, UserTaxProfile>  $configs
     */
    public static function duplicateUserTaxProfile(string|int $userId, Collection $configs): self
    {
        $ids = $configs->pluck('id')->implode(', ');

        return new self(
            message: __('larabill::exceptions.fiscal_integrity.user_duplicate', [
                'user_id' => $userId,
                'ids'     => $ids,
            ]),
            severity: self::SEVERITY_ATOMIC,
            affectedUserId: (string) $userId,
            duplicateConfigs: $configs
        );
    }

    /**
     * Check if this is a global (company-level) integrity issue.
     */
    public function isGlobal(): bool
    {
        return $this->severity === self::SEVERITY_GLOBAL;
    }

    /**
     * Check if this is an atomic (user-level) integrity issue.
     */
    public function isAtomic(): bool
    {
        return $this->severity === self::SEVERITY_ATOMIC;
    }

    /**
     * Get the severity level.
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Get the affected user ID (only for atomic severity).
     */
    public function getAffectedUserId(): ?string
    {
        return $this->affectedUserId;
    }

    /**
     * Get the duplicate configurations that caused this exception.
     *
     * @return Collection<int, CompanyFiscalConfig>|Collection<int, UserTaxProfile>|Collection<int|string, mixed>
     */
    public function getDuplicateConfigs(): Collection
    {
        return $this->duplicateConfigs;
    }

    /**
     * Get context array for logging.
     *
     * @return array{severity: string, affected_user_id: string|null, duplicate_config_ids: array<int, mixed>, duplicate_count: int}
     */
    public function context(): array
    {
        return [
            'severity'             => $this->severity,
            'affected_user_id'     => $this->affectedUserId,
            'duplicate_config_ids' => $this->duplicateConfigs->pluck('id')->toArray(),
            'duplicate_count'      => $this->duplicateConfigs->count(),
        ];
    }
}
