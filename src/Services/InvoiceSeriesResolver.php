<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use InvalidArgumentException;

/**
 * Resolves the real fiscal SERIES identifier for a given fiscal TYPE.
 *
 * AID-307 separates the fiscal TYPE (`InvoiceSerieType` → AEAT `TipoFactura`
 * F1/F2/R1) from the fiscal SERIES (a consumer-chosen string that becomes the
 * AEAT `NumSerieFactura` series component, stored in `invoices.prefix`). This
 * resolver is the single source of truth for that series, consolidating the
 * three formerly-disconnected sources into one cascade:
 *
 *   1. An explicit series requested by the caller — the "eligible, not
 *      opinionated" lever: a consumer runs multiple series for the same fiscal
 *      type (RD 1619/2012 art. 6) by passing `invoiceData['series']`.
 *   2. `config('larabill.invoice_numbering.series.{type}')`.
 *   3. Legacy config `invoice_prefix` / `proforma_prefix`, so a v4 consumer
 *      that upgrades without re-publishing its config keeps working.
 *   4. The type's built-in default prefix (FAC/PRO/TIK/RECT).
 *
 * The series must be a non-empty string of at most 10 characters (the width of
 * the `prefix` column). An explicitly requested series that is too long fails
 * loud; an empty request falls through to configuration.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvoiceSeriesResolver
{
    private const MAX_LENGTH = 10;

    /**
     * Resolve the fiscal series identifier for a fiscal type.
     *
     * @param  string|null  $requested  Optional explicit series chosen by the caller.
     *
     * @throws InvalidArgumentException When an explicitly requested series exceeds 10 characters.
     */
    public function resolve(InvoiceSerieType $type, ?string $requested = null): string
    {
        $explicit = $requested !== null ? trim($requested) : '';

        if ($explicit !== '') {
            return $this->assertFits($explicit);
        }

        return $this->assertFits($this->fromConfig($type) ?? $type->defaultPrefix());
    }

    private function fromConfig(InvoiceSerieType $type): ?string
    {
        $configured = config('larabill.invoice_numbering.series.'.$type->label());

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $legacyKey = match ($type) {
            InvoiceSerieType::PROFORMA => 'proforma_prefix',
            InvoiceSerieType::INVOICE  => 'invoice_prefix',
            default                    => null,
        };

        if ($legacyKey !== null) {
            $legacy = config('larabill.invoice_numbering.'.$legacyKey);

            if (is_string($legacy) && trim($legacy) !== '') {
                return trim($legacy);
            }
        }

        return null;
    }

    private function assertFits(string $series): string
    {
        if (mb_strlen($series) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "Fiscal series '{$series}' exceeds the maximum length of ".self::MAX_LENGTH.' characters.'
            );
        }

        return $series;
    }
}
