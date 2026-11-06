<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;

/*
 * AID-1864, T7 (spec 2026-11-06 §5): the transactional regression on a real
 * engine. A vetoed APPLIED or REFUNDED exit must roll back everything the
 * call wrote — proven here where a consumer runs (MySQL 8.4 and MariaDB
 * 11.4), not on SQLite. No RefreshDatabase wraps these tests: each call
 * opens and rolls back its own outermost transaction, so what the assertions
 * read afterwards is what the engine really kept.
 */

beforeEach(function () {
    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->markTestSkipped('LARABILL_TEST_MYSQL_* env not configured');
    }

    $this->bootstrap();
    $this->customer = $this->seedUser();
    $this->issuer   = $this->seedUser();
});

function aid1864EngineTopUp(string $customer, int $cents, int $daysAgo = 1): GroupedPayment
{
    return app(PaymentService::class)->receive(
        $customer, cents($cents), 'EUR', now()->subDays($daysAgo)->startOfSecond(),
        PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
}

function aid1864EngineInvoice(string $issuer, string $customer, int $cents): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id'      => $issuer, 'billable_user_id' => $customer,
        'total_amount' => cents($cents), 'is_immutable' => false, 'paid_at' => null, 'due_date' => null,
    ]);
}

/**
 * Vetoes the $onAttempt-th write of an exit of $kind. The listener is
 * registered after the fixtures and always removed.
 */
function aid1864EngineExpectVetoRefusal(CreditMovementKind $kind, Closure $call, int $onAttempt = 1): void
{
    $attempts = 0;
    CreditMovement::saving(function (CreditMovement $movement) use ($kind, $onAttempt, &$attempts): ?bool {
        if ($movement->kind !== $kind) {
            return null;
        }

        return ++$attempts === $onAttempt ? false : null;
    });

    try {
        expect($call)->toThrow(LogicException::class, 'The write of a credit exit was vetoed by a model listener');
    } finally {
        CreditMovement::flushEventListeners();
        CreditMovement::clearBootedModels();
    }

    expect($attempts)->toBe($onAttempt);
}

it('rolls back a vetoed CustomerCredit::apply() on the real engine', function () {
    aid1864EngineTopUp($this->customer, 5000);
    $invoice = aid1864EngineInvoice($this->issuer, $this->customer, 3000);

    aid1864EngineExpectVetoRefusal(
        CreditMovementKind::APPLIED,
        fn () => app(CustomerCredit::class)->apply($this->customer, $invoice, cents(3000)),
    );

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($invoice->fresh()->paid_at)->toBeNull()
        ->and(CustomerCredit::for($this->customer, 'EUR')->balance()->unscaledValue())->toBe(5000);
});

it('rolls back the first exit of a CustomerCredit::refund() whose second exit is vetoed, on the real engine', function () {
    // Two entries, so that the FIRST REFUNDED exit is really inserted before
    // the veto: a refund whose only exit is vetoed runs no SQL at all and
    // would pass with no rollback (implementation gate, round 1).
    aid1864EngineTopUp($this->customer, 2000, daysAgo: 3);
    aid1864EngineTopUp($this->customer, 2000, daysAgo: 2);

    aid1864EngineExpectVetoRefusal(
        CreditMovementKind::REFUNDED,
        fn () => app(CustomerCredit::class)->refund($this->customer, cents(3000), 'EUR', now(), PaymentMethod::BankTransfer),
        onAttempt: 2,
    );

    expect(CreditMovement::query()->where('kind', CreditMovementKind::REFUNDED->value)->count())->toBe(0)
        ->and(CustomerCredit::for($this->customer, 'EUR')->balance()->unscaledValue())->toBe(4000);
});

it('rolls back a vetoed PaymentService::allocate() on the real engine', function () {
    $payment = aid1864EngineTopUp($this->customer, 5000);
    $invoice = aid1864EngineInvoice($this->issuer, $this->customer, 3000);

    aid1864EngineExpectVetoRefusal(
        CreditMovementKind::APPLIED,
        fn () => app(PaymentService::class)->allocate($payment, new Allocation($invoice->id, cents(3000))),
    );

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($invoice->fresh()->paid_at)->toBeNull()
        ->and($payment->fresh()->availableCredit()->unscaledValue())->toBe(5000);
});
