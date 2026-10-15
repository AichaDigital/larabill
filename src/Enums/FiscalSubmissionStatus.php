<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * FiscalSubmissionStatus Enum (ADR-016 §5.3)
 *
 * The fiscal SUBMISSION axis — the state of this document's registration with
 * the invoicing-record system (Veri*FACTU via lara-verifactu). Set at issuance
 * ONLY (the §8.2 CHECK binds it to `invoice_document_status = ISSUED` on
 * fiscal rows); the outbox, handler and typed submission outcomes ship with
 * R2 (AID-1629, AID-1634).
 *
 * R1 writes only `PENDING` / `NOT_REQUIRED` (at issuance) and the backfill
 * value `LEGACY_UNKNOWN`; the remaining transitions arrive with R2.
 *
 * @method string label() Get human-readable label
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum FiscalSubmissionStatus: int
{
    case NOT_REQUIRED     = 0; // Config-derived: the operation needs no fiscal submission
    case PENDING          = 1; // Issued, submission record not yet sent (outbox, R2)
    case REGISTERED       = 2; // Accepted by the fiscal system (R2)
    case ACTION_REQUIRED  = 3; // System demands operator action (R2)
    case CONTENT_REJECTED = 4; // System rejected the record content (R2)
    case LEGACY_UNKNOWN   = 5; // Backfill value: pre-ADR-016 row, no submission state was recorded

    /**
     * The integer values pinned by the ADR-016 §8.2 CHECK (migration
     * `backfill_invoice_document_status`). The clause set hand-writes these
     * six integers in SQL, where enums cannot travel — this test-guarded
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
            self::NOT_REQUIRED     => 'not_required',
            self::PENDING          => 'pending',
            self::REGISTERED       => 'registered',
            self::ACTION_REQUIRED  => 'action_required',
            self::CONTENT_REJECTED => 'content_rejected',
            self::LEGACY_UNKNOWN   => 'legacy_unknown',
        };
    }
}
