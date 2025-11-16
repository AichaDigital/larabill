<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * LegalEntityType Model
 *
 * Catalog of legal entity types (personas físicas, sociedades, etc.)
 *
 * @property int $id
 * @property string $code Unique code (PERSONA_FISICA, SOCIEDAD_LIMITADA, etc.)
 * @property string $name Name in Spanish
 * @property string|null $name_en Name in English
 * @property string|null $abbreviation Official abbreviation (SL, SA, etc.)
 * @property string $country_code ISO 3166-1 alpha-2
 * @property string|null $description
 * @property bool $requires_tax_id
 * @property bool $is_company
 * @property bool $is_active
 * @property int $sort_order
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class LegalEntityType extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'legal_entity_types';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'name',
        'name_en',
        'abbreviation',
        'country_code',
        'description',
        'requires_tax_id',
        'is_company',
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
            'is_company'      => 'boolean',
            'is_active'       => 'boolean',
            'sort_order'      => 'integer',
            'metadata'        => 'array',
        ];
    }

    /**
     * Get the display name (localized if available).
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        return $locale === 'en' && $this->name_en
            ? $this->name_en
            : $this->name;
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
     * Check if this entity type requires a company structure.
     */
    public function requiresCompanyStructure(): bool
    {
        return $this->is_company;
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
     * Scope company types only.
     */
    public function scopeCompanies($query)
    {
        return $query->where('is_company', true);
    }

    /**
     * Scope individual/natural person types.
     */
    public function scopeIndividuals($query)
    {
        return $query->where('is_company', false);
    }

    /**
     * Scope ordered by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
