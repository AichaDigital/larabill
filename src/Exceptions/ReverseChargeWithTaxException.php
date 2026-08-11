<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * A document was issued declaring reverse charge (`is_roi_taxed = true`) while
 * its lines carry real tax.
 *
 * The two cannot coexist: under the reverse-charge mechanism the recipient
 * self-assesses the tax, so the issuer charges none. The AEAT states it as
 * rule 1237 — an N2 breakdown (not subject by localisation) cannot carry VAT.
 *
 * The package does NOT fix the figures for you. Line amounts are frozen fiscal
 * content (ADR-001) and `TaxCalculationService` never reads the qualification,
 * so silently zeroing the tax would rewrite what the customer accepted. Decide
 * which of the two is wrong: drop `is_roi_taxed` if the operation really is
 * taxed, or supply lines with no tax if it really is reverse charge.
 *
 * Thrown before any snapshot is generated and before the document is sealed,
 * inside the issuing transaction: no invoice row, no lines, and no consumed
 * fiscal number survive it (effects a consumer's own model observers may have
 * triggered on `creating`/`created` are NOT rolled back — bind those with
 * `afterCommit`).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ReverseChargeWithTaxException extends RuntimeException
{
    public static function forInvoice(Invoice $invoice): self
    {
        $total = $invoice->total_tax_amount?->unscaledValue() ?? 0;

        $where = $total !== 0
            ? sprintf('total tax %d (base-100)', $total)
            : 'a non-zero amount inside the lines\' taxes_applied breakdown';

        return new self(
            'Cannot issue a document with is_roi_taxed = true while its lines carry real tax: '.
            $where.'. Under reverse charge the recipient self-assesses the tax (AEAT rule 1237: '.
            'an N2 breakdown cannot carry VAT). Either clear the tax from the lines or drop the '.
            'is_roi_taxed flag — the package will not rewrite frozen line amounts for you.'
        );
    }
}
