<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice Model
 *
 * Represents an invoice with immutability and encryption features.
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
        'due_date',
        'paid_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_immutable' => 'boolean',
        'immutable_at' => 'datetime',
        'paid_at' => 'datetime',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'fiscal_data' => 'array',
        'vat_verification' => 'array',
    ];

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
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Get the user that owns the invoice.
     */
    public function user(): BelongsTo
    {
        $userModel = config('larabill.models.user');

        return $this->belongsTo($userModel);
    }
}
