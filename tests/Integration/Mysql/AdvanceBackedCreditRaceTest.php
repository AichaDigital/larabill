<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * AID-1856 (spec 2026-11-06, §7 — T8b, T12, T13, T15): the entry lock of
 * apply() and allocate() against a concurrent linkAdvanceInvoice(), on the
 * engines a consumer runs (MySQL 8.4 and MariaDB 11.4). SQLite has no row
 * locks and cannot say anything here.
 *
 * Two connections in ONE process, so every interleaving is deterministic:
 * the call under test runs on the primary connection, and a hook fired from
 * inside it (a model event, or a query listener) runs the REAL
 * linkAdvanceInvoice() on a second connection by making that connection the
 * default one for the duration of the hook. No RefreshDatabase wraps these
 * tests: what the assertions read afterwards is what the engine kept.
 */

const AID1856_SECOND = 'testing_second';

beforeEach(function () {
    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->markTestSkipped('LARABILL_TEST_MYSQL_* env not configured');
    }

    $this->bootstrap();
    $this->customer = $this->seedUser();
    $this->issuer   = $this->seedUser();
    $this->stranger = $this->seedUser();

    config(['database.connections.'.AID1856_SECOND => config('database.connections.testing')]);
    DB::purge(AID1856_SECOND);
    DB::connection(AID1856_SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function () {
    CreditMovement::flushEventListeners();
    CreditMovement::clearBootedModels();

    if (MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        DB::purge(AID1856_SECOND); // before the harness drops the tables: no metadata lock left behind
    }
});

/** @param  array<int, Allocation>  $allocations */
function aid1856EngineTopUp(string $customer, int $cents, int $daysAgo, array $allocations = [], string $currency = 'EUR'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        $customer, cents($cents), $currency, now()->subDays($daysAgo)->startOfSecond(),
        PaymentMethod::BankTransfer, null, null, $allocations, CreditMovementKind::TOP_UP,
    );
}

/** A SENT invoice; with $fiscalEur its fiscal currency is EUR, otherwise it has none. */
function aid1856EngineInvoice(string $issuer, string $customer, int $cents, bool $fiscalEur = true): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id'                  => $issuer, 'billable_user_id' => $customer,
        'total_amount'             => cents($cents), 'is_immutable' => false, 'paid_at' => null, 'due_date' => null,
        'company_fiscal_config_id' => $fiscalEur ? CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id : null,
    ]);
}

function aid1856EngineAdvanceDraft(string $issuer, string $customer): Invoice
{
    return Invoice::factory()->unissuedDraft()->create(['billable_user_id' => $customer, 'user_id' => $issuer]);
}

/**
 * A payment holding TWO entries: its TOP_UP remainder and a SURPLUS minted by
 * reversing a direct allocation.
 *
 * @return array{0: GroupedPayment, 1: CreditMovement, 2: CreditMovement} payment, TOP_UP, SURPLUS
 */
function aid1856EngineTwoEntryPayment(string $issuer, string $customer, int $topUpCents, int $surplusCents, int $daysAgo): array
{
    $paid    = aid1856EngineInvoice($issuer, $customer, $surplusCents);
    $payment = aid1856EngineTopUp($customer, $topUpCents + $surplusCents, $daysAgo, [new Allocation($paid->id, cents($surplusCents))]);
    app(PaymentService::class)->reverseAllocation($payment->allocations()->sole()->id, 'reassign');

    return [
        $payment->refresh(),
        $payment->creditMovements()->where('kind', CreditMovementKind::TOP_UP->value)->sole(),
        $payment->creditMovements()->where('kind', CreditMovementKind::SURPLUS->value)->sole(),
    ];
}

/**
 * Run $work with the SECOND connection as the default one — so the real
 * linkAdvanceInvoice() (DB::transaction(), Invoice::query(), static::query())
 * resolves to it — and restore the primary connection whatever happens.
 */
function aid1856OnSecondConnection(Closure $work): mixed
{
    $primary = DB::getDefaultConnection();
    DB::setDefaultConnection(AID1856_SECOND);

    try {
        return $work();
    } finally {
        DB::setDefaultConnection($primary);
    }
}

/** The REAL link, on the second connection. Returns 'committed', or the driver error code that refused it. */
function aid1856LinkOnSecondConnection(string $entryId, string $advanceId): string|int
{
    return aid1856OnSecondConnection(function () use ($entryId, $advanceId): string|int {
        try {
            CreditMovement::query()->findOrFail($entryId)->linkAdvanceInvoice(Invoice::query()->findOrFail($advanceId));

            return 'committed';
        } catch (QueryException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }
    });
}

/** An id that sorts immediately before or after $id: the same string with its last hex digit moved by one. */
function aid1856NeighbourId(string $id, bool $before): string
{
    $last = hexdec(substr($id, -1));

    if ($before ? $last > 0 : $last < 15) {
        return substr($id, 0, -1).dechex($before ? $last - 1 : $last + 1);
    }

    $previous = hexdec(substr($id, -2, 1)); // carry on the previous digit

    return substr($id, 0, -2).dechex($before ? $previous - 1 : $previous + 1).($before ? 'f' : '0');
}

/**
 * Record every query of the primary connection from now on.
 *
 * @return object{queries: list<array{sql: string, bindings: array<int, mixed>}>, on: bool}
 */
function aid1856Trace(?Closure $onQuery = null): object
{
    $trace = (object) ['queries' => [], 'on' => true];

    DB::connection('testing')->listen(function (QueryExecuted $query) use ($trace, $onQuery): void {
        if (! $trace->on || $query->connectionName !== 'testing') {
            return;
        }

        $sql              = strtolower($query->sql);
        $trace->queries[] = ['sql' => $sql, 'bindings' => $query->bindings];

        if ($onQuery !== null) {
            $onQuery($sql, $trace);
        }
    });

    return $trace;
}

function aid1856IsLockingRead(string $sql): bool
{
    return str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'lock in share mode');
}

function aid1856TouchesMovements(string $sql): bool
{
    return str_contains($sql, '`credit_movements`');
}

/** The plain SELECT of ENTRY rows — the shape of a candidate read (entries are filtered by `kind in`). */
function aid1856IsEntrySelect(string $sql): bool
{
    return str_starts_with(ltrim($sql), 'select')
        && str_contains($sql, 'from `credit_movements`')
        && str_contains($sql, '`kind` in')
        && ! str_starts_with(ltrim($sql), 'select distinct')
        && ! aid1856IsLockingRead($sql);
}

// ── T12 — a link attempted while the call holds the entry ───────────────────

it('makes a link wait for a running call that holds the entry, and locks nothing else (T12)', function (string $path) {
    $payment = aid1856EngineTopUp($this->customer, 5000, 2);
    $entry   = $payment->creditMovements()->sole();
    $advance = aid1856EngineAdvanceDraft($this->issuer, $this->customer);
    $invoice = aid1856EngineInvoice($this->issuer, $this->customer, 3000);

    // Rows the call does NOT hold: another customer's entry, and the ids on both sides of the locked one.
    $foreign    = aid1856EngineTopUp($this->stranger, 700, 2)->creditMovements()->sole();
    $foreignRow = (array) DB::table('credit_movements')->where('id', $foreign->id)->first();

    $fired = 0;
    $seen  = [];
    CreditMovement::creating(function (CreditMovement $movement) use (&$fired, &$seen, $entry, $advance, $foreign, $foreignRow): void {
        if ($movement->kind !== CreditMovementKind::APPLIED || ++$fired !== 1) {
            return;
        }

        // The planned entry is locked by now: the real link must wait and time out.
        $seen['link'] = aid1856LinkOnSecondConnection($entry->id, $advance->id);

        $second = DB::connection(AID1856_SECOND);
        foreach ([
            'insert before' => fn () => $second->table('credit_movements')->insert(['id' => aid1856NeighbourId($entry->id, true)] + $foreignRow),
            'insert after'  => fn () => $second->table('credit_movements')->insert(['id' => aid1856NeighbourId($entry->id, false)] + $foreignRow),
            'update other'  => fn () => $second->table('credit_movements')->where('id', $foreign->id)->update(['notes' => 'not locked']),
        ] as $label => $write) {
            try {
                $write();
                $seen[$label] = 'ok';
            } catch (QueryException $e) {
                $seen[$label] = (int) ($e->errorInfo[1] ?? 0);
            }
        }
    });

    $path === 'apply'
        ? app(CustomerCredit::class)->apply($this->customer, $invoice, cents(3000))
        : app(PaymentService::class)->allocate($payment, new Allocation($invoice->id, cents(3000)));

    $fresh = CreditMovement::query()->findOrFail($entry->id);

    expect($fired)->toBe(1)
        ->and($seen)->toBe(['link' => 1205, 'insert before' => 'ok', 'insert after' => 'ok', 'update other' => 'ok'])
        ->and($fresh->advance_invoice_id)->toBeNull()
        ->and($fresh->remaining()->unscaledValue())->toBe(2000)
        ->and(CreditMovement::query()->where('consumes_movement_id', $entry->id)->active()->count())->toBe(1);
})->with(['apply', 'allocate']);

// ── T13, T15 — a link COMMITTED after the candidate read and before the entry lock ──

it('excludes an entry linked after the candidate read: the decision comes from the locked row (T13, T15)', function (string $path, bool $withFreeEntry) {
    if ($withFreeEntry) {
        // Two entries of one payment share `occurred_at`, so `id` breaks the FIFO tie — and
        // the order of ids is the engine's (a native UUID column does not sort like a
        // string). The entry to link must be the FIFO HEAD, or a wrong implementation
        // would fund from the other one and never touch it: ask the engine which it is.
        [$payment]      = aid1856EngineTwoEntryPayment($this->issuer, $this->customer, 5000, 3000, 2);
        [$entry, $free] = $payment->creditMovements()->orderBy('occurred_at')->orderBy('id')->get()->all();
    } else {
        $payment = aid1856EngineTopUp($this->customer, 5000, 2);
        $entry   = $payment->creditMovements()->sole();
        $free    = null;
    }

    $advance = aid1856EngineAdvanceDraft($this->issuer, $this->customer);
    $invoice = aid1856EngineInvoice($this->issuer, $this->customer, 2000, fiscalEur: $path !== 'apply-no-currency');

    // Fire on the FIRST plain SELECT of entry rows issued inside the transaction
    // (after the first locking read of the payments). Whether that was the read
    // that fed the lock is verified below, on the trace.
    $state = (object) ['inTransaction' => false, 'entrySelects' => 0, 'firedAt' => null, 'link' => null];
    $trace = aid1856Trace(function (string $sql, object $trace) use ($state, $entry, $advance): void {
        if (aid1856IsLockingRead($sql) && str_contains($sql, '`grouped_payments`')) {
            $state->inTransaction = true;
        }

        if ($state->inTransaction && aid1856IsEntrySelect($sql) && ++$state->entrySelects === 1) {
            $state->firedAt = count($trace->queries) - 1;
            $state->link    = aid1856LinkOnSecondConnection($entry->id, $advance->id);
        }
    });

    $call = fn () => match ($path) {
        'apply', 'apply-no-currency' => app(CustomerCredit::class)->apply($this->customer, $invoice, cents(2000)),
        'allocate'                   => app(PaymentService::class)->allocate($payment, new Allocation($invoice->id, cents(2000))),
        // T15 ran allocate() inside a wrapper with no prior read; since 9.5.0 (AID-1884) that
        // wrapper is refused before any read, so the race is covered by the unwrapped case above
        // and the refusal by tests/Integration/Mysql/CallerSnapshotGuardEngineTest.php.
    };

    $refused = null;
    try {
        $call();
    } catch (InsufficientCreditException|AllocationExceedsPaymentException $e) {
        $refused = $e::class;
    }
    $trace->on = false;

    // The interleaving really happened: one hook, and the link is committed.
    expect($state->entrySelects)->toBeGreaterThanOrEqual(1)
        ->and($state->link)->toBe('committed')
        ->and(aid1856OnSecondConnection(fn () => CreditMovement::query()->findOrFail($entry->id)->advance_invoice_id))->toBe($advance->id);

    // The trace: after the hook, a locking read of credit_movements binds the linked
    // entry's id — so its id was read BEFORE the link landed — and every locking read
    // of that table binds exactly one id (§3.2).
    $locks = array_values(array_filter(
        array_slice($trace->queries, $state->firedAt + 1),
        fn (array $query): bool => aid1856TouchesMovements($query['sql']) && aid1856IsLockingRead($query['sql']),
    ));

    expect($locks)->not->toBeEmpty()
        ->and(collect($locks)->contains(fn (array $query): bool => in_array($entry->id, $query['bindings'], true)))->toBeTrue()
        ->and(collect($locks)->every(fn (array $query): bool => count($query['bindings']) === 1))->toBeTrue();

    // The decision: no APPLIED exit on the linked entry.
    expect(CreditMovement::query()->where('consumes_movement_id', $entry->id)->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0);

    if ($free === null) {
        expect($refused)->toBe(str_starts_with($path, 'apply') ? InsufficientCreditException::class : AllocationExceedsPaymentException::class)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0);
    } else {
        expect($refused)->toBeNull()
            ->and(CreditMovement::query()->where('consumes_movement_id', $free->id)->where('kind', CreditMovementKind::APPLIED->value)->sum('amount'))->toEqual(-2000);
    }
})->with(['apply', 'apply-no-currency', 'allocate'])
    ->with(['only the linked entry' => false, 'a second, unlinked entry' => true]);

// ── T8b — refund() holds no entry lock (GUARD: green on 9.3.2) ──────────────

it('lets a link commit during a refund, and reads credit_movements without any lock (T8b)', function () {
    $entry   = aid1856EngineTopUp($this->customer, 5000, 2)->creditMovements()->sole();
    $advance = aid1856EngineAdvanceDraft($this->issuer, $this->customer);

    $fired = 0;
    $link  = null;
    CreditMovement::creating(function (CreditMovement $movement) use (&$fired, &$link, $entry, $advance): void {
        if ($movement->kind === CreditMovementKind::REFUNDED && ++$fired === 1) {
            $link = aid1856LinkOnSecondConnection($entry->id, $advance->id);
        }
    });

    $trace     = aid1856Trace();
    $exits     = app(CustomerCredit::class)->refund($this->customer, cents(1000), 'EUR', now()->startOfSecond(), PaymentMethod::BankTransfer);
    $trace->on = false;

    $fresh = CreditMovement::query()->findOrFail($entry->id);

    expect($fired)->toBe(1)
        ->and($link)->toBe('committed')
        ->and($fresh->advance_invoice_id)->toBe($advance->id)
        ->and($exits)->toHaveCount(1)
        ->and($exits->first()->consumes_movement_id)->toBe($entry->id)
        ->and($fresh->remaining()->unscaledValue())->toBe(4000);

    // The whole call, on credit_movements: plain SELECTs and INSERTs only.
    $onMovements = array_values(array_filter($trace->queries, fn (array $query): bool => aid1856TouchesMovements($query['sql'])));
    $verbs       = array_values(array_unique(array_map(fn (array $query): string => strtok(ltrim($query['sql']), ' '), $onMovements)));
    sort($verbs);

    expect($onMovements)->not->toBeEmpty()
        ->and($verbs)->toBe(['insert', 'select'])
        ->and(collect($onMovements)->contains(fn (array $query): bool => aid1856IsLockingRead($query['sql'])))->toBeFalse();
});
