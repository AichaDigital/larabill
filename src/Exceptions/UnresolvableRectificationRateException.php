<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * The explicit rectification amount cannot be taxed without inventing a rate.
 *
 * ADR-014: a "por diferencias" rectificative must reach the FROZEN fiscal
 * context of the invoice it corrects. When the original carries lines under
 * MORE THAN ONE tax rate, a single explicit amount has no defensible rate
 * distribution — larabill does not invent it (AID-589 doctrine: a business
 * value is never guessed). The consumer either rectifies the full original
 * (lines are cloned per rate) or issues one rectificative per affected line.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class UnresolvableRectificationRateException extends RuntimeException
{
    /**
     * @param  list<int>  $sourceRateIds  the distinct frozen rates found
     */
    public static function forInvoice(Invoice $invoice, array $sourceRateIds): self
    {
        $rates = implode(', ', $sourceRateIds);

        return new self(
            "Cannot rectify invoice [{$invoice->fiscal_number}] with an explicit amount: ".
            "its frozen lines carry multiple tax rates [{$rates}], so the difference cannot be ".
            'attributed to one rate without inventing a distribution. Rectify the full original '.
            '(null amount), or issue one rectificative per affected line.'
        );
    }
}
