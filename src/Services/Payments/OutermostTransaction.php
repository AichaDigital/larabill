<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Payments;

use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use Illuminate\Support\Facades\DB;

/**
 * The transaction-level guard of the payment writes that must be the
 * OUTERMOST transaction (AID-962, spec §13.10 row 45): PaymentService::
 * receive(), allocate(), reverse() and reverseAllocation();
 * GroupedPaymentService::register(); CustomerCredit::apply() and refund();
 * VoucherSaleService::sellVoucher() and consumeVoucher(). Called as their
 * first statement, before any read (receive(), allocate() and register()
 * since 9.5.0, AID-1884).
 *
 * @internal Called only by the guarded services above (PaymentService, GroupedPaymentService, CustomerCredit, VoucherSaleService); the exception and the config key are the contract.
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
