<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use AichaDigital\Larabill\Tests\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * User Model for testing model mapping (UUID-first per ADR-006).
 */
class User extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
    ];

    /**
     * Create a new factory instance bound to this UUID-keyed test User.
     *
     * Without the explicit modelName(), Orchestra's UserFactory falls back to
     * Illuminate\Foundation\Auth\User, which lacks HasUuids and tries to
     * insert a NULL `id` against the UUID `users.id` column.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
