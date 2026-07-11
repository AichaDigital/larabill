<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Database\Factories\CompanyFiscalConfigFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CompanyFiscalConfig Model
 *
 * Configuración fiscal de la empresa emisora de facturas.
 * Implementa temporalidad para mantener histórico de cambios fiscales.
 *
 * @property int $id
 * @property string $business_name
 * @property string $tax_id
 * @property string $legal_entity_type
 * @property string $address
 * @property string $city
 * @property string|null $state
 * @property string $zip_code
 * @property string $country_code
 * @property bool $is_oss
 * @property bool $is_roi
 * @property string $currency
 * @property string $fiscal_year_start
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class CompanyFiscalConfig extends Model
{
    /** @use HasFactory<CompanyFiscalConfigFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'company_fiscal_configs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'business_name',
        'tax_id',
        'legal_entity_type',
        'address',
        'city',
        'state',
        'zip_code',
        'country_code',
        'is_oss',
        'is_roi',
        'currency',
        'fiscal_year_start',
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
            'is_oss'            => 'boolean',
            'is_roi'            => 'boolean',
            'is_active'         => 'boolean',
            'valid_from'        => 'date',
            'valid_until'       => 'date',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CompanyFiscalConfigFactory
    {
        return CompanyFiscalConfigFactory::new();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Al crear nueva config, cerrar la anterior automáticamente
        static::creating(function ($model) {
            if ($model->is_active && ! $model->valid_until) {
                static::closeActiveConfig($model->valid_from);
            }
        });
    }

    /**
     * Cierra la config activa anterior estableciendo valid_until.
     */
    protected static function closeActiveConfig(Carbon $newValidFrom): void
    {
        static::where('is_active', true)
            ->whereNull('valid_until')
            ->update([
                'valid_until' => $newValidFrom->copy()->subDay(),
                'is_active'   => false,
            ]);
    }

    /**
     * Obtiene la config activa actual (sin valid_until).
     */
    public static function getActive(): ?self
    {
        return static::where('is_active', true)
            ->whereNull('valid_until')
            ->first();
    }

    /**
     * Obtiene la config vigente en una fecha específica.
     *
     * @api Amber-band contract operation (AID-407): consumers call it only behind an app-side port.
     */
    public static function getValidAt(Carbon $date): ?self
    {
        return static::where('valid_from', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date);
            })
            ->orderBy('valid_from', 'desc')
            ->first();
    }

    /**
     * Crea una nueva config cerrando la anterior.
     *
     * @api Amber-band contract operation (AID-407): consumers call it only behind an app-side port.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createNew(array $attributes): self
    {
        // Asegurar que es activa y sin valid_until
        $attributes['is_active']   = true;
        $attributes['valid_until'] = null;

        // @phpstan-ignore-next-line argument.type (dynamic attribute array; larastan checkModelProperties false positive)
        return static::create($attributes);
    }

    /**
     * Scope: Configs activas.
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
     * Scope: Configs vigentes en una fecha.
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
     * Scope: Configs por país.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope: Operadores OSS.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOss(Builder $query): Builder
    {
        return $query->where('is_oss', true);
    }

    /**
     * Scope: Operadores ROI.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRoi(Builder $query): Builder
    {
        return $query->where('is_roi', true);
    }

    /**
     * Comprueba si la config está activa.
     */
    public function isCurrentlyActive(): bool
    {
        return $this->is_active && $this->valid_until === null;
    }

    /**
     * Comprueba si la config estaba vigente en una fecha.
     */
    public function wasValidAt(Carbon $date): bool
    {
        $startOk = $this->valid_from->lte($date);
        $endOk   = $this->valid_until === null || $this->valid_until->gte($date);

        return $startOk && $endOk;
    }

    /**
     * Obtiene el rango de vigencia como string legible.
     */
    public function getValidityRangeAttribute(): string
    {
        $from = $this->valid_from->format('d/m/Y');
        $to   = $this->valid_until ? $this->valid_until->format('d/m/Y') : 'Actual';

        return "{$from} → {$to}";
    }

    /**
     * Formato completo de identidad fiscal.
     */
    public function getFullFiscalIdentityAttribute(): string
    {
        return "{$this->business_name} ({$this->tax_id})";
    }

    /**
     * Dirección completa formateada.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->zip_code,
            $this->city,
            $this->state,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }
}
