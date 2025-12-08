<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Concerns\HasUserRelation;
use AichaDigital\Larabill\Database\Factories\UserTaxProfileFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * UserTaxProfile Model
 *
 * Unified fiscal history for all Users (DIRECT and DELEGATED).
 * Replaces CustomerFiscalData with simpler architecture.
 *
 * Implements temporal validity to maintain immutable fiscal data over time.
 * A User can have multiple tax profiles, but only one active (valid_until = null).
 *
 * @property int $id
 * @property string $user_id FK to users.id
 * @property string $fiscal_name Fiscal name (may differ from user.name)
 * @property string|null $tax_id NIF/CIF/VAT number
 * @property string|null $legal_entity_type_code FK to legal_entity_types.code
 * @property string|null $address Fiscal address
 * @property string|null $city City
 * @property string|null $state Province/State
 * @property string|null $zip_code Postal code
 * @property string $country_code ISO 3166-1 alpha-2
 * @property bool $is_company Company (true) vs Individual (false)
 * @property bool $is_eu_vat_registered EU VAT registration (intra-community)
 * @property bool $is_exempt_vat VAT exempt
 * @property Carbon $valid_from Validity start date
 * @property Carbon|null $valid_until Validity end date (null = current/active)
 * @property bool $is_active Active config
 * @property string|null $notes Notes about fiscal change
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 *
 * @see ADR-003 for architectural decisions
 */
class UserTaxProfile extends Model
{
    use HasFactory;
    use HasUserRelation;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'user_tax_profiles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'fiscal_name',
        'tax_id',
        'legal_entity_type_code',
        'address',
        'city',
        'state',
        'zip_code',
        'country_code',
        'is_company',
        'is_eu_vat_registered',
        'is_exempt_vat',
        'valid_from',
        'valid_until',
        'is_active',
        'notes',
    ];

    /**
     * Casts for attributes.
     *
     * Note: user_id cast is handled by HasUserRelation trait.
     */
    protected function casts(): array
    {
        return [
            'is_company'           => 'boolean',
            'is_eu_vat_registered' => 'boolean',
            'is_exempt_vat'        => 'boolean',
            'is_active'            => 'boolean',
            'valid_from'           => 'date',
            'valid_until'          => 'date',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): UserTaxProfileFactory
    {
        return UserTaxProfileFactory::new();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // When creating new config, close previous active config automatically
        static::creating(function ($model) {
            if ($model->is_active && ! $model->valid_until) {
                static::closeActiveForUser($model->user_id, $model->valid_from);
            }
        });
    }

    /**
     * Closes active config for user by setting valid_until.
     */
    protected static function closeActiveForUser(string $userId, Carbon $newValidFrom): void
    {
        static::where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->update([
                'valid_until' => $newValidFrom->copy()->subDay(),
                'is_active'   => false,
            ]);
    }

    // user() relationship is provided by HasUserRelation trait

    /**
     * Get the legal entity type.
     */
    public function legalEntityType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_code', 'code');
    }

    /**
     * Get active config for a user.
     */
    public static function getActiveForUser(string|int $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->first();
    }

    /**
     * Get config valid for a user at a specific date.
     */
    public static function getValidForUserAt(string|int $userId, Carbon $date): ?self
    {
        return static::where('user_id', $userId)
            ->where('valid_from', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date);
            })
            ->orderBy('valid_from', 'desc')
            ->first();
    }

    /**
     * Create new config for user, closing previous one.
     */
    public static function createForUser(string|int $userId, array $attributes): self
    {
        $attributes['user_id']     = $userId;
        $attributes['is_active']   = true;
        $attributes['valid_until'] = null;

        return static::create($attributes);
    }

    /**
     * Scope: Configs for a user.
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Active configs.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('valid_until');
    }

    /**
     * Scope: Configs valid at a date.
     */
    public function scopeValidAt(Builder $query, Carbon $date): Builder
    {
        return $query->where('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date);
            });
    }

    /**
     * Scope: Companies only.
     */
    public function scopeCompanies(Builder $query): Builder
    {
        return $query->where('is_company', true);
    }

    /**
     * Scope: Individuals only.
     */
    public function scopeIndividuals(Builder $query): Builder
    {
        return $query->where('is_company', false);
    }

    /**
     * Scope: EU VAT registered.
     */
    public function scopeEuVatRegistered(Builder $query): Builder
    {
        return $query->where('is_eu_vat_registered', true);
    }

    /**
     * Scope: VAT exempt.
     */
    public function scopeVatExempt(Builder $query): Builder
    {
        return $query->where('is_exempt_vat', true);
    }

    /**
     * Check if config is currently active.
     */
    public function isCurrentlyActive(): bool
    {
        return $this->is_active && $this->valid_until === null;
    }

    /**
     * Check if config was valid at a specific date.
     */
    public function wasValidAt(Carbon $date): bool
    {
        $startOk = $this->valid_from->lte($date);
        $endOk   = $this->valid_until === null || $this->valid_until->gte($date);

        return $startOk && $endOk;
    }

    /**
     * Get the validity range as readable string.
     */
    public function getValidityRangeAttribute(): string
    {
        $from = $this->valid_from->format('d/m/Y');
        $to   = $this->valid_until ? $this->valid_until->format('d/m/Y') : 'Current';

        return "{$from} → {$to}";
    }

    /**
     * Full fiscal identity format.
     */
    public function getFullFiscalIdentityAttribute(): string
    {
        $taxId = $this->tax_id ? " ({$this->tax_id})" : '';

        return "{$this->fiscal_name}{$taxId}";
    }

    /**
     * Complete formatted address.
     */
    public function getFullAddressAttribute(): string
    {
        if (! $this->address) {
            return '';
        }

        $parts = array_filter([
            $this->address,
            $this->zip_code,
            $this->city,
            $this->state,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if VAT is required for invoices.
     */
    public function requiresVAT(): bool
    {
        // VAT exempt don't require
        if ($this->is_exempt_vat) {
            return false;
        }

        // EU VAT registered can apply reverse charge (B2B)
        if ($this->is_eu_vat_registered) {
            return false; // Reverse charge
        }

        return true; // Normal VAT required
    }
}
