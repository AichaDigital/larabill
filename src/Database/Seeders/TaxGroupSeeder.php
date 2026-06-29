<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Tax Group Seeder
 *
 * Seeds example tax groups showing how to combine multiple tax rates.
 * These are examples - users should create their own tax groups.
 */
class TaxGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Example 1: Single tax (Spain Standard VAT)
        $this->createTaxGroup(
            'IVA General España',
            'Impuesto al valor agregado general (21%) para España',
            ['IVA General España']
        );

        // Example 2: Multiple taxes (Boston Sales - State + City)
        $this->createTaxGroup(
            'Venta Estándar Boston',
            'Massachusetts State Sales Tax (6.25%) + Boston City Surcharge (0.50%)',
            ['MA State Sales Tax', 'Boston City Surcharge'],
            [0, 1] // Priority: state first, then city
        );

        // Example 3: Canadian HST (GST + PST combined)
        $this->createTaxGroup(
            'HST Ontario',
            'Harmonized Sales Tax - Canadian GST (5%) + Ontario PST (8%)',
            ['Canadian GST', 'Ontario PST'],
            [0, 1]
        );

        // Example 4: San Francisco Combined Rate
        $this->createTaxGroup(
            'Venta San Francisco',
            'California State Sales Tax (7.25%) + SF Local Tax (2.25%)',
            ['CA State Sales Tax', 'San Francisco Local Tax'],
            [0, 1]
        );

        // Example 5: UK Standard VAT
        $this->createTaxGroup(
            'UK Standard VAT',
            'UK Standard VAT rate (20%)',
            ['UK Standard VAT']
        );

        // Example 6: Australian GST
        $this->createTaxGroup(
            'Australian Standard GST',
            'Australian Goods and Services Tax (10%)',
            ['Australian GST']
        );
    }

    /**
     * Create a tax group with associated tax rates.
     *
     * @param  string  $name  Group name
     * @param  string  $description  Group description
     * @param  array<string>  $taxRateNames  Array of tax rate names to associate
     * @param  array<int>  $priorities  Array of priorities (same order as $taxRateNames)
     */
    private function createTaxGroup(string $name, string $description, array $taxRateNames, array $priorities = []): void
    {
        $group = TaxGroup::firstOrCreate(
            ['name' => $name],
            ['description' => $description]
        );

        foreach ($taxRateNames as $index => $rateName) {
            $taxRate = TaxRate::where('name', $rateName)->first();

            if ($taxRate) {
                $priority = $priorities[$index] ?? 0;

                // Attach if not already attached
                if (! $group->taxRates()->wherePivot('tax_rate_id', $taxRate->id)->exists()) {
                    $group->taxRates()->attach($taxRate->id, ['priority' => $priority]);
                }
            }
        }
    }
}
