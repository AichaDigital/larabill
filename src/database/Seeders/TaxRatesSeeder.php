<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Tax Rates Seeder
 *
 * Seeds tax rates for different countries and regions.
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
                'country_code'       => 'ES',
                'country_name'       => 'Spain',
                'tax_name'           => 'IVA General',
                'tax_type'           => 'standard',
                'rate'               => 21.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'ES',
                'country_name'       => 'Spain',
                'tax_name'           => 'IVA Reducido',
                'tax_type'           => 'reduced',
                'rate'               => 10.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'reduced_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'ES',
                'country_name'       => 'Spain',
                'tax_name'           => 'IVA Superreducido',
                'tax_type'           => 'super_reduced',
                'rate'               => 4.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'super_reduced_goods_services',
                'special_conditions' => null,
            ],
        ];

        foreach ($spanishRates as $rate) {
            TaxRate::updateOrCreate(
                [
                    'country_code' => $rate['country_code'],
                    'tax_name'     => $rate['tax_name'],
                ],
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
                'country_code'       => 'DE',
                'country_name'       => 'Germany',
                'tax_name'           => 'MwSt',
                'tax_type'           => 'standard',
                'rate'               => 19.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'FR',
                'country_name'       => 'France',
                'tax_name'           => 'TVA',
                'tax_type'           => 'standard',
                'rate'               => 20.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'IT',
                'country_name'       => 'Italy',
                'tax_name'           => 'IVA',
                'tax_type'           => 'standard',
                'rate'               => 22.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'NL',
                'country_name'       => 'Netherlands',
                'tax_name'           => 'BTW',
                'tax_type'           => 'standard',
                'rate'               => 21.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'PT',
                'country_name'       => 'Portugal',
                'tax_name'           => 'IVA',
                'tax_type'           => 'standard',
                'rate'               => 23.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'BE',
                'country_name'       => 'Belgium',
                'tax_name'           => 'TVA',
                'tax_type'           => 'standard',
                'rate'               => 21.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'AT',
                'country_name'       => 'Austria',
                'tax_name'           => 'USt',
                'tax_type'           => 'standard',
                'rate'               => 20.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'IE',
                'country_name'       => 'Ireland',
                'tax_name'           => 'VAT',
                'tax_type'           => 'standard',
                'rate'               => 23.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'FI',
                'country_name'       => 'Finland',
                'tax_name'           => 'ALV',
                'tax_type'           => 'standard',
                'rate'               => 24.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
            [
                'country_code'       => 'SE',
                'country_name'       => 'Sweden',
                'tax_name'           => 'Moms',
                'tax_type'           => 'standard',
                'rate'               => 25.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => null,
            ],
        ];

        foreach ($euRates as $rate) {
            TaxRate::updateOrCreate(
                [
                    'country_code' => $rate['country_code'],
                    'tax_name'     => $rate['tax_name'],
                ],
                $rate
            );
        }
    }

    /**
     * Seed special territories rates.
     */
    private function seedSpecialTerritoriesRates(): void
    {
        $specialRates = [
            [
                'country_code'       => 'IC',
                'country_name'       => 'Canary Islands',
                'tax_name'           => 'IGIC',
                'tax_type'           => 'standard',
                'rate'               => 7.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type'          => 'special_territory',
                ],
            ],
            [
                'country_code'       => 'CE',
                'country_name'       => 'Ceuta',
                'tax_name'           => 'IPSI',
                'tax_type'           => 'standard',
                'rate'               => 0.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type'          => 'special_territory',
                ],
            ],
            [
                'country_code'       => 'ML',
                'country_name'       => 'Melilla',
                'tax_name'           => 'IPSI',
                'tax_type'           => 'standard',
                'rate'               => 0.0, // Base100 cast handles conversion
                'is_active'          => true,
                'applies_to'         => 'general_goods_services',
                'special_conditions' => [
                    'exempt_from_spanish_vat' => true,
                    'territory_type'          => 'special_territory',
                ],
            ],
        ];

        foreach ($specialRates as $rate) {
            TaxRate::updateOrCreate(
                [
                    'country_code' => $rate['country_code'],
                    'tax_name'     => $rate['tax_name'],
                ],
                $rate
            );
        }
    }
}
