<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Models\Invoice;

/**
 * The currency an invoice is denominated in, for the collection-side paths
 * (payments, applied credit, outstanding). The PDF template data is out of
 * scope: it still carries a fixed currency (spec 2026-11-04 §4).
 *
 * An ISSUED invoice whose `fiscal_snapshot` carries a currency owns that
 * currency: issue() froze it, and a later edit of the referenced
 * CompanyFiscalConfig does not re-denominate the document. The guarantee
 * covers ONLY those invoices. A row that is not yet issued, or a legacy
 * issued row whose snapshot is missing or carries no currency, falls back to
 * the referenced configuration exactly as before 9.3.1 — its currency still
 * follows an edit of that configuration.
 *
 * Emission (what currency a NEW invoice is issued in) does not read this:
 * that stays the issuer configuration at issue time.
 *
 * @internal Not part of the supported surface (docs/api-surface.md).
 */
final class DocumentCurrency
{
    /**
     * ISSUED with a frozen snapshot currency → that currency. Otherwise (not
     * yet issued, or a legacy issued row with no snapshot currency) → the
     * referenced CompanyFiscalConfig's currency; null when it references none.
     *
     * The fallback returns the configuration value raw, exactly as the
     * collection paths read it before 9.3.1: an empty string stays an empty
     * string (a currency that matches no payment), not "no currency".
     *
     * The fallback reads the relation as loaded on the instance (lazy-loading
     * it when it is not), so a caller holding a locked row decides from what
     * it loaded under its locks.
     */
    public static function of(Invoice $invoice): ?string
    {
        $frozen = self::frozen($invoice);
        if ($frozen !== null) {
            return $frozen;
        }

        $configured = $invoice->companyFiscalConfig?->currency;

        return is_string($configured) ? $configured : null;
    }

    /** The currency frozen in the snapshot of an ISSUED invoice, or null when there is none. */
    public static function frozen(Invoice $invoice): ?string
    {
        if ($invoice->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
            return null;
        }

        $frozen = $invoice->getFiscalSnapshotData()['currency'] ?? null;

        return is_string($frozen) && $frozen !== '' ? $frozen : null;
    }
}
