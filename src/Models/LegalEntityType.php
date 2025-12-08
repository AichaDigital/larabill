<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * LegalEntityType Model
 *
 * Catalog of legal entity types (personas físicas, sociedades, etc.)
 * Uses spatie/laravel-translatable for name and description fields.
 *
 * @property int $id
 * @property string $code Unique code in English (INDIVIDUAL, LIMITED_COMPANY, etc.)
 * @property array $name Name (translatable JSON)
 * @property array|null $abbreviation Official abbreviation (translatable JSON: SL, Ltd, etc.)
 * @property string $country_code ISO 3166-1 alpha-2
 * @property array|null $description Description (translatable JSON)
 * @property bool $requires_tax_id
 * @property bool $is_active
 * @property int $sort_order
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class LegalEntityType extends Model
{
    use HasFactory;
    use HasTranslations;

    /**
     * The table associated with the model.
     */
    protected $table = 'legal_entity_types';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Translatable attributes (spatie/laravel-translatable).
     *
     * @var array<string>
     */
    public array $translatable = [
        'name',
        'abbreviation',
        'description',
    ];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'name',
        'abbreviation',
        'country_code',
        'description',
        'requires_tax_id',
        'is_active',
        'sort_order',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'requires_tax_id' => 'boolean',
            'is_active'       => 'boolean',
            'sort_order'      => 'integer',
            'metadata'        => 'array',
        ];
    }

    /**
     * Get the formatted name with abbreviation.
     */
    public function getFormattedNameAttribute(): string
    {
        if ($this->abbreviation) {
            return "{$this->name} ({$this->abbreviation})";
        }

        return $this->name;
    }

    /**
     * Scope active entity types only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by country.
     */
    public function scopeCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope ordered by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get all customers of this legal entity type.
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'legal_entity_type_code', 'code');
    }
}
