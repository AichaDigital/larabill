<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Casts;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Support\MoneyDomain;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Money cast for the AEAT-class columns of the §2 domain matrix (AID-1527):
 * composition over lara100's final FixedDecimalCast, with the NORMALIZED
 * scale-2 storage cents validated against the AEAT money domain on set.
 *
 * This cast ALWAYS enforces — no domain-toggle parameter: Laravel passes cast
 * parameters as STRINGS (HasAttributes), so a `false` flag would arrive
 * truthy (round-2 finding 5). It is applied ONLY to AEAT-class columns;
 * non-AEAT columns (accumulators, quantity) keep the original
 * FixedDecimalCast:2.
 *
 * `$withoutObjectCaching = true` (gate finding 4): Eloquent caches the value
 * object and re-invokes set() while merging cached attributes during
 * toArray()/getAttributes() — with object caching enabled, an innocent read
 * of a legacy over-domain row (bulk-written, pre-widening) throws on
 * SERIALIZATION. The flag keeps get() unguarded AND keeps the write guard
 * effective on assignment.
 *
 * Rejection surfaces: MoneyDomainOverflowException on Eloquent assignment
 * paths; on bypass paths (where()->update(), upsert(), raw SQL, relation
 * pivots) the engine CHECK raises Laravel's QueryException (§2.3).
 *
 * @api Supported public surface (AID-1527).
 *
 * @implements CastsAttributes<FixedDecimal|null, FixedDecimal|null>
 */
final class AichaMoneyCast implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    public function __construct(
        private readonly int $scale = 2,
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?FixedDecimal
    {
        return (new FixedDecimalCast($this->scale))->get($model, $key, $value, $attributes);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        $cents = (new FixedDecimalCast($this->scale))->set($model, $key, $value, $attributes); // NORMALIZED to scale 2 (finding 3: a scale-0 VO of '1e12' normalizes to 1e14 cents)

        if ($cents !== null) {
            MoneyDomain::assertValidMoneyCents($cents);
        }

        return $cents;
    }
}
