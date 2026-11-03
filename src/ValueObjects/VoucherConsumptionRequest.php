<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A consumption of voucher balance against a service (AID-1825 minimal scope
 * §1 item 5): whose balance, how much, in which currency, for which service,
 * and the consumer-declared OPERATION KEY that makes it replayable.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final readonly class VoucherConsumptionRequest
{
    public function __construct(
        public string $billableUserId,
        public FixedDecimal $amount,
        public string $currency,
        public string $serviceRef,
        public ?string $serviceDescription,
        public DateTimeInterface $consumedAt,
        public ?string $recordedBy,
        public string $operationKey,
    ) {
        if (trim($operationKey) === '') {
            throw new InvalidArgumentException('A voucher consumption needs the consumer-declared operation key; larabill never derives one (AID-1825 §1).');
        }

        if (trim($serviceRef) === '') {
            throw new InvalidArgumentException('A voucher consumption names the service it pays for (service_ref).');
        }
    }

    /**
     * The immutable payload a replay must match: payer, amount, currency,
     * the service reference AND its description — what the consumption IS.
     * `consumedAt` and `recordedBy` are retry metadata (a retry carries a
     * new instant and may come from another actor) and are deliberately
     * NOT part of the identity (gate round 5, decision recorded in the
     * CHANGELOG). Encoded as a JSON list, so field boundaries survive any
     * character the fields may contain (a `|` in a reference, gate round 3).
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->billableUserId,
            $this->amount->toScale(2)->unscaledValue(),
            $this->currency,
            $this->serviceRef,
            $this->serviceDescription,
        ], JSON_THROW_ON_ERROR));
    }
}
