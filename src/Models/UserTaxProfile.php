<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

/**
 * UserTaxProfile Model
 *
 * Represents fiscal profile information for a user.
 * Stores tax identification, business details, and legal entity type.
 *
 * @property int $id
 * @property string|int $user_id
 * @property bool $is_current
 * @property string $legal_entity_type
 * @property string $tax_code
 * @property string $business_name
 * @property string $address
 * @property string $city
 * @property string $postal_code
 * @property string $country
 * @property string|null $state
 * @property string|null $phone
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserTaxProfile extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'user_tax_profiles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'is_current',
        'legal_entity_type',
        'tax_code',
        'business_name',
        'address',
        'city',
        'postal_code',
        'country',
        'state',
        'phone',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('user_tax_profile');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'user_tax_profile');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('user_tax_profile');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'user_tax_profile');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Make this tax profile the current one for the user.
     */
    public function makeCurrent(): void
    {
        // Set all other tax profiles for this user as not current
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        // Set this one as current
        $this->update(['is_current' => true]);
    }

    /**
     * Scope to get only current tax profiles.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /**
     * Get the user that owns the tax profile.
     *
     * @return BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Get the VAT verification for this tax profile.
     *
     * @return HasOne<VatVerification, $this>
     */
    public function vatVerification(): HasOne
    {
        return $this->hasOne(VatVerification::class, 'vat_code', 'tax_code');
    }

    /**
     * Get the invoices using this tax profile.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        $invoiceModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('invoice');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->hasMany($invoiceModel, 'tax_profile_id');
    }
}
