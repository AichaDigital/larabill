<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Payments;

/**
 * How a replayed idempotency key is compared with the payment it already
 * names (AID-962, spec §5.1/§5.2).
 *
 * - Legacy: the 8.x register() comparison — payer, currency, amount and the
 *   distinct invoice ids of the direct allocations.
 * - Full: Legacy plus receivedAt (to the second, UTC), method, reference,
 *   creditKind (only when the payment created an entry) and the per-invoice
 *   allocation map.
 *
 * @internal Selected by the payment services, never by a consumer.
 */
enum ReplayPolicy
{
    case Legacy;
    case Full;
}
