<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Agreement lifecycle states (ADR-015 §3.1) — the string-backed replacement
 * named by the `ServiceStatus` shim contract (ADR-015 §5).
 *
 * The values are the lowercase state strings that slice B's §4.2 migration
 * persists into `article_service_status.status` when it re-maps the legacy
 * int 0-4 of `ServiceStatus` (slice 2b left the column legacy on purpose).
 * Until that migration lands, this enum is ADDITIVE surface only: nothing
 * existing reads or writes it, and the `status` cast stays `ServiceStatus`.
 *
 * The §3.1 transition map rides on this enum (`canTransitionTo()`);
 * the close/suspend API that enforces it lives on `ArticleServiceStatus`
 * (this slice, ADR-015 §3.2).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum AgreementStatus: string
{
    case PENDING   = 'pending';
    case ACTIVE    = 'active';
    case SUSPENDED = 'suspended';
    case GUARANTEE = 'guarantee';
    case CLOSED    = 'closed';

    /**
     * The §3.1 state machine: legal targets per state, INCLUDING the illegal
     * pairs by omission. `closed` is final (no edge leaves it); `pending`
     * can only be provisioned (`active`) or closed; `guarantee` can only be
     * resolved by the recorded refund (→ `closed`); `active` may suspend,
     * close or enter the guarantee window. The map is pinned by
     * tests/Unit/Models/ArticleServiceStatusStateMachineTest.php — illegal
     * transitions throw `IllegalAgreementTransitionException` naming the
     * transition and the legal targets.
     */
    private const TRANSITIONS = [
        self::PENDING->value   => [self::ACTIVE, self::CLOSED],
        self::ACTIVE->value    => [self::SUSPENDED, self::CLOSED, self::GUARANTEE],
        self::SUSPENDED->value => [self::ACTIVE, self::CLOSED],
        self::GUARANTEE->value => [self::CLOSED],
        self::CLOSED->value    => [],
    ];

    /**
     * Whether this state has a legal edge to the target (ADR-015 §3.1).
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->value], true);
    }

    /**
     * The legal targets from this state — empty for a final state.
     *
     * @return array<self>
     */
    public function legalTargets(): array
    {
        return self::TRANSITIONS[$this->value];
    }
}
