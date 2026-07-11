<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Enums\TaxType;
use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Tax Rate Seeder
 *
 * Seeds common tax rates from different regions.
 * These are examples - users should create their own tax rates.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class TaxRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $taxRates = [
            // Spain (VAT)
            ['name' => 'IVA General España', 'rate' => 2100, 'region' => 'ES', 'type' => TaxType::VAT],
            ['name' => 'IVA Reducido España', 'rate' => 1000, 'region' => 'ES', 'type' => TaxType::VAT],
            ['name' => 'IVA Superreducido España', 'rate' => 400, 'region' => 'ES', 'type' => TaxType::VAT],

            // United States - Massachusetts (Sales Tax)
            ['name' => 'MA State Sales Tax', 'rate' => 625, 'region' => 'US-MA', 'type' => TaxType::SALES_TAX],
            ['name' => 'Boston City Surcharge', 'rate' => 50, 'region' => 'US-MA-BOSTON', 'type' => TaxType::SALES_TAX],

            // United States - California (Sales Tax)
            ['name' => 'CA State Sales Tax', 'rate' => 725, 'region' => 'US-CA', 'type' => TaxType::SALES_TAX],
            ['name' => 'San Francisco Local Tax', 'rate' => 225, 'region' => 'US-CA-SF', 'type' => TaxType::SALES_TAX],

            // European Union - General VAT rates
            ['name' => 'VAT France', 'rate' => 2000, 'region' => 'FR', 'type' => TaxType::VAT],
            ['name' => 'VAT Germany', 'rate' => 1900, 'region' => 'DE', 'type' => TaxType::VAT],
            ['name' => 'VAT Italy', 'rate' => 2200, 'region' => 'IT', 'type' => TaxType::VAT],

            // Australia (GST)
            ['name' => 'Australian GST', 'rate' => 1000, 'region' => 'AU', 'type' => TaxType::GST],

            // Canada (GST + PST)
            ['name' => 'Canadian GST', 'rate' => 500, 'region' => 'CA', 'type' => TaxType::GST],
            ['name' => 'Ontario PST', 'rate' => 800, 'region' => 'CA-ON', 'type' => TaxType::SALES_TAX],

            // United Kingdom
            ['name' => 'UK Standard VAT', 'rate' => 2000, 'region' => 'GB', 'type' => TaxType::VAT],
            ['name' => 'UK Reduced VAT', 'rate' => 500, 'region' => 'GB', 'type' => TaxType::VAT],
        ];

        foreach ($taxRates as $taxRateData) {
            TaxRate::firstOrCreate(
                ['name' => $taxRateData['name'], 'region' => $taxRateData['region']],
                $taxRateData
            );
        }
    }
}
