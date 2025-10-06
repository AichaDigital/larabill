<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItem Model
 *
 * Represents an item within an invoice.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 * Tax rates are stored as base-100 integers (e.g., 21.50% => 2150).
 *
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity Base-100 integer for fractional quantities (e.g., 1.5 => 150)
 * @property int $unit_price Base-100 integer (e.g., 1234 => €12.34)
 * @property int $subtotal Base-100 integer (e.g., 1234 => €12.34)
 * @property int $tax_rate Base-100 integer (e.g., 2150 => 21.50%)
 * @property int $tax_amount Base-100 integer (e.g., 1234 => €12.34)
 * @property int $total Base-100 integer (e.g., 1234 => €12.34)
 */
class InvoiceItem extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
    ];

    /**
     * Casts for attributes.
     *
     * Uses integers in base 100 for monetary values and quantities
     * Example: €12.34 is stored as 1234, 1.5 quantity as 150
     */
    public function casts(): array
    {
        return [
            'quantity'   => 'integer', // Base 100: 1.5 => 150
            'unit_price' => 'integer', // Base 100: €12.34 => 1234
            'subtotal'   => 'integer', // Base 100: €12.34 => 1234
            'tax_rate'   => 'integer', // Base 100: 21.50% => 2150
            'tax_amount' => 'integer', // Base 100: €12.34 => 1234
            'total'      => 'integer', // Base 100: €12.34 => 1234
        ];
    }

    /**
     * Convert monetary amount to base 100 integer.
     */
    public static function amountToBase100(float $amount): int
    {
        return (int) ($amount * 100);
    }

    /**
     * Convert base 100 integer to monetary amount.
     */
    public static function base100ToAmount(int $base100): float
    {
        return $base100 / 100.0;
    }

    /**
     * Convert percentage to base 100 integer.
     */
    public static function percentageToBase100(float $percentage): int
    {
        return (int) ($percentage * 100);
    }

    /**
     * Convert base 100 integer to percentage.
     */
    public static function base100ToPercentage(int $base100): float
    {
        return $base100 / 100.0;
    }

    /**
     * Convert quantity to base 100 integer.
     */
    public static function quantityToBase100(float $quantity): int
    {
        return (int) ($quantity * 100);
    }

    /**
     * Convert base 100 integer to quantity.
     */
    public static function base100ToQuantity(int $base100): float
    {
        return $base100 / 100.0;
    }

    // Getters for monetary amounts
    public function getQuantityAsFloat(): float
    {
        return static::base100ToQuantity($this->getRawOriginal('quantity'));
    }

    public function getUnitPriceAsAmount(): float
    {
        return static::base100ToAmount($this->getRawOriginal('unit_price'));
    }

    public function getSubtotalAsAmount(): float
    {
        return static::base100ToAmount($this->getRawOriginal('subtotal'));
    }

    public function getTaxRateAsPercentage(): float
    {
        return static::base100ToPercentage($this->getRawOriginal('tax_rate'));
    }

    public function getTaxAmountAsAmount(): float
    {
        return static::base100ToAmount($this->getRawOriginal('tax_amount'));
    }

    public function getTotalAsAmount(): float
    {
        return static::base100ToAmount($this->getRawOriginal('total'));
    }

    // Setters from monetary amounts
    public function setQuantityFromFloat(float $quantity): self
    {
        $this->update(['quantity' => static::quantityToBase100($quantity)]);

        return $this;
    }

    public function setUnitPriceFromAmount(float $amount): self
    {
        $this->update(['unit_price' => static::amountToBase100($amount)]);

        return $this;
    }

    public function setSubtotalFromAmount(float $amount): self
    {
        $this->update(['subtotal' => static::amountToBase100($amount)]);

        return $this;
    }

    public function setTaxRateFromPercentage(float $percentage): self
    {
        $this->update(['tax_rate' => static::percentageToBase100($percentage)]);

        return $this;
    }

    public function setTaxAmountFromAmount(float $amount): self
    {
        $this->update(['tax_amount' => static::amountToBase100($amount)]);

        return $this;
    }

    public function setTotalFromAmount(float $amount): self
    {
        $this->update(['total' => static::amountToBase100($amount)]);

        return $this;
    }

    /**
     * Calculate subtotal from quantity and unit price.
     */
    public function calculateSubtotal(): int
    {
        $quantity = (int) $this->getAttribute('quantity');
        $unitPrice = (int) $this->getAttribute('unit_price');
        return (int) (($quantity * $unitPrice) / 100);
    }

    /**
     * Calculate tax amount from subtotal and tax rate.
     */
    public function calculateTaxAmount(): int
    {
        $subtotal = (int) $this->getAttribute('subtotal');
        $taxRate = (int) $this->getAttribute('tax_rate');
        return (int) (($subtotal * $taxRate) / 10000); // Divide by 10000 because both are base 100
    }

    /**
     * Calculate total from subtotal and tax amount.
     */
    public function calculateTotal(): int
    {
        $subtotal = (int) $this->getAttribute('subtotal');
        $taxAmount = (int) $this->getAttribute('tax_amount');
        return $subtotal + $taxAmount;
    }

    /**
     * Get the invoice that owns the item.
     */
    public function invoice(): BelongsTo
    {
        $invoiceModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('invoice');

        return $this->belongsTo($invoiceModel);
    }

    /**
     * Accessor for quantity to return as formatted string.
     */
    public function getQuantityAttribute($value): string
    {
        return number_format(static::base100ToQuantity((int) $value), 2, '.', '');
    }

    /**
     * Accessor for unit_price to return as formatted string.
     */
    public function getUnitPriceAttribute($value): string
    {
        return number_format(static::base100ToAmount((int) $value), 2, '.', '');
    }

    /**
     * Accessor for subtotal to return as formatted string.
     */
    public function getSubtotalAttribute($value): string
    {
        return number_format(static::base100ToAmount((int) $value), 2, '.', '');
    }

    /**
     * Accessor for tax_rate to return as formatted string.
     */
    public function getTaxRateAttribute($value): string
    {
        return number_format(static::base100ToPercentage((int) $value), 4, '.', '');
    }

    /**
     * Accessor for tax_amount to return as formatted string.
     */
    public function getTaxAmountAttribute($value): string
    {
        return number_format(static::base100ToAmount((int) $value), 2, '.', '');
    }

    /**
     * Accessor for total to return as formatted string.
     */
    public function getTotalAttribute($value): string
    {
        return number_format(static::base100ToAmount((int) $value), 2, '.', '');
    }
}
