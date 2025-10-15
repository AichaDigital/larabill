<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * InvoiceItem Factory
 *
 * v0.3.3: Updated for fiscal compliance
 * Uses integer base 100 for monetary values, quantities, and tax rates
 * Example: €12.34 is stored as 1234, 1.5 quantity as 150, 21.50% as 2150
 *
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    public function definition(): array
    {
        $quantity      = $this->faker->numberBetween(100, 5000); // 1.00 to 50.00 in base 100
        $unitPrice     = $this->faker->numberBetween(100, 10000); // €1.00 to €100.00 in base 100
        $taxableAmount = (int) (($quantity * $unitPrice) / 100); // Calculate taxable amount
        $taxRate       = $this->faker->randomElement([2100, 1000, 400]); // 21%, 10%, or 4% in base 100
        $taxAmount     = (int) (($taxableAmount * $taxRate) / 10000); // Calculate tax amount
        $totalAmount   = $taxableAmount + $taxAmount;

        return [
            'invoice_id'  => $this->faker->numberBetween(1, 100),

            // v0.3.3: New fields
            'item_type'    => $this->faker->randomElement([ItemType::GOOD->value, ItemType::SERVICE->value]),
            'description'  => $this->faker->randomElement([
                'Web Development Services',
                'Consulting Hours',
                'Software License',
                'Training Session',
                'Technical Support',
                'Custom Development',
                'System Integration',
                'Data Migration',
                'Security Audit',
                'Performance Optimization',
            ]),
            'internal_code' => $this->faker->optional(0.5)->numerify('PROD-####'),

            // Quantity & Unit
            'quantity'         => $quantity,
            'unit_measure_id'  => null, // Optional FK
            'unit_price'       => $unitPrice,

            // v0.3.3: Renamed fields
            'taxable_amount' => $taxableAmount,

            // Tax
            'tax_rate'        => $taxRate,
            'tax_category_id' => null, // Optional FK
            'tax_amount'      => $taxAmount,
            'total_amount'    => $totalAmount,

            // v0.3.3: Service dates (optional)
            'service_date_from' => null,
            'service_date_to'   => null,

            // v0.3.3: Metadata
            'metadata' => null,
        ];
    }

    /**
     * Create a service item (with service dates)
     */
    public function service(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'item_type'         => ItemType::SERVICE->value,
                'description'       => $this->faker->randomElement([
                    'Consulting Services',
                    'Software Development',
                    'Technical Support',
                    'System Maintenance',
                ]),
                'service_date_from' => $this->faker->dateTimeBetween('-60 days', '-30 days'),
                'service_date_to'   => $this->faker->dateTimeBetween('-29 days', 'now'),
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
                'item_type'   => ItemType::GOOD->value,
                'description' => $this->faker->randomElement([
                    'Software License',
                    'Hardware Equipment',
                    'Office Supplies',
                    'Computer Accessories',
                ]),
                'service_date_from' => null,
                'service_date_to'   => null,
            ];
        });
    }

    /**
     * Create an item with standard VAT rate (21%)
     */
    public function standardVat(): static
    {
        return $this->state(function (array $attributes) {
            $taxRate   = 2100; // 21% in base 100
            $taxAmount = (int) (($attributes['taxable_amount'] * $taxRate) / 10000);

            return [
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmount,
                'total_amount' => $attributes['taxable_amount'] + $taxAmount,
            ];
        });
    }

    /**
     * Create an item with reduced VAT rate (10%)
     */
    public function reducedVat(): static
    {
        return $this->state(function (array $attributes) {
            $taxRate   = 1000; // 10% in base 100
            $taxAmount = (int) (($attributes['taxable_amount'] * $taxRate) / 10000);

            return [
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmount,
                'total_amount' => $attributes['taxable_amount'] + $taxAmount,
            ];
        });
    }

    /**
     * Create an item with super reduced VAT rate (4%)
     */
    public function superReducedVat(): static
    {
        return $this->state(function (array $attributes) {
            $taxRate   = 400; // 4% in base 100
            $taxAmount = (int) (($attributes['taxable_amount'] * $taxRate) / 10000);

            return [
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmount,
                'total_amount' => $attributes['taxable_amount'] + $taxAmount,
            ];
        });
    }

    /**
     * Create an exempt item (0% VAT)
     */
    public function exempt(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'tax_rate'     => 0, // 0% in base 100
                'tax_amount'   => 0,
                'total_amount' => $attributes['taxable_amount'], // No tax added
            ];
        });
    }

    /**
     * Create a high-value item
     */
    public function highValue(): static
    {
        return $this->state(function (array $attributes) {
            $unitPrice     = $this->faker->numberBetween(50000, 500000); // €500.00 to €5000.00 in base 100
            $taxableAmount = (int) (($attributes['quantity'] * $unitPrice) / 100);
            $taxAmount     = (int) (($taxableAmount * $attributes['tax_rate']) / 10000);

            return [
                'unit_price'     => $unitPrice,
                'taxable_amount' => $taxableAmount,
                'tax_amount'     => $taxAmount,
                'total_amount'   => $taxableAmount + $taxAmount,
            ];
        });
    }

    /**
     * Create a fractional quantity item
     */
    public function fractionalQuantity(): static
    {
        return $this->state(function (array $attributes) {
            $quantity      = $this->faker->numberBetween(150, 250); // 1.50 to 2.50 in base 100
            $taxableAmount = (int) (($quantity * $attributes['unit_price']) / 100);
            $taxAmount     = (int) (($taxableAmount * $attributes['tax_rate']) / 10000);

            return [
                'quantity'       => $quantity,
                'taxable_amount' => $taxableAmount,
                'tax_amount'     => $taxAmount,
                'total_amount'   => $taxableAmount + $taxAmount,
            ];
        });
    }
}
