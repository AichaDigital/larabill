<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The consumer opted into requiring a recurring emission hook
 * (`larabill.recurring_billing.require_emission_hook` = true) but no
 * RecurringEmissionHookContract binding was found.
 *
 * Thrown BEFORE the billing loop starts: nothing is emitted and no fiscal
 * number is consumed. This is the "no handler → typed failure BEFORE
 * issuing" guard for installations whose compliance path (e.g. Verifactu
 * registration) runs inside the emission boundary and must never be
 * silently skipped. The gate does not apply to dry runs.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingRecurringEmissionHookException extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'larabill.recurring_billing.require_emission_hook is enabled but no '.
            'RecurringEmissionHookContract implementation is bound. Bind your hook '.
            '(fiscal registration, OSS accumulation, ...) in the container, or set '.
            'the config key to false if recurring emission needs no in-boundary integration.'
        );
    }
}
