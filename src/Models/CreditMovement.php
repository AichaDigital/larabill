<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Database\Factories\CreditMovementFactory;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property int|null $band_start Base-100 cent offset where this exit's band starts on its entry's tape (AID-1778); NULL on entries and on unresolvable entries
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
        'consumes_movement_id', 'band_start', 'advance_invoice_id', 'method', 'recorded_by', 'reference', 'notes',
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
     * Stamp the advance invoice that documents this entry — the only setter
     * of `advance_invoice_id`, once, and only on an entry. The link is
     * one-to-one in BOTH directions (AID-1778 §5.6, O2): an entry cannot
     * link twice, and an advance cannot back two entries.
     *
     * Since 9.0 the advance must be an unnumbered DRAFT fiscal INVOICE at
     * link time (the documented consumer sequence is build → link → issue):
     * the narrowing refuses a non-`invoice` document
     * (`InvalidAdvanceInvoiceException::advanceNotAnInvoice()`) and a
     * non-draft one (`::advanceNotDraft()`). The 8.3.0 method accepted any
     * document in any state — see `UPGRADE-9.0.md`.
     *
     * Steps, inside ONE short transaction: (1) FIRST statement, the X-lock
     * read of the advance invoice row — the serialization point, which makes
     * the document checks and the conflict read race-free against a
     * concurrent `issue()` of the same advance; (2) the KIND check, then the
     * DRAFT check, on the LOCKED instance; (3) the advance-side conflict set
     * materialized in PHP AFTER the lock — non-empty →
     * `CreditMovementAlreadyLinkedException::forAdvance()` naming the
     * winning entry, thrown BEFORE any UPDATE, so the refused entry's own
     * row stays NULL; (4) the conditional atomic UPDATE with the entry-side
     * guard only (`whereNull('advance_invoice_id')` + entry kinds), still
     * needed because two advances racing to link the SAME entry serialize on
     * DIFFERENT invoice locks — the loser surfaces `::forMovement` from the
     * `affected === 0` handling. The UPDATE contains no subquery over
     * `credit_movements` (the conflict ids are consumed as a PHP emptiness
     * check), so MySQL's same-target-table refusal (ERROR 1093) cannot arise
     * and the SQL is identical on MySQL 8.4 and MariaDB 11.4.
     *
     * Caller contract (NOT enforced, same snapshot rule as
     * `PaymentService::receive()`/`allocate()`): call this as the OUTERMOST
     * transaction, or inside a wrapper that has made NO prior read. Under
     * REPEATABLE READ a plain read made earlier by the caller fixes the
     * snapshot, and the conflict read of step (3) would see stale rows even
     * after the invoice lock is acquired. The outermost/empty-wrapper cases
     * are the supported contexts (spec R24); a locking read of the conflict
     * set was deliberately rejected — an empty-range lock on
     * `advance_invoice_id` is a gap lock, the AID-715 deadlock pattern.
     *
     * Concurrency (InnoDB): the transaction takes the advance invoice's row
     * X lock before touching `credit_movements`; the UPDATE then takes the
     * entry's row X lock plus an FK shared lock on the advance invoice. A
     * concurrent `CustomerCredit::apply()` holds its payments, takes the
     * invoice X lock and takes FK shared locks on the entry rows it consumes
     * while inserting exits — the two paths can meet in opposite order and
     * one is chosen as the deadlock victim (SQLSTATE 40001 / error 1213).
     * Nothing is half-written; the consumer retries the call (spec R27 pins
     * the retry contract on this lock order).
     *
     * @throws InvalidCreditMovementException when the row is an exit
     * @throws CreditMovementAlreadyLinkedException when the row is already linked, or the advance is already linked to another entry
     * @throws InvalidAdvanceInvoiceException when the advance is not a fiscal invoice, or is no longer an unnumbered DRAFT
     *
     * @since 9.0 Narrowed to an unnumbered DRAFT fiscal invoice (8.3.0 accepted any document in any state).
     */
    public function linkAdvanceInvoice(Invoice $advance): void
    {
        DB::transaction(function () use ($advance): void {
            // (1) FIRST statement: the locking read of the advance invoice
            // row — the serialization point of every link of this advance.
            $locked = Invoice::query()
                ->whereKey($advance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // (2) Document checks on the LOCKED instance — KIND first (a
            // proforma carries no document status, so the draft check alone
            // would not name the cause), then DRAFT. The row-level document
            // kind is the `serie` enum (the `type` of spec §5.6 is the
            // createInvoice() input that selects it — there is no `type`
            // column); only an ordinary INVOICE can back an advance.
            if ($locked->serie !== InvoiceSerieType::INVOICE) {
                throw InvalidAdvanceInvoiceException::advanceNotAnInvoice((string) $locked->id);
            }

            if ($locked->invoice_document_status !== InvoiceDocumentStatus::DRAFT) {
                throw InvalidAdvanceInvoiceException::advanceNotDraft((string) $locked->id);
            }

            // (3) The advance-side conflict set, materialized in PHP AFTER
            // the lock: two concurrent linkers of the same advance serialize
            // on that lock, the loser reads the committed winner and throws
            // BEFORE any UPDATE — its own row stays NULL (J1).
            $conflicts = static::query()
                ->select('id')
                ->where('advance_invoice_id', $locked->getKey())
                ->where('id', '!=', $this->getKey())
                ->pluck('id');

            if ($conflicts->isNotEmpty()) {
                throw CreditMovementAlreadyLinkedException::forAdvance(
                    (string) $locked->id,
                    (string) $conflicts->first(),
                );
            }

            // (4) The conditional UPDATE keeps ONLY the entry-side guard:
            // two advances racing to link the SAME entry serialize on
            // different invoice locks, so one passes its own conflict check
            // and must lose here, surfacing ::forMovement (unchanged from
            // 8.3.0). No subquery over credit_movements — no ERROR 1093.
            $affected = static::query()
                ->whereKey($this->getKey())
                ->whereNull('advance_invoice_id')
                ->whereIn('kind', self::entryKindValues())
                ->update(['advance_invoice_id' => $locked->getKey()]);

            if ($affected === 0) {
                $current = static::query()->whereKey($this->getKey())->firstOrFail();

                $this->refresh();

                if ($current->isExit()) {
                    throw InvalidCreditMovementException::notAnEntry((string) $current->id);
                }

                throw CreditMovementAlreadyLinkedException::forMovement((string) $current->id, (string) $current->advance_invoice_id);
            }
        });

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
