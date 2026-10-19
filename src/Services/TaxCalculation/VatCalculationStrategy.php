<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\TaxCalculation;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Models\TaxGroup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class VatCalculationStrategy implements TaxCalculationStrategy
{
    /**
     * AID-1527 §3-bis site 1: the widened money domain exposes float
     * arithmetic that is wrong or overflows at magnitudes now legal. The
     * per-rate tax is computed with FOUR ordered branches, classified BEFORE
     * any int cast (round-4/round-5 adjudications), so no input class wraps
     * silently.
     *
     * @param  array<string, mixed>  $context
     * @return array{total_tax_amount: int, taxes_applied: array<int, array<string, mixed>>}
     */
    public function calculate(float $baseAmount, TaxGroup $taxGroup, array $context = []): array
    {
        $totalTaxAmount = 0;
        $taxesApplied   = [];

        foreach ($taxGroup->taxRates as $taxRate) {
            $taxAmount = $this->taxForRate($baseAmount, (int) $taxRate->rate);

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

    /**
     * The tax for one rate, under the AID-1527 §3-bis discipline.
     *
     * Branch order is binding: non-finite → pre-cast magnitude cutoff →
     * integral-representable exact path → fractional legacy expression with a
     * checked output boundary.
     */
    private function taxForRate(float $baseAmount, int $rate): int
    {
        // 1. Non-finite: invalid money, never a rounding question (round-4:
        //    the legacy expression wrapped NAN/INF to zero silently).
        if (is_nan($baseAmount) || is_infinite($baseAmount)) {
            throw new InvalidArgumentException(sprintf(
                'VAT base amount must be a finite float, got [%s].',
                var_export($baseAmount, true)
            ));
        }

        // 2. Conservative pre-cast magnitude cutoff (round-5 finding 1: 2^53,
        //    2^53+2 and -2^63 cast to EXACT ints and bypassed a post-cast
        //    cutoff). This is a CONSERVATIVE CUTOFF, not a claim that every
        //    float above 2^53 is inexact — the contract refuses to guess
        //    where exactness ends.
        if (abs($baseAmount) >= 2 ** 53) {
            throw new InvalidArgumentException(sprintf(
                'VAT base amount [%s] is beyond the 2^53 float-exact magnitude cutoff; refusing to compute tax on a value the float contract cannot guarantee.',
                var_export($baseAmount, true)
            ));
        }

        // 3. Integral float (only reachable below the cutoff, so the int cast
        //    is precision-independent — round-3 finding 1). Exact BigDecimal
        //    path: the legacy float expression loses cents above ~2^46 in the
        //    product. Brick's RoundingMode::HalfUp is half-away-from-zero —
        //    the same semantics as PHP round(). -0.0 lands here → 0.
        if ($baseAmount == (int) $baseAmount) {
            $exact = BigDecimal::of((string) (int) $baseAmount)
                ->multipliedBy(BigDecimal::of($rate))
                ->dividedBy(10000, 0, RoundingMode::HalfUp);

            // Materialized through the lara100 checked extraction
            // (FixedDecimal::unscaledValue()): an out-of-int result throws
            // the typed InvalidFixedDecimal, never a silent wrap.
            return FixedDecimal::ofDecimalString((string) $exact, 0)->unscaledValue();
        }

        // 4. Fractional float — a legacy surface the contract admits. The
        //    historical float expression VERBATIM for the value, with a
        //    CHECKED output boundary before the final (int) cast against the
        //    native int interval with an EXCLUSIVE upper bound (round-5
        //    finding 2: abs($result) <= PHP_INT_MAX promotes PHP_INT_MAX to
        //    2^63 in the float comparison, so a rounded result of exactly
        //    2^63 passed the guard and (int) wrapped it to PHP_INT_MIN).
        $result = round($baseAmount * ($rate / 10000));

        if (! is_finite($result)
            || $result < (float) PHP_INT_MIN
            || $result >= -(float) PHP_INT_MIN
        ) {
            throw new InvalidArgumentException(sprintf(
                'VAT tax result [%s] for base [%s] and rate [%d] falls outside the native integer range.',
                var_export($result, true),
                var_export($baseAmount, true),
                $rate
            ));
        }

        return (int) $result;
    }
}
