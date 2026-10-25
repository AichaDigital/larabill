<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\CreditMovementKind;
use Exception;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class GroupedPaymentValidationException extends Exception
{
    public static function emptyInvoiceList(): self
    {
        return new self('Grouped payment requires at least one invoice.');
    }

    /** @param array<int, string> $ids */
    public static function duplicateInvoices(array $ids): self
    {
        return new self('Grouped payment invoice list contains duplicate ids: '.implode(', ', $ids));
    }

    public static function mixedUsers(): self
    {
        return new self('All invoices in a grouped payment must share the same billable_user_id.');
    }

    public static function currencyMismatch(string $invoiceId, string $expected, string $got): self
    {
        return new self("Invoice {$invoiceId} currency {$got} does not match the payment currency {$expected}.");
    }

    public static function proformaNotPayable(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} is a proforma and cannot be settled by a grouped payment.");
    }

    public static function notPayableStatus(string $invoiceId, int $status): self
    {
        return new self("Invoice {$invoiceId} has status {$status}; only SENT/OVERDUE/PENDING invoices are payable.");
    }

    public static function alreadyActivelyPaid(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} is already covered by an active grouped payment.");
    }

    public static function amountMismatch(int $expectedUnscaled, int $gotUnscaled): self
    {
        return new self("Grouped payment amount mismatch: expected sum {$expectedUnscaled}, got {$gotUnscaled} (base-100).");
    }

    /** @param array<int, string> $ids */
    public static function invoicesNotFound(array $ids): self
    {
        return new self('Grouped payment references invoices that do not exist: '.implode(', ', $ids));
    }

    public static function notIssued(string $invoiceId): self
    {
        return new self("Invoice {$invoiceId} is not issued; only issued invoices can receive payment allocations.");
    }

    public static function notPayableAmount(string $invoiceId, int $totalUnscaled): self
    {
        return new self("Invoice {$invoiceId} has total {$totalUnscaled} (base-100); only invoices with a positive total are payable.");
    }

    public static function nonPositiveAmount(int $unscaled): self
    {
        return new self("Payment amount {$unscaled} (base-100) must be strictly positive.");
    }

    public static function subCentAmount(string $amount): self
    {
        return new self("Payment amount {$amount} is sub-cent: amounts must be exact to the cent.");
    }

    public static function creditKindNotAnEntry(CreditMovementKind $kind): self
    {
        return new self("Credit movement kind {$kind->name} is not an entry; only SURPLUS or TOP_UP can originate from a payment.");
    }
}
