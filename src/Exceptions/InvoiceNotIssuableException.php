<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Models\Invoice;

/**
 * `issue()` was called on a document that cannot be issued.
 *
 * ADR-016 §6: issuance is only legal from a DRAFT (direct) or PREPARED
 * (conversion-born) document that is not cancelled, and only when the row's
 * fiscal coherence holds. Everything else is a typed refusal naming the
 * remedy (§6 failure taxonomy — domain-invalid, not transient).
 *
 * Note the deliberate asymmetry with the idempotent path: an ALREADY-ISSUED
 * invoice does NOT throw — `issue()` returns it unchanged (spec §6 step 1,
 * round 3 finding S3: idempotency first, eligibility second). This exception
 * is only for rows that can never reach issuance.
 *
 * Extends `RuntimeException` (domain state, not argument shape).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvoiceNotIssuableException extends \RuntimeException
{
    public static function cancelled(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice [%s] has document status CANCELLED and can never be issued. Remedy: create a new document (a cancelled document is terminal, ADR-016 §5.1).',
            (string) $invoice->getKey(),
        ));
    }

    public static function forDocumentStatus(Invoice $invoice, ?InvoiceDocumentStatus $documentStatus): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be issued: its document status is %s (eligible states are DRAFT for direct documents and PREPARED for conversion-born ones). Proforma rows are invisible to the document axis and have no issuance path (ADR-016 §6).',
            (string) $invoice->getKey(),
            $documentStatus?->label() ?? 'NULL',
        ));
    }

    public static function missingFiscalContext(Invoice $invoice, string $what): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be issued: %s is missing on the row. Remedy: recreate the document with a complete fiscal context (ADR-016 §6 step 2).',
            (string) $invoice->getKey(),
            $what,
        ));
    }
}
