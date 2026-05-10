<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use AichaDigital\Larabill\Tests\Database\Factories\TestUserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test User Model for Package Testing
 *
 * UUID-first per ADR-006 — primary key is UUID v7 char(36).
 */
class TestUser extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'test_users';

    /**
     * Primary key type (UUID v7 string char(36)).
     */
    protected $keyType = 'string';

    /**
     * Disable auto-increment (UUIDs).
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return TestUserFactory::new();
    }
}
