<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Support\Facades\DB;

/*
 * AID-1884 (spec 2026-11-07 rev 3, §1 and §5 S3/S4) on a real engine, at the
 * production level: this harness does not wrap tests in a transaction and
 * keeps the shipped `base_transaction_level = 0`.
 *
 * S3 — the three sequences measured on v9.4.0: the caller opens a transaction
 * and makes ONE plain read (it pins the REPEATABLE READ snapshot); a second
 * connection pays or consumes and commits; the caller then calls receive(),
 * allocate() or register() on the same invoice / entry. On v9.4.0 the
 * caller's sums came from the old snapshot and both committed (140,00 / 140,00
 * / 160,00 against 100,00). Oracle: (i) the second call returned and its
 * commit is visible; (ii) the caller's call throws exactly
 * PaymentOutsideTransactionRequiredException naming the method, level 1, base
 * 0; (iii) any other throwable fails the test; (iv) a fresh read shows the
 * second connection's values only. The monetary invariant is asserted BEFORE
 * the exception, and a caller call that does not throw is COMMITTED, as a real
 * caller would: with a guard removed the case fails on the money, not on a
 * missing exception (the sensitivity the spec asks for).
 *
 * S4 at level 0 — process() stays unguarded: register() reaches it at the
 * ambient level and a voucher still sells.
 */

const AID1884_ENGINE_SECOND = 'testing_second';

beforeEach(function () {
    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->markTestSkipped('LARABILL_TEST_MYSQL_* env not configured');
    }

    $this->bootstrap();
    $this->customer = $this->seedUser();
    $this->issuer   = $this->seedUser();

    config(['database.connections.'.AID1884_ENGINE_SECOND => config('database.connections.testing')]);
    DB::purge(AID1884_ENGINE_SECOND);
});

afterEach(function () {
    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        return;
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge(AID1884_ENGINE_SECOND);
});

/** Run $work with the second connection as the default one (the services use the default). */
function aid1884OnSecondConnection(Closure $work): mixed
{
    $primary = DB::getDefaultConnection();
    DB::setDefaultConnection(AID1884_ENGINE_SECOND);
    try {
        return $work();
    } finally {
        DB::setDefaultConnection($primary);
    }
}

function aid1884EngineInvoice(string $issuer, string $customer): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id'                  => $issuer,
        'billable_user_id'         => $customer,
        'total_amount'             => cents(10000),
        'is_immutable'             => false,
        'paid_at'                  => null,
        'due_date'                 => null,
        'company_fiscal_config_id' => CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id,
    ]);
}

/** Active allocations against $invoiceId, in cents, read in a NEW transaction of a fresh connection. */
function aid1884FreshAllocated(string $invoiceId): int
{
    return (int) aid1884OnSecondConnection(fn () => DB::table('grouped_payment_invoice')
        ->where('invoice_id', $invoiceId)->where('status', 0)->sum('applied_amount'));
}

/**
 * The caller's side of S3: open a transaction, pin the snapshot with one plain
 * read, let the second connection commit, then make the call. A call that
 * throws is rolled back; one that returns is committed (what a real caller
 * does). Returns what was thrown, or null.
 *
 * @param  Closure(): mixed  $second
 * @param  Closure(): mixed  $caller
 * @return array{second: string, thrown: ?Throwable, level: int}
 */
function aid1884CallerSequence(Closure $second, Closure $caller): array
{
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    Invoice::query()->count(); // the caller's plain read: it pins the snapshot

    // (i) the second connection's call returns and commits before the caller's call.
    aid1884OnSecondConnection($second);
    $secondOutcome = 'returned';

    $level  = DB::transactionLevel();
    $thrown = null;
    try {
        $caller();
        DB::commit();
    } catch (Throwable $e) {
        $thrown = $e;
        DB::rollBack();
    }

    return ['second' => $secondOutcome, 'thrown' => $thrown, 'level' => $level];
}

function aid1884AssertExactRefusal(?Throwable $thrown, string $method): void
{
    // (iii) anything else — lock wait, deadlock, validation, a generic runtime error — fails here.
    expect($thrown === null ? null : $thrown::class)->toBe(PaymentOutsideTransactionRequiredException::class);
    // (ii) the exact refusal, naming the method, level 1 and base 0.
    expect($thrown->getMessage())->toStartWith("{$method} must not run inside a caller's transaction: transaction level is 1, the allowed base level is 0.");
}

// ── S3 — the three sequences of §1 ──────────────────────────────────────────

it('refuses receive() in a caller transaction that already read, instead of over-allocating the invoice (S3)', function () {
    $invoice = aid1884EngineInvoice($this->issuer, $this->customer);

    $run = aid1884CallerSequence(
        fn () => app(PaymentService::class)->receive($this->customer, cents(6000), 'EUR', now()->startOfSecond(), PaymentMethod::BankTransfer, 'S3-R-2nd', null, [new Allocation((string) $invoice->id, cents(6000))]),
        fn () => app(PaymentService::class)->receive($this->customer, cents(8000), 'EUR', now()->startOfSecond(), PaymentMethod::BankTransfer, 'S3-R-caller', null, [new Allocation((string) $invoice->id, cents(8000))]),
    );

    expect($run['second'])->toBe('returned')
        ->and($run['level'])->toBe(1);

    // (iv) the monetary invariant first: only the second connection's 60,00 is allocated.
    expect(aid1884FreshAllocated((string) $invoice->id))->toBe(6000)
        ->and(aid1884OnSecondConnection(fn () => Invoice::query()->findOrFail($invoice->id)->status))->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and(aid1884OnSecondConnection(fn () => GroupedPayment::query()->count()))->toBe(1);

    aid1884AssertExactRefusal($run['thrown'], PaymentService::class.'::receive');
})->group('db-integration');

it('refuses allocate() in a caller transaction that already read, instead of over-consuming the entry (S3)', function () {
    $invoiceA = aid1884EngineInvoice($this->issuer, $this->customer);
    $invoiceB = aid1884EngineInvoice($this->issuer, $this->customer);
    $payment  = app(PaymentService::class)->receive($this->customer, cents(10000), 'EUR', now()->subDay()->startOfSecond(), PaymentMethod::BankTransfer, 'S3-A-topup', null, [], CreditMovementKind::TOP_UP);
    $entryId  = (string) $payment->creditMovements()->entries()->sole()->id;

    $run = aid1884CallerSequence(
        fn () => app(PaymentService::class)->allocate(GroupedPayment::query()->findOrFail($payment->id), new Allocation((string) $invoiceA->id, cents(6000))),
        fn () => app(PaymentService::class)->allocate($payment->refresh(), new Allocation((string) $invoiceB->id, cents(8000))),
    );

    expect($run['second'])->toBe('returned')
        ->and($run['level'])->toBe(1);

    // (iv) the monetary invariant first: the 100,00 entry carries only the second connection's 60,00 exit.
    $exits = (int) aid1884OnSecondConnection(fn () => CreditMovement::query()
        ->where('consumes_movement_id', $entryId)->whereNull('reversed_at')->sum('amount'));

    expect(-$exits)->toBe(6000)
        ->and(aid1884FreshAllocated((string) $invoiceA->id))->toBe(6000)
        ->and(aid1884FreshAllocated((string) $invoiceB->id))->toBe(0);

    aid1884AssertExactRefusal($run['thrown'], PaymentService::class.'::allocate');
})->group('db-integration');

it('refuses register() in a caller transaction that already read, instead of over-collecting a partially paid invoice (S3)', function () {
    $invoice = aid1884EngineInvoice($this->issuer, $this->customer);

    $run = aid1884CallerSequence(
        fn () => app(PaymentService::class)->receive($this->customer, cents(6000), 'EUR', now()->startOfSecond(), PaymentMethod::BankTransfer, 'S3-G-2nd', null, [new Allocation((string) $invoice->id, cents(6000))]),
        fn () => app(GroupedPaymentService::class)->register($this->customer, [(string) $invoice->id], now()->startOfSecond(), cents(10000), 'EUR'),
    );

    expect($run['second'])->toBe('returned')
        ->and($run['level'])->toBe(1);

    // (iv) the monetary invariant first: only the second connection's 60,00 is allocated.
    expect(aid1884FreshAllocated((string) $invoice->id))->toBe(6000)
        ->and(aid1884OnSecondConnection(fn () => Invoice::query()->findOrFail($invoice->id)->status))->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and(aid1884OnSecondConnection(fn () => GroupedPayment::query()->count()))->toBe(1);

    aid1884AssertExactRefusal($run['thrown'], GroupedPaymentService::class.'::register');
})->group('db-integration');

// ── S4 at level 0 — process() stays unguarded (preservation control) ───────

it('lets register() reach process() at the production level 0 (S4)', function () {
    $invoice = aid1884EngineInvoice($this->issuer, $this->customer);
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

    expect(DB::transactionLevel())->toBe(0);
    app(GroupedPaymentService::class)->register($this->customer, [(string) $invoice->id], now()->startOfSecond(), cents(10000), 'EUR');

    expect($spy->levels)->toBe([0])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
})->group('db-integration');

it('still sells a voucher through process() at the production level 0 (S4)', function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $this->customer,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    $request = new VoucherSaleRequest((string) $proforma->id, $this->customer, $proforma->fresh()->total_amount, 'EUR', now()->startOfSecond(), PaymentMethod::Card, 'aid1884-v0', null, 'aid1884-v0');

    expect(DB::transactionLevel())->toBe(0)
        ->and(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold);
})->group('db-integration');
