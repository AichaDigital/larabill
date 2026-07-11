<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSeriesControl>
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class InvoiceSeriesControlFactory extends Factory
{
    protected $model = InvoiceSeriesControl::class;

    public function definition(): array
    {
        $fiscalYear      = now()->year;
        $fiscalYearStart = now()->startOfYear();
        $fiscalYearEnd   = now()->endOfYear();

        return [
            'prefix'            => $this->faker->regexify('[A-Z]{3}'),
            'serie'             => InvoiceSerieType::INVOICE,
            'fiscal_year'       => $fiscalYear,
            'fiscal_year_start' => $fiscalYearStart,
            'fiscal_year_end'   => $fiscalYearEnd,
            'last_number'       => 0,
            'start_number'      => 1,
            'reset_annually'    => true,
            'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
            'is_active'         => true,
            'description'       => $this->faker->sentence(),
            'validation_rules'  => null,
            'last_used_at'      => null,
            'user_id'           => InvoiceSeriesControl::GLOBAL_SCOPE,
        ];
    }

    public function proforma(): static
    {
        return $this->state(fn (array $attributes) => [
            'serie'  => InvoiceSerieType::PROFORMA,
            'prefix' => 'PRO',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withUser(int|string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    public function withValidationRules(array $rules): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_rules' => $rules,
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_number'  => $this->faker->numberBetween(1, 100),
            'last_used_at' => now(),
        ]);
    }
}
