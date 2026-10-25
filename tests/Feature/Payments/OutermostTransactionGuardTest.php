<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Support\Facades\DB;

/*
 * Spec §13.10 row 45: reverse(), reverseAllocation(), CustomerCredit::apply()
 * and refund() refuse to run above `larabill.payments.base_transaction_level`
 * — before reading anything. The suite's base level is 1 (RefreshDatabase,
 * tests/TestCase.php), so a DB::transaction() inside a test is level 2.
 */

/** A paid invoice and a TOP_UP credit, both born outside any wrapper. */
function aid962GuardFixture(): array
{
    $inv     = PaymentFixtures::sentInvoice(12100);
    $svc     = app(PaymentService::class);
    $payment = $svc->receive(TestCase::USER_UUID_2, cents(12100), 'EUR', now()->subDay()->startOfSecond(), PaymentMethod::BankTransfer, 'TRF-1', null, [new Allocation($inv->id, cents(12100))]);
    $credit  = $svc->receive(TestCase::USER_UUID_2, cents(5000), 'EUR', now()->subDay()->startOfSecond(), PaymentMethod::BankTransfer, 'TRF-2', null, [], CreditMovementKind::TOP_UP);
    $open    = PaymentFixtures::sentInvoice(3000);

    return [$inv, $payment, $credit, $open];
}

/** @return array{int, int, int, string} */
function aid962GuardState(GroupedPayment $payment): array
{
    return [
        GroupedPayment::query()->where('status', GroupedPaymentStatus::REVERSED->value)->count(),
        PaymentAllocation::query()->count(),
        CreditMovement::query()->count(),
        (string) $payment->fresh()->status->value,
    ];
}

dataset('guarded payment writes', [
    'PaymentService::reverse()'           => [fn (GroupedPayment $payment, $open) => app(PaymentService::class)->reverse($payment, 'test'), 'reverse'],
    'PaymentService::reverseAllocation()' => [fn (GroupedPayment $payment, $open) => app(PaymentService::class)->reverseAllocation((int) $payment->allocations->first()->id, 'test'), 'reverseAllocation'],
    'CustomerCredit::apply()'             => [fn (GroupedPayment $payment, $open) => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $open, cents(3000)), 'apply'],
    'CustomerCredit::refund()'            => [fn (GroupedPayment $payment, $open) => app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(1000), 'EUR', now(), PaymentMethod::BankTransfer), 'refund'],
]);

it('refuses inside a caller transaction, before any read and with nothing written', function (Closure $call, string $method) {
    [, $payment, , $open] = aid962GuardFixture();
    $before               = aid962GuardState($payment);
    $queries              = 0;

    expect(fn () => DB::transaction(function () use ($call, $payment, $open, &$queries): void {
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $call($payment, $open);
    }))->toThrow(PaymentOutsideTransactionRequiredException::class, "{$method} must not run inside a caller's transaction: transaction level is 2, the allowed base level is 1");

    expect($queries)->toBe(0)
        ->and(aid962GuardState($payment))->toBe($before)
        ->and($open->fresh()->status)->toBe(InvoiceStatus::SENT);
})->with('guarded payment writes');

it('proceeds when the base level is raised by one', function (Closure $call, string $method) {
    [, $payment, , $open] = aid962GuardFixture();
    $before               = aid962GuardState($payment);
    config()->set('larabill.payments.base_transaction_level', 2);

    DB::transaction(fn () => $call($payment, $open));

    expect(aid962GuardState($payment))->not->toBe($before);
})->with('guarded payment writes');

it('proceeds at the base level itself (the outermost transaction of the harness)', function (Closure $call, string $method) {
    [, $payment, , $open] = aid962GuardFixture();
    $before               = aid962GuardState($payment);

    $call($payment, $open);

    expect(aid962GuardState($payment))->not->toBe($before);
})->with('guarded payment writes');

it('leaves receive() and allocate() unguarded inside a wrapper with no prior read', function () {
    $inv = PaymentFixtures::sentInvoice(12100);

    $payment = DB::transaction(fn () => app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(12100), 'EUR', now()->subDay()->startOfSecond(), PaymentMethod::BankTransfer, 'TRF-W', null, [], CreditMovementKind::TOP_UP));
    DB::transaction(fn () => app(PaymentService::class)->allocate($payment, new Allocation($inv->id, cents(12100))));

    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID);
});
