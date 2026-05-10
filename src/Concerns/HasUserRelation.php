<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that have a user_id foreign key.
 *
 * Provides a user() relationship to the configured User model
 * (config('larabill.user_model'), defaults to App\Models\User).
 *
 * Larabill is UUID-first (ADR-006): user_id columns are UUID v7 char(36).
 *
 * Usage:
 *   use HasUserRelation;
 */
trait HasUserRelation
{
    /**
     * Get the user that owns this record.
     *
     * The User model class is read from config('larabill.user_model').
     * Defaults to App\Models\User if not configured.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }
}
