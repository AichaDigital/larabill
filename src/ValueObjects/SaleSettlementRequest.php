<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The settlement request of a one-off sale (AID-1997 spec §3, §4.3): the
 * operation key of the RESERVATION (not a fresh key — the reservation owns
 * the idempotency), the payer, the amount actually charged, and the receipt
 * facts. The frozen expectation and the locked proforma are both revalidated
 * against it at S1 (D4: a mismatching charge is refused with zero writes).
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
final readonly class SaleSettlementRequest
{
    public function __construct(
        public string $operationKey,
        public string $billableUserId,
        public FixedDecimal $amount,
        public string $currency,
        public DateTimeInterface $receivedAt,
        public PaymentMethod $method,
        public ?string $reference,
        public ?string $recordedBy,
    ) {
        if (! $amount->isPositive()) {
            throw new GroupedPaymentValidationException(
                "The one-off sale charge amount must be positive, got {$amount->toDecimalString()}."
            );
        }

        if ($amount->toScale(2)->compareTo($amount) !== 0) {
            throw new GroupedPaymentValidationException(
                "The one-off sale charge amount must be sub-cent exact, got {$amount->toDecimalString()}."
            );
        }
    }

    public function receivedAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::instance(Carbon::instance($this->receivedAt)->utc());
    }
}
