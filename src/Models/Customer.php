<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Enums\RelationshipType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

/**
 * Customer Model
 *
 * Represents any billable entity (person, company, public entity)
 * Can be linked to a User or exist independently
 *
 * @property int $id
 * @property int|string|null $user_id FK to users (nullable - customer can exist without User)
 * @property RelationshipType $relationship_type Customer relationship type
 * @property string $display_name
 * @property string $name Alias for display_name
 * @property string|null $relationship_to_user Alias for relationship_type
 * @property string|null $internal_code
 * @property string $legal_entity_type_code FK to legal_entity_types
 * @property int|null $current_tax_profile_id FK to customer_tax_profiles
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $inactive_since
 * @property string|null $inactive_reason
 * @property string|null $notes
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'customers';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \AichaDigital\Larabill\Database\Factories\CustomerFactory
    {
        return \AichaDigital\Larabill\Database\Factories\CustomerFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'relationship_type',
        'display_name',
        'internal_code',
        'legal_entity_type_code',
        'current_tax_profile_id',
        'is_active',
        'inactive_since',
        'inactive_reason',
        'notes',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'relationship_type' => RelationshipType::class,
            'is_active'         => 'boolean',
            'inactive_since'    => 'datetime',
            'metadata'          => 'array',
        ];
    }

    /**
     * Get the user (if linked).
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Get the legal entity type.
     */
    public function legalEntityType(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class, 'legal_entity_type_code', 'code');
    }

    /**
     * Get the current tax profile.
     */
    public function currentTaxProfile(): HasOne
    {
        return $this->hasOne(CustomerTaxProfile::class, 'id', 'current_tax_profile_id');
    }

    /**
     * Get all tax profiles (historical).
     */
    public function taxProfiles(): HasMany
    {
        return $this->hasMany(CustomerTaxProfile::class)->orderBy('valid_from', 'desc');
    }

    /**
     * Get all invoices for this customer.
     */
    public function invoices(): HasMany
    {
        $invoiceModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('invoice');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->hasMany($invoiceModel, 'customer_id');
    }

    /**
     * Deactivate this customer.
     */
    public function deactivate(string $reason): void
    {
        $this->is_active       = false;
        $this->inactive_since  = now();
        $this->inactive_reason = $reason;
        $this->save();
    }

    /**
     * Reactivate this customer.
     */
    public function activate(): void
    {
        $this->is_active       = true;
        $this->inactive_since  = null;
        $this->inactive_reason = null;
        $this->save();
    }

    /**
     * Check if customer is a person (not company).
     */
    public function isPerson(): bool
    {
        return ! optional($this->legalEntityType)->is_company;
    }

    /**
     * Check if customer is a company.
     */
    public function isCompany(): bool
    {
        return optional($this->legalEntityType)->is_company ?? false;
    }

    /**
     * Scope active customers only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope inactive customers.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope by relationship type.
     */
    public function scopeRelationshipType($query, RelationshipType $type)
    {
        return $query->where('relationship_type', $type);
    }

    /**
     * Scope customers linked to a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope companies only.
     */
    public function scopeCompanies($query)
    {
        return $query->whereHas('legalEntityType', function ($q) {
            $q->where('is_company', true);
        });
    }

    /**
     * Scope individuals only.
     */
    public function scopeIndividuals($query)
    {
        return $query->whereHas('legalEntityType', function ($q) {
            $q->where('is_company', false);
        });
    }
}
