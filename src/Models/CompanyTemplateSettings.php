<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * Company Template Settings Model
 *
 * Manages company-specific template settings, notes, and payment terms.
 *
 * @property int $id
 * @property string $company_id
 * @property string $setting_type
 * @property string $invoice_type
 * @property string $scope
 * @property string|null $client_id
 * @property string $value
 * @property bool $is_active
 */
class CompanyTemplateSettings extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'setting_type',
        'invoice_type',
        'scope',
        'client_id',
        'value',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Setting types.
     */
    public const SETTING_TEMPLATE = 'template';

    public const SETTING_NOTES = 'notes';

    public const SETTING_PAYMENT_TERMS = 'payment_terms';

    /**
     * Scope types.
     */
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_CLIENT = 'client';

    public const SCOPE_INDIVIDUAL = 'individual';

    /**
     * Get settings by company.
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get settings by type.
     */
    public function scopeBySettingType(Builder $query, string $settingType): Builder
    {
        return $query->where('setting_type', $settingType);
    }

    /**
     * Get settings by invoice type.
     */
    public function scopeByInvoiceType(Builder $query, string $invoiceType): Builder
    {
        return $query->where('invoice_type', $invoiceType);
    }

    /**
     * Get settings by scope.
     */
    public function scopeByScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * Get active settings only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get setting value for company and type.
     */
    public static function getSetting(
        string $companyId,
        string $settingType,
        string $invoiceType,
        ?string $clientId = null
    ): ?string {
        // Try to get individual setting first
        if ($clientId) {
            $setting = static::forCompany($companyId)
                ->bySettingType($settingType)
                ->byInvoiceType($invoiceType)
                ->byScope(self::SCOPE_CLIENT)
                ->where('client_id', $clientId)
                ->active()
                ->first();

            if ($setting) {
                return $setting->value;
            }
        }

        // Fallback to global setting
        $setting = static::forCompany($companyId)
            ->bySettingType($settingType)
            ->byInvoiceType($invoiceType)
            ->byScope(self::SCOPE_GLOBAL)
            ->active()
            ->first();

        return $setting?->value;
    }

    /**
     * Set setting value for company and type.
     */
    public static function setSetting(
        string $companyId,
        string $settingType,
        string $invoiceType,
        string $value,
        string $scope = self::SCOPE_GLOBAL,
        ?string $clientId = null
    ): self {
        return static::updateOrCreate(
            [
                'company_id'   => $companyId,
                'setting_type' => $settingType,
                'invoice_type' => $invoiceType,
                'scope'        => $scope,
                'client_id'    => $clientId,
            ],
            [
                'value'     => $value,
                'is_active' => true,
            ]
        );
    }

    /**
     * Get all settings for a company.
     */
    public static function getCompanySettings(string $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forCompany($companyId)
            ->active()
            ->orderBy('setting_type')
            ->orderBy('invoice_type')
            ->orderBy('scope')
            ->get();
    }

    /**
     * Get template settings for a company.
     */
    public static function getTemplateSettings(string $companyId, string $invoiceType): array
    {
        $settings = static::forCompany($companyId)
            ->bySettingType(self::SETTING_TEMPLATE)
            ->byInvoiceType($invoiceType)
            ->active()
            ->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->scope] = $setting->value;
        }

        return $result;
    }

    /**
     * Get default notes for a company.
     */
    public static function getDefaultNotes(string $companyId, string $invoiceType, ?string $clientId = null): ?string
    {
        return static::getSetting($companyId, self::SETTING_NOTES, $invoiceType, $clientId);
    }

    /**
     * Get payment terms for a company.
     */
    public static function getPaymentTerms(string $companyId, string $invoiceType, ?string $clientId = null): ?string
    {
        return static::getSetting($companyId, self::SETTING_PAYMENT_TERMS, $invoiceType, $clientId);
    }

    /**
     * Get available setting types.
     */
    public static function getSettingTypes(): array
    {
        return [
            self::SETTING_TEMPLATE      => 'Template',
            self::SETTING_NOTES         => 'Notes',
            self::SETTING_PAYMENT_TERMS => 'Payment Terms',
        ];
    }

    /**
     * Get available scopes.
     */
    public static function getScopes(): array
    {
        return [
            self::SCOPE_GLOBAL     => 'Global',
            self::SCOPE_CLIENT     => 'Client',
            self::SCOPE_INDIVIDUAL => 'Individual',
        ];
    }
}
