<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * Invoice Template Model
 *
 * Manages different invoice templates for various invoice types.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string $type
 * @property string $template_path
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_active
 * @property array|null $settings
 */
class InvoiceTemplate extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'display_name',
        'type',
        'template_path',
        'description',
        'is_default',
        'is_active',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'settings'   => 'array',
    ];

    /**
     * Get templates by type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Get active templates only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get default template for a type.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Get default template for a specific type.
     */
    public static function getDefaultForType(string $type): ?self
    {
        return static::where('type', $type)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get all active templates for a type.
     */
    public static function getActiveForType(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('type', $type)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Check if template exists and is active.
     */
    public static function existsAndActive(string $name, string $type): bool
    {
        return static::where('name', $name)
            ->where('type', $type)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get template by name and type.
     */
    public static function getByName(string $name, string $type): ?self
    {
        return static::where('name', $name)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get template settings.
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set template setting.
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
    }

    /**
     * Get available template types.
     */
    public static function getAvailableTypes(): array
    {
        return [
            'fiscal'         => 'Factura Fiscal',
            'proforma'       => 'Factura Proforma',
            'reverse-charge' => 'Factura Reverse Charge',
            'exempt'         => 'Factura Exenta',
        ];
    }

    /**
     * Get template statistics.
     */
    public static function getStatistics(): array
    {
        return [
            'total_templates'   => static::count(),
            'active_templates'  => static::where('is_active', true)->count(),
            'templates_by_type' => static::active()
                ->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'default_templates' => static::where('is_default', true)->count(),
        ];
    }
}
