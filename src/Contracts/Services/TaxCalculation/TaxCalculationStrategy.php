<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Contracts\Services\TaxCalculation;

use AichaDigital\Larabill\Models\TaxGroup;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
interface TaxCalculationStrategy
{
    /**
     * @param  float  $baseAmount  El monto imponible (base-100)
     * @param  TaxGroup  $taxGroup  El grupo de impuestos a aplicar
     * @param  array<string, mixed>  $context  Contexto adicional (ej: dirección del comprador/vendedor)
     * @return array{total_tax_amount: int, taxes_applied: array<int, array<string, mixed>>} Un array que contiene 'total_tax_amount' y 'taxes_applied'
     */
    public function calculate(float $baseAmount, TaxGroup $taxGroup, array $context = []): array;
}
