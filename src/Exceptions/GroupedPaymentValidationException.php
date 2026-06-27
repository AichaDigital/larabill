<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use Exception;

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
}
