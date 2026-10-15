<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * InvoiceDocumentStatus Enum (ADR-016 §5.1)
 *
 * The DOCUMENT axis of an invoice — where the row stands in the numbering
 * lifecycle. It is deliberately SEPARATE from `InvoiceStatus` (the
 * collection/fossil axis), which keeps existing for payment state (§5.2).
 *
 * The axis exists ONLY for fiscal rows (INVOICE, SIMPLIFIED, RECTIFICATIVE):
 * proforma rows carry NULL on this column and on every lifecycle column —
 * the bidirectional coherence is database-enforced on MySQL (named CHECK,
 * ADR-016 §8.2) and application-enforced on every engine (`Invoice`
 * saving guard, shipped in the same PR).
 *
 * ```text
 * DRAFT ──▶ PREPARED ──issue()──▶ ISSUED   (terminal)
 *   │           │
 *   └───────────┴──▶ CANCELLED          (only before issuance)
 * ```
 *
 * R1 (PR-1) ships the enum and the column with the transitional stamping;
 * `issue()` / `cancelPreparedInvoice()` / the two-phase `createInvoice()`
 * arrive in PR-2 (AID-1627).
 *
 * @method string label() Get human-readable label
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum InvoiceDocumentStatus: int
{
    case DRAFT     = 0; // Direct-invoice birth state; editable; no number (PR-2)
    case PREPARED  = 1; // Conversion-born; verbatim proforma clone; no number (PR-2)
    case ISSUED    = 2; // Terminal; numbered; fiscally immutable (PR-2 guards)
    case CANCELLED = 3; // Terminal; never numbered; reachable from DRAFT/PREPARED only

    /**
     * The integer values pinned by the ADR-016 §8.2 CHECK (migration
     * `backfill_invoice_document_status`). The clause set hand-writes these
     * four integers in SQL, where enums cannot travel — this test-guarded
     * mapping is the single reconciliation point between the two.
     *
     * @return array<int, int>
     */
    public static function checkDomainValues(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => 'draft',
            self::PREPARED  => 'prepared',
            self::ISSUED    => 'issued',
            self::CANCELLED => 'cancelled',
        };
    }

    /**
     * Whether this state carries a fiscal correlative (numbered).
     * Only ISSUED rows are numbered; the §8.2 CHECK binds the five
     * number/issuance fields to exactly this state on fiscal rows.
     */
    public function isNumbered(): bool
    {
        return $this === self::ISSUED;
    }
}
