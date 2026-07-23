<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use RuntimeException;

/**
 * No fiscal series was resolvable for an invoice type: no explicit per-call
 * series, no `larabill.invoice_numbering.series.{type}` config, no legacy
 * prefix key.
 *
 * The fiscal series (AEAT `NumSerieFactura`) is a consumer business decision
 * — one installation runs `FAC`, another runs per-tenant series like
 * `CASTRIS`/`AAICHA`/`RECT-CASTRIS` (AID-289). Before AID-589,
 * `InvoiceSeriesResolver` filled that silence with a hardcoded default
 * (`FAC`/`PRO`/`TIK`/`RECT`), which is an invented fiscal value issued with no
 * warning, not a convenience. Configure the series explicitly — per call, via
 * `larabill.invoice_numbering.series.{type}`, or the legacy prefix keys —
 * before issuing that fiscal type.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingFiscalSeriesException extends RuntimeException
{
    public static function forType(InvoiceSerieType $type): self
    {
        return new self(
            "No fiscal series configured for invoice type '{$type->label()}'. ".
            'Set larabill.invoice_numbering.series.'.$type->label().
            ' (or LARABILL_SERIES_'.mb_strtoupper($type->label()).'), or pass an explicit series per call.'
        );
    }
}
