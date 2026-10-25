<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Payments;

use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use Illuminate\Support\Facades\DB;

/**
 * The transaction-level guard of the payment writes that must be the
 * OUTERMOST transaction (AID-962, spec §13.10 row 45): PaymentService::
 * reverse(), reverseAllocation(), CustomerCredit::apply() and refund().
 * Called as their first statement, before any read.
 *
 * @internal Called only by the two payment services; the exception and the config key are the contract.
 */
final class OutermostTransaction
{
    /**
     * @throws PaymentOutsideTransactionRequiredException when DB::transactionLevel() exceeds `larabill.payments.base_transaction_level`
     */
    public static function assert(string $method): void
    {
        $level     = DB::transactionLevel();
        $baseLevel = (int) config('larabill.payments.base_transaction_level', 0);

        if ($level > $baseLevel) {
            throw PaymentOutsideTransactionRequiredException::forMethod($method, $level, $baseLevel);
        }
    }
}
