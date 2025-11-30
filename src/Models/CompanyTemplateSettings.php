<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Enums\{SettingScope, SettingType, TemplateInvoiceType};
use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * Company Template Settings Model
 *
 * Manages company-specific template settings, notes, and payment terms.
 *
 * @property int $id
 * @property string|int $user_id
 * @property SettingType $setting_type
 * @property TemplateInvoiceType $invoice_type
 * @property SettingScope $scope
 * @property string|int|null $client_id
 * @property string $value
 * @property bool $is_active
 */
class CompanyTemplateSettings extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
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
    protected function casts(): array
    {
        return [
            'setting_type' => SettingType::class,
            'invoice_type' => TemplateInvoiceType::class,
            'scope'        => SettingScope::class,
            'is_active'    => 'boolean',
        ];
    }

    /**
     * Get settings by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string|int $companyId): Builder
    {
        return $query->where('user_id', $companyId);
    }

    /**
     * Get settings by type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBySettingType(Builder $query, SettingType $settingType): Builder
    {
        return $query->where('setting_type', $settingType);
    }

    /**
     * Get settings by invoice type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByInvoiceType(Builder $query, TemplateInvoiceType $invoiceType): Builder
    {
        return $query->where('invoice_type', $invoiceType);
    }

    /**
     * Get settings by scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByScope(Builder $query, SettingScope $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * Get active settings only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get setting value for company and type.
     */
    public static function getSetting(
        string|int $companyId,
        SettingType $settingType,
        TemplateInvoiceType $invoiceType,
        string|int|null $clientId = null
    ): ?string {
        // Try to get individual setting first
        if ($clientId) {
            $setting = static::forCompany($companyId)
                ->bySettingType($settingType)
                ->byInvoiceType($invoiceType)
                ->byScope(SettingScope::CLIENT)
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
            ->byScope(SettingScope::GLOBAL_SCOPE)
            ->active()
            ->first();

        return $setting?->value;
    }

    /**
     * Set setting value for company and type.
     */
    public static function setSetting(
        string|int $companyId,
        SettingType $settingType,
        TemplateInvoiceType $invoiceType,
        string $value,
        SettingScope $scope = SettingScope::GLOBAL_SCOPE,
        string|int|null $clientId = null
    ): self {
        return static::updateOrCreate(
            [
                'user_id'      => $companyId,
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
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyTemplateSettings>
     */
    public static function getCompanySettings(string|int $companyId): \Illuminate\Database\Eloquent\Collection
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
     *
     * @return array<string, mixed>
     */
    public static function getTemplateSettings(string|int $companyId, TemplateInvoiceType $invoiceType): array
    {
        $settings = static::forCompany($companyId)
            ->bySettingType(SettingType::TEMPLATE)
            ->byInvoiceType($invoiceType)
            ->active()
            ->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->scope->name] = $setting->value;
        }

        return $result;
    }

    /**
     * Get default notes for a company.
     */
    public static function getDefaultNotes(
        string|int $companyId,
        TemplateInvoiceType $invoiceType,
        string|int|null $clientId = null
    ): ?string {
        return static::getSetting($companyId, SettingType::NOTES, $invoiceType, $clientId);
    }

    /**
     * Get payment terms for a company.
     */
    public static function getPaymentTerms(
        string|int $companyId,
        TemplateInvoiceType $invoiceType,
        string|int|null $clientId = null
    ): ?string {
        return static::getSetting($companyId, SettingType::PAYMENT_TERMS, $invoiceType, $clientId);
    }

    /**
     * Get available setting types.
     *
     * @return array<int, string>
     */
    public static function getSettingTypes(): array
    {
        return array_combine(
            array_column(SettingType::cases(), 'value'),
            array_map(fn (SettingType $type) => $type->label(), SettingType::cases())
        );
    }

    /**
     * Get available scopes.
     *
     * @return array<int, string>
     */
    public static function getScopes(): array
    {
        return array_combine(
            array_column(SettingScope::cases(), 'value'),
            array_map(fn (SettingScope $scope) => $scope->label(), SettingScope::cases())
        );
    }
}
