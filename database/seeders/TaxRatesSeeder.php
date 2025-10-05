<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * TaxRatesSeeder
 *
 * Seeds the database with default tax rates for Spain, EU, and worldwide.
 */
class TaxRatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Spanish tax rates
        $this->createSpanishRates();

        // EU tax rates
        $this->createEURates();

        // Special territories
        $this->createSpecialTerritoriesRates();
    }

    /**
     * Create Spanish tax rates.
     */
    private function createSpanishRates(): void
    {
        $spanishRates = [
            [
                'country_code' => 'ES',
                'country_name' => 'Spain',
                'tax_name'     => 'IVA General',
                'tax_type'     => 'VAT',
                'rate'         => 0.21,
                'applies_to'   => 'all',
            ],
            [
                'country_code' => 'ES',
                'country_name' => 'Spain',
                'tax_name'     => 'IVA Reducido',
                'tax_type'     => 'VAT',
                'rate'         => 0.10,
                'applies_to'   => 'food_medicine',
            ],
            [
                'country_code' => 'ES',
                'country_name' => 'Spain',
                'tax_name'     => 'IVA Superreducido',
                'tax_type'     => 'VAT',
                'rate'         => 0.04,
                'applies_to'   => 'basic_food',
            ],
        ];

        foreach ($spanishRates as $rate) {
            TaxRate::create($rate);
        }
    }

    /**
     * Create EU tax rates.
     */
    private function createEURates(): void
    {
        $euRates = [
            ['country_code' => 'DE', 'country_name' => 'Germany', 'tax_name' => 'MwSt', 'rate' => 0.19],
            ['country_code' => 'FR', 'country_name' => 'France', 'tax_name' => 'TVA', 'rate' => 0.20],
            ['country_code' => 'IT', 'country_name' => 'Italy', 'tax_name' => 'IVA', 'rate' => 0.22],
            ['country_code' => 'NL', 'country_name' => 'Netherlands', 'tax_name' => 'BTW', 'rate' => 0.21],
            ['country_code' => 'PT', 'country_name' => 'Portugal', 'tax_name' => 'IVA', 'rate' => 0.23],
            ['country_code' => 'BE', 'country_name' => 'Belgium', 'tax_name' => 'BTW', 'rate' => 0.21],
            ['country_code' => 'AT', 'country_name' => 'Austria', 'tax_name' => 'USt', 'rate' => 0.20],
            ['country_code' => 'IE', 'country_name' => 'Ireland', 'tax_name' => 'VAT', 'rate' => 0.23],
            ['country_code' => 'FI', 'country_name' => 'Finland', 'tax_name' => 'ALV', 'rate' => 0.24],
            ['country_code' => 'SE', 'country_name' => 'Sweden', 'tax_name' => 'Moms', 'rate' => 0.25],
        ];

        foreach ($euRates as $rate) {
            TaxRate::create([
                'country_code' => $rate['country_code'],
                'country_name' => $rate['country_name'],
                'tax_name'     => $rate['tax_name'],
                'tax_type'     => 'VAT',
                'rate'         => $rate['rate'],
                'applies_to'   => 'all',
            ]);
        }
    }

    /**
     * Create special territories rates.
     */
    private function createSpecialTerritoriesRates(): void
    {
        $specialRates = [
            [
                'country_code'       => 'IC', // Canary Islands
                'country_name'       => 'Canary Islands',
                'tax_name'           => 'IGIC',
                'tax_type'           => 'IGIC',
                'rate'               => 0.07,
                'applies_to'         => 'all',
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'note'                    => 'Inversión del sujeto pasivo - Art. 69 Ley IVA',
                ],
            ],
            [
                'country_code'       => 'CE', // Ceuta
                'country_name'       => 'Ceuta',
                'tax_name'           => 'IPSI',
                'tax_type'           => 'IPSI',
                'rate'               => 0.00,
                'applies_to'         => 'all',
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'note'                    => 'Operación exenta - Prestación de servicios en Ceuta',
                ],
            ],
            [
                'country_code'       => 'ML', // Melilla
                'country_name'       => 'Melilla',
                'tax_name'           => 'IPSI',
                'tax_type'           => 'IPSI',
                'rate'               => 0.00,
                'applies_to'         => 'all',
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'note'                    => 'Operación exenta - Prestación de servicios en Melilla',
                ],
            ],
        ];

        foreach ($specialRates as $rate) {
            TaxRate::create($rate);
        }
    }
}
