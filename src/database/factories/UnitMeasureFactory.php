<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\UnitMeasureCategory;
use AichaDigital\Larabill\Models\UnitMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;

class UnitMeasureFactory extends Factory
{
    protected $model = UnitMeasure::class;

    public function definition(): array
    {
        return [
            'code'       => $this->faker->unique()->word(),
            'symbol'     => $this->faker->lexify('?'),
            'name'       => $this->faker->words(2, true),
            'category'   => $this->faker->randomElement(UnitMeasureCategory::cases()),
            'is_active'  => true,
            'sort_order' => 0,
        ];
    }
}
