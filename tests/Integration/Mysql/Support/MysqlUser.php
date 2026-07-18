<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Integration\Mysql\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumer-shaped user model for the MySQL integration suite (AID-553).
 *
 * Bound to the real `users` table these cases create (UUID v7 char(36) id,
 * ADR-006). The suite used to lean on ModelMappingService's silent fallback
 * to a tests-namespace class; the mapping now requires an explicit
 * larabill.user_model — the same contract a real consumer fulfils.
 */
class MysqlUser extends Model
{
    use HasUuids;

    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
    ];
}
