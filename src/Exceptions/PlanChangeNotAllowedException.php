<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A plan change (ADR-015 §3.5) was requested for an agreement that is not
 * eligible, or with a policy value outside the declared domain.
 *
 * Raised by `PlanChangeService::changePlan()` for every refusal the spec
 * establishes — each case typed and named, never a silent reinterpretation:
 *
 * - `suspended` → the agreement must be reinstated (or closed) first;
 * - `guarantee` → the agreement completes its refund first;
 * - scheduled close (`close_scheduled_at` present) → the close materialises
 *   or `ArticleServiceStatus::cancelScheduledClose()` runs first (§3.2);
 * - `align_end` on an auto-renewing agreement → an auto agreement has no
 *   previous end date to align to (gate round 10 P2);
 * - unknown policy value → the only two policies are `restart` and
 *   `align_end` (§3.5 config contract).
 *
 * The `closed` state is NOT a refusal: re-invoking against an already-closed
 * agreement is the idempotent no-op of the atomic boundary (gate round 6
 * P1) — `changePlan()` returns null for it.
 *
 * Extends `InvalidArgumentException` on purpose: consumers already catching
 * that keep working, while anyone who wants to tell this apart from other
 * bad arguments can catch the specific type.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class PlanChangeNotAllowedException extends InvalidArgumentException
{
    public static function suspended(ArticleServiceStatus $agreement): self
    {
        return new self(sprintf(
            'Agreement [%s] is SUSPENDED and cannot be plan-changed (ADR-015 §3.5): only active agreements '
            .'with no scheduled close are plan-changed. Remediation: reinstate it (or close it) through the '
            .'close/suspend API first — no silent reinterpretation.',
            (string) $agreement->getKey(),
        ));
    }

    public static function inGuarantee(ArticleServiceStatus $agreement): self
    {
        return new self(sprintf(
            'Agreement [%s] is inside its GUARANTEE window and cannot be plan-changed (ADR-015 §3.5): the '
            .'guarantee completes its refund first. Remediation: record or confirm the refund through the '
            .'guarantee flow (the transient state resolves to closed) before requesting the plan change.',
            (string) $agreement->getKey(),
        ));
    }

    public static function closeScheduled(ArticleServiceStatus $agreement): self
    {
        /** @var CarbonInterface|null $scheduledAt */
        $scheduledAt = $agreement->close_scheduled_at;

        return new self(sprintf(
            'Agreement [%s] has a close scheduled for [%s] and cannot be plan-changed (ADR-015 §3.5): a plan '
            .'change must not overwrite a scheduled closure (gate round 7 P1 #4). Remediation: let the close '
            .'materialise, or cancel the schedule explicitly with '
            .'ArticleServiceStatus::cancelScheduledClose() (§3.2) before requesting the plan change.',
            (string) $agreement->getKey(),
            $scheduledAt?->toDateString() ?? 'unknown date',
        ));
    }

    public static function alignEndRequiresClosedTerm(ArticleServiceStatus $agreement): self
    {
        return new self(sprintf(
            'The align_end plan-change policy requires a CLOSED-TERM predecessor (renewal = closed_term with '
            .'a non-null expires_at), but agreement [%s] is auto-renewing (renewal = %s, expires_at = %s) '
            .'(ADR-015 §3.5, gate round 10 P2): an auto-renewing agreement has no previous end date to align '
            .'to. Remediation: use the restart policy for this agreement, or give it a fixed term first — '
            .'the combination is rejected, never silently reinterpreted.',
            (string) $agreement->getKey(),
            (string) $agreement->renewal,
            $agreement->expires_at?->toDateString() ?? 'null',
        ));
    }

    public static function unknownPolicy(string $value): self
    {
        return new self(sprintf(
            'Unknown plan-change policy [%s] (ADR-015 §3.5): the only declared values are "restart" (the new '
            .'plan runs its full term from the change date) and "align_end" (the successor runs only until '
            .'the predecessor term end). Fix config larabill.agreements.plan_change.policy (or the explicit '
            .'policy argument) — no silent reinterpretation of an out-of-domain value.',
            $value,
        ));
    }
}
