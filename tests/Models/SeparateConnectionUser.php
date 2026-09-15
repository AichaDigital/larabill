<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

/**
 * Consumer user model living on ANOTHER connection than the fiscal tables (AID-1301).
 *
 * Same shape as TestUser, bound to the `larabill_users` connection, whose
 * database holds no `user_tax_profiles` table. Any query that embeds the
 * fiscal tables inside a statement run on the user model's connection fails
 * against it, which is how the tests prove that the relink hook and the
 * published repair recipe never assume both models share a connection.
 */
class SeparateConnectionUser extends TestUser
{
    protected $connection = 'larabill_users';
}
