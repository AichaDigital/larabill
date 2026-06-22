<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * InvoiceItem Factory
 *
 * v0.3.3: Updated for agnostic tax system
 * Uses new schema with total_tax_amount and taxes_applied
 *
 * AID-237: monetary attributes are scale-2 FixedDecimal. The factory now emits
 * internally consistent FixedDecimal values (cents) with exact HalfUp arithmetic.
 *
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity  = FixedDecimal::ofUnscaled($this->faker->numberBetween(100, 10000), 2);   // 1.00–100.00 units
        $unitPrice = FixedDecimal::ofUnscaled($this->faker->numberBetween(1000, 100000), 2);  // €10.00–€1000.00

        $taxable = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
        $tax     = $taxable->multipliedBy(2100)->dividedBy(10000, 2, RoundingMode::HalfUp); // 21% VAT
        $total   = $taxable->plus($tax);

        return [
            'item_type'         => $this->faker->randomElement([ItemType::GOOD, ItemType::SERVICE]),
            'description'       => $this->faker->sentence(3),
            'internal_code'     => $this->faker->optional()->regexify('[A-Z]{4}-[0-9]{4}'),
            'quantity'          => $quantity,
            'unit_measure_id'   => null,
            'unit_price'        => $unitPrice,
            'taxable_amount'    => $taxable,
            'total_tax_amount'  => $tax,
            'taxes_applied'     => [
                [
                    'source_rate_id' => 1,
                    'name'           => 'VAT Standard',
                    'rate'           => 2100, // 21% in base-10000
                    'amount'         => $tax->unscaledValue(),
                ],
            ],
            'total_amount'      => $total,
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
                'total_tax_amount' => FixedDecimal::zero(2),
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
            $unitPrice = FixedDecimal::ofUnscaled($this->faker->numberBetween(50000, 500000), 2); // €500–€5000
            $taxable   = $attributes['quantity']->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
            $tax       = $taxable->multipliedBy(2100)->dividedBy(10000, 2, RoundingMode::HalfUp);

            return [
                'unit_price'       => $unitPrice,
                'taxable_amount'   => $taxable,
                'total_tax_amount' => $tax,
                'total_amount'     => $taxable->plus($tax),
            ];
        });
    }
}
