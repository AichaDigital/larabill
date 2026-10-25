<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\CreditMovementFactory;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CreditMovement — one append-only row of the customer-credit ledger
 * (AID-962, spec §3.3/§4.2).
 *
 * An ENTRY (SURPLUS, TOP_UP) has a positive amount and is born of a grouped
 * payment; an EXIT (APPLIED, REFUNDED) has a negative amount and points at
 * the entry it consumes. What is left of an entry is its amount plus the
 * sum of its active exits. A reversed movement (`reversed_at` set) no longer
 * counts anywhere. The balance is never stored: it is always a sum.
 *
 * @property string $id
 * @property string $billable_user_id
 * @property string $currency
 * @property CreditMovementKind $kind
 * @property FixedDecimal $amount Base-100, signed: entries > 0, exits < 0
 * @property string $grouped_payment_id
 * @property int|null $allocation_id
 * @property string|null $consumes_movement_id
 * @property string|null $advance_invoice_id
 * @property PaymentMethod|null $method
 * @property string|null $recorded_by
 * @property string|null $reference
 * @property string|null $notes
 * @property Carbon $occurred_at
 * @property Carbon|null $reversed_at
 * @property string|null $reversed_by
 * @property string|null $reverse_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @api Supported public surface (AID-962; see docs/api-surface.md).
 */
class CreditMovement extends Model
{
    /** @use HasFactory<CreditMovementFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'billable_user_id', 'currency', 'kind', 'amount', 'grouped_payment_id', 'allocation_id',
        'consumes_movement_id', 'advance_invoice_id', 'method', 'recorded_by', 'reference', 'notes',
        'occurred_at', 'reversed_at', 'reversed_by', 'reverse_reason',
    ];

    public function casts(): array
    {
        return [
            'amount'      => AichaMoneyCast::class.':2',
            'kind'        => CreditMovementKind::class,
            'method'      => PaymentMethod::class,
            'occurred_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CreditMovementFactory
    {
        return CreditMovementFactory::new();
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

    /**
     * The entry an exit consumes.
     *
     * @return BelongsTo<CreditMovement, $this>
     */
    public function consumes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'consumes_movement_id');
    }

    /**
     * The exits consuming this entry (active and reversed alike).
     *
     * @return HasMany<CreditMovement, $this>
     */
    public function consumedBy(): HasMany
    {
        return $this->hasMany(self::class, 'consumes_movement_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function advanceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'advance_invoice_id');
    }

    public function isEntry(): bool
    {
        return $this->kind->isEntry();
    }

    public function isExit(): bool
    {
        return $this->kind->isExit();
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * What is left of this entry: its amount plus the sum of its ACTIVE exits,
     * read with a fresh query (never the loaded relation). Zero for an exit.
     */
    public function remaining(): FixedDecimal
    {
        if (! $this->isEntry()) {
            return FixedDecimal::zero(2);
        }

        $consumed = (int) static::query()
            ->where('consumes_movement_id', $this->id)
            ->whereNull('reversed_at')
            ->sum('amount');

        return $this->amount->plus(FixedDecimal::ofUnscaled($consumed, 2));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('reversed_at'));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEntries(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('kind'), self::entryKindValues());
    }

    /**
     * Stamp the advance invoice that documents this entry (DECISION 4) — the
     * only setter of `advance_invoice_id`, once, and only on an entry.
     *
     * A conditional atomic UPDATE, never a check in memory: two instances
     * loaded with a null link cannot both link — the first wins, the second
     * fails loud. The instance is refreshed either way.
     *
     * Concurrency (InnoDB): the UPDATE takes the entry's row X lock and an FK
     * shared lock on the advance invoice, while a concurrent
     * CustomerCredit::apply() to that same invoice holds the invoice X lock
     * and takes FK shared locks on the entries it consumes. The two can meet
     * in opposite order and one is chosen as the deadlock victim (SQLSTATE
     * 40001 / error 1213). Nothing is half-written; the consumer retries the
     * call.
     *
     * @throws InvalidCreditMovementException when the row is an exit
     * @throws CreditMovementAlreadyLinkedException when the row is already linked
     */
    public function linkAdvanceInvoice(Invoice $advance): void
    {
        $affected = static::query()
            ->whereKey($this->getKey())
            ->whereNull('advance_invoice_id')
            ->whereIn('kind', self::entryKindValues())
            ->update(['advance_invoice_id' => $advance->getKey()]);

        if ($affected === 0) {
            $current = static::query()->whereKey($this->getKey())->firstOrFail();

            $this->refresh();

            if ($current->isExit()) {
                throw InvalidCreditMovementException::notAnEntry((string) $current->id);
            }

            throw CreditMovementAlreadyLinkedException::forMovement((string) $current->id, (string) $current->advance_invoice_id);
        }

        $this->refresh();
    }

    /** @return list<int> */
    private static function entryKindValues(): array
    {
        return array_values(array_map(
            static fn (CreditMovementKind $kind): int => $kind->value,
            array_filter(CreditMovementKind::cases(), static fn (CreditMovementKind $kind): bool => $kind->isEntry()),
        ));
    }
}
