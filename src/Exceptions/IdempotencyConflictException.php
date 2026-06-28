<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use Exception;

class IdempotencyConflictException extends Exception
{
    public static function forKey(string $key): self
    {
        return new self("Idempotency key '{$key}' was reused with a different payload.");
    }

    public static function keySpentByReversal(string $key): self
    {
        return new self("Idempotency key '{$key}' maps to a reversed payment; a re-payment needs a new key.");
    }
}
