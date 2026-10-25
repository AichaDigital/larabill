<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Payments;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The validated, immutable input of one PaymentService::receive() call
 * (AID-962, spec §5.1.1-§5.1.2), carrying its idempotency key.
 *
 * Amounts are normalised to scale 2 (they are cent-exact by construction),
 * and receivedAt to the application timezone so it persists as the same
 * instant it was given.
 *
 * @internal Built by PaymentService and GroupedPaymentService; not consumer surface.
 */
final readonly class ReceiveRequest
{
    /**
     * @param  list<Allocation>  $allocations
     * @param  bool  $replayOnly  true only for forLegacyReplay(): its allocations are 1-cent placeholders, so PaymentService::process() refuses it before any write
     */
    public function __construct(
        public string $billableUserId,
        public FixedDecimal $amount,
        public string $currency,
        public CarbonImmutable $receivedAt,
        public PaymentMethod $method,
        public ?string $reference,
        public ?string $recordedBy,
        public array $allocations,
        public CreditMovementKind $creditKind,
        public string $idempotencyKey,
        public bool $replayOnly = false,
    ) {}

    /**
     * Validate the receive() input (spec §5.1.2) and derive the key when none
     * is given: sha256 of
     * `billable|currency|amountCents|receivedAt (Y-m-d\TH:i:s\Z, UTC)|method|reference|creditKind|invoiceId:cents,…`
     * with the allocations sorted by invoice id.
     *
     * @param  array<int, mixed>  $allocations  list<Allocation>
     *
     * @throws GroupedPaymentValidationException on a non-positive or sub-cent amount, an exit kind, or duplicate invoice ids
     * @throws AllocationExceedsPaymentException when the allocations add up to more than the amount
     */
    public static function fromReceive(
        string $billableUserId,
        FixedDecimal $amount,
        string $currency,
        DateTimeInterface $receivedAt,
        PaymentMethod $method,
        ?string $reference,
        ?string $recordedBy,
        array $allocations,
        CreditMovementKind $creditKind,
        ?string $idempotencyKey,
    ): self {
        if (! $amount->isPositive()) {
            throw GroupedPaymentValidationException::nonPositiveAmount($amount->unscaledValue());
        }

        if ($amount->toScale(2)->compareTo($amount) !== 0) {
            throw GroupedPaymentValidationException::subCentAmount($amount->toDecimalString());
        }

        if (! $creditKind->isEntry()) {
            throw GroupedPaymentValidationException::creditKindNotAnEntry($creditKind);
        }

        $list = [];
        foreach ($allocations as $allocation) {
            if (! $allocation instanceof Allocation) {
                throw new InvalidArgumentException('receive() allocations must be '.Allocation::class.' instances.');
            }
            $list[] = $allocation;
        }

        $ids = array_map(static fn (Allocation $a): string => $a->invoiceId, $list);
        if (count($ids) !== count(array_unique($ids))) {
            $dupes = array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1));
            throw GroupedPaymentValidationException::duplicateInvoices(array_map(static fn ($id): string => (string) $id, $dupes));
        }

        $amount = $amount->toScale(2);
        $at     = CarbonImmutable::instance($receivedAt)->setTimezone(date_default_timezone_get());

        $request = new self(
            $billableUserId, $amount, $currency, $at, $method, $reference, $recordedBy, $list, $creditKind, '',
        );

        $key = $idempotencyKey ?? $request->deriveIdempotencyKey();

        $allocated = array_sum($request->allocationMap());
        if ($allocated > $amount->unscaledValue()) {
            throw AllocationExceedsPaymentException::forPayment($key, $amount->unscaledValue(), $allocated);
        }

        return new self(
            $billableUserId, $amount, $currency, $at, $method, $reference, $recordedBy, $list, $creditKind, $key,
        );
    }

    /**
     * The request register() compares a replayed key with BEFORE it reads the
     * invoices (spec §5.2): only what ReplayPolicy::Legacy reads is real —
     * payer, currency, amount and the invoice ids. The per-invoice totals are
     * unknown before the lock, so each allocation carries a placeholder cent.
     * Never pass this request to PaymentService::process(): it must not feed a
     * write, and process() enforces it (replayOnly, LogicException).
     *
     * @param  list<string>  $invoiceIds
     */
    public static function forLegacyReplay(
        string $billableUserId,
        array $invoiceIds,
        FixedDecimal $amount,
        string $currency,
        DateTimeInterface $paidAt,
        ?string $reference,
        string $idempotencyKey,
    ): self {
        return new self(
            $billableUserId,
            $amount,
            $currency,
            CarbonImmutable::instance($paidAt),
            PaymentMethod::Card,
            $reference,
            null,
            array_map(static fn ($id): Allocation => new Allocation((string) $id, FixedDecimal::ofUnscaled(1, 2)), array_values($invoiceIds)),
            CreditMovementKind::SURPLUS,
            $idempotencyKey,
            replayOnly: true,
        );
    }

    /**
     * Requested cents per invoice id, sorted by invoice id.
     *
     * @return array<string, int>
     */
    public function allocationMap(): array
    {
        $map = [];
        foreach ($this->allocations as $allocation) {
            $map[$allocation->invoiceId] = ($map[$allocation->invoiceId] ?? 0) + $allocation->amount->toScale(2)->unscaledValue();
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * The requested invoice ids, sorted — duplicates kept, so a request that
     * names an invoice twice never compares equal to a distinct set.
     *
     * @return list<string>
     */
    public function invoiceIds(): array
    {
        $ids = array_map(static fn (Allocation $a): string => $a->invoiceId, $this->allocations);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** Sum of the requested allocations (scale 2). */
    public function allocatedAmount(): FixedDecimal
    {
        return FixedDecimal::ofUnscaled(array_sum($this->allocationMap()), 2);
    }

    private function deriveIdempotencyKey(): string
    {
        $allocations = [];
        foreach ($this->allocationMap() as $invoiceId => $cents) {
            $allocations[] = $invoiceId.':'.$cents;
        }

        return hash('sha256', implode('|', [
            $this->billableUserId,
            $this->currency,
            (string) $this->amount->unscaledValue(),
            $this->receivedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            (string) $this->method->value,
            $this->reference ?? '',
            (string) $this->creditKind->value,
            implode(',', $allocations),
        ]));
    }
}
