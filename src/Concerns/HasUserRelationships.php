<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use AichaDigital\Larabill\Enums\UserRelationshipType;
use AichaDigital\Larabill\Models\UserTaxProfile;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

/**
 * Trait for User models that participate in the Larabill self-referencing system.
 *
 * This trait provides the self-referencing relationships for User models
 * as defined in ADR-003 (User/Customer Unification).
 *
 * Provides:
 * - parent(): The parent user (for DELEGATED clients)
 * - children(): Users that are clients of this user
 * - taxProfiles(): All tax profiles for this user
 * - activeTaxProfile(): The currently active tax profile
 *
 * Usage in your User model:
 *   use AichaDigital\Larabill\Concerns\HasUserRelationships;
 *
 *   class User extends Authenticatable
 *   {
 *       use HasUserRelationships;
 *
 *       protected function casts(): array
 *       {
 *           return [
 *               'relationship_type' => UserRelationshipType::class,
 *               // ... other casts
 *           ];
 *       }
 *   }
 *
 * @see ADR-003 for architectural decisions
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasUserRelationships
{
    /**
     * Get the parent user (for DELEGATED clients).
     *
     * Returns null if this is a DIRECT client (parent_user_id = null).
     *
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_user_id');
    }

    /**
     * Get all child users (clients of this user).
     *
     * Only DIRECT users can have children (DELEGATED clients).
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_user_id');
    }

    /**
     * Get all tax profiles for this user.
     *
     * Includes both active and historical profiles.
     *
     * @return HasMany<UserTaxProfile, $this>
     */
    public function taxProfiles(): HasMany
    {
        return $this->hasMany(UserTaxProfile::class, 'user_id');
    }

    /**
     * Get the currently active tax profile.
     *
     * Active means is_active = true AND valid_until = null.
     *
     * @return HasOne<UserTaxProfile, $this>
     */
    public function activeTaxProfile(): HasOne
    {
        return $this->hasOne(UserTaxProfile::class, 'user_id')
            ->where('is_active', true)
            ->whereNull('valid_until');
    }

    /**
     * Check if this user is a DIRECT client (of the Company).
     *
     * DIRECT clients have no parent_user_id.
     */
    public function isDirect(): bool
    {
        return $this->parent_user_id === null;
    }

    /**
     * Check if this user is a DELEGATED client (of another User).
     *
     * DELEGATED clients have a parent_user_id.
     */
    public function isDelegated(): bool
    {
        return $this->parent_user_id !== null;
    }

    /**
     * Check if this user has any children (delegated clients).
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Get the relationship type.
     *
     * This is a helper for when the cast is not applied.
     */
    public function getRelationshipTypeEnum(): UserRelationshipType
    {
        if ($this->relationship_type instanceof UserRelationshipType) {
            return $this->relationship_type;
        }

        return UserRelationshipType::from((int) $this->relationship_type);
    }

    /**
     * Scope: Only DIRECT clients.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDirect($query)
    {
        return $query->whereNull('parent_user_id');
    }

    /**
     * Scope: Only DELEGATED clients.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDelegated($query)
    {
        return $query->whereNotNull('parent_user_id');
    }

    /**
     * Scope: Children of a specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @param  int|string  $parentId
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeChildrenOf($query, $parentId)
    {
        return $query->where('parent_user_id', $parentId);
    }

    /**
     * Scope: With active tax profile loaded.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeWithActiveTaxProfile($query)
    {
        return $query->with(['activeTaxProfile']);
    }
}
