<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use Dyrynda\Database\Support\Casts\EfficientUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that have a user_id foreign key.
 *
 * This trait provides:
 * 1. Automatic EfficientUuid cast for user_id when using binary UUID storage
 * 2. A user() relationship to the configured User model
 *
 * The trait is agnostic to the user ID type - it reads from config('larabill.user_id_type')
 * and applies the appropriate cast only when needed.
 *
 * Supported user_id types:
 * - 'int' or 'integer': Standard auto-increment (no cast needed)
 * - 'uuid': String UUID (no cast needed)
 * - 'uuid_binary': Binary UUID 16 bytes (applies EfficientUuid cast)
 * - 'ulid': String ULID (no cast needed)
 *
 * Usage:
 *   use HasUserRelation;
 *
 *   // The trait will automatically:
 *   // - Add EfficientUuid cast to user_id if config says uuid_binary
 *   // - Provide user() relationship to the configured User model
 */
trait HasUserRelation
{
    /**
     * Initialize the trait.
     *
     * This method is automatically called by Eloquent when the model boots.
     * It merges the user_id cast into the model's casts array when needed.
     */
    public function initializeHasUserRelation(): void
    {
        // Only add cast for binary UUID storage
        if ($this->shouldCastUserIdToBinaryUuid()) {
            // Merge the cast into existing casts
            $this->mergeCasts([
                'user_id' => EfficientUuid::class,
            ]);
        }
    }

    /**
     * Determine if user_id should be cast to binary UUID.
     */
    protected function shouldCastUserIdToBinaryUuid(): bool
    {
        $userIdType = config('larabill.user_id_type', 'uuid');

        return $userIdType === 'uuid_binary';
    }

    /**
     * Get the user that owns this record.
     *
     * The User model class is read from config('larabill.user_model').
     * Defaults to App\Models\User if not configured.
     *
     * @return BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }
}
