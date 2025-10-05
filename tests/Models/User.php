<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User Model for testing model mapping
 */
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}
