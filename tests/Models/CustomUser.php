<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomUser Model for testing model mapping
 */
class CustomUser extends Model
{
    protected $fillable = [
        'name',
        'email',
    ];
}
