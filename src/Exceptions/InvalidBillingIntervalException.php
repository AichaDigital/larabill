<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use InvalidArgumentException;

/**
 * A billing interval input is outside the ADR-015 cadence domain.
 *
 * Raised by `BillingInterval` for a unit other than `month`/`year`, a
 * quantity outside 1-12 (months) or 1-10 (years), and — through
 * `fromLegacyFrequency()` — by legacy `BillingFrequency` inputs the new
 * domain cannot represent: `WEEKLY`/`BIWEEKLY` (retired by ADR-015, zero
 * instances in the migration corpus per the AID-895 survey) and any value
 * outside 0-9. ADR-015 §5 records this narrowing as the qualified exception
 * of STABILITY.md §3.
 *
 * Extends `InvalidArgumentException` on purpose: consumers already catching
 * that keep working, while anyone who wants to tell this apart from other bad
 * arguments can catch the specific type.
 *
 * NOT `final` by design: `LegacyCadenceUnsupportedException` — the ONE
 * qualified STABILITY.md §3 narrowing of the 7.0 shim layer — is-a this
 * exception so every existing catch-site of the bridge keeps working.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class InvalidBillingIntervalException extends InvalidArgumentException
{
    public static function forUnit(string $unit): self
    {
        return new self(sprintf(
            "Invalid billing interval unit '%s'. Accepted values: 'month' or 'year' (lowercase) — the ADR-015 cadence domain.",
            $unit
        ));
    }

    public static function forQuantity(int $quantity, string $unit): self
    {
        return new self(sprintf(
            "Invalid billing interval quantity %d for unit '%s'. Valid domain: 1-12 when unit is 'month', 1-10 when unit is 'year' (ADR-015 cadence domain). Remediation: pick a quantity inside the domain or change the unit.",
            $quantity,
            $unit
        ));
    }

    public static function forLegacyFrequency(int $frequency): self
    {
        return new self(sprintf(
            'Legacy billing frequency %d cannot be represented by BillingInterval. Accepted: 0 (ONE_TIME — null cadence) and 3-9 (month/year cadences). WEEKLY (1) and BIWEEKLY (2) were retired by ADR-015 (zero instances in the migration corpus); values outside 0-9 are out of the legacy domain. Remediation: map the cadence to a month/year interval or migrate the record to a null cadence (one-time).',
            $frequency
        ));
    }
}
