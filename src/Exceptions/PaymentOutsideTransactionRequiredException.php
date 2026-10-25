<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use LogicException;

/**
 * A payment write that must run as the OUTERMOST transaction was called
 * inside a caller's transaction (AID-962, spec §13.10 rows 41 and 45).
 *
 * PaymentService::reverse(), reverseAllocation(), CustomerCredit::apply()
 * and refund() read the ids to lock BEFORE opening their own transaction.
 * Inside a wrapper that read becomes the wrapper's first consistent read and,
 * under REPEATABLE READ, pins the snapshot before the locks: the
 * recomputation (or the credit plan) would read stale rows, and apply()/
 * refund() could consume the same remainder twice. The guard refuses before
 * reading anything.
 *
 * The allowed level is `config('larabill.payments.base_transaction_level')`
 * (default 0). A test suite that wraps every test in a transaction
 * (RefreshDatabase) sets it to 1.
 *
 * @api Supported public surface (AID-962; see docs/api-surface.md).
 */
final class PaymentOutsideTransactionRequiredException extends LogicException
{
    public static function forMethod(string $method, int $level, int $baseLevel): self
    {
        return new self(
            "{$method} must not run inside a caller's transaction: transaction level is {$level}, the allowed base level is {$baseLevel}. "
            .'It reads the ids to lock before opening its own transaction, and inside a wrapper that read would pin the REPEATABLE READ snapshot before the locks. '
            .'Call it outside your transaction, or raise config larabill.payments.base_transaction_level only if your harness itself opens that level (e.g. RefreshDatabase → 1).'
        );
    }
}
