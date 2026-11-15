<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use DateTimeInterface;

/**
 * The refund request of a settled one-off sale (AID-1997 spec §3, D8 rev11):
 * the sale's operation key, the refund's OWN idempotency key, the GROSS
 * amount to give back (null = the whole remaining refundable, resolved and
 * frozen at R1), and the money-out facts. The package checks no window, no
 * legal cause and no business rule — when and why to refund is consumer
 * policy (products/larabill.md rev3, owner-approved 2026-11-15).
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
final readonly class SaleRefundRequest
{
    public function __construct(
        public string $operationKey,
        public string $refundKey,
        public ?FixedDecimal $amount,
        public string $currency,
        public DateTimeInterface $refundedAt,
        public PaymentMethod $method,
        public ?string $reference,
        public ?string $recordedBy,
        public ?string $reason,
    ) {
        if (mb_strlen($refundKey) > 191) {
            throw new GroupedPaymentValidationException(
                "The refund key must be at most 191 characters, got {$refundKey}."
            );
        }

        if ($amount !== null) {
            if (! $amount->isPositive()) {
                throw new GroupedPaymentValidationException(
                    "The refund amount must be positive, got {$amount->toDecimalString()}."
                );
            }

            if ($amount->toScale(2)->compareTo($amount) !== 0) {
                throw new GroupedPaymentValidationException(
                    "The refund amount must be sub-cent exact, got {$amount->toDecimalString()}."
                );
            }
        }
    }
}
