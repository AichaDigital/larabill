<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Enums\TaxType;
use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Tax Rates Seeder
 *
 * Seeds comprehensive tax rates for Spain, EU countries, and special territories.
 * Uses the enhanced tax_rates structure with Laravel SoftDeletes.
 */
class TaxRatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedSpanishRates();
        $this->seedEURates();
        $this->seedSpecialTerritoriesRates();
    }

    /**
     * Seed Spanish tax rates.
     */
    private function seedSpanishRates(): void
    {
        $spanishRates = [
            [
                'name'   => 'IVA General España',
                'rate'   => 2100, // 21%
                'region' => 'ES',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'IVA Reducido España',
                'rate'   => 1000, // 10%
                'region' => 'ES',
                'type'   => TaxType::VAT,
                'special_conditions' => [
                    'applies_to' => 'reduced_goods_services',
                    'examples' => ['food', 'books', 'cultural_events'],
                ],
            ],
            [
                'name'   => 'IVA Superreducido España',
                'rate'   => 400, // 4%
                'region' => 'ES',
                'type'   => TaxType::VAT,
                'special_conditions' => [
                    'applies_to' => 'super_reduced_goods_services',
                    'examples' => ['basic_food', 'medicines', 'newspapers'],
                ],
            ],
        ];

        foreach ($spanishRates as $rate) {
            TaxRate::updateOrCreate(
                ['name' => $rate['name'], 'region' => $rate['region']],
                $rate
            );
        }
    }

    /**
     * Seed EU tax rates.
     */
    private function seedEURates(): void
    {
        $euRates = [
            [
                'name'   => 'MwSt Germany',
                'rate'   => 1900, // 19%
                'region' => 'DE',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'TVA France',
                'rate'   => 2000, // 20%
                'region' => 'FR',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'IVA Italy',
                'rate'   => 2200, // 22%
                'region' => 'IT',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'BTW Netherlands',
                'rate'   => 2100, // 21%
                'region' => 'NL',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'IVA Portugal',
                'rate'   => 2300, // 23%
                'region' => 'PT',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'TVA Belgium',
                'rate'   => 2100, // 21%
                'region' => 'BE',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'USt Austria',
                'rate'   => 2000, // 20%
                'region' => 'AT',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'VAT Ireland',
                'rate'   => 2300, // 23%
                'region' => 'IE',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'ALV Finland',
                'rate'   => 2400, // 24%
                'region' => 'FI',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
            [
                'name'   => 'Moms Sweden',
                'rate'   => 2500, // 25%
                'region' => 'SE',
                'type'   => TaxType::VAT,
                'special_conditions' => null,
            ],
        ];

        foreach ($euRates as $rate) {
            TaxRate::updateOrCreate(
                ['name' => $rate['name'], 'region' => $rate['region']],
                $rate
            );
        }
    }

    /**
     * Seed special territories rates (Canary Islands, Ceuta, Melilla).
     */
    private function seedSpecialTerritoriesRates(): void
    {
        $specialRates = [
            [
                'name'   => 'IGIC General Canarias',
                'rate'   => 700, // 7%
                'region' => 'IC',  // Special code for Canary Islands
                'type'   => TaxType::VAT,
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type' => 'special_territory',
                    'applies_to' => 'general_goods_services',
                    'note' => 'IGIC (Impuesto General Indirecto Canario) applies instead of Spanish IVA',
                ],
            ],
            [
                'name'   => 'IGIC Reducido Canarias',
                'rate'   => 300, // 3%
                'region' => 'IC',
                'type'   => TaxType::VAT,
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type' => 'special_territory',
                    'applies_to' => 'reduced_goods_services',
                ],
            ],
            [
                'name'   => 'IPSI Ceuta',
                'rate'   => 0, // 0% for digital services
                'region' => 'CE',
                'type'   => TaxType::OTHER,
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type' => 'special_territory',
                    'note' => 'Operación exenta - Prestación de servicios digitales en Ceuta',
                    'applies_to' => 'digital_services',
                ],
            ],
            [
                'name'   => 'IPSI Melilla',
                'rate'   => 0, // 0% for digital services
                'region' => 'ML',
                'type'   => TaxType::OTHER,
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type' => 'special_territory',
                    'note' => 'Operación exenta - Prestación de servicios digitales en Melilla',
                    'applies_to' => 'digital_services',
                ],
            ],
        ];

        foreach ($specialRates as $rate) {
            TaxRate::updateOrCreate(
                ['name' => $rate['name'], 'region' => $rate['region']],
                $rate
            );
        }
    }
}
