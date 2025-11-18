<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Models\TaxGroup;
use Illuminate\Support\Facades\App;

/**
 * Tax Calculation Service
 *
 * Orquesta el cálculo de impuestos delegando a una estrategia específica.
 */
class TaxCalculationService
{
    private TaxCalculationStrategy $strategy;

    /**
     * Constructor.
     */
    public function __construct(?TaxCalculationStrategy $strategy = null)
    {
        // Si no se provee una estrategia, resuelve una por defecto desde el contenedor de servicios.
        // Esto permite inyección de dependencias y configuración flexible.
        $this->strategy = $strategy ?? App::make(TaxCalculationStrategy::class);
    }

    /**
     * Establece una nueva estrategia de cálculo dinámicamente.
     *
     * @return $this
     */
    public function setStrategy(TaxCalculationStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Calcula los impuestos para un monto base usando la estrategia actual.
     */
    public function calculate(float $baseAmount, TaxGroup $taxGroup, array $context = []): array
    {
        return $this->strategy->calculate($baseAmount, $taxGroup, $context);
    }

    /**
     * Calculate taxes for an invoice item (v0.4.0).
     *
     * @param  array{
     *     article_id: int,
     *     quantity: int,
     *     base_price: float,
     *     customer_id: int
     * }  $itemData
     * @return array{
     *     taxable_amount: float,
     *     tax_group_id: int|null,
     *     total_tax_amount: float,
     *     total_amount: float
     * }
     */
    public function calculateForInvoiceItem(array $itemData): array
    {
        $quantity      = $itemData['quantity'];
        $basePrice     = $itemData['base_price'];
        $taxableAmount = $quantity * $basePrice;

        // TODO: Get tax group from article or customer location
        // For now, return basic calculation without taxes
        $taxGroupId     = null;
        $totalTaxAmount = 0;
        $totalAmount    = $taxableAmount;

        // If we have a tax group, calculate
        // $taxGroup = TaxGroup::find($taxGroupId);
        // if ($taxGroup) {
        //     $result = $this->calculate($taxableAmount, $taxGroup, [
        //         'customer_id' => $itemData['customer_id'],
        //     ]);
        //     $totalTaxAmount = $result['total_tax_amount'];
        //     $totalAmount = $result['total_amount'];
        // }

        return [
            'taxable_amount'   => $taxableAmount,
            'tax_group_id'     => $taxGroupId,
            'total_tax_amount' => $totalTaxAmount,
            'total_amount'     => $totalAmount,
        ];
    }
}
