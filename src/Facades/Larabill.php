<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AichaDigital\Larabill\Larabill
 */
class Larabill extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AichaDigital\Larabill\Larabill::class;
    }
}
