<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

/**
 * Fiscal invoice number value object.
 *
 * Immutable representation of a generated invoice number with its components.
 * Use __toString() for backward compatibility where a string was expected.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
readonly class InvoiceNumber
{
    public function __construct(
        public string $formatted,
        public string $prefix,
        public int $fiscalYear,
        public int $seriesNumber,
    ) {}

    public function __toString(): string
    {
        return $this->formatted;
    }
}
