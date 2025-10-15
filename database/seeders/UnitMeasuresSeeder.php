<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Enums\UnitMeasureCategory;
use AichaDigital\Larabill\Models\UnitMeasure;
use Illuminate\Database\Seeder;

/**
 * Unit Measures Seeder
 *
 * Seeds common unit measures for invoices.
 * Covers CEE/EU standard units and common usage.
 */
class UnitMeasuresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = [
            // COUNT
            ['code' => 'unit', 'symbol' => 'ud.', 'name' => 'Unit', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 1],
            ['code' => 'dozen', 'symbol' => 'dz', 'name' => 'Dozen', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 2],
            ['code' => 'pack', 'symbol' => 'pk', 'name' => 'Pack', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 3],
            ['code' => 'box', 'symbol' => 'bx', 'name' => 'Box', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 4],

            // WEIGHT
            ['code' => 'gram', 'symbol' => 'g', 'name' => 'Gram', 'category' => UnitMeasureCategory::WEIGHT, 'sort_order' => 10],
            ['code' => 'kilogram', 'symbol' => 'kg', 'name' => 'Kilogram', 'category' => UnitMeasureCategory::WEIGHT, 'sort_order' => 11],
            ['code' => 'ton', 'symbol' => 't', 'name' => 'Metric Ton', 'category' => UnitMeasureCategory::WEIGHT, 'sort_order' => 12],
            ['code' => 'pound', 'symbol' => 'lb', 'name' => 'Pound', 'category' => UnitMeasureCategory::WEIGHT, 'sort_order' => 13],
            ['code' => 'ounce', 'symbol' => 'oz', 'name' => 'Ounce', 'category' => UnitMeasureCategory::WEIGHT, 'sort_order' => 14],

            // VOLUME
            ['code' => 'liter', 'symbol' => 'L', 'name' => 'Liter', 'category' => UnitMeasureCategory::VOLUME, 'sort_order' => 20],
            ['code' => 'milliliter', 'symbol' => 'mL', 'name' => 'Milliliter', 'category' => UnitMeasureCategory::VOLUME, 'sort_order' => 21],
            ['code' => 'cubic_meter', 'symbol' => 'm³', 'name' => 'Cubic Meter', 'category' => UnitMeasureCategory::VOLUME, 'sort_order' => 22],
            ['code' => 'gallon', 'symbol' => 'gal', 'name' => 'Gallon', 'category' => UnitMeasureCategory::VOLUME, 'sort_order' => 23],

            // LENGTH
            ['code' => 'meter', 'symbol' => 'm', 'name' => 'Meter', 'category' => UnitMeasureCategory::LENGTH, 'sort_order' => 30],
            ['code' => 'centimeter', 'symbol' => 'cm', 'name' => 'Centimeter', 'category' => UnitMeasureCategory::LENGTH, 'sort_order' => 31],
            ['code' => 'kilometer', 'symbol' => 'km', 'name' => 'Kilometer', 'category' => UnitMeasureCategory::LENGTH, 'sort_order' => 32],
            ['code' => 'inch', 'symbol' => 'in', 'name' => 'Inch', 'category' => UnitMeasureCategory::LENGTH, 'sort_order' => 33],
            ['code' => 'foot', 'symbol' => 'ft', 'name' => 'Foot', 'category' => UnitMeasureCategory::LENGTH, 'sort_order' => 34],

            // AREA
            ['code' => 'square_meter', 'symbol' => 'm²', 'name' => 'Square Meter', 'category' => UnitMeasureCategory::AREA, 'sort_order' => 40],
            ['code' => 'square_foot', 'symbol' => 'ft²', 'name' => 'Square Foot', 'category' => UnitMeasureCategory::AREA, 'sort_order' => 41],
            ['code' => 'hectare', 'symbol' => 'ha', 'name' => 'Hectare', 'category' => UnitMeasureCategory::AREA, 'sort_order' => 42],

            // TIME
            ['code' => 'hour', 'symbol' => 'h', 'name' => 'Hour', 'category' => UnitMeasureCategory::TIME, 'sort_order' => 50],
            ['code' => 'day', 'symbol' => 'day', 'name' => 'Day', 'category' => UnitMeasureCategory::TIME, 'sort_order' => 51],
            ['code' => 'week', 'symbol' => 'wk', 'name' => 'Week', 'category' => UnitMeasureCategory::TIME, 'sort_order' => 52],
            ['code' => 'month', 'symbol' => 'mo', 'name' => 'Month', 'category' => UnitMeasureCategory::TIME, 'sort_order' => 53],
            ['code' => 'year', 'symbol' => 'yr', 'name' => 'Year', 'category' => UnitMeasureCategory::TIME, 'sort_order' => 54],
        ];

        foreach ($units as $unit) {
            UnitMeasure::updateOrCreate(
                ['code' => $unit['code']],
                $unit
            );
        }
    }
}
