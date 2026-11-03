<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUuid;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\SaleIntegrityReason;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * VoucherSaleClaim — the claim a payment places on a proforma at S1 of a
 * voucher sale (AID-1825 minimal scope §1), one per proforma. It carries the
 * stage of the sale, the FROZEN EXPECTATION the later stages revalidate
 * (amount, currency, billable user — §1a rule 4), the sale mark
 * (`issued_by_sale`: `issue()` ran inside `sellVoucher()`) and whether the
 * optional issuance hook ran. `operation_key` is the sale's idempotency key:
 * the consumer-declared payment event.
 *
 * Written only by the package (VoucherSaleService); consumers read it through
 * the sale invoice (`Invoice::saleClaim()`) and the recovery reads.
 *
 * @property string $id
 * @property string $proforma_id
 * @property string $grouped_payment_id
 * @property string $operation_key
 * @property VoucherSaleStage $stage
 * @property FixedDecimal $expected_amount
 * @property string $expected_currency
 * @property string $expected_billable_user_id
 * @property bool $issued_by_sale
 * @property bool $hook_ran
 * @property Carbon|null $issuing_at
 * @property Carbon|null $backed_at
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
class VoucherSaleClaim extends Model
{
    use HasUuid;

    protected $fillable = [
        'proforma_id', 'grouped_payment_id', 'operation_key', 'stage',
        'expected_amount', 'expected_currency', 'expected_billable_user_id',
        'issued_by_sale', 'hook_ran', 'issuing_at', 'backed_at',
    ];

    public function casts(): array
    {
        return [
            'stage'           => VoucherSaleStage::class,
            'expected_amount' => FixedDecimalCast::class.':2',
            'issued_by_sale'  => 'boolean',
            'hook_ran'        => 'boolean',
            'issuing_at'      => 'datetime',
            'backed_at'       => 'datetime',
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

    /**
     * The sale invoice born of this claim (set at S2), if any.
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'sale_claim_id');
    }

    /**
     * The sale invoice against this claim's frozen expectation (§1a rule 4,
     * the invoice half): it references this claim, carries the expected
     * total and the expected payer. `issue()` runs it under the invoice lock
     * BEFORE the number is consumed (gate round 6: a direct rewrite of the
     * PREPARED invoice between S3a and issuance must be refused there, not
     * discovered by S3b after the correlative is gone).
     *
     * @throws SaleIntegrityException
     */
    public function assertInvoiceMatches(Invoice $invoice, bool $preSettlement = false): void
    {
        $id = (string) $this->id;

        if ((string) $invoice->sale_claim_id !== $id) {
            throw SaleIntegrityException::because(SaleIntegrityReason::ClaimMissing, $id, (string) $invoice->id, 'the invoice does not reference this claim');
        }

        if ($invoice->total_amount->compareTo($this->expected_amount) !== 0) {
            throw SaleIntegrityException::because(SaleIntegrityReason::AmountDrift, $id, (string) $invoice->id, "expected {$this->expected_amount->unscaledValue()}, invoice {$invoice->total_amount->unscaledValue()} (base-100)");
        }

        if ((string) $invoice->billable_user_id !== $this->expected_billable_user_id) {
            throw SaleIntegrityException::because(SaleIntegrityReason::PayerDrift, $id, (string) $invoice->id, 'the payer of the invoice is not the expected billable user');
        }

        // Before S3b nothing may be collected or backed yet — the same
        // predicates S3a applies, repeated by issue() under its own locks
        // (gate round 7: a collection write between S3a and the number, or
        // inside the consumer hook, must be refused before the fiscal call).
        if ($preSettlement) {
            if (PaymentAllocation::query()->where('invoice_id', $invoice->id)->where('status', AllocationStatus::ACTIVE->value)->exists()) {
                throw SaleIntegrityException::because(SaleIntegrityReason::CompetingAllocation, $id, (string) $invoice->id, 'the sale invoice already carries an allocation before settlement');
            }

            if ($invoice->status !== InvoiceStatus::DRAFT || $invoice->paid_at !== null || CreditMovement::query()->where('sale_invoice_id', $invoice->id)->active()->entries()->exists()) {
                throw SaleIntegrityException::because(SaleIntegrityReason::CollectionStateUnexpected, $id, (string) $invoice->id, "collection status {$invoice->status->name}, a paid_at or a voucher entry before settlement");
            }
        }
    }

    /**
     * The funding payment against this claim's frozen expectation (§1a rule
     * 4, the half that needs no invoice): POSTED and never reversed, the
     * expected amount, currency and payer. Used by S2 before converting, by
     * S3a/S3b, and by `issue()` under the payment lock before the number is
     * consumed and again after the consumer hook (gate round 5).
     *
     * @throws SaleIntegrityException
     */
    public function assertPaymentMatches(GroupedPayment $payment, ?string $invoiceId): void
    {
        $id = (string) $this->id;

        if (! $payment->isPosted() || $payment->reversed_at !== null) {
            throw SaleIntegrityException::because(SaleIntegrityReason::PaymentNotPosted, $id, $invoiceId, 'the funding payment is not POSTED (or carries a reversal instant)');
        }

        if ($payment->amount->compareTo($this->expected_amount) !== 0) {
            throw SaleIntegrityException::because(SaleIntegrityReason::AmountDrift, $id, $invoiceId, "expected {$this->expected_amount->unscaledValue()}, payment {$payment->amount->unscaledValue()} (base-100)");
        }

        if ($payment->currency !== $this->expected_currency) {
            throw SaleIntegrityException::because(SaleIntegrityReason::CurrencyDrift, $id, $invoiceId, "expected {$this->expected_currency}, payment {$payment->currency}");
        }

        if ((string) $payment->billable_user_id !== $this->expected_billable_user_id) {
            throw SaleIntegrityException::because(SaleIntegrityReason::PayerDrift, $id, $invoiceId, 'the payer of the payment is not the expected billable user');
        }
    }

    /**
     * The sale authorization (AID-1825 §1a rules 1-2): the four invoice
     * paths that a claimed row refuses — conversion, issue, prepared
     * cancellation, and the claimed rows' own model guards — are legal only
     * while `sellVoucher()` runs them. Same per-process flag pattern as
     * Invoice::withIssuanceAuthorization(); reentrant-safe.
     *
     * @internal VoucherSaleService is the only caller.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function withSaleAuthorization(string $claimId, callable $write): mixed
    {
        $previous                = self::$authorizedClaimId;
        self::$authorizedClaimId = $claimId;

        try {
            return $write();
        } finally {
            self::$authorizedClaimId = $previous;
        }
    }

    /**
     * Switch the authorization OFF for the duration of $callback — used
     * around the consumer's issuance hook, so consumer code running inside
     * the sale cannot write to claimed rows (implementation gate round 1 B4).
     *
     * @internal InvoiceService::issue() is the only caller.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutSaleAuthorization(callable $callback): mixed
    {
        $previous                = self::$authorizedClaimId;
        self::$authorizedClaimId = null;

        try {
            return $callback();
        } finally {
            self::$authorizedClaimId = $previous;
        }
    }

    /** Whether the current process is inside a sellVoucher() stage of THIS claim. */
    public static function isAuthorizedFor(string $claimId): bool
    {
        return self::$authorizedClaimId !== null && self::$authorizedClaimId === $claimId;
    }

    private static ?string $authorizedClaimId = null;
}
