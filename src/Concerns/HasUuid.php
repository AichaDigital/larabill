<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use Illuminate\Support\Str;

/**
 * Agnostic UUID trait for Eloquent models.
 *
 * Uses Laravel 12 native UUID v7 (ordered) for optimal index performance.
 * Configures the model for string UUID primary keys.
 *
 * Usage:
 *   use HasUuid;
 *
 * Migration:
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
                // Generate UUID v7 (ordered) for better index performance
                $model->{$keyName} = (string) Str::orderedUuid();
            }
        });
    }

    /**
     * Initialize the trait - configure model for UUID usage.
     */
    protected function initializeHasUuid(): void
    {
        $this->incrementing = false;
        $this->keyType      = 'string';
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    /**
     * Resolve the route binding for UUID.
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
     * Get the UUID column name.
     */
    public function uuidColumn(): string
    {
        return $this->getKeyName();
    }

    /**
     * Get the UUID columns.
     *
     * @return array<string>
     */
    public function uuidColumns(): array
    {
        return [$this->getKeyName()];
    }
}
