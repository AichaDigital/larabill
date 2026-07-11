<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Database\Factories\UserTaxProfileFactory;
use AichaDigital\Larabill\Services\ModelMappingService;
use AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * UserTaxProfile Model
 *
 * Represents a fiscal identity that can be shared by multiple user accounts.
 * A real person (identified by tax_id) may have multiple user accounts
 * (different emails for staff, customer, delegate roles) all sharing
 * the same tax profile.
 *
 * Implements temporal validity to maintain immutable fiscal data over time.
 * When fiscal data changes, a new profile is created and all linked users
 * are updated to point to the new profile.
 *
 * @property int $id
 * @property string|int $owner_user_id User who can edit this profile (ADR-004: renamed from user_id)
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
 * @property-read Collection|Invoice[] $invoices
 *
 * @see ADR-003 for initial architecture
 * @see ADR-004 for authorization changes (user_id -> owner_user_id, shared profiles)
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class UserTaxProfile extends Model implements LegallyRetainable
{
    /** @use HasFactory<UserTaxProfileFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'user_tax_profiles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'owner_user_id',
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

        // When creating new config, close previous active config for the same owner
        static::creating(function ($model) {
            if ($model->is_active && ! $model->valid_until && $model->owner_user_id) {
                static::closeActiveForOwner($model->owner_user_id, $model->valid_from);
            }
        });

        // After creating new profile, update all linked users to point to the new profile
        static::created(function ($model) {
            if ($model->is_active && ! $model->valid_until) {
                $model->updateLinkedUsersToNewProfile();
            }
        });
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Get the owner user (who can edit this profile).
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line
        return $this->belongsTo($userModel, 'owner_user_id');
    }

    /**
     * Get all users linked to this tax profile.
     *
     * Multiple user accounts can share the same fiscal identity.
     *
     * @return HasMany<Model, $this>
     */
    public function linkedUsers(): HasMany
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line
        return $this->hasMany($userModel, 'current_tax_profile_id');
    }

    /**
     * Get the legal entity type.
     *
     * @return BelongsTo<LegalEntityType, $this>
     */
    public function legalEntityType(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_code', 'code');
    }

    /**
     * Get invoices that reference this fiscal profile snapshot.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'user_tax_profile_id');
    }

    /**
     * The instant until which this fiscal profile must be retained
     * (lara-privacy `LegallyRetainable`).
     *
     * A profile has no flat retention of its own: it is held for as long as any
     * fiscal invoice that references it is still held. So the answer is the
     * latest (MAX) `retainedUntil()` across its invoices; a later rectificative
     * pointing at this snapshot re-extends the hold. A profile that has never
     * backed an invoice carries no obligation and returns null (decision C).
     *
     * The hold derives from the invoices, never from the profile's own
     * `deleted_at`: a soft-deleted profile that still backs a live fiscal
     * invoice remains held. Invoices are not soft-deletable, so the collection
     * already covers every relevant record.
     */
    public function retainedUntil(): ?DateTimeInterface
    {
        $holds = $this->invoices
            ->map(static fn (Invoice $invoice): ?DateTimeInterface => $invoice->retainedUntil())
            ->filter()
            ->all();

        return $holds === [] ? null : max($holds);
    }

    // ========================================
    // STATIC QUERY METHODS
    // ========================================

    /**
     * Closes active config for owner by setting valid_until.
     */
    protected static function closeActiveForOwner(string|int $ownerId, Carbon $newValidFrom): void
    {
        static::where('owner_user_id', $ownerId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->update([
                'valid_until' => $newValidFrom->copy()->subDay(),
                'is_active'   => false,
            ]);
    }

    /**
     * Get active config for an owner.
     */
    public static function getActiveForOwner(string|int $ownerId): ?self
    {
        return static::where('owner_user_id', $ownerId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->first();
    }

    /**
     * Get config valid for an owner at a specific date.
     */
    public static function getValidForOwnerAt(string|int $ownerId, Carbon $date): ?self
    {
        return static::where('owner_user_id', $ownerId)
            ->where('valid_from', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date);
            })
            ->orderBy('valid_from', 'desc')
            ->first();
    }

    /**
     * Create new config for owner, closing previous one.
     *
     * @api Amber-band contract operation (AID-407): consumers call it only behind an app-side port.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createForOwner(string|int $ownerId, array $attributes): self
    {
        $attributes['owner_user_id'] = $ownerId;
        $attributes['is_active']     = true;
        $attributes['valid_until']   = null;

        // @phpstan-ignore-next-line argument.type (dynamic attribute array; larastan checkModelProperties false positive)
        return static::create($attributes);
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope: Configs for an owner.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOwner(Builder $query, string|int $ownerId): Builder
    {
        return $query->where('owner_user_id', $ownerId);
    }

    /**
     * Scope: Active configs.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('valid_until');
    }

    /**
     * Scope: Configs valid at a date.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompanies(Builder $query): Builder
    {
        return $query->where('is_company', true);
    }

    /**
     * Scope: Individuals only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIndividuals(Builder $query): Builder
    {
        return $query->where('is_company', false);
    }

    /**
     * Scope: EU VAT registered.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEuVatRegistered(Builder $query): Builder
    {
        return $query->where('is_eu_vat_registered', true);
    }

    /**
     * Scope: VAT exempt.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVatExempt(Builder $query): Builder
    {
        return $query->where('is_exempt_vat', true);
    }

    // ========================================
    // INSTANCE METHODS
    // ========================================

    /**
     * Update all users linked to the old profile to point to this new profile.
     *
     * Called automatically after creating a new active profile.
     */
    protected function updateLinkedUsersToNewProfile(): void
    {
        // Find the previous profile for the same owner
        $previousProfile = static::where('owner_user_id', $this->owner_user_id)
            ->where('id', '!=', $this->id)
            ->whereNotNull('valid_until')
            ->orderBy('valid_until', 'desc')
            ->first();

        if (! $previousProfile) {
            return;
        }

        // Update all users pointing to the old profile
        $userModel = ModelMappingService::getModelClass('user');
        $userModel::where('current_tax_profile_id', $previousProfile->id)
            ->update(['current_tax_profile_id' => $this->id]);
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

        return "{$from} -> {$to}";
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
