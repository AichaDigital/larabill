<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\InvalidAllocationException;
use AichaDigital\Larabill\ValueObjects\Allocation;

it('rejects a non-positive amount', fn () => new Allocation('x', cents(0)))
    ->throws(InvalidAllocationException::class);

it('rejects a sub-cent amount', fn () => new Allocation('x', FixedDecimal::ofUnscaled(5, 3)))
    ->throws(InvalidAllocationException::class, 'sub-cent');

it('accepts an exact cent amount', function () {
    expect((new Allocation('x', cents(5000)))->amount->unscaledValue())->toBe(5000);
});

it('rejects a negative cent amount as non-positive', fn () => new Allocation('x', cents(-1)))
    ->throws(InvalidAllocationException::class, 'Allocation amount [-1] (scale 2) must be strictly positive.');

it('rejects a negative scale-3 amount as non-positive, before the sub-cent check', fn () => new Allocation('x', FixedDecimal::ofUnscaled(-5, 3)))
    ->throws(InvalidAllocationException::class, 'Allocation amount [-5] (scale 3) must be strictly positive.');
