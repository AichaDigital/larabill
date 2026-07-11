<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\GroupedPaymentFactory;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * GroupedPayment — accounting record of one external collection settling N issued invoices.
 * Immutable once posted; lifecycle posted → reversed. Money is FixedDecimal:2 over integer base-100.
 *
 * @property string $id
 * @property string $billable_user_id
 * @property FixedDecimal $amount
 * @property string $currency
 * @property Carbon $paid_at
 * @property string|null $reference
 * @property string $idempotency_key
 * @property GroupedPaymentStatus $status
 * @property Carbon|null $reversed_at
 * @property string|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class GroupedPayment extends Model
{
    /** @use HasFactory<GroupedPaymentFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'billable_user_id', 'amount', 'currency', 'paid_at', 'reference',
        'idempotency_key', 'status', 'reversed_at', 'reversed_by', 'reverse_reason', 'notes',
    ];

    public function casts(): array
    {
        return [
            'amount'      => FixedDecimalCast::class.':2',
            'status'      => GroupedPaymentStatus::class,
            'paid_at'     => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): GroupedPaymentFactory
    {
        return GroupedPaymentFactory::new();
    }

    /** @return BelongsToMany<Invoice, $this> */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'grouped_payment_invoice', 'grouped_payment_id', 'invoice_id')
            ->withPivot(['applied_amount', 'previous_status', 'previous_paid_at', 'active_invoice_id'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Model, $this> */
    public function payer(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('larabill.user_model');

        return $this->belongsTo($userModel, 'billable_user_id');
    }

    public function isPosted(): bool
    {
        return $this->status === GroupedPaymentStatus::POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === GroupedPaymentStatus::REVERSED;
    }
}
