<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use AichaDigital\Larabill\Support\MigrationHelper;
use Dyrynda\Database\Support\Casts\EfficientUuid;
use Illuminate\Support\Str;

/**
 * Agnostic UUID trait for Eloquent models.
 *
 * Supports multiple UUID strategies based on configuration:
 * - 'uuid': String UUID v7 (36 chars) - uses Laravel native
 * - 'uuid_binary': Binary UUID (16 bytes) - uses Dyrynda EfficientUuid cast
 *
 * For binary UUID, requires dyrynda/laravel-model-uuid package.
 * The trait automatically configures the model based on larabill.user_id_type config.
 *
 * Usage:
 *   use HasUuid;
 *
 * For binary UUID, ensure your migration uses:
 *   $table->binary('id', 16)->primary();
 *
 * For string UUID:
 *   $table->uuid('id')->primary();
 */
trait HasUuid
{
    /**
     * Boot the trait and set up UUID generation.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            $keyName = $model->getKeyName();

            if (empty($model->{$keyName})) {
                $idType = MigrationHelper::getUserIdType();

                // Generate UUID v7 (ordered) for better index performance
                $uuid = (string) Str::orderedUuid();

                if ($idType === 'uuid_binary') {
                    // For binary storage, the EfficientUuid cast handles conversion
                    // We just set the string value, cast converts to binary on save
                    $model->{$keyName} = $uuid;
                } else {
                    // For string storage, use directly
                    $model->{$keyName} = $uuid;
                }
            }
        });
    }

    /**
     * Initialize the trait - configure model for UUID usage.
     */
    protected function initializeHasUuid(): void
    {
        $this->incrementing = false;

        $idType = MigrationHelper::getUserIdType();

        if ($idType === 'uuid_binary') {
            // Binary UUID: key type is string (EfficientUuid cast handles conversion)
            $this->keyType = 'string';
        } else {
            // String UUID: key type is string
            $this->keyType = 'string';
        }
    }

    /**
     * Get the casts array, adding EfficientUuid for binary UUID if needed.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        $casts  = parent::getCasts();
        $idType = MigrationHelper::getUserIdType();

        if ($idType === 'uuid_binary') {
            // Add EfficientUuid cast for binary storage
            $casts[$this->getKeyName()] = EfficientUuid::class;
        }

        return $casts;
    }

    /**
     * Get the route key for the model.
     *
     * For binary UUID, this returns the string representation.
     */
    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    /**
     * Resolve the route binding for UUID.
     *
     * Handles both string and binary UUID lookups.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?? $this->getRouteKeyName();

        return $this->resolveRouteBindingQuery($this, $value, $field)->first();
    }

    /**
     * Check if the model is using binary UUID storage.
     */
    public function usesBinaryUuid(): bool
    {
        return MigrationHelper::getUserIdType() === 'uuid_binary';
    }

    /**
     * Get the UUID column name (for compatibility with dyrynda package).
     */
    public function uuidColumn(): string
    {
        return $this->getKeyName();
    }

    /**
     * Get the UUID columns (for compatibility with dyrynda package).
     *
     * @return array<string>
     */
    public function uuidColumns(): array
    {
        return [$this->getKeyName()];
    }
}
