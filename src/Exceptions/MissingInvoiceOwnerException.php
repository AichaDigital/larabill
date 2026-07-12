<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\Invoice;
use RuntimeException;

/**
 * An invoice reached owner-scoped accounting (e.g. the EU OSS sales threshold)
 * without an owner (`user_id`). The schema declares the column NOT NULL, so a
 * null owner here means a corrupt or unsaved row — attributing the amount to a
 * fabricated fallback owner would be silent corruption of the threshold ledger
 * (AID-391: the old code fell back to config('larabill.company.id', '1'), a
 * key that does not exist and a value that is not even a UUID).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingInvoiceOwnerException extends RuntimeException
{
    public static function forInvoice(Invoice $invoice): self
    {
        return new self(
            "Invoice {$invoice->fiscal_number} has no owner (user_id) — refusing to attribute "
            .'EU sales threshold amounts to a fabricated owner.'
        );
    }
}
