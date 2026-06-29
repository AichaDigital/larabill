<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Database\Factories\LegalEntityTypeFactory;
use AichaDigital\Larabill\Services\ModelMappingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * LegalEntityType Model
 *
 * Catalog of legal entity types (personas físicas, sociedades, etc.)
 * Uses spatie/laravel-translatable for name and description fields.
 *
 * @property int $id
 * @property string $code Unique code in English (INDIVIDUAL, LIMITED_COMPANY, etc.)
 * @property array<string, string> $name Name (translatable JSON)
 * @property array<string, string>|null $abbreviation Official abbreviation (translatable JSON: SL, Ltd, etc.)
 * @property string $country_code ISO 3166-1 alpha-2
 * @property array<string, string>|null $description Description (translatable JSON)
 * @property bool $requires_tax_id
 * @property bool $is_active
 * @property int $sort_order
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalEntityType extends Model
{
    /** @use HasFactory<LegalEntityTypeFactory> */
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
            'is_active'       => 'boolean',
            'sort_order'      => 'integer',
            'metadata'        => 'array',
        ];
    }

    /**
     * Get the formatted name with abbreviation.
     *
     * Uses getTranslation() to get string values from translatable fields.
     */
    public function getFormattedNameAttribute(): string
    {
        /** @var string $name */
        $name = $this->getTranslation('name', app()->getLocale());

        /** @var string|null $abbreviation */
        $abbreviation = $this->getTranslation('abbreviation', app()->getLocale());

        if ($abbreviation) {
            return "{$name} ({$abbreviation})";
        }

        return $name;
    }

    /**
     * Scope active entity types only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by country.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope ordered by sort_order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get all users of this legal entity type (ADR-003).
     *
     * @return HasMany<Model, $this>
     */
    public function users(): HasMany
    {
        $userModel = ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type
        return $this->hasMany($userModel, 'legal_entity_type_code', 'code');
    }
}
