<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Models\Invoice;

/**
 * `cancelPreparedInvoice()` was called on a document it cannot release.
 *
 * ADR-016 §10: the audited release only applies to a conversion-born PREPARED
 * document (an aborted preparation, never numbered). Direct drafts keep the
 * existing consumer cancellation path (legacy `status = CANCELLED` + document
 * CANCELLED with actor NULL, §10 last paragraph); ISSUED documents are
 * terminal and have their own correction artifacts (ADR-014).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvoiceNotCancellableException extends \RuntimeException
{
    public static function forDocumentStatus(Invoice $invoice, ?InvoiceDocumentStatus $documentStatus): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be released through cancelPreparedInvoice(): its document status is %s (only PREPARED is releasable; direct drafts use the consumer cancellation path and ISSUED documents are terminal, ADR-016 §10).',
            (string) $invoice->getKey(),
            $documentStatus?->label() ?? 'NULL',
        ));
    }

    public static function notConversionBorn(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be released through cancelPreparedInvoice(): it is not conversion-born (no proforma link). A direct draft is cancelled through the consumer cancellation path (ADR-016 §10).',
            (string) $invoice->getKey(),
        ));
    }
}
