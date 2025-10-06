<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Factories;

use AichaDigital\Larabill\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * InvoiceItem Factory
 *
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
        $quantity  = $this->faker->numberBetween(100, 5000); // 1.00 to 50.00 in base 100
        $unitPrice = $this->faker->numberBetween(100, 10000); // €1.00 to €100.00 in base 100
        $subtotal  = (int) (($quantity * $unitPrice) / 100); // Calculate subtotal
        $taxRate   = $this->faker->randomElement([2100, 1000, 400]); // 21%, 10%, or 4% in base 100
        $taxAmount = (int) (($subtotal * $taxRate) / 10000); // Calculate tax amount
        $total     = $subtotal + $taxAmount;

        return [
            'invoice_id'  => $this->faker->numberBetween(1, 100),
            'description' => $this->faker->randomElement([
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
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
            'subtotal'   => $subtotal,
            'tax_rate'   => $taxRate,
            'tax_amount' => $taxAmount,
            'total'      => $total,
        ];
    }

    /**
     * Create an item with standard VAT rate (21%)
     */
    public function standardVat(): static
    {
        return $this->state(function (array $attributes) {
            $taxRate   = 2100; // 21% in base 100
            $taxAmount = (int) (($attributes['subtotal'] * $taxRate) / 10000);

            return [
                'tax_rate'   => $taxRate,
                'tax_amount' => $taxAmount,
                'total'      => $attributes['subtotal'] + $taxAmount,
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
            $taxAmount = (int) (($attributes['subtotal'] * $taxRate) / 10000);

            return [
                'tax_rate'   => $taxRate,
                'tax_amount' => $taxAmount,
                'total'      => $attributes['subtotal'] + $taxAmount,
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
            $taxAmount = (int) (($attributes['subtotal'] * $taxRate) / 10000);

            return [
                'tax_rate'   => $taxRate,
                'tax_amount' => $taxAmount,
                'total'      => $attributes['subtotal'] + $taxAmount,
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
                'tax_rate'   => 0, // 0% in base 100
                'tax_amount' => 0,
                'total'      => $attributes['subtotal'], // No tax added
            ];
        });
    }

    /**
     * Create a high-value item
     */
    public function highValue(): static
    {
        return $this->state(function (array $attributes) {
            $unitPrice = $this->faker->numberBetween(50000, 500000); // €500.00 to €5000.00 in base 100
            $subtotal  = (int) (($attributes['quantity'] * $unitPrice) / 100);
            $taxAmount = (int) (($subtotal * $attributes['tax_rate']) / 10000);

            return [
                'unit_price' => $unitPrice,
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $subtotal + $taxAmount,
            ];
        });
    }

    /**
     * Create a fractional quantity item
     */
    public function fractionalQuantity(): static
    {
        return $this->state(function (array $attributes) {
            $quantity  = $this->faker->numberBetween(150, 250); // 1.50 to 2.50 in base 100
            $subtotal  = (int) (($quantity * $attributes['unit_price']) / 100);
            $taxAmount = (int) (($subtotal * $attributes['tax_rate']) / 10000);

            return [
                'quantity'   => $quantity,
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $subtotal + $taxAmount,
            ];
        });
    }
}
