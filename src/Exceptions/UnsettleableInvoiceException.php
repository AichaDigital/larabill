<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * The invoice cannot be settled with a credit line in a new invoice.
 *
 * ADR-014: a settlement liquidates the unconsumed value of a contract whose
 * invoice was actually EMITTED and still exists. A draft was never emitted
 * (nothing to settle), a proforma is not a fiscal document, and an annulled
 * invoice never existed — there is nothing to liquidate for any of them.
 * The consumer chooses the artifact; larabill only refuses to express one
 * against a document that cannot carry it.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class UnsettleableInvoiceException extends RuntimeException
{
    public static function forDraft(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] is a DRAFT and cannot be settled: ".
            'a settlement liquidates value of an emitted document. Seal it first, or delete the draft.'
        );
    }

    public static function forProforma(Invoice $invoice): self
    {
        return new self(
            "Document [{$invoice->fiscal_number}] is a proforma and cannot be settled: ".
            'a proforma is not a fiscal document and was never declared to the AEAT. '.
            'Convert it to an invoice first (InvoiceService::convertProformaToInvoice).'
        );
    }

    public static function forAnnulled(Invoice $invoice): self
    {
        return new self(
            "Invoice [{$invoice->fiscal_number}] is annulled and cannot be settled: ".
            'an annulled invoice never existed (ADR-014 RegistroAnulacion) and has nothing to liquidate.'
        );
    }

    public static function creditArticleNotInOriginal(Invoice $invoice, int $creditArticleId): self
    {
        return new self(
            "Article [{$creditArticleId}] cannot price the credit line of invoice [{$invoice->fiscal_number}]: ".
            "the credit line's tax context must come from an article actually present in the invoice being settled."
        );
    }

    /**
     * @param  array<int, int|null>  $groupIds  the distinct current tax groups
     *                                          spanned by the invoice's lines (null = untaxed article)
     */
    public static function ambiguousCreditArticle(Invoice $invoice, array $groupIds): self
    {
        $ids = implode(', ', array_map(
            fn (?int $groupId): string => $groupId === null ? 'NULL' : (string) $groupId,
            $groupIds
        ));

        return new self(
            "Invoice [{$invoice->fiscal_number}] spans more than one tax group ({$ids}) and cannot be ".
            "settled without an explicit article: the consumer must choose the credit line's article ".
            'explicitly — larabill does not invent the distribution (AID-589 doctrine).'
        );
    }
}
