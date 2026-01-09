<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;

/**
 * Fiscal Change Detector
 *
 * Detects changes in fiscal configuration between proforma creation
 * and invoice conversion time. Used to warn or block conversion
 * when significant fiscal changes have occurred.
 *
 * @see ADR-001 Gestión de proformas con cambio fiscal
 */
class FiscalChangeDetector
{
    /**
     * Critical fields that should block conversion if changed.
     * These are legally significant and would affect the invoice's validity.
     */
    public const CRITICAL_COMPANY_FIELDS = [
        'tax_id',
        'country_code',
    ];

    public const CRITICAL_USER_FIELDS = [
        'tax_id',
        'country_code',
        'is_eu_vat_registered',
    ];

    /**
     * Warning fields that should be reported but not block conversion.
     */
    public const WARNING_COMPANY_FIELDS = [
        'business_name',
        'address',
        'city',
        'state',
        'zip_code',
        'is_oss',
        'is_roi',
    ];

    public const WARNING_USER_FIELDS = [
        'fiscal_name',
        'address',
        'city',
        'state',
        'zip_code',
        'is_company',
        'is_exempt_vat',
    ];

    /**
     * Detect all fiscal changes for a proforma since its creation.
     *
     * @return array{
     *     has_changes: bool,
     *     has_critical_changes: bool,
     *     company: array{changed: bool, critical: bool, old_id: int|null, new_id: int|null, changes: array<string, array{old: mixed, new: mixed}>},
     *     user: array{changed: bool, critical: bool, old_id: int|null, new_id: int|null, changes: array<string, array{old: mixed, new: mixed}>}
     * }
     */
    public function detectChanges(Invoice $proforma): array
    {
        $companyChanges = $this->detectCompanyChanges($proforma);
        $userChanges    = $this->detectUserChanges($proforma);

        return [
            'has_changes'          => $companyChanges['changed']  || $userChanges['changed'],
            'has_critical_changes' => $companyChanges['critical'] || $userChanges['critical'],
            'company'              => $companyChanges,
            'user'                 => $userChanges,
        ];
    }

    /**
     * Detect changes in CompanyFiscalConfig since proforma creation.
     *
     * @return array{changed: bool, critical: bool, old_id: int|null, new_id: int|null, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function detectCompanyChanges(Invoice $proforma): array
    {
        $oldConfigId   = $proforma->company_fiscal_config_id;
        $currentConfig = CompanyFiscalConfig::getActive();
        $newConfigId   = $currentConfig?->id;

        // If same config, no changes
        if ($oldConfigId === $newConfigId) {
            return [
                'changed'  => false,
                'critical' => false,
                'old_id'   => $oldConfigId,
                'new_id'   => $newConfigId,
                'changes'  => [],
            ];
        }

        // Config changed - compare fields
        $oldConfig = $oldConfigId ? CompanyFiscalConfig::find($oldConfigId) : null;
        $changes   = $this->compareConfigs($oldConfig, $currentConfig, self::CRITICAL_COMPANY_FIELDS, self::WARNING_COMPANY_FIELDS);

        return [
            'changed'  => true,
            'critical' => $changes['has_critical'],
            'old_id'   => $oldConfigId,
            'new_id'   => $newConfigId,
            'changes'  => $changes['fields'],
        ];
    }

    /**
     * Detect changes in UserTaxProfile since proforma creation.
     *
     * @return array{changed: bool, critical: bool, old_id: int|null, new_id: int|null, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function detectUserChanges(Invoice $proforma): array
    {
        $oldProfileId   = $proforma->user_tax_profile_id;
        $currentProfile = $proforma->billable_user_id
            ? UserTaxProfile::getValidForOwnerAt($proforma->billable_user_id, now())
            : null;
        $newProfileId = $currentProfile?->id;

        // If same profile, no changes
        if ($oldProfileId === $newProfileId) {
            return [
                'changed'  => false,
                'critical' => false,
                'old_id'   => $oldProfileId,
                'new_id'   => $newProfileId,
                'changes'  => [],
            ];
        }

        // Profile changed - compare fields
        $oldProfile = $oldProfileId ? UserTaxProfile::find($oldProfileId) : null;
        $changes    = $this->compareConfigs($oldProfile, $currentProfile, self::CRITICAL_USER_FIELDS, self::WARNING_USER_FIELDS);

        return [
            'changed'  => true,
            'critical' => $changes['has_critical'],
            'old_id'   => $oldProfileId,
            'new_id'   => $newProfileId,
            'changes'  => $changes['fields'],
        ];
    }

    /**
     * Compare two config/profile objects and return field-level changes.
     *
     * @param  array<string>  $criticalFields
     * @param  array<string>  $warningFields
     * @return array{has_critical: bool, fields: array<string, array{old: mixed, new: mixed, level: string}>}
     */
    protected function compareConfigs(
        ?object $old,
        ?object $new,
        array $criticalFields,
        array $warningFields
    ): array {
        $changes     = [];
        $hasCritical = false;

        $allFields = array_unique(array_merge($criticalFields, $warningFields));

        foreach ($allFields as $field) {
            $oldValue = $old?->{$field};
            $newValue = $new?->{$field};

            // Normalize booleans for comparison
            if (is_bool($oldValue)) {
                $oldValue = $oldValue ? 1 : 0;
            }
            if (is_bool($newValue)) {
                $newValue = $newValue ? 1 : 0;
            }

            if ($oldValue !== $newValue) {
                $level = in_array($field, $criticalFields, true) ? 'critical' : 'warning';

                if ($level === 'critical') {
                    $hasCritical = true;
                }

                $changes[$field] = [
                    'old'   => $old?->{$field},
                    'new'   => $new?->{$field},
                    'level' => $level,
                ];
            }
        }

        return [
            'has_critical' => $hasCritical,
            'fields'       => $changes,
        ];
    }

    /**
     * Check if proforma can be safely converted (no critical changes).
     */
    public function canConvertSafely(Invoice $proforma): bool
    {
        $changes = $this->detectChanges($proforma);

        return ! $changes['has_critical_changes'];
    }

    /**
     * Get human-readable summary of changes.
     *
     * @return array<string>
     */
    public function getChangeSummary(Invoice $proforma): array
    {
        $changes = $this->detectChanges($proforma);
        $summary = [];

        if (! $changes['has_changes']) {
            return [];
        }

        if ($changes['company']['changed']) {
            $summary[] = $this->formatChangeSummary('company', $changes['company']);
        }

        if ($changes['user']['changed']) {
            $summary[] = $this->formatChangeSummary('user', $changes['user']);
        }

        return $summary;
    }

    /**
     * Format change summary for a config type.
     */
    protected function formatChangeSummary(string $type, array $changes): string
    {
        $label          = $type === 'company' ? 'Company fiscal config' : 'Customer tax profile';
        $changedFields  = array_keys($changes['changes']);
        $criticalFields = array_filter(
            $changedFields,
            fn ($field) => $changes['changes'][$field]['level'] === 'critical'
        );

        if ($changes['critical']) {
            return sprintf(
                '[CRITICAL] %s changed (ID: %s → %s). Critical fields: %s',
                $label,
                $changes['old_id'] ?? 'none',
                $changes['new_id'] ?? 'none',
                implode(', ', $criticalFields)
            );
        }

        return sprintf(
            '[WARNING] %s changed (ID: %s → %s). Fields: %s',
            $label,
            $changes['old_id'] ?? 'none',
            $changes['new_id'] ?? 'none',
            implode(', ', $changedFields)
        );
    }
}
