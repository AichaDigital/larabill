<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * The invoice cannot be annulled.
 *
 * ADR-014: an annulment ("the invoice should never have existed") is
 * registered as an AEAT `RegistroAnulacion` — it produces NO new document
 * and only cancels the original. Only an emitted, never-annulled fiscal
 * invoice with no issued rectificatives qualifies: a draft was never
 * declared, a proforma is not a fiscal document, a cancelled invoice is
 * already annulled (one annulment per invoice, the lara-verifactu AID-726
 * analogue), and annulling an invoice that carries issued rectificatives
 * would contradict "it never existed" — the consumer resolves those first.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvoiceNotAnnullableException extends RuntimeException
{
    public static function forDraft(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] is a DRAFT and cannot be annulled: ".
            'a draft was never declared — there is nothing to annul. Delete the draft instead.'
        );
    }

    public static function forProforma(Invoice $invoice): self
    {
        return new self(
            "Document [{$invoice->fiscal_number}] is a proforma and cannot be annulled: ".
            'a proforma is not a fiscal document and was never declared to the AEAT.'
        );
    }

    public static function alreadyAnnulled(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] is already CANCELLED and cannot be annulled again: ".
            'one annulment per invoice (the lara-verifactu one-cancellation analogue, AID-726).'
        );
    }

    public static function hasRectificatives(Invoice $invoice, int $count): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] has {$count} issued rectificative(s) and cannot be annulled: ".
            'annulment asserts the invoice never existed, which contradicts documents correcting it. '.
            'Resolve the rectificatives first.'
        );
    }
}
