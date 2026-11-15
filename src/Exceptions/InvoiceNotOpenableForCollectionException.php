<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * The named C1 transition refused an invoice (AID-1997 spec §3, D5).
 *
 * `InvoiceService::openForCollection()` opens an ISSUED document whose
 * collection axis sits at DRAFT for settlement allocation. Everything else
 * refuses: a non-ISSUED document, a proforma, an invoice carrying a live
 * voucher claim or a non-terminal one-off reservation — their own
 * settlement flow is the only writer of the collection axis.
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
final class InvoiceNotOpenableForCollectionException extends RuntimeException
{
    public static function forNonIssued(Invoice $invoice): self
    {
        $axis = $invoice->invoice_document_status === null ? 'null' : $invoice->invoice_document_status->value;

        return new self(
            "Invoice [{$invoice->id}] is not an ISSUED document (document axis: {$axis}) ".
            'and cannot be opened for collection: issue it first.'
        );
    }

    public static function forProforma(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->id}] is a proforma and cannot be opened for collection: ".
            'convert it to a fiscal invoice first.'
        );
    }

    public static function forVoucherSale(Invoice $invoice, string $claimId): self
    {
        return new self(
            "Invoice [{$invoice->id}] carries an open voucher sale claim [{$claimId}]: ".
            'the sale\'s own settlement is the only writer of its collection axis.'
        );
    }

    public static function forReservation(Invoice $invoice, string $reservationId): self
    {
        return new self(
            "Invoice [{$invoice->id}] carries a non-terminal one-off sale reservation [{$reservationId}]: ".
            'the sale\'s settle() is the only writer of its collection axis.'
        );
    }
}
