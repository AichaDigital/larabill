<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * A fiscal invoice reached PDF generation without a usable fiscal verification
 * QR, while this installation declares it mandatory
 * (`larabill.pdf.require_fiscal_verification_qr`).
 *
 * Absence and loss are not the same thing: an installation outside VeriFACTU
 * legitimately has no QR and renders without the tax block. With the contract
 * switched on, a missing record — or a value that is not a usable image — is a
 * LOST datum, and the document must not be produced (AID-508). The alternative
 * is what this ticket exists to remove: emitting a plausible invoice that lies.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingFiscalVerificationQrException extends RuntimeException
{
    public static function forInvoice(Invoice $invoice): self
    {
        return new self(
            "Invoice {$invoice->fiscal_number} is a fiscal document without a usable fiscal "
            .'verification QR, and larabill.pdf.require_fiscal_verification_qr is enabled — '
            .'refusing to produce a tax document without its QR.'
        );
    }
}
