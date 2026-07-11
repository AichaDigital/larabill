<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Concerns;

use AichaDigital\Larabill\Enums\UserRelationshipType;
use AichaDigital\Larabill\Models\UserTaxProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait for User models that participate in the Larabill billing system.
 *
 * This trait provides:
 *
 * - Self-referencing relationships for delegation hierarchy (ADR-003)
 * - Tax profile relationships (ADR-004: shared profiles via owner_user_id)
 *
 * NOTE: Authorization logic (UserType, AccessLevel, Departments) has been
 * moved to the application layer per ADR-005. This trait only handles
 * billing-related relationships.
 *
 * Usage in your User model:
 *
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
 * @property int|null $current_tax_profile_id
 * @property string|int|null $parent_user_id
 * @property UserRelationshipType|int $relationship_type
 *
 * @see ADR-003 for delegation architecture
 * @see ADR-004 for tax profile changes (owner_user_id)
 * @see ADR-005 for authorization moved to application
 *
 * @phpstan-require-extends Model
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
trait HasUserRelationships
{
    // ========================================
    // TAX PROFILE RELATIONSHIPS (ADR-004)
    // ========================================

    /**
     * Get the current active tax profile.
     *
     * This is the fiscal identity currently used for this user account.
     * Multiple user accounts can share the same tax profile.
     *
     * @return BelongsTo<UserTaxProfile, $this>
     */
    public function currentTaxProfile(): BelongsTo
    {
        return $this->belongsTo(UserTaxProfile::class, 'current_tax_profile_id');
    }

    /**
     * Get all tax profiles owned by this user.
     *
     * These are profiles where this user is the owner (can edit).
     *
     * @return HasMany<UserTaxProfile, $this>
     */
    public function ownedTaxProfiles(): HasMany
    {
        return $this->hasMany(UserTaxProfile::class, 'owner_user_id');
    }

    // ========================================
    // DELEGATION RELATIONSHIPS (ADR-003)
    // ========================================

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

    // ========================================
    // DELEGATION HELPERS (ADR-003)
    // ========================================

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
     * Get the relationship type enum.
     *
     * This is a helper for when the cast is not applied.
     */
    public function getRelationshipTypeEnum(): UserRelationshipType
    {
        if ($this->relationship_type instanceof UserRelationshipType) {
            return $this->relationship_type;
        }

        return UserRelationshipType::from((int) ($this->relationship_type ?? 0));
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope: Only DIRECT clients.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDirect($query)
    {
        return $query->whereNull('parent_user_id');
    }

    /**
     * Scope: Only DELEGATED clients.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDelegated($query)
    {
        return $query->whereNotNull('parent_user_id');
    }

    /**
     * Scope: Children of a specific user.
     *
     * @param  Builder<static>  $query
     * @param  int|string  $parentId
     * @return Builder<static>
     */
    public function scopeChildrenOf($query, $parentId)
    {
        return $query->where('parent_user_id', $parentId);
    }

    /**
     * Scope: With current tax profile loaded.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithCurrentTaxProfile($query)
    {
        return $query->with(['currentTaxProfile']);
    }
}
