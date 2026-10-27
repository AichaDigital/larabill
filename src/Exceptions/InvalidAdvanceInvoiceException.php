<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\TaxGroup;
use RuntimeException;

/**
 * The credit movement or tax group cannot back the requested advance invoice.
 *
 * Raised by `AdvanceInvoiceBuilder::forCreditEntry()` before any write: an
 * advance whose per-rate distribution would have to be invented (a multi-rate
 * tax group) is refused, never solved (the same doctrine as
 * `UnresolvableRectificationRateException` and
 * `UnsettleableInvoiceException::ambiguousCreditArticle`).
 *
 * Since AID-1778 S4 it is also raised by the pro-rata helpers
 * `deductionLine()`/`rectificativeLines()`: a broken or multi-rate frozen
 * snapshot of the advance, an exit whose entry is not the advance's entry,
 * an unresolvable entry position (a NULL band on an active exit), and a
 * rectificative-lines result that does not sum to the exit's take.
 *
 * @api Supported public surface (AID-1778; see docs/api-surface.md).
 */
final class InvalidAdvanceInvoiceException extends RuntimeException
{
    public static function multiRateTaxGroup(TaxGroup $group): self
    {
        return new self(sprintf(
            'Tax group [%s] carries %d rates; an advance invoice can only be built from a single-rate group — the per-rate reverse distribution of a multi-rate advance would be an invented one. Allocate the reverse base across rates explicitly (a future minor) or build the advance from a single-rate group.',
            $group->name,
            $group->taxRates->count(),
        ));
    }

    /**
     * The pro-rata helper received an exit whose consumed movement is
     * missing, is not an ENTRY, or whose entry carries no advance invoice
     * link — there is no advance to compute the pro-rata against. Loud,
     * zero writes (AID-1778 spec §5.1).
     */
    public static function notTheAdvanceEntry(string $exitId): self
    {
        return new self(sprintf(
            'Credit movement [%s] does not consume an entry linked to an advance invoice — no pro-rata can be computed. The exit was NOT written; link the consumed entry to its advance invoice first.',
            $exitId,
        ));
    }

    /**
     * The entry's position history is DECLARED UNRESOLVABLE (spec §5.7):
     * an ACTIVE exit of the entry carries a NULL `band_start`, the legacy
     * backfill's declaration that the exact band order could not be
     * reconstructed from stored data (E1+E2+E3 failed). The helpers refuse
     * loudly instead of inventing a split from an ambiguous history —
     * resolving such an entry is an operator decision over real evidence,
     * out of 8.4 scope.
     */
    public static function unresolvedEntryPosition(string $entryId): self
    {
        return new self(sprintf(
            'Credit entry [%s] is declared unresolvable: an active exit carries a NULL band_start, so no exact position history exists (AID-1778 spec §5.7). The pro-rata was NOT computed; resolving the entry requires an operator decision over real evidence.',
            $entryId,
        ));
    }

    /**
     * The advance's frozen per-rate breakdown (`taxes_applied` snapshots of
     * its lines) cannot back a pro-rata: an entry without `source_rate_id`,
     * the same rate id repeated across lines, or `taxes_applied` amounts not
     * summing to the line's `total_tax_amount` (spec §5.2, G6). Loud, zero
     * writes — a broken snapshot is refused, never guessed around.
     */
    public static function brokenAdvanceSnapshot(string $advanceId, string $reason): self
    {
        return new self(sprintf(
            'Advance invoice [%s] carries a broken frozen tax snapshot and cannot back a pro-rata: %s. The pro-rata was NOT computed; repair the advance\'s frozen breakdown first.',
            $advanceId,
            $reason,
        ));
    }

    /**
     * The advance's frozen snapshot carries two or more DISTINCT source
     * rates (spec §5.2, G6): no single rounding function f(c) = HalfUp(c·T/G)
     * exists, and attributing the band to one rate would be an invented
     * distribution. Same doctrine as the builder's multi-rate refusal —
     * refused, not solved (the TaxGroup-flavoured `multiRateTaxGroup()`
     * covers the builder side, where the group object is at hand).
     *
     * @param  list<int|string>  $sourceRateIds  the distinct rate ids found
     */
    public static function multiRateTaxGroupFromSnapshot(string $advanceId, array $sourceRateIds): self
    {
        return new self(sprintf(
            'Advance invoice [%s] carries %d distinct source rates in its frozen snapshot (%s); a pro-rata can only be computed from a single-rate advance — the per-rate attribution would be an invented one.',
            $advanceId,
            count($sourceRateIds),
            implode(', ', $sourceRateIds),
        ));
    }

    /**
     * The rectificative lines a helper built for a REFUNDED exit do not sum
     * to the exit's take: Σ |total_amount| ≠ |exit.amount| (spec §5.1, H8).
     * The identity holds by construction, so this fires only on a bug in
     * the builder's own arithmetic — a defensive assertion, never a caller
     * error (`rectify(items:)` itself stays exit-agnostic).
     */
    public static function itemsDoNotSumToExit(string $exitId, int $expectedCents, int $actualCents): self
    {
        return new self(sprintf(
            'Credit exit [%s]: the built rectificative lines sum to %d cents but the exit takes %d cents — internal arithmetic bug (Σ |total_amount| must equal |exit.amount|). Nothing was written.',
            $exitId,
            $actualCents,
            $expectedCents,
        ));
    }

    /**
     * The received document is not a fiscal INVOICE — a proforma (or any
     * non-`invoice` type) can never document a credit entry as its advance
     * (AID-1778 §5.6, J7). The KIND check runs BEFORE the DRAFT check on the
     * locked advance row: a proforma carries `invoice_document_status = NULL`,
     * so the draft check alone would refuse it without naming the real cause.
     *
     * @api Additive named constructor (AID-1778, 9.0).
     */
    public static function advanceNotAnInvoice(string $invoiceId): self
    {
        return new self(sprintf(
            'Invoice [%s] is not a fiscal INVOICE (e.g. a proforma carries no document status) and cannot document a credit entry as its advance. The link was NOT written.',
            $invoiceId,
        ));
    }

    /**
     * The advance invoice is no longer an unnumbered DRAFT: an advance is
     * linked at build time and issued afterwards (the documented consumer
     * sequence is build → link → issue, AID-1778 §5.6, L9). Since 9.0 the
     * link refuses an ISSUED, PREPARED or CANCELLED advance instead of
     * accepting it (the 8.3.0 permissive link was narrowed — see the
     * `@since 9.0` note on `CreditMovement::linkAdvanceInvoice()`).
     *
     * @api Additive named constructor (AID-1778, 9.0).
     */
    public static function advanceNotDraft(string $invoiceId): self
    {
        return new self(sprintf(
            'Advance invoice [%s] is not an unnumbered DRAFT — an advance is linked at build time and issued afterwards (build → link → issue). The link was NOT written.',
            $invoiceId,
        ));
    }

    /**
     * The take cannot be placed anywhere on its entry's cent tape: the free
     * gaps computed from the ACTIVE exits' bands total less than the take.
     * Under a consistent ledger this is unreachable (the free tape is always
     * at least the entry's remaining balance), so it fires only on broken
     * band arithmetic — loud, zero writes (AID-1778 spec §5.3).
     */
    public static function cannotPlaceExit(string $entryId, int $takeCents, int $freeCents): self
    {
        return new self(sprintf(
            'Credit entry [%s]: a take of %d cents (base-100) exceeds the %d cents of free tape left by the active exits\' bands — broken ledger arithmetic. The exit was NOT written; repair the ledger before consuming this entry.',
            $entryId,
            $takeCents,
            $freeCents,
        ));
    }
}
