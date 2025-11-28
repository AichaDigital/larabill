<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use Illuminate\Support\Str;

/**
 * Trait for models using string UUID (36 chars) as primary key.
 *
 * Provides automatic UUID v7 generation using Laravel's native support.
 * No external dependencies, fully compatible with Filament and Laravel ecosystem.
 */
trait HasUuid
{
    /**
     * Boot the trait and set up UUID generation.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            $keyName = $model->getKeyName();

            if (empty($model->{$keyName})) {
                $model->{$keyName} = (string) Str::orderedUuid(); // UUID v7
            }
        });
    }

    /**
     * Initialize the trait.
     */
    protected function initializeHasUuid(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    /**
     * Get the route key for the model (UUID for URLs).
     */
    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }
}

