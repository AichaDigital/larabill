<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * SaleReservation — the reservation that claims a proforma for a one-off
 * sale without vouchers (AID-1997 spec §4, D1/D2), at most one live per
 * proforma (enforced under the proforma lock, §4.1). It carries the FROZEN
 * EXPECTATION the later stages revalidate (amount, currency, billable
 * user), the stage of the sale, the payment/allocation links (each UNIQUE:
 * a payment or allocation belongs to at most one reservation) and the sale
 * mark (`issued_by_sale`: `issue()` ran inside `settle()` S3).
 *
 * `operation_key` is the sale's idempotency key: the same key replays, a
 * different payload under the same key conflicts. Written only by the
 * package (OneOffSaleService); consumers read it through the returned
 * results and `diagnose()`.
 *
 * @property string $id
 * @property string $proforma_id
 * @property string $operation_key
 * @property SaleReservationStage $stage
 * @property FixedDecimal $expected_amount
 * @property string $expected_currency
 * @property string $expected_billable_user_id
 * @property string|null $grouped_payment_id
 * @property string|null $allocation_id
 * @property bool $issued_by_sale
 * @property Carbon|null $paid_at
 * @property Carbon|null $issuing_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $released_at
 * @property string|null $released_by
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
class SaleReservation extends Model
{
    use HasUuid;

    protected $fillable = [
        'proforma_id', 'operation_key', 'stage',
        'expected_amount', 'expected_currency', 'expected_billable_user_id',
        'grouped_payment_id', 'allocation_id', 'issued_by_sale',
        'paid_at', 'issuing_at', 'settled_at', 'released_at', 'released_by',
    ];

    public function casts(): array
    {
        return [
            'stage'           => SaleReservationStage::class,
            'expected_amount' => FixedDecimalCast::class.':2',
            'issued_by_sale'  => 'boolean',
            'paid_at'         => 'datetime',
            'issuing_at'      => 'datetime',
            'settled_at'      => 'datetime',
            'released_at'     => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'proforma_id');
    }

    /** @return BelongsTo<GroupedPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(GroupedPayment::class, 'grouped_payment_id');
    }

    /** @return BelongsTo<PaymentAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'allocation_id');
    }

    /** The sale invoice born of this reservation (set at S2).
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'sale_reservation_id');
    }

    /** @return HasMany<SaleRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class, 'sale_reservation_id');
    }

    /**
     * The refund-aware money readers of the settled sale (spec §3, D8 rev11,
     * V4): ONE non-`Abandoned` capacity predicate everywhere.
     */
    public function refunded(): FixedDecimal
    {
        return FixedDecimal::ofUnscaled(
            (int) $this->refunds()->where('stage', '!=', SaleRefundStage::Abandoned->value)->sum('amount'),
            2
        );
    }

    public function netSettled(): FixedDecimal
    {
        return $this->expected_amount->minus($this->refunded());
    }

    public function remainingRefundable(): FixedDecimal
    {
        return $this->expected_amount->minus($this->refunded());
    }

    // === The reservation authorization (AID-1997 spec §4.1 step 6) ===
    // The invoice guards (G1-G5) refuse an unauthorized freeze/unfreeze of a
    // reserved proforma; the reservation's own writes run inside this
    // authorization. Same per-process flag pattern as VoucherSaleClaim::
    // withSaleAuthorization(); reentrant-safe.

    private static ?string $authorizedReservationId = null;

    /**
     * Run $write as the reservation's own write path (AID-1997 §4.1/§4.2).
     *
     * @internal OneOffSaleService is the only caller.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function withReservationAuthorization(string $reservationId, callable $write): mixed
    {
        $previous                       = self::$authorizedReservationId;
        self::$authorizedReservationId  = $reservationId;

        try {
            return $write();
        } finally {
            self::$authorizedReservationId = $previous;
        }
    }

    /** Whether the current process is inside a reserve()/release() write of THIS reservation. */
    public static function isAuthorizedFor(string $reservationId): bool
    {
        return self::$authorizedReservationId !== null && self::$authorizedReservationId === $reservationId;
    }
}
