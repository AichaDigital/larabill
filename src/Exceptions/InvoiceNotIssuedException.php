<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;

/**
 * A fiscal document that has not been issued (document status other than
 * ISSUED) was sent to PDF generation.
 *
 * ADR-016 §7.1 (AID-1686, from the clientes consumer AID-1670): an unissued
 * fiscal document carries NULL on all five number/issuance fields (§8.2 C4),
 * and every shipped fiscal template reads `invoice_date->format()` unguarded
 * — rendering one died inside the Blade with `Call to a member function
 * format() on null`. `DomPDFService::generatePDF()` now fails closed BEFORE
 * rendering, naming the remedy; the consumer decides what to show (a draft
 * preview template is a separate product decision, not this exception's job).
 *
 * Proforma rows are NOT affected: their document axis does not exist
 * (§8.2 C2), their issuance fields are NOT NULL (C3), and their template is
 * chosen by the serie — the guard only covers fiscal series.
 *
 * Note the vocabulary distinction: `InvoiceNotIssuableException` is for a
 * document that can NEVER reach issuance (issue() path); this one is for a
 * document that has not been issued YET (PDF path) — issuance may still be
 * pending, legal on it.
 *
 * Extends `RuntimeException` (domain state, not argument shape).
 *
 * @api New surface (AID-1686).
 */
final class InvoiceNotIssuedException extends \RuntimeException
{
    public static function notIssued(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice [%s] cannot be rendered: it has not been issued yet (document status %s, no fiscal number or issuance date). Remedy: issue the document first (InvoiceService::issue(), ADR-016 §6), or gate the download on it (the clientes policy denies until issued).',
            (string) $invoice->getKey(),
            $invoice->invoice_document_status?->label() ?? 'NULL',
        ));
    }
}
