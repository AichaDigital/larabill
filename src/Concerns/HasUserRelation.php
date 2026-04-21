<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that have a user_id foreign key.
 *
 * This trait provides a user() relationship to the configured User model.
 * The trait is agnostic to the user ID type - it reads from config('larabill.user_id_type')
 * and supports int, uuid, and ulid types.
 *
 * Note: uuid_binary was removed in v1.0 for compatibility reasons.
 * See ADR-002 for details.
 *
 * Supported user_id types:
 * - 'int' or 'integer': Standard auto-increment (no cast needed)
 * - 'uuid': String UUID v7 (no cast needed) - RECOMMENDED
 * - 'ulid': String ULID (no cast needed)
 *
 * Usage:
 *   use HasUserRelation;
 *
 *   // The trait will automatically:
 *   // - Provide user() relationship to the configured User model
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
