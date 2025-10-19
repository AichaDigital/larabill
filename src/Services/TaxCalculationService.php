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
}
