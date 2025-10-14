<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Models\TaxCategory;
use Illuminate\Database\Seeder;

/**
 * Tax Categories Seeder
 *
 * Seeds common tax categories for different regions.
 * Covers CEE/EU VAT, US Sales Tax, Australia GST, Canada HST/GST.
 *
 * Users can add custom categories for their specific needs.
 */
class TaxCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            // ===============================
            // CEE/EU - VAT Categories
            // ===============================
            [
                'code'         => 'vat_standard_es',
                'name'         => 'IVA General (ES)',
                'tax_type'     => 'vat',
                'description'  => 'Tipo general de IVA en España - Aplicable a la mayoría de bienes y servicios',
                'default_rate' => 21.00,
                'country_code' => 'ES',
                'region_code'  => null,
                'sort_order'   => 1,
            ],
            [
                'code'         => 'vat_reduced_es',
                'name'         => 'IVA Reducido (ES)',
                'tax_type'     => 'vat',
                'description'  => 'Tipo reducido de IVA en España - Alimentos, transporte, hostelería',
                'default_rate' => 10.00,
                'country_code' => 'ES',
                'region_code'  => null,
                'sort_order'   => 2,
            ],
            [
                'code'         => 'vat_super_reduced_es',
                'name'         => 'IVA Superreducido (ES)',
                'tax_type'     => 'vat',
                'description'  => 'Tipo superreducido de IVA en España - Alimentos básicos, libros, medicamentos',
                'default_rate' => 4.00,
                'country_code' => 'ES',
                'region_code'  => null,
                'sort_order'   => 3,
            ],
            [
                'code'         => 'vat_exempt_es',
                'name'         => 'IVA Exento (ES)',
                'tax_type'     => 'vat',
                'description'  => 'Operaciones exentas de IVA - Educación, sanidad, seguros, etc.',
                'default_rate' => 0.00,
                'country_code' => 'ES',
                'region_code'  => null,
                'sort_order'   => 4,
            ],
            [
                'code'         => 'vat_reverse_charge_eu',
                'name'         => 'Reverse Charge B2B (EU)',
                'tax_type'     => 'vat',
                'description'  => 'Inversión del sujeto pasivo - Operaciones B2B intracomunitarias',
                'default_rate' => 0.00,
                'country_code' => 'ES',
                'region_code'  => null,
                'sort_order'   => 5,
            ],

            // ===============================
            // USA - Sales Tax
            // ===============================
            [
                'code'         => 'sales_tax_ca',
                'name'         => 'Sales Tax (California)',
                'tax_type'     => 'sales_tax',
                'description'  => 'California State Sales Tax',
                'default_rate' => 7.25,
                'country_code' => 'US',
                'region_code'  => 'CA',
                'sort_order'   => 10,
            ],
            [
                'code'         => 'sales_tax_ny',
                'name'         => 'Sales Tax (New York)',
                'tax_type'     => 'sales_tax',
                'description'  => 'New York State Sales Tax',
                'default_rate' => 4.00,
                'country_code' => 'US',
                'region_code'  => 'NY',
                'sort_order'   => 11,
            ],
            [
                'code'         => 'sales_tax_tx',
                'name'         => 'Sales Tax (Texas)',
                'tax_type'     => 'sales_tax',
                'description'  => 'Texas State Sales Tax',
                'default_rate' => 6.25,
                'country_code' => 'US',
                'region_code'  => 'TX',
                'sort_order'   => 12,
            ],

            // ===============================
            // Australia - GST
            // ===============================
            [
                'code'         => 'gst_standard_au',
                'name'         => 'GST (Australia)',
                'tax_type'     => 'gst',
                'description'  => 'Goods and Services Tax - Standard rate',
                'default_rate' => 10.00,
                'country_code' => 'AU',
                'region_code'  => null,
                'sort_order'   => 20,
            ],
            [
                'code'         => 'gst_exempt_au',
                'name'         => 'GST Exempt (Australia)',
                'tax_type'     => 'gst',
                'description'  => 'GST-free goods and services',
                'default_rate' => 0.00,
                'country_code' => 'AU',
                'region_code'  => null,
                'sort_order'   => 21,
            ],

            // ===============================
            // Canada - GST/HST
            // ===============================
            [
                'code'         => 'hst_on',
                'name'         => 'HST (Ontario)',
                'tax_type'     => 'hst',
                'description'  => 'Harmonized Sales Tax - Ontario',
                'default_rate' => 13.00,
                'country_code' => 'CA',
                'region_code'  => 'ON',
                'sort_order'   => 30,
            ],
            [
                'code'         => 'gst_standard_ca',
                'name'         => 'GST (Canada)',
                'tax_type'     => 'gst',
                'description'  => 'Goods and Services Tax - Federal',
                'default_rate' => 5.00,
                'country_code' => 'CA',
                'region_code'  => null,
                'sort_order'   => 31,
            ],
        ];

        foreach ($categories as $category) {
            TaxCategory::updateOrCreate(
                ['code' => $category['code']],
                $category
            );
        }
    }
}
