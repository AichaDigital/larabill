<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\GroupedPaymentFactory;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property PaymentMethod $method How the money arrived (AID-962); legacy rows read Card
 * @property string|null $recorded_by Actor who recorded the payment (AID-962)
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
        'method', 'recorded_by',
    ];

    public function casts(): array
    {
        return [
            'amount'      => FixedDecimalCast::class.':2',
            'status'      => GroupedPaymentStatus::class,
            'method'      => PaymentMethod::class,
            'paid_at'     => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): GroupedPaymentFactory
    {
        return GroupedPaymentFactory::new();
    }

    /**
     * The invoices this payment was allocated to — ONE ROW PER ALLOCATION.
     *
     * Since 8.3 (AID-962) the payment/invoice pair is no longer unique: an
     * invoice re-allocated after an allocation reversal appears twice, each
     * time with its own pivot (status, applied_at, reversal trio). Do not
     * count or compare invoices through this relation; the canonical 8.3
     * relation is allocations().
     *
     * @return BelongsToMany<Invoice, $this>
     */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'grouped_payment_invoice', 'grouped_payment_id', 'invoice_id')
            ->withPivot([
                'applied_amount', 'previous_status', 'previous_paid_at', 'active_invoice_id',
                'status', 'applied_at', 'reversed_at', 'reversed_by', 'reverse_reason',
            ])
            ->withTimestamps();
    }

    /**
     * Allocation rows of this payment, active and reversed alike (AID-962).
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'grouped_payment_id');
    }

    /**
     * Credit movements whose money comes from this payment (AID-962).
     *
     * @return HasMany<CreditMovement, $this>
     */
    public function creditMovements(): HasMany
    {
        return $this->hasMany(CreditMovement::class, 'grouped_payment_id');
    }

    /**
     * `amount` minus the applied amount of its ACTIVE allocations (fresh query).
     * A refund does not reduce it — it reduces the remaining of the entries.
     */
    public function unallocatedAmount(): FixedDecimal
    {
        $allocated = (int) DB::table('grouped_payment_invoice')
            ->where('grouped_payment_id', $this->id)
            ->where('status', AllocationStatus::ACTIVE->value)
            ->sum('applied_amount');

        return $this->amount->minus(FixedDecimal::ofUnscaled($allocated, 2));
    }

    /**
     * Sum of what is left of this payment's ACTIVE credit entries (spec §4.2),
     * each entry's remaining being its amount plus its active exits.
     */
    public function availableCredit(): FixedDecimal
    {
        $entries = CreditMovement::query()
            ->where('grouped_payment_id', $this->id)
            ->active()
            ->entries();

        $entered  = (int) (clone $entries)->sum('amount');
        $consumed = (int) CreditMovement::query()
            ->active()
            ->whereIn('consumes_movement_id', $entries->select('id'))
            ->sum('amount');

        return FixedDecimal::ofUnscaled($entered + $consumed, 2);
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
