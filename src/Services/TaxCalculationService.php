<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Models\Article;
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
     * ADR-003: Uses billable_user_id instead of customer_id.
     *
     * Units: `quantity` and `base_price` are base100 integers
     * (qty=100 ⇒ 1.0 unit; price=10000 ⇒ €100.00); their product is divided
     * by 100 once so the taxable amount stays in base100.
     *
     * Tax group resolution: an explicit `tax_group_id` wins; otherwise the
     * article's `tax_group_id`. Without a resolvable tax group the item is
     * tax-free (zero tax, empty taxes_applied).
     *
     * @param  array{
     *     article_id?: int|mixed,
     *     tax_group_id?: int|null,
     *     quantity: int|mixed,
     *     base_price: float|mixed,
     *     billable_user_id?: string|null
     * }  $itemData
     * @return array{
     *     taxable_amount: int,
     *     tax_group_id: int|null,
     *     total_tax_amount: int,
     *     taxes_applied: array<int, array<string, mixed>>,
     *     total_amount: int
     * }
     */
    public function calculateForInvoiceItem(array $itemData): array
    {
        $quantity      = $itemData['quantity'];
        $basePrice     = $itemData['base_price'];
        // Both values are base100 cents. EU/Spain norm: settle quantity × base_price
        // to the cent with HalfUp (never truncation). Example: qty=100 (1.0 unit) ×
        // price=10000 (€100.00) → €100.00 = 10000.
        $taxableAmount = FixedDecimal::ofUnscaled((int) $quantity, 2)
            ->multipliedBy(FixedDecimal::ofUnscaled((int) $basePrice, 2))
            ->toScale(2, RoundingMode::HalfUp)
            ->unscaledValue();

        $taxGroupId = $itemData['tax_group_id']
            ?? Article::query()->find($itemData['article_id'] ?? null)?->tax_group_id;

        /** @var TaxGroup|null $taxGroup */
        $taxGroup = TaxGroup::query()->with('taxRates')->find($taxGroupId);

        $totalTaxAmount = 0;
        $taxesApplied   = [];

        if ($taxGroup) {
            $result = $this->calculate($taxableAmount, $taxGroup, [
                'billable_user_id' => $itemData['billable_user_id'] ?? null,
            ]);

            $totalTaxAmount = (int) $result['total_tax_amount'];
            $taxesApplied   = $result['taxes_applied'];
        }

        return [
            'taxable_amount'   => $taxableAmount,
            'tax_group_id'     => $taxGroup?->id,
            'total_tax_amount' => $totalTaxAmount,
            'taxes_applied'    => $taxesApplied,
            'total_amount'     => $taxableAmount + $totalTaxAmount,
        ];
    }
}
