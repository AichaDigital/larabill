<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use AichaDigital\Larabill\Database\Factories\CustomerFiscalDataFactory;

/**
 * CustomerFiscalData Model
 *
 * Histórico fiscal de clientes/usuarios.
 * Implementa temporalidad para mantener cambios fiscales en el tiempo.
 *
 * @property int $id
 * @property string $user_id
 * @property string $fiscal_name
 * @property string|null $tax_id
 * @property string|null $legal_entity_type
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $zip_code
 * @property string $country_code
 * @property bool $is_company
 * @property bool $is_eu_vat_registered
 * @property bool $is_exempt_vat
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class CustomerFiscalData extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'customer_fiscal_data';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'fiscal_name',
        'tax_id',
        'legal_entity_type',
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
    protected static function newFactory(): CustomerFiscalDataFactory
    {
        return CustomerFiscalDataFactory::new();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Al crear nueva config, cerrar la anterior del usuario automáticamente
        static::creating(function ($model) {
            if ($model->is_active && ! $model->valid_until) {
                static::closeActiveForUser($model->user_id, $model->valid_from);
            }
        });
    }

    /**
     * Cierra la config activa anterior del usuario estableciendo valid_until.
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

    /**
     * Relación con User.
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Obtiene la config activa del usuario.
     */
    public static function getActiveForUser(string $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('valid_until')
            ->first();
    }

    /**
     * Obtiene la config vigente del usuario en una fecha específica.
     */
    public static function getValidForUserAt(string $userId, Carbon $date): ?self
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
     * Crea nueva config para usuario cerrando la anterior.
     */
    public static function createForUser(string $userId, array $attributes): self
    {
        // Asegurar user_id, activa y sin valid_until
        $attributes['user_id']     = $userId;
        $attributes['is_active']   = true;
        $attributes['valid_until'] = null;

        return static::create($attributes);
    }

    /**
     * Scope: Configs de un usuario.
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Configs activas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('valid_until');
    }

    /**
     * Scope: Configs vigentes en una fecha.
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
     * Scope: Solo empresas.
     */
    public function scopeCompanies(Builder $query): Builder
    {
        return $query->where('is_company', true);
    }

    /**
     * Scope: Solo particulares.
     */
    public function scopeIndividuals(Builder $query): Builder
    {
        return $query->where('is_company', false);
    }

    /**
     * Scope: Con registro IVA EU.
     */
    public function scopeEuVatRegistered(Builder $query): Builder
    {
        return $query->where('is_eu_vat_registered', true);
    }

    /**
     * Scope: Exentos de IVA.
     */
    public function scopeVatExempt(Builder $query): Builder
    {
        return $query->where('is_exempt_vat', true);
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
        $taxId = $this->tax_id ? " ({$this->tax_id})" : '';

        return "{$this->fiscal_name}{$taxId}";
    }

    /**
     * Dirección completa formateada.
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
     * Comprueba si requiere factura con IVA.
     */
    public function requiresVAT(): bool
    {
        // Exentos de IVA no requieren
        if ($this->is_exempt_vat) {
            return false;
        }

        // Con registro EU VAT pueden aplicar reverse charge (B2B)
        if ($this->is_eu_vat_registered) {
            return false; // Reverse charge
        }

        return true; // Requiere IVA normal
    }
}

