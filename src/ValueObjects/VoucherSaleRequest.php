<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\PaymentMethod;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What a consumer declares when a payment arrives for a top-up proforma
 * (AID-1825 minimal scope §1): the proforma, the payer, the money as it
 * arrived and the OPERATION KEY — the consumer-declared identity of the
 * payment event (a card payment-intent id; for a transfer, the consumer's
 * own durable identifier of that credit). larabill replays by that key and
 * never derives one: a sale without a key is refused before any read.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final readonly class VoucherSaleRequest
{
    public function __construct(
        public string $proformaId,
        public string $billableUserId,
        public FixedDecimal $amount,
        public string $currency,
        public DateTimeInterface $receivedAt,
        public PaymentMethod $method,
        public ?string $reference,
        public ?string $recordedBy,
        public string $operationKey,
    ) {
        if (trim($operationKey) === '') {
            throw new InvalidArgumentException('A voucher sale needs the consumer-declared operation key of its payment event; larabill never derives one (AID-1825 §1).');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A voucher sale needs a positive amount.');
        }

        // Cent-exact, like ReceiveRequest: a sub-cent amount would be rounded
        // into an alias of the recorded cents on a same-key re-call (gate round 8).
        if ($amount->toScale(2)->compareTo($amount) !== 0) {
            throw new InvalidArgumentException("A voucher sale amount is cent-exact; {$amount->toDecimalString()} carries a fraction of a cent.");
        }
    }
}
