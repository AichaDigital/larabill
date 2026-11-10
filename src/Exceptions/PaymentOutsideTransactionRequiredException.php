<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use LogicException;

/**
 * A payment write that must run as the OUTERMOST transaction was called
 * inside a caller's transaction (AID-962, spec §13.10 rows 41 and 45).
 *
 * Under REPEATABLE READ a plain read made earlier in the caller's transaction
 * pins the snapshot that the method's sums would read: the row locks return
 * current rows, but the outstanding of an invoice, what is left of a credit
 * entry or the credit plan would be summed from stale ones, and the call
 * could allocate or consume past what exists (some of these methods also
 * read the ids to lock before opening their own transaction, which pins the
 * snapshot by itself). The guard refuses before reading anything. Guarded:
 * PaymentService::receive(), allocate(), reverse(), reverseAllocation();
 * GroupedPaymentService::register(); CustomerCredit::apply(), refund();
 * VoucherSaleService::sellVoucher(), consumeVoucher().
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
            .'A plain read made earlier in the caller\'s transaction pins the REPEATABLE READ snapshot that its sums would read, so it could allocate or consume past what exists. '
            .'Call it outside your transaction, or raise config larabill.payments.base_transaction_level only if your harness itself opens that level (e.g. RefreshDatabase → 1).'
        );
    }
}
