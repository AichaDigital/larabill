<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use AichaDigital\Larabill\Tests\Database\Factories\TestUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test User Model for Package Testing
 *
 * This is a test-only User model used exclusively for testing purposes.
 * It uses a unique name (TestUser) to avoid any collisions with the
 * application's User model.
 */
class TestUser extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'test_users';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
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
