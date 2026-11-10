<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Support\Facades\DB;

/*
 * AID-1884 (spec 2026-11-07 rev 3, §5): PaymentService::receive(), allocate()
 * and GroupedPaymentService::register() refuse to run above
 * `larabill.payments.base_transaction_level`, as their literal first statement.
 *
 * Levels: this suite runs every test inside the RefreshDatabase transaction
 * (level 1) with base 1 (tests/TestCase.php), so a DB::transaction() here is
 * level 2. The level-0 cases (production) live in the engine test
 * tests/Integration/Mysql/CallerSnapshotGuardEngineTest.php: reaching level 0
 * here would mean committing the harness transaction.
 */

const AID1884_GUARDED = [
    'receive'  => PaymentService::class.'::receive',
    'allocate' => PaymentService::class.'::allocate',
    'register' => GroupedPaymentService::class.'::register',
];

/**
 * One happy-path call per guarded method, its fixtures built at the harness
 * level (outside any extra wrapper).
 *
 * @return Closure(): mixed
 */
function aid1884GuardedCall(string $method): Closure
{
    $invoice = PaymentFixtures::sentInvoice(10000);
    $at      = now()->startOfSecond();
    $svc     = app(PaymentService::class);

    return match ($method) {
        'receive'  => fn () => $svc->receive(TestCase::USER_UUID_2, cents(10000), 'EUR', $at, PaymentMethod::BankTransfer, 'AID1884-R', null, [new Allocation($invoice->id, cents(10000))]),
        'allocate' => (function () use ($svc, $invoice, $at): Closure {
            $topUp = $svc->receive(TestCase::USER_UUID_2, cents(10000), 'EUR', $at, PaymentMethod::BankTransfer, 'AID1884-T', null, [], CreditMovementKind::TOP_UP);

            return fn () => $svc->allocate($topUp, new Allocation($invoice->id, cents(10000)));
        })(),
        'register' => fn () => app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$invoice->id], $at, cents(10000), 'EUR'),
    };
}

/** @return array<string, string> every row the three methods can write, serialized */
function aid1884PaymentState(): array
{
    $state = [];
    foreach (['grouped_payments', 'grouped_payment_invoice', 'credit_movements', 'invoices'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $state;
}

/**
 * The tokens of the first statement of $class::$method's body, comments and
 * whitespace dropped (no parser dependency: the fallback the spec names).
 *
 * @param  class-string  $class
 * @return list<string>
 */
function aid1884FirstStatementTokens(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);
    $lines      = file((string) $reflection->getFileName());
    $source     = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

    $inBody    = false;
    $statement = [];
    foreach (token_get_all('<?php '.$source) as $token) {
        if (! $inBody) {
            $inBody = $token === '{';

            continue;
        }
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $statement[] = is_array($token) ? $token[1] : $token;
        if ($token === ';') {
            break;
        }
    }

    return $statement;
}

// ── S1 — refused inside an extra wrapper (red on v9.4.0) ────────────────────

it('refuses inside a caller transaction (level 2, base 1), with the exact message and nothing written (S1)', function (string $method) {
    $call    = aid1884GuardedCall($method);
    $before  = aid1884PaymentState();
    $caught  = null;
    $queries = 0;

    expect(DB::transactionLevel())->toBe(1);

    try {
        DB::transaction(function () use ($call, &$queries): void {
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $call();
        });
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(PaymentOutsideTransactionRequiredException::class)
        ->and($caught->getMessage())->toStartWith(AID1884_GUARDED[$method]." must not run inside a caller's transaction: transaction level is 2, the allowed base level is 1.")
        // Query silence: a check, not the placement proof (that is S5).
        ->and($queries)->toBe(0)
        ->and(aid1884PaymentState())->toBe($before)
        ->and(DB::transactionLevel())->toBe(1);
})->with(array_keys(AID1884_GUARDED));

// ── S2 — the escape still works (preservation control) ──────────────────────

it('runs as before inside an extra wrapper when the base level is raised to 2 (S2)', function (string $method) {
    $call   = aid1884GuardedCall($method);
    $before = aid1884PaymentState();
    config()->set('larabill.payments.base_transaction_level', 2);

    $result = DB::transaction(function () use ($call): mixed {
        expect(DB::transactionLevel())->toBe(2);

        return $call();
    });

    expect($result)->not->toBeNull()
        ->and(aid1884PaymentState())->not->toBe($before);
})->with(array_keys(AID1884_GUARDED));

// ── S5 — placement: the guard is the literal first statement ────────────────

it('places the guard as the literal first statement of the method body (S5)', function (string $method) {
    [$class, $name] = explode('::', AID1884_GUARDED[$method]);

    $tokens = aid1884FirstStatementTokens($class, $name);

    expect($tokens)->toBe(['OutermostTransaction', '::', 'assert', '(', '__METHOD__', ')', ';']);
})->with(array_keys(AID1884_GUARDED));

it('reports the guard before payload validation: an invalid amount inside a wrapper is a transaction refusal (S5)', function () {
    $caught = null;

    try {
        DB::transaction(fn () => app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(0), 'EUR', now(), PaymentMethod::BankTransfer, null, null, []));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(PaymentOutsideTransactionRequiredException::class);
});

// ── S6 — a same-key replay inside a wrapper is refused too ──────────────────

it('refuses a same-key replay of receive() inside a wrapper, which used to return the original payment (S6)', function () {
    $svc  = app(PaymentService::class);
    $args = [TestCase::USER_UUID_2, cents(10000), 'EUR', now()->startOfSecond(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP, 'aid1884-replay'];

    $first = $svc->receive(...$args);

    expect(fn () => DB::transaction(fn () => $svc->receive(...$args)))
        ->toThrow(PaymentOutsideTransactionRequiredException::class, AID1884_GUARDED['receive'].' must not run');

    expect(GroupedPayment::query()->count())->toBe(1)
        ->and(GroupedPayment::query()->sole()->id)->toBe($first->id);
});

// ── S4 — process() is not guarded (preservation control, harness level 1) ──

it('lets register() reach process() at the caller ambient level (S4, level 1)', function () {
    $invoice = PaymentFixtures::sentInvoice(10000);
    $spy     = new class extends PaymentService
    {
        /** @var list<int> */
        public array $levels = [];

        public function process(ReceiveRequest $request, ReplayPolicy $policy): GroupedPayment
        {
            $this->levels[] = DB::transactionLevel();

            return parent::process($request, $policy);
        }
    };
    app()->instance(PaymentService::class, $spy);

    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$invoice->id], now()->startOfSecond(), cents(10000), 'EUR');

    expect($spy->levels)->toBe([1])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
});

it('still sells a voucher through process() at the harness level (S4, level 1)', function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => TestCase::USER_UUID_2,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    $request = new VoucherSaleRequest((string) $proforma->id, TestCase::USER_UUID_2, $proforma->fresh()->total_amount, 'EUR', now()->startOfSecond(), PaymentMethod::Card, 'aid1884-v1', null, 'aid1884-v1');

    expect(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold);
});
