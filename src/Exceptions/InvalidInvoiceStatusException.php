<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\InvoiceStatus;
use InvalidArgumentException;

/**
 * The `status` handed to an invoice-issuing call is not an `InvoiceStatus`.
 *
 * Until AID-838 this input was guessed rather than validated: four of the
 * seven case names were mapped and every other string — `'sent'`,
 * `'overdue'`, `'converted'`, and any typo — silently became DRAFT, so a
 * consumer got a draft it never asked for and no warning that its intent had
 * been discarded. Integers were not checked at all, which pushed the failure
 * down into Eloquent's enum cast as a raw `ValueError`.
 *
 * Extends `InvalidArgumentException` on purpose: consumers already catching
 * that keep working, while anyone who wants to tell this apart from other bad
 * arguments can catch the specific type.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvalidInvoiceStatusException extends InvalidArgumentException
{
    public static function forValue(string|int $status): self
    {
        return new self(sprintf(
            "Invalid invoice status '%s'. Accepted: the case name (%s), case-insensitive, or its integer value.",
            (string) $status,
            implode(', ', self::acceptedNames())
        ));
    }

    /**
     * Derived from the enum, never listed by hand: a parallel list is what
     * fell out of sync and produced the silent DRAFT fallback.
     *
     * @return array<int, string>
     */
    private static function acceptedNames(): array
    {
        return array_map(
            static fn (InvoiceStatus $case): string => mb_strtolower($case->name),
            InvoiceStatus::cases()
        );
    }
}
