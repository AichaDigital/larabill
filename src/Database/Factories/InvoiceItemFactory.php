<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * InvoiceItem Factory
 *
 * v0.3.3: Updated for agnostic tax system
 * Uses new schema with total_tax_amount and taxes_applied
 *
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity       = $this->faker->randomFloat(2, 1, 100);
        $unitPrice      = $this->faker->randomFloat(2, 10, 1000);
        $taxableAmount  = $quantity      * $unitPrice;
        $totalTaxAmount = $taxableAmount * 0.21; // 21% VAT
        $totalAmount    = $taxableAmount + $totalTaxAmount;

        return [
            'item_type'         => $this->faker->randomElement([ItemType::GOOD, ItemType::SERVICE]),
            'description'       => $this->faker->sentence(3),
            'internal_code'     => $this->faker->optional()->regexify('[A-Z]{4}-[0-9]{4}'),
            'quantity'          => $quantity,
            'unit_measure_id'   => null,
            'unit_price'        => $unitPrice,
            'taxable_amount'    => $taxableAmount,
            'total_tax_amount'  => $totalTaxAmount,
            'taxes_applied'     => [
                [
                    'source_rate_id' => 1,
                    'name'           => 'VAT Standard',
                    'rate'           => 2100, // 21% in base-100
                    'amount'         => (int) ($totalTaxAmount * 100), // Convert to base-100
                ],
            ],
            'total_amount'      => $totalAmount,
            'service_date_from' => null,
            'service_date_to'   => null,
            'metadata'          => null,
        ];
    }

    /**
     * Create a service item (with service dates)
     */
    public function service(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'item_type'         => ItemType::SERVICE,
                'description'       => 'Consulting Services',
                'service_date_from' => now()->subDays(30),
                'service_date_to'   => now(),
            ];
        });
    }

    /**
     * Create a good item
     */
    public function good(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'item_type'         => ItemType::GOOD,
                'description'       => 'Software License',
                'service_date_from' => null,
                'service_date_to'   => null,
            ];
        });
    }

    /**
     * Create an item with no taxes
     */
    public function exempt(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'total_tax_amount' => 0,
                'taxes_applied'    => [],
                'total_amount'     => $attributes['taxable_amount'],
            ];
        });
    }

    /**
     * Create an item with high value
     */
    public function highValue(): static
    {
        return $this->state(function (array $attributes) {
            $unitPrice      = $this->faker->randomFloat(2, 500, 5000);
            $taxableAmount  = $attributes['quantity'] * $unitPrice;
            $totalTaxAmount = $taxableAmount          * 0.21;

            return [
                'unit_price'       => $unitPrice,
                'taxable_amount'   => $taxableAmount,
                'total_tax_amount' => $totalTaxAmount,
                'total_amount'     => $taxableAmount + $totalTaxAmount,
            ];
        });
    }
}
