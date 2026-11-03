<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Database\Factories\InvoiceItemFactory;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Services\ModelMappingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItem Model - Immutable Record Layer
 *
 * Represents an item within an invoice with immutable tax snapshot.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * v0.3.3: Agnostic Tax System Refactor
 * - Removed: tax_rate, tax_category_id (old single-tax approach)
 * - Added: total_tax_amount, taxes_applied (JSON) for multiple taxes support
 * - Tax immutability: Once created, the invoice item stores a permanent snapshot
 *   of all applied taxes, independent of future changes to tax_rates or tax_groups
 *
 * Tax Structure:
 * - total_tax_amount: Sum of all taxes (base-100 integer) - optimized for aggregation
 * - taxes_applied: JSON array with detailed tax breakdown
 *   Example: [{"source_rate_id":12,"name":"MA State Sales Tax","rate":625,"amount":625}]
 *
 * @property int $id
 * @property string $invoice_id UUID foreign key to invoices table
 * @property string|null $deducts_invoice_id Advance invoice this line deducts (AID-962, DECISION 4)
 * @property int|null $article_id FK to articles (v0.4.0)
 * @property ItemType $item_type Good or Service
 * @property string $description
 * @property string|null $internal_code User product/service code
 * @property FixedDecimal $quantity Scale-2 FixedDecimal (1.50 stored unscaled 150)
 * @property int|null $unit_measure_id FK to unit_measures
 * @property FixedDecimal $unit_price Scale-2 FixedDecimal (€12.34 stored unscaled 1234)
 * @property FixedDecimal $taxable_amount Scale-2 FixedDecimal (€12.34 stored unscaled 1234)
 * @property FixedDecimal $total_tax_amount Scale-2 FixedDecimal - sum of all taxes
 * @property array<int, array{source_rate_id: int, name: string, rate: int, amount: int}> $taxes_applied JSON snapshot of applied taxes (immutable)
 * @property FixedDecimal $total_amount Scale-2 FixedDecimal (€12.34 stored unscaled 1234)
 * @property int $tax_rate Computed from taxes_applied (first tax rate or 0)
 * @property Carbon|null $service_date_from Service start date
 * @property Carbon|null $service_date_to Service end date
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    /**
     * Boot the model (ADR-016 §6.5, PR-2): no line mutation of any kind —
     * create, update or delete — on a fiscally protected invoice (persisted
     * `is_immutable` OR persisted document status ISSUED), through any public
     * operation. v7 §7.2's line-level half of D8; the header half is the
     * `Invoice` `updating` guard shipped in the same PR. Raw SQL and
     * query-builder mass operations remain outside this guarantee — the
     * DB-level half of D8 was cancelled in AID-468.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (InvoiceItem $item): void {
            $item->guardParents('writing a line');
        });

        static::deleting(function (InvoiceItem $item): void {
            $item->guardParents('deleting a line');
        });
    }

    /**
     * Whether the parent invoice's PERSISTED state is fiscally protected
     * (ADR-016 §6.5): immutable, or document status ISSUED.
     */
    /**
     * AID-1825 §1a rule 1: the lines of a claimed proforma or of its PREPARED
     * sale invoice are closed to writes and deletes outside `sellVoucher()`
     * — a line delete can keep the header total and slip past the header
     * revalidation, so the lines are guarded on their own events.
     *
     * @throws SaleClaimedException
     */
    /**
     * ONE pass over both parents of the write — the ORIGINAL parent (a line
     * moved out of a document) and the destination (a line moved into one):
     * locked in id order (the same order for every writer, so two opposite
     * moves cannot deadlock — gate round 4), each judged by its COMMITTED
     * state: fiscally protected (immutable or ISSUED — ADR-016 §6.5, now on
     * the original parent too, gate round 4) or claimed by a voucher sale
     * outside the sale (AID-1825 §1a rule 1, gate round 1 B3).
     *
     * @throws \Exception|SaleClaimedException
     */
    public function guardParents(string $operation): void
    {
        $parentIds = array_values(array_unique(array_filter([
            $this->getRawOriginal('invoice_id'),
            $this->getAttribute('invoice_id'),
        ], static fn ($id): bool => $id !== null)));
        sort($parentIds);

        foreach ($parentIds as $parentId) {
            // A LOCKING read on THIS model's connection: the row as committed
            // now, held until this write commits (transactional save/delete).
            $invoice = (new Invoice)->setConnection($this->getConnectionName())->newQuery()->whereKey($parentId)->lockForUpdate()->first();
            if ($invoice === null) {
                continue;
            }

            $originalDoc = $invoice->getRawOriginal('invoice_document_status');
            if ((bool) $invoice->getRawOriginal('is_immutable') || (is_int($originalDoc) && $originalDoc === InvoiceDocumentStatus::ISSUED->value)) {
                throw new \Exception(sprintf(
                    'Cannot %s of a fiscally protected invoice (ADR-016 §6.5: immutable or ISSUED documents are closed to line mutation through any public operation).',
                    $operation === 'deleting a line' ? 'delete invoice lines' : 'write invoice lines',
                ));
            }

            $claim = $invoice->openSaleClaim(lock: true);
            if ($claim === null || VoucherSaleClaim::isAuthorizedFor((string) $claim->id)) {
                continue;
            }

            throw $invoice->getRawOriginal('sale_claim_id') !== null
                ? SaleClaimedException::invoice((string) $invoice->getKey(), (string) $claim->id, $operation)
                : SaleClaimedException::proforma((string) $invoice->getKey(), (string) $claim->id, $operation);
        }
    }

    /** @deprecated since the sale boundary — kept for callers; guardParents() is what the model events run. */
    public function assertParentNotClaimed(string $operation): void
    {
        $this->guardParents($operation);
    }

    public function parentInvoiceIsProtected(): bool
    {
        // A LOCKING read of the parent: it returns the row as committed now,
        // not the caller's repeatable-read snapshot of it (gate round 3,
        // stale parent state), and holds it until this write commits (the
        // transactional save()/delete()).
        $invoice = $this->invoice()->lockForUpdate()->first();

        if ($invoice === null) {
            return false;
        }

        $originalDoc = $invoice->getRawOriginal('invoice_document_status');

        return (bool) $invoice->getRawOriginal('is_immutable')
            || (is_int($originalDoc) && $originalDoc === InvoiceDocumentStatus::ISSUED->value);
    }

    /**
     * The UUID columns for this model.
     * Including foreign key to enable proper UUID binary conversions in queries.
     *
     * @return list<string>
     */
    public function uuidColumns(): array
    {
        return ['invoice_id'];
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'invoice_id',
        'article_id',
        'item_type',
        'description',
        'internal_code',
        'quantity',
        'unit_measure_id',
        'unit_price',
        'taxable_amount',
        'total_tax_amount',
        'taxes_applied',
        'total_amount',
        'service_date_from',
        'service_date_to',
        'metadata',
        'deducts_invoice_id',
    ];

    /**
     * Casts for attributes.
     *
     * Uses Base100 cast from lara100 package for monetary values, quantities and percentages
     * Automatically handles conversion between decimals and base-100 integers
     * Example: €12.34 ↔ 1234, 1.5 ↔ 150, 21.50% ↔ 2150
     *
     * Note: invoice_id is handled by accessor/mutator for binary UUID conversion
     */
    public function casts(): array
    {
        return [
            'item_type'         => ItemType::class, // PHP Enum
            'quantity'          => FixedDecimalCast::class.':2', // 1.50 ↔ 150 (NOT money — no AEAT cap, AID-1527 §2 G2)
            'unit_price'        => AichaMoneyCast::class.':2', // €12.34 ↔ 1234 (AEAT domain, AID-1527)
            'taxable_amount'    => AichaMoneyCast::class.':2', // €12.34 ↔ 1234 (AEAT domain, AID-1527)
            'total_tax_amount'  => AichaMoneyCast::class.':2',
            'taxes_applied'     => 'array',
            'total_amount'      => AichaMoneyCast::class.':2', // €12.34 ↔ 1234 (AEAT domain, AID-1527)
            'service_date_from' => 'date',
            'service_date_to'   => 'date',
            'metadata'          => 'array',
        ];
    }

    /**
     * Override update to prevent modifications of immutable invoices' lines.
     *
     * Same pattern as Invoice::update() (AID-558, D8): the fiscal content of
     * a frozen invoice is protected at the line level too. save() stays as
     * the deliberate internal door for mutable documents — since ADR-016 §6.5
     * (PR-2) the `saving` guard above closes it on protected ones (immutable
     * or ISSUED), reading the parent's persisted state. Raw SQL and
     * query-builder mass updates remain outside this guarantee — the DB-level
     * half of D8 was cancelled in AID-468.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    /**
     * AID-1825 §1a rule 1: the parent-claim guards in `saving`/`deleting`
     * lock the parent row(s) and read the claim; the lock must outlive the
     * write for the check to be atomic, so saves and deletes run inside a
     * transaction (a savepoint for callers already in one). See Invoice::save().
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return (bool) $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        /** @var bool|null $deleted */
        $deleted = $this->getConnection()->transaction(fn (): ?bool => parent::delete());

        return $deleted;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $invoice = $this->invoice()->getResults();

        $originalDoc = $invoice?->getRawOriginal('invoice_document_status');

        if ($invoice !== null
            && ((bool) $invoice->getRawOriginal('is_immutable')
                || (is_int($originalDoc) && $originalDoc === InvoiceDocumentStatus::ISSUED->value))) {
            throw new \Exception('Cannot update items of an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Get the invoice that owns the item.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        $invoiceModel = ModelMappingService::getModelClass('invoice');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($invoiceModel, 'invoice_id', 'id');
    }

    /**
     * Get the unit measure for this item
     *
     * @return BelongsTo<UnitMeasure, $this>
     */
    public function unitMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitMeasure::class);
    }

    /**
     * The advance invoice this line deducts (AID-962, DECISION 4), so the
     * consumer can query which lines deduct a given advance.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function deductsInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'deducts_invoice_id');
    }

    // ========================================
    // CALCULATION HELPERS
    // ========================================

    /**
     * Calculate taxable amount from quantity and unit price.
     *
     * EU/Spain norm: settle quantity × unit_price to the cent with HalfUp
     * (round half away from zero), never truncation. The exact product is
     * scale 4; toScale(2, HalfUp) rounds it to the persisted cent.
     */
    public function calculateTaxableAmount(): FixedDecimal
    {
        return $this->quantity
            ->multipliedBy($this->unit_price)
            ->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Get tax breakdown from the immutable taxes_applied snapshot.
     *
     * @return array<int, array{source_rate_id: int, name: string, rate: int, amount: int}>
     */
    public function getTaxBreakdown(): array
    {
        return $this->taxes_applied ?? [];
    }

    /**
     * Accessor for tax_rate (computed from taxes_applied).
     * Returns the first tax rate in base100 format, or 0 if no taxes.
     */
    public function getTaxRateAttribute(): int
    {
        $taxes = $this->taxes_applied ?? [];

        if (empty($taxes)) {
            return 0;
        }

        // Return the rate from the first tax
        return (int) ($taxes[0]['rate'] ?? 0);
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope for goods only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeGoods(Builder $query): Builder
    {
        return $query->where('item_type', ItemType::GOOD->value);
    }

    /**
     * Scope for services only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeServices(Builder $query): Builder
    {
        return $query->where('item_type', ItemType::SERVICE->value);
    }

    /**
     * Scope for items with service dates
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithServiceDates(Builder $query): Builder
    {
        return $query->whereNotNull('service_date_from')
            ->whereNotNull('service_date_to');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceItemFactory
    {
        return InvoiceItemFactory::new();
    }
}
