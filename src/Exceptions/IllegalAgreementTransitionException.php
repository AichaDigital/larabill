<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\AgreementStatus;
use InvalidArgumentException;

/**
 * An agreement transition is illegal under the ADR-015 §3.1 state machine.
 *
 * Raised by the close/suspend API (`ArticleServiceStatus::close()` /
 * `::suspend()` / `::activate()` / `::cancelScheduledClose()`) when the
 * current state has no legal edge to the requested target — e.g. leaving
 * `closed` (a final state), suspending a `pending` agreement, or
 * reinstating a `guarantee` agreement. The message names the transition
 * and lists the legal targets so the caller can act, never guess.
 *
 * Extends `InvalidArgumentException` on purpose: consumers already catching
 * that keep working, while anyone who wants to tell this apart from other
 * bad arguments can catch the specific type.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class IllegalAgreementTransitionException extends InvalidArgumentException
{
    public static function transition(AgreementStatus $from, AgreementStatus $to): self
    {
        $targets = $from->legalTargets();

        return new self(sprintf(
            "Illegal agreement transition %s → %s (ADR-015 §3.1 state machine). Legal targets from '%s': %s. "
            .'Remediation: reinstate, close or settle the agreement through the close/suspend API before '
            .'requesting this transition — no silent reinterpretation.',
            $from->value,
            $to->value,
            $from->value,
            $targets === [] ? 'none — this state is final' : implode(', ', array_map(fn (AgreementStatus $t) => $t->value, $targets)),
        ));
    }

    public static function requiresActive(AgreementStatus $current): self
    {
        return new self(sprintf(
            "The operation requires an ACTIVE agreement, but this agreement is '%s' (ADR-015 §3.1). "
            .'Remediation: only active agreements support it — reinstate or settle the agreement first.',
            $current->value,
        ));
    }

    /**
     * A SCHEDULED close (a future effective date) was requested from a
     * non-active state (AID-1593). The materialiser only processes rows in
     * `active`, so a schedule planted from `suspended`/`pending`/`guarantee`
     * would never fire — a zombie close that keeps the agreement open
     * forever. Refused loud instead; the immediate close keeps working from
     * every state the §3.1 transition map allows.
     */
    public static function scheduledCloseFromNonActive(AgreementStatus $current): self
    {
        return new self(sprintf(
            "A scheduled close requires an ACTIVE agreement, but this agreement is '%s' (ADR-015 §3.1/§3.2). "
            .'A close_scheduled_at planted from a non-active state would never be materialised — the scheduler '
            .'only processes ACTIVE rows. Remediation: reinstate the agreement first and then schedule the close, '
            .'or close immediately (omit or pass a past effectiveAt), which is legal from every state the '
            .'§3.1 transition map allows.',
            $current->value,
        ));
    }
}
