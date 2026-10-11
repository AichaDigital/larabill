<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

/**
 * A legacy `BillingFrequency` input reaches the deprecated shim layer with a
 * cadence the ADR-015 agreement model cannot represent.
 *
 * The ONE qualified narrowing of STABILITY.md §3 (operator decision, gate
 * rounds 2-3 of AID-953/949/952): `WEEKLY` (1) and `BIWEEKLY` (2) shim inputs
 * emit `E_USER_DEPRECATED` and throw this exception instead of forwarding.
 * The narrowing is measured, not aesthetic: zero instances in the migration
 * corpus (AID-895 survey, 2026-08-12), the cadence domain is months 1-12 /
 * years 1-10 by design (ADR-015 §2.4), and the §4.3 migration preflight
 * loud-aborts any weekly or biweekly row — so no 7.0 installation can hold
 * such data. `UPGRADE-7.0.md` documents it as the single case where the
 * deprecated surface narrows instead of forwarding; no other shim narrows.
 *
 * Extends `InvalidBillingIntervalException` on purpose (is-a compatibility):
 * every legacy-typed path funnels through
 * `BillingInterval::fromLegacyFrequency()`, whose catch-sites were written
 * against the plain bridge exception and keep working.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class LegacyCadenceUnsupportedException extends InvalidBillingIntervalException
{
    public static function forLegacyFrequency(int $frequency): self
    {
        return new self(sprintf(
            'Legacy billing frequency %d (weekly/biweekly cadence) cannot be forwarded to the ADR-015 '
            .'agreement model: the cadence domain is months 1-12 and years 1-10, and weekly/biweekly '
            .'are unrepresentable by design. Measured imperative: zero instances in the migration '
            .'corpus (AID-895 survey, 2026-08-12), and the migration preflight loud-aborts any weekly '
            .'or biweekly row, so no 7.0 installation can hold such data. Remediation: express the '
            .'cadence as a month/year BillingInterval, or migrate the record to a null cadence '
            .'(one-time). See UPGRADE-7.0.md.',
            $frequency
        ));
    }
}
