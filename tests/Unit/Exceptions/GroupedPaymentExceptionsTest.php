<?php

use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException as V;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException as C;

it('builds every validation failure', function () {
    expect(V::emptyInvoiceList())->toBeInstanceOf(V::class);
    expect(V::duplicateInvoices(['a', 'a'])->getMessage())->toContain('a');
    expect(V::mixedUsers())->toBeInstanceOf(V::class);
    expect(V::currencyMismatch('inv-1', 'EUR', 'USD')->getMessage())->toContain('EUR')->toContain('USD');
    expect(V::proformaNotPayable('inv-1')->getMessage())->toContain('inv-1');
    expect(V::notPayableStatus('inv-1', 0)->getMessage())->toContain('inv-1');
    expect(V::alreadyActivelyPaid('inv-1')->getMessage())->toContain('inv-1');
    expect(V::amountMismatch(1000, 999)->getMessage())->toContain('1000')->toContain('999');
    expect(V::invoicesNotFound(['x'])->getMessage())->toContain('x');
});

it('builds idempotency conflicts', function () {
    expect(C::forKey('k-1')->getMessage())->toContain('k-1');
    expect(C::keySpentByReversal('k-2')->getMessage())->toContain('k-2');
});
