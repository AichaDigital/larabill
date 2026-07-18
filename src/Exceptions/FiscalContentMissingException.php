<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * A fiscal invoice was rendered by a template that dropped mandatory fiscal
 * content the data layer handed to it.
 *
 * This is the safe-restyle guarantee of ADR-011 (AID-502): presentation may
 * restyle everything, but it may not omit or rewrite fiscal values — a PDF
 * missing its number, dates, party identification or tax breakdown is not a
 * degraded invoice, it is not an invoice (RD 1619/2012 arts. 6/7). Consumer
 * templates that cannot pass this gate must be fixed, or the installation must
 * consciously assume the risk via `larabill.pdf.validate_fiscal_content`.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class FiscalContentMissingException extends RuntimeException
{
    /**
     * @param  list<string>  $missing  Field keys absent from the rendered output
     */
    public static function forInvoice(Invoice $invoice, array $missing): self
    {
        return new self(
            "Invoice {$invoice->fiscal_number}: the rendered PDF output is missing mandatory "
            .'fiscal content: ['.implode(', ', $missing).'] — a template must print every '
            .'fiscal datum it is handed (ADR-011). Fix the template, or disable '
            .'larabill.pdf.validate_fiscal_content to assume the risk.'
        );
    }
}
