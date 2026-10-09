<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * The invoice cannot be corrected by a rectificative.
 *
 * ADR-014: a rectificative corrects a fiscal document that was actually
 * EMITTED. A draft was never registered (nothing to correct), a proforma
 * is not a fiscal document at all, and an annulled document was declared
 * never to have existed — neither may carry `rectifies_invoice_id`, because
 * declaring any of them to the AEAT as R1 would misrepresent reality.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvoiceNotRectifiableException extends RuntimeException
{
    public static function forDraft(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] is a DRAFT and cannot be rectified: ".
            'a rectificative corrects an emitted document. Seal it first, or delete the draft.'
        );
    }

    public static function forProforma(Invoice $invoice): self
    {
        return new self(
            "Document [{$invoice->fiscal_number}] is a proforma and cannot be rectified: ".
            'a proforma is not a fiscal document and was never declared to the AEAT. '.
            'Convert it to an invoice first (InvoiceService::convertProformaToInvoice).'
        );
    }

    public static function forAnnulled(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] is annulled and cannot be rectified: ".
            'annulment and rectification are mutually exclusive — the annulled document '.
            'was declared never to have existed, so it cannot be corrected.'
        );
    }
}
