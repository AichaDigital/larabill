<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * PaymentAllocation — one row of `grouped_payment_invoice`: a slice of a
 * grouped payment applied to one invoice (AID-962, spec §3.2/§4.5).
 *
 * The allocation is a first-class, append-only row: "reversing" it sets
 * `status = REVERSED` with actor and reason, it is never edited nor deleted,
 * and only ACTIVE rows count in any sum. The same payment/invoice pair may
 * appear more than once (a re-allocation after a reversal), and two live
 * payments may cover one invoice — neither column pair is unique any more.
 *
 * The 8.x columns `previous_status`, `previous_paid_at` and
 * `active_invoice_id` are @deprecated: all three are still written for
 * every new allocation, for compatibility. `previous_*` are no longer read
 * by the package; `active_invoice_id` is still read by
 * GroupedPaymentService::register() (the `alreadyActivelyPaid` guard) and
 * by the consumer's 8.x reader. Removal of all three in 10.0.
 *
 * @property int $id
 * @property string $grouped_payment_id
 * @property string $invoice_id
 * @property FixedDecimal $applied_amount
 * @property AllocationStatus $status
 * @property Carbon|null $applied_at Date the allocation settles the invoice; legacy rows read the payment's paid_at
 * @property Carbon|null $reversed_at
 * @property string|null $reversed_by
 * @property string|null $reverse_reason
 * @property int $previous_status @deprecated Still written, no longer read; removal in 10.0.
 * @property Carbon|null $previous_paid_at @deprecated Still written, no longer read; removal in 10.0.
 * @property string|null $active_invoice_id @deprecated Still written (= invoice_id while ACTIVE, NULL once reversed); still read by register()'s alreadyActivelyPaid guard and the consumer's 8.x reader; no longer a uniqueness guarantee; removal in 10.0.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api Supported public surface (AID-962; see docs/api-surface.md).
 */
class PaymentAllocation extends Pivot
{
    protected $table = 'grouped_payment_invoice';

    public $incrementing = true;

    protected $keyType = 'int';

    public function casts(): array
    {
        return [
            'applied_amount'   => FixedDecimalCast::class.':2',
            'status'           => AllocationStatus::class,
            'applied_at'       => 'datetime',
            'reversed_at'      => 'datetime',
            'previous_paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GroupedPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(GroupedPayment::class, 'grouped_payment_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Every credit movement pointing at this allocation (`allocation_id`):
     * the APPLIED exits that fund it from credit, AND the SURPLUS entry born
     * of its reversal when it was funded directly — so not only exits.
     *
     * @return HasMany<CreditMovement, $this>
     */
    public function creditMovements(): HasMany
    {
        return $this->hasMany(CreditMovement::class, 'allocation_id');
    }

    public function isActive(): bool
    {
        return $this->status === AllocationStatus::ACTIVE;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), AllocationStatus::ACTIVE->value);
    }
}
