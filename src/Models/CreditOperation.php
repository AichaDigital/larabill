<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Enums\CreditOperationKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CreditOperation — one keyed ledger operation (AID-1825): the consumer's
 * operation key, what kind of operation it was, whose, and a fingerprint of
 * its immutable payload. The same key with the same fingerprint replays the
 * exits that carry `credit_operation_id`; another payload under the same key
 * is an IdempotencyConflictException. Written only by the package.
 *
 * @property string $id
 * @property string $operation_key
 * @property CreditOperationKind $kind
 * @property string $billable_user_id
 * @property string $fingerprint
 * @property Carbon $recorded_at
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
class CreditOperation extends Model
{
    use HasUuid;

    protected $fillable = ['operation_key', 'kind', 'billable_user_id', 'fingerprint', 'recorded_at'];

    public function casts(): array
    {
        return [
            'kind'        => CreditOperationKind::class,
            'recorded_at' => 'datetime',
        ];
    }

    /** @return HasMany<CreditMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CreditMovement::class, 'credit_operation_id');
    }
}
