<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * SaleRefund — the durable refund intent of a settled one-off sale (AID-1997
 * spec §4.5, D8 rev11). Born at R1 with the FULL frozen payload and the
 * COMPLETE frozen fiscal plan (`bucket_plan`: entries + frozen_sources),
 * computed under the reservation lock — the intent RESERVES capacity before
 * any irreversible issuance. Flips to `Refunded` as the refund's LAST write
 * after post-commit detection passes (R3(c)), or to `Abandoned` by the same
 * key's owner-authorized recovery for a proven pre-issuance refusal (R3-7).
 * Never deleted.
 *
 * Written only by the package (OneOffSaleService); consumers read it through
 * the returned results, the reservation's readers and `diagnose()`.
 *
 * @property string $id
 * @property string $sale_reservation_id
 * @property string $refund_key
 * @property SaleRefundStage $stage
 * @property string|null $rectificative_invoice_id
 * @property FixedDecimal $amount
 * @property array{is_full_clone: bool, frozen_sources: array<string, mixed>, entries: array<int, array<string, mixed>>}|null $bucket_plan
 * @property string $currency
 * @property Carbon $refunded_at
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $recorded_by
 * @property string|null $reason
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
class SaleRefund extends Model
{
    use HasUuid;

    protected $fillable = [
        'sale_reservation_id', 'refund_key', 'stage',
        'rectificative_invoice_id', 'amount', 'bucket_plan', 'currency',
        'refunded_at', 'method', 'reference', 'recorded_by', 'reason',
    ];

    public function casts(): array
    {
        return [
            'stage'         => SaleRefundStage::class,
            'amount'        => FixedDecimalCast::class.':2',
            'bucket_plan'   => 'array',
            'refunded_at'   => 'datetime',
            'method'        => PaymentMethod::class,
        ];
    }

    /** @return BelongsTo<SaleReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(SaleReservation::class, 'sale_reservation_id');
    }

    /** The refund-owned rectificative (linked in the creation's phase-1 commit, R3-3).
     *
     * @return HasOne<Invoice, $this>
     */
    public function rectificative(): HasOne
    {
        return $this->hasOne(Invoice::class, 'sale_refund_id');
    }
}
