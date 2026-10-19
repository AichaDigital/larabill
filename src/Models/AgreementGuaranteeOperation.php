<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Agreement Guarantee Operation — the ADR-015 §2.6 registry row of one
 * guarantee refund claim (slice 2, AID-953).
 *
 * REGISTRY ONLY (ADR-015 §2.6): larabill records what happened; the
 * CONSUMER decides client policy — eligibility by date, whether to refund
 * at all, the refund channel. Nothing here decides a refund.
 *
 * Lifecycle: `pending` (opened into the registry, agreement moved to the
 * transient `guarantee` state) → the rectificative is issued through
 * `SettlementService::rectify()` stamped with the deterministic operation
 * key (`guarantee_ops:{id}`, §3.4) → `refunded` once the consumer confirms
 * the refund (`refunded_at`, sentinel released). If the refund is never
 * confirmed, the row stays `pending` and the agreement stays `guarantee` —
 * no automatic closure.
 *
 * PENDING-IDEMPOTENCY SENTINEL (gate round 2 P1): `pending_slot` holds the
 * fixed value `'PENDING'` while the operation is pending and NULL once
 * refunded. The UNIQUE index on `(agreement_id, pending_slot)` makes two
 * concurrent pendings for the same agreement impossible (a partial unique
 * index is not portable to MySQL/MariaDB); refunded rows (NULL slot) never
 * collide. Same mechanism as `grouped_payment_invoice.active_invoice_id`.
 *
 * @property string $id UUID v7 — the identity inside the deterministic settlement operation key (§3.4)
 * @property int $agreement_id FK to article_service_status
 * @property string $original_invoice_id FK to the emitted invoice the refund corrects (ADR-014 original)
 * @property string|null $rectifies_invoice_id FK to the rectificative once issued (Phase 2)
 * @property FixedDecimal $amount Refund amount, base-100, strictly positive
 * @property string $status 'pending' | 'refunded'
 * @property Carbon|null $refunded_at Refund confirmation timestamp
 * @property string|null $pending_slot 'PENDING' while pending, NULL once refunded (unique with agreement_id)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ArticleServiceStatus $agreement
 * @property-read Invoice $originalInvoice
 * @property-read Invoice|null $rectifiesInvoice
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class AgreementGuaranteeOperation extends Model
{
    use HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * The fixed sentinel value held while the operation is pending (§2.6).
     */
    public const PENDING_SLOT = 'PENDING';

    /**
     * The table associated with the model.
     */
    protected $table = 'article_guarantee_operations';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'agreement_id',
        'original_invoice_id',
        'rectifies_invoice_id',
        'amount',
        'status',
        'refunded_at',
        'pending_slot',
    ];

    /**
     * The attributes that should be cast.
     */
    public function casts(): array
    {
        return [
            'agreement_id' => 'integer',
            'amount'       => AichaMoneyCast::class.':2',
            'status'       => 'string',
            'refunded_at'  => 'datetime',
        ];
    }

    /**
     * The agreement whose guarantee window this operation belongs to.
     *
     * @return BelongsTo<ArticleServiceStatus, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ArticleServiceStatus::class, 'agreement_id');
    }

    /**
     * The emitted invoice the refund corrects — the rectificative's ADR-014
     * original, whose frozen fiscal context it reuses.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    /**
     * The rectificative issued for this claim, once settle() Phase 2
     * recorded it. NULL while the refund is only opened (or if the consumer
     * confirms a refund settled out-of-band — the registry-only stance).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function rectifiesInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'rectifies_invoice_id');
    }

    /**
     * Whether this operation is still pending (sentinel held).
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Whether the refund of this operation was confirmed (sentinel released).
     */
    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }
}
