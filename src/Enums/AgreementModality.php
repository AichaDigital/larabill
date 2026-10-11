<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Agreement modality (ADR-015 §2.1) — what KIND of contract the agreement
 * row carries, independent of its lifecycle state (`AgreementStatus`).
 *
 *   - `recurring`: a cadence-bearing agreement the recurrent engine bills
 *     on schedule (the default — and the backfill for every existing
 *     cadence-bearing row, §4.1);
 *   - `one_time`: emitted exactly once AT CONTRACTING; the NULL cadence is
 *     outside the recurrent engine's domain (§3.2 tail — it refuses loud
 *     if one ever reaches it);
 *   - `non_billable`: never emits at all. NOT derivable from legacy data —
 *     it exists for the consumer/importer (§2.5 Free Account) to set; no
 *     backfill invents it.
 *
 * The persisted invariant `one_time ⟺ NULL cadence` is established by the
 * `modality` migration (derived from the slice-2b cadence columns) and kept
 * by the contracting/import paths; no DB constraint enforces the pair, so
 * the emission gate treats a NULL cadence as unschedulable regardless of
 * the stored value.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum AgreementModality: string
{
    case RECURRING    = 'recurring';
    case ONE_TIME     = 'one_time';
    case NON_BILLABLE = 'non_billable';
}
