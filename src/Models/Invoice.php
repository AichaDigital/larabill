<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Carbon;

/**
 * Invoice Model
 *
 * Represents an invoice with immutability and encryption features.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * @property string $number
 * @property string $type
 * @property string $status
 * @property int|string $user_id
 * @property string|null $user_tax_info_encrypted
 * @property bool $is_immutable
 * @property Carbon|null $immutable_at
 * @property int $subtotal Base-100 integer (e.g., 1234 => €12.34)
 * @property int $tax_amount Base-100 integer (e.g., 1234 => €12.34)
 * @property int $total Base-100 integer (e.g., 1234 => €12.34)
 * @property array|null $fiscal_data
 * @property array|null $vat_verification
 * @property bool $is_roi_taxed
 * @property Carbon|null $due_date
 * @property Carbon|null $paid_at
 */
class Invoice extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'number',
        'type',
        'status',
        'user_id',
        'user_tax_info_encrypted',
        'is_immutable',
        'immutable_at',
        'subtotal',
        'tax_amount',
        'total',
        'fiscal_data',
        'vat_verification',
        'is_roi_taxed',
        'due_date',
        'paid_at',
    ];

    /**
     * Casts for attributes.
     *
     * Uses integers in base 100 for monetary values (amounts, prices)
     * Example: €12.34 is stored as 1234, €1.00 as 100
     */
    public function casts(): array
    {
        return [
            'is_immutable' => 'boolean',
            'immutable_at' => 'datetime',
            'paid_at' => 'datetime',
            'due_date' => 'date',
            'subtotal' => 'integer', // Base 100: €12.34 = 1234
            'tax_amount' => 'integer', // Base 100: €12.34 = 1234
            'total' => 'integer', // Base 100: €12.34 = 1234
            'fiscal_data' => 'array',
            'vat_verification' => 'array',
            'is_roi_taxed' => 'boolean',
        ];
    }

    /**
     * Convert monetary amount to base 100 integer.
     *
     * @param  float  $amount  The monetary amount (e.g., 12.34)
     * @return int The base 100 integer (e.g., 1234)
     */
    public static function amountToBase100(float $amount): int
    {
        return (int) ($amount * 100);
    }

    /**
     * Convert base 100 integer to monetary amount.
     *
     * @param  int  $base100  The base 100 integer (e.g., 1234)
     * @return float The monetary amount (e.g., 12.34)
     */
    public static function base100ToAmount(int $base100): float
    {
        return $base100 / 100.0;
    }

    /**
     * Get subtotal as monetary amount.
     */
    public function getSubtotalAsAmount(): float
    {
        return static::base100ToAmount($this->subtotal);
    }

    /**
     * Get tax amount as monetary amount.
     */
    public function getTaxAmountAsAmount(): float
    {
        return static::base100ToAmount($this->tax_amount);
    }

    /**
     * Get total as monetary amount.
     */
    public function getTotalAsAmount(): float
    {
        return static::base100ToAmount($this->total);
    }

    /**
     * Set subtotal from monetary amount.
     */
    public function setSubtotalFromAmount(float $amount): self
    {
        $this->update(['subtotal' => static::amountToBase100($amount)]);
        return $this;
    }

    /**
     * Set tax amount from monetary amount.
     */
    public function setTaxAmountFromAmount(float $amount): self
    {
        $this->update(['tax_amount' => static::amountToBase100($amount)]);
        return $this;
    }

    /**
     * Set total from monetary amount.
     */
    public function setTotalFromAmount(float $amount): self
    {
        $this->update(['total' => static::amountToBase100($amount)]);
        return $this;
    }

    /**
     * Make the invoice immutable.
     */
    public function makeImmutable(): void
    {
        $this->is_immutable = true;
        $this->immutable_at = now();
        $this->save();
    }

    /**
     * Override update to prevent modifications of immutable invoices.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->is_immutable) {
            throw new \Exception('Cannot update an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

    /**
     * Get the invoice items.
     */
    public function items(): HasMany
    {
        $invoiceItemModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('invoice_item');

        return $this->hasMany($invoiceItemModel);
    }

    /**
     * Get the user that owns the invoice.
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        return $this->belongsTo($userModel);
    }

    /**
     * Accessor for subtotal to return as formatted string.
     */
    public function getSubtotalAttribute($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Accessor for tax_amount to return as formatted string.
     */
    public function getTaxAmountAttribute($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Accessor for total to return as formatted string.
     */
    public function getTotalAttribute($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
