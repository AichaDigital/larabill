<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\TaxCalculation;

use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Models\TaxGroup;

class VatCalculationStrategy implements TaxCalculationStrategy
{
    public function calculate(float $baseAmount, TaxGroup $taxGroup, array $context = []): array
    {
        $totalTaxAmount = 0;
        $taxesApplied   = [];

        foreach ($taxGroup->taxRates as $taxRate) {
            // Lógica de cálculo de IVA simple por ahora. Se puede expandir.
            $taxAmount = round($baseAmount * ($taxRate->rate / 10000));

            $totalTaxAmount += $taxAmount;
            $taxesApplied[] = [
                'source_rate_id' => $taxRate->id,
                'name'           => $taxRate->name,
                'rate'           => $taxRate->rate,
                'amount'         => $taxAmount,
            ];
        }

        return [
            'total_tax_amount' => $totalTaxAmount,
            'taxes_applied'    => $taxesApplied,
        ];
    }
}
