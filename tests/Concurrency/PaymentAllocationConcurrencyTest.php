<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-962 — fork proofs of the payment-allocation invariants (spec §5.4, §10):
 * with no UNIQUE left to express it, "Σ active allocations ≤ invoice total"
 * is guaranteed by the invoice row lock — taken as the FIRST read of the
 * transaction, so every later (plain) sum sees what the previous holder of
 * that lock committed.
 *
 * Scenario (g) pins the REPEATABLE READ trap found in the Task 7 review: the
 * read view of a transaction is fixed by its first consistent read. When the
 * idempotency-key pre-check ran BEFORE the invoice lock, a child that waited
 * on the lock read paid = 0 from that stale view and over-allocated.
 *
 * SENSITIVITY (measured 2026-10-25 on the final code, Task 7 fix round 1):
 * moving the key pre-check back before lockInvoices() in
 * PaymentService::process() turns (g) RED on BOTH engines — MySQL 8.4.10 and
 * MariaDB 11.4.13 each allocated 6 of 6 children (expected exactly 1). The
 * equivalent stale-read state measured in the previous round (unlocked pivot
 * sum after a pre-lock read) gave MySQL 5 of 6 and MariaDB 6 of 6. Green on
 * both with the lock first. A locking aggregate over the pivot was tried
 * and rejected: on an empty range it is a gap lock shared across invoices
 * and deadlocked (1213) writers of different invoices (AID-715).
 *
 * Scenarios (a)–(f) (Task 11, spec §10) — six children each, released on the
 * same barrier; outcome sets observed on the final code, MySQL 8.4.10 (3384)
 * and MariaDB 11.4.13 (3307): 7 green runs per engine, zero skips, the
 * outcome sets recorded in 2 of them (identical in both):
 *   (a) receive:ok 1, receive:notPayableStatus 5. The losers meet the PAID
 *       invoice the winner left: §5.1.3 checks the status before the
 *       outstanding, so the refusal is notPayableStatus, not exceedsInvoice.
 *   (b) apply:ok 1, apply:forBalance 5.
 *   (c) refund:ok 1, apply:forBalance 3, refund:forBalance 2 (the apply-wins
 *       branch is asserted too, never observed).
 *   (d) reverse:ok 3 (one reversal, two stable no-ops), apply:forBalance 3
 *       (the apply-wins branch — apply:ok 1, reverse:entryHasActiveExits 3 —
 *       is asserted too, never observed).
 *   (e) register:<the one payment id> 6 — every child replayed the winner in
 *       every run. The assertion is wider (spec §13 row 44): a loser whose
 *       pre-check ran before the winner committed meets the PAID invoice under
 *       register()'s eligibility lock (notPayableStatus / alreadyActivelyPaid,
 *       controlled, as in GroupedPaymentIdempotencyConcurrencyTest); required:
 *       one payment, at least one replay of it, zero forKey, nothing else.
 *   (f) receive:ok 2, receive:exceedsInvoice 1, annul:hasActiveAllocations 3
 *       (the annul-wins branch is asserted too, never observed).
 * The branch a race takes depends on the scheduler; a branch never observed
 * here is pinned by its deterministic feature test, not by this file.
 *
 * SENSITIVITY (a)–(f), measured 2026-10-25, each mutation on a backup copy,
 * restored from the copy and checked with `git diff --quiet`, 2 runs per
 * engine, same result in every run:
 *   - (a) lockForUpdate() removed from Ledger::lockInvoices(): RED on MySQL
 *     8.4.10 and MariaDB 11.4.13 — but by DEADLOCK, not by over-allocation:
 *     4-5 of 6 children die with 1213 on the collection-state UPDATE of the
 *     invoice (the pivot INSERT takes a shared FK lock on the invoice row,
 *     the UPDATE then needs it exclusive). The double allocation itself was
 *     not observed; the gate turns red because a deadlock is uncontrolled.
 *   - (b), (c), (d) lockForUpdate() removed from CustomerCredit::lockPayments():
 *     RED on both engines for the invariant itself — (b) apply:ok 6 (credit
 *     spent six times), (c) every child wins (3 apply + 3 refund; MariaDB once
 *     2 apply + 3 refund), (d) apply:ok 3 AND reverse:ok 3 (credit spent from
 *     a REVERSED payment).
 *   - (f) lockForUpdate() removed from SettlementService::annul(): RED on both
 *     engines — annul:ok 3 alongside receive:ok 2 (a CANCELLED invoice with
 *     active allocations).
 *   - (e) measures convergence only: a fork cannot force a child into the
 *     idempotency-key backstop (the winner may commit before the others pass
 *     register()'s pre-check). The replay POLICY's sensitivity is the
 *     deterministic race test of Task 10
 *     (tests/Integration/Mysql/ReplayBackstopRaceTest.php, spec §9, §13 row 43).
 *
 * Child count 6, as in (g): below the innodb_lock_wait_timeout ceiling (each
 * scenario serialises at most six short transactions on one row lock; the
 * slowest run holds well under a second against the 50 s default).
 *
 * Same harness rules as the other fork tests: no RefreshDatabase (children
 * read parent-committed rows on their own connections), bound to
 * MysqlIntegrationTestCase (tests/Pest.php), released on an absolute-time
 * barrier (STD-004), anti-skip guard once armed. Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    // Anti-skip guard (AID-264/AID-258): once armed, a missing precondition fails loud.
    if (! function_exists('pcntl_fork')) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the pcntl extension is unavailable — the concurrency gate would silently prove nothing.');
    }

    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the LARABILL_TEST_MYSQL_* env is not configured — the concurrency gate would silently prove nothing.');
    }

    $this->bootstrap();
});

it('(g) six partial receive() on one invoice never over-allocate it', function () {
    $childCount = 6;
    $owner      = $this->seedUser();
    $billable   = $this->seedUser();

    $invoice = Invoice::factory()->sent()->create([
        'user_id'      => $owner, 'billable_user_id' => $billable,
        'total_amount' => cents(12100), 'is_immutable' => false, 'paid_at' => null, 'due_date' => null,
    ]);
    $invoiceId = (string) $invoice->id;

    $resultDir = sys_get_temp_dir().'/larabill_alloc_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // STD-004: absolute-time barrier — every child released at one instant.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing'); // never share the parent's PDO across the fork
            time_sleep_until($startAt);

            try {
                app(PaymentService::class)->receive(
                    $billable, cents(7000), 'EUR', now(), PaymentMethod::BankTransfer, "partial-{$i}", null,
                    [new Allocation($invoiceId, cents(7000))],
                );
                exit(0); // allocated
            } catch (AllocationExceedsInvoiceException) {
                exit(2); // controlled loss: the outstanding left no room
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/'.getmypid().'.err', get_class($e).': '.$e->getMessage());
                exit(1); // UNCONTROLLED
            }
        }

        $pids[] = $pid;
    }

    $allocated = $refused = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        match ($code) {
            0       => $allocated++,
            2       => $refused++,
            default => $uncontrolled++,
        };
    }

    if ($uncontrolled > 0) {
        $errors = glob($resultDir.'/*.err') ?: [];
        $detail = implode("\n", array_map(fn (string $f): string => basename($f).': '.(string) file_get_contents($f), $errors));
        test()->fail("$uncontrolled of $childCount children died uncontrolled:\n$detail");
    }

    DB::purge('testing'); // re-read committed state on a fresh connection

    $fresh = Invoice::query()->findOrFail($invoiceId);

    expect($allocated)->toBe(1)
        ->and($refused)->toBe(5)
        ->and(DB::table('grouped_payment_invoice')->where('invoice_id', $invoiceId)->where('status', AllocationStatus::ACTIVE->value)->count())->toBe(1)
        ->and($fresh->amountPaid()->unscaledValue())->toBe(7000)
        ->and($fresh->status)->toBe(InvoiceStatus::PARTIALLY_PAID);
})->group('concurrency');

/*
 * Scenarios (a)–(f) (AID-962 Task 11, spec §10). Shared harness below:
 * aid962ForkRace() forks one child per role, releases them all on one
 * absolute-time barrier, and classifies each exit — 0 success, 2 controlled
 * (a domain exception aid962ForkClassify() recognises), 1 uncontrolled (the
 * error goes to a per-child .err file and the parent fails with all of them).
 * Every child calls the service at TOP LEVEL, never inside a wrapping
 * transaction (spec §13.10 row 43). Roles alternate so the fork order does
 * not hand one role a head start.
 */

/**
 * @param  list<string>  $roles  one child per entry
 * @param  Closure(string, int): string  $body  returns the success tag
 * @return array<string, int> tally of "role:tag", sorted by key
 */
function aid962ForkRace(array $roles, Closure $body): array
{
    $dir = sys_get_temp_dir().'/larabill_alloc_'.Str::random(8);
    mkdir($dir, 0755, true);

    // STD-004: absolute-time barrier — every child released at one instant.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    foreach ($roles as $i => $role) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing'); // never share the parent's PDO across the fork
            time_sleep_until($startAt);

            try {
                file_put_contents("{$dir}/{$i}.tag", "{$role}:".$body($role, $i));
                exit(0);
            } catch (Throwable $e) {
                $tag = aid962ForkClassify($e);
                if ($tag !== null) {
                    file_put_contents("{$dir}/{$i}.tag", "{$role}:{$tag}");
                    exit(2);
                }

                file_put_contents("{$dir}/{$i}.err", "{$role}: ".get_class($e).': '.$e->getMessage());
                exit(1);
            }
        }

        $pids[$i] = $pid;
    }

    $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        if ($code !== 0 && $code !== 2) {
            $uncontrolled++;
        }
    }

    if ($uncontrolled > 0) {
        $errors = glob($dir.'/*.err') ?: [];
        $detail = implode("\n", array_map(fn (string $f): string => basename($f).': '.(string) file_get_contents($f), $errors));
        test()->fail("{$uncontrolled} of ".count($roles)." children died uncontrolled:\n{$detail}");
    }

    $tags = array_map(fn (string $f): string => (string) file_get_contents($f), glob($dir.'/*.tag') ?: []);
    expect($tags)->toHaveCount(count($roles));

    $tally = array_count_values($tags);
    ksort($tally);

    DB::purge('testing'); // the parent re-reads committed state on a fresh connection

    return $tally;
}

/** The controlled outcome a domain exception stands for, or null (uncontrolled). */
function aid962ForkClassify(Throwable $e): ?string
{
    $message = $e->getMessage();

    return match (true) {
        $e instanceof AllocationExceedsInvoiceException  => 'exceedsInvoice',
        $e instanceof InsufficientCreditException        => match (true) {
            str_contains($message, 'insufficient')       => 'forBalance',
            str_contains($message, 'cannot be voided')   => 'entryHasActiveExits',
            default                                      => 'insufficient:other',
        },
        $e instanceof GroupedPaymentValidationException  => match (true) {
            str_contains($message, 'only SENT/OVERDUE/PENDING') => 'notPayableStatus',
            str_contains($message, 'already covered')           => 'alreadyActivelyPaid',
            default                                             => 'validation:other',
        },
        $e instanceof InvoiceNotAnnullableException      => match (true) {
            str_contains($message, 'active payment allocation') => 'hasActiveAllocations',
            str_contains($message, 'already CANCELLED')         => 'alreadyAnnulled',
            default                                             => 'annul:other',
        },
        $e instanceof IdempotencyConflictException       => 'idempotencyConflict',
        default                                          => null,
    };
}

/**
 * The customer's credit: TOP_UP payments with no allocations, received in
 * order (oldest first), each at the top level. Returns their ids.
 *
 * @param  list<int>  $centsList
 * @return list<string>
 */
function aid962ForkTopUps(string $billable, array $centsList): array
{
    $ids = [];
    foreach ($centsList as $n => $cents) {
        $ids[] = (string) app(PaymentService::class)->receive(
            $billable, cents($cents), 'EUR', now()->subDays(count($centsList) - $n), PaymentMethod::BankTransfer, "topup-{$n}", null,
            [], CreditMovementKind::TOP_UP,
        )->id;
    }

    return $ids;
}

/**
 * SENT invoices of $billable, one per child, with an EUR fiscal config so
 * apply() runs the fiscal-currency path.
 *
 * @return list<string>
 */
function aid962ForkInvoices(string $owner, string $billable, int $count, int $totalCents, bool $withFiscalConfig = true): array
{
    $config = $withFiscalConfig ? CompanyFiscalConfig::factory()->create(['currency' => 'EUR']) : null;

    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $ids[] = (string) Invoice::factory()->sent()->create([
            'user_id'                  => $owner, 'billable_user_id' => $billable,
            'company_fiscal_config_id' => $config?->id,
            'total_amount'             => cents($totalCents), 'is_immutable' => false, 'paid_at' => null, 'due_date' => null,
        ])->id;
    }

    return $ids;
}

/** Entries of $billable whose ACTIVE exits exceed them — must always be zero. */
function aid962ForkOverConsumedEntries(string $billable): int
{
    return CreditMovement::query()
        ->where('billable_user_id', $billable)
        ->active()
        ->entries()
        ->get()
        ->filter(fn (CreditMovement $entry): bool => $entry->remaining()->isNegative())
        ->count();
}

/** ACTIVE APPLIED exits consuming an entry of a REVERSED payment — must always be zero. */
function aid962ForkSpentFromReversed(string $billable): int
{
    return DB::table('credit_movements as x')
        ->join('credit_movements as e', 'e.id', '=', 'x.consumes_movement_id')
        ->join('grouped_payments as p', 'p.id', '=', 'e.grouped_payment_id')
        ->where('x.billable_user_id', $billable)
        ->where('x.kind', CreditMovementKind::APPLIED->value)
        ->whereNull('x.reversed_at')
        ->where('p.status', GroupedPaymentStatus::REVERSED->value)
        ->count();
}

/** @return list<string> six roles alternating $a and $b */
function aid962ForkAlternate(string $a, string $b): array
{
    return [$a, $b, $a, $b, $a, $b];
}

it('(a) six full receive() on one invoice allocate it exactly once', function () {
    $owner       = $this->seedUser();
    $billable    = $this->seedUser();
    [$invoiceId] = aid962ForkInvoices($owner, $billable, 1, 10000, withFiscalConfig: false);

    $tally = aid962ForkRace(array_fill(0, 6, 'receive'), function (string $role, int $i) use ($billable, $invoiceId): string {
        app(PaymentService::class)->receive(
            $billable, cents(10000), 'EUR', now(), PaymentMethod::BankTransfer, "race-{$i}", null,
            [new Allocation($invoiceId, cents(10000))],
        );

        return 'ok';
    });

    $fresh = Invoice::query()->findOrFail($invoiceId);

    // The winner settles the invoice in full, so every loser meets a PAID invoice:
    // the §5.1.3 list checks the status before the outstanding (Ledger::assertPayable()
    // runs first), and the refusal is notPayableStatus, not exceedsInvoice.
    expect($tally)->toBe(['receive:notPayableStatus' => 5, 'receive:ok' => 1])
        ->and(DB::table('grouped_payment_invoice')->where('invoice_id', $invoiceId)->where('status', AllocationStatus::ACTIVE->value)->count())->toBe(1)
        ->and(GroupedPayment::query()->where('billable_user_id', $billable)->count())->toBe(1)
        ->and($fresh->amountPaid()->unscaledValue())->toBe(10000)
        ->and($fresh->status)->toBe(InvoiceStatus::PAID);
})->group('concurrency');

it('(b) six apply() of the whole balance, each on its own invoice, spend it exactly once', function () {
    $owner    = $this->seedUser();
    $billable = $this->seedUser();
    aid962ForkTopUps($billable, [1500, 1400]);
    $invoiceIds = aid962ForkInvoices($owner, $billable, 6, 2900);

    $tally = aid962ForkRace(array_fill(0, 6, 'apply'), function (string $role, int $i) use ($billable, $invoiceIds): string {
        app(CustomerCredit::class)->apply($billable, Invoice::query()->findOrFail($invoiceIds[$i]), cents(2900));

        return 'ok';
    });

    expect($tally)->toBe(['apply:forBalance' => 5, 'apply:ok' => 1])
        ->and(CustomerCredit::for($billable, 'EUR')->balance()->unscaledValue())->toBe(0)
        ->and(aid962ForkOverConsumedEntries($billable))->toBe(0)
        ->and(Invoice::query()->whereIn('id', $invoiceIds)->where('status', InvoiceStatus::PAID->value)->count())->toBe(1)
        // one allocation per source payment spent: 1500 + 1400
        ->and(DB::table('grouped_payment_invoice')->whereIn('invoice_id', $invoiceIds)->where('status', AllocationStatus::ACTIVE->value)->count())->toBe(2);
})->group('concurrency');

it('(c) apply() against refund() of the whole balance: exactly one wins', function () {
    $owner    = $this->seedUser();
    $billable = $this->seedUser();
    aid962ForkTopUps($billable, [1500, 1400]);
    $invoiceIds = aid962ForkInvoices($owner, $billable, 6, 2900);

    $tally = aid962ForkRace(aid962ForkAlternate('apply', 'refund'), function (string $role, int $i) use ($billable, $invoiceIds): string {
        if ($role === 'apply') {
            app(CustomerCredit::class)->apply($billable, Invoice::query()->findOrFail($invoiceIds[$i]), cents(2900));
        } else {
            app(CustomerCredit::class)->refund($billable, cents(2900), 'EUR', now(), PaymentMethod::BankTransfer, "refund-{$i}");
        }

        return 'ok';
    });

    $wins    = ($tally['apply:ok'] ?? 0)         + ($tally['refund:ok'] ?? 0);
    $refused = ($tally['apply:forBalance'] ?? 0) + ($tally['refund:forBalance'] ?? 0);

    $applied  = (int) CreditMovement::query()->where('billable_user_id', $billable)->active()->where('kind', CreditMovementKind::APPLIED->value)->sum('amount');
    $refunded = (int) CreditMovement::query()->where('billable_user_id', $billable)->active()->where('kind', CreditMovementKind::REFUNDED->value)->sum('amount');

    expect($wins)->toBe(1, 'tally: '.json_encode($tally))
        ->and($refused)->toBe(5, 'tally: '.json_encode($tally))
        ->and(CustomerCredit::for($billable, 'EUR')->balance()->unscaledValue())->toBe(0)
        ->and(aid962ForkOverConsumedEntries($billable))->toBe(0)
        // the money left exactly once, by whichever path won
        ->and($applied + $refunded)->toBe(-2900)
        ->and(isset($tally['apply:ok']) ? [$applied, $refunded] : [$refunded, $applied])->toBe([-2900, 0]);
})->group('concurrency');

it('(d) reverse() against apply() on the same payment never spends a reversed payment', function () {
    $owner       = $this->seedUser();
    $billable    = $this->seedUser();
    [$paymentId] = aid962ForkTopUps($billable, [2900]);
    $invoiceIds  = aid962ForkInvoices($owner, $billable, 6, 2900);

    $tally = aid962ForkRace(aid962ForkAlternate('reverse', 'apply'), function (string $role, int $i) use ($billable, $invoiceIds, $paymentId): string {
        if ($role === 'reverse') {
            app(PaymentService::class)->reverse(GroupedPayment::query()->findOrFail($paymentId), "race-{$i}");
        } else {
            app(CustomerCredit::class)->apply($billable, Invoice::query()->findOrFail($invoiceIds[$i]), cents(2900));
        }

        return 'ok';
    });

    $payment = GroupedPayment::query()->findOrFail($paymentId);

    // Exactly one of the two orders, never a mix (spec §10 (d)).
    $reversalWon = ['apply:forBalance' => 3, 'reverse:ok' => 3];
    $applyWon    = ['apply:forBalance' => 2, 'apply:ok' => 1, 'reverse:entryHasActiveExits' => 3];

    // The reversed-credit check first: a mutation that breaks the tally still runs it.
    expect(aid962ForkSpentFromReversed($billable))->toBe(0)
        ->and($tally)->toBeIn([$reversalWon, $applyWon])
        ->and(aid962ForkOverConsumedEntries($billable))->toBe(0)
        ->and(CustomerCredit::for($billable, 'EUR')->balance()->unscaledValue())->toBe(0)
        ->and($payment->status)->toBe($tally === $reversalWon ? GroupedPaymentStatus::REVERSED : GroupedPaymentStatus::POSTED)
        ->and(Invoice::query()->whereIn('id', $invoiceIds)->where('status', InvoiceStatus::PAID->value)->count())->toBe($tally === $reversalWon ? 0 : 1);
})->group('concurrency');

it('(e) six register() with the same legacy key converge on one payment and never raise forKey', function () {
    $owner      = $this->seedUser();
    $billable   = $this->seedUser();
    $invoiceIds = aid962ForkInvoices($owner, $billable, 2, 5000, withFiscalConfig: false);

    // Same payer, currency, amount and invoice ids → same derived legacy key;
    // paidAt (distinct second) and reference differ per child.
    $tally = aid962ForkRace(array_fill(0, 6, 'register'), function (string $role, int $i) use ($billable, $invoiceIds): string {
        return (string) app(GroupedPaymentService::class)->register(
            billableUserId: $billable,
            invoiceIds: $invoiceIds,
            paidAt: now()->subSeconds($i + 1),
            amount: cents(10000),
            currency: 'EUR',
            reference: "legacy-{$i}",
        )->id;
    });

    $payments = GroupedPayment::query()->where('billable_user_id', $billable)->get();

    $replayTag = 'register:'.$payments->first()?->id;

    // Spec §13 row 44: a loser whose pre-check ran before the winner committed
    // meets the PAID invoice under register()'s eligibility lock — a controlled
    // refusal on a correct implementation. What must never appear is forKey
    // (idempotencyConflict) or any outcome outside these three kinds.
    $replayed = $tally[$replayTag] ?? 0;
    $refused  = ($tally['register:notPayableStatus'] ?? 0) + ($tally['register:alreadyActivelyPaid'] ?? 0);

    expect($payments)->toHaveCount(1)
        ->and($tally)->not->toHaveKey('register:idempotencyConflict')
        ->and($replayed)->toBeGreaterThanOrEqual(1, 'tally: '.json_encode($tally))
        ->and($replayed + $refused)->toBe(6, 'tally: '.json_encode($tally))
        ->and(DB::table('grouped_payment_invoice')->where('grouped_payment_id', $payments->first()->id)->count())->toBe(2)
        ->and(Invoice::query()->whereIn('id', $invoiceIds)->where('status', InvoiceStatus::PAID->value)->count())->toBe(2);
})->group('concurrency');

it('(f) annul() against receive() on one SENT invoice: exactly one side wins', function () {
    $owner       = $this->seedUser();
    $billable    = $this->seedUser();
    [$invoiceId] = aid962ForkInvoices($owner, $billable, 1, 10000, withFiscalConfig: false);

    $tally = aid962ForkRace(aid962ForkAlternate('annul', 'receive'), function (string $role, int $i) use ($billable, $invoiceId): string {
        if ($role === 'annul') {
            app(SettlementService::class)->annul(Invoice::query()->findOrFail($invoiceId));
        } else {
            app(PaymentService::class)->receive(
                $billable, cents(4000), 'EUR', now(), PaymentMethod::BankTransfer, "race-{$i}", null,
                [new Allocation($invoiceId, cents(4000))],
            );
        }

        return 'ok';
    });

    $fresh  = Invoice::query()->findOrFail($invoiceId);
    $active = DB::table('grouped_payment_invoice')->where('invoice_id', $invoiceId)->where('status', AllocationStatus::ACTIVE->value)->count();

    // Annulment first: the payments meet a CANCELLED invoice. Payment first:
    // every annulment meets an active allocation; 3 × 4000 > 10000 leaves the
    // third payment without room.
    $annulWon   = ['annul:alreadyAnnulled' => 2, 'annul:ok' => 1, 'receive:notPayableStatus' => 3];
    $paymentWon = ['annul:hasActiveAllocations' => 3, 'receive:exceedsInvoice' => 1, 'receive:ok' => 2];

    expect($tally)->toBeIn([$annulWon, $paymentWon]);

    if ($tally === $annulWon) {
        expect($fresh->status)->toBe(InvoiceStatus::CANCELLED)->and($active)->toBe(0);
    } else {
        expect($fresh->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
            ->and($active)->toBe(2)
            ->and($fresh->amountPaid()->unscaledValue())->toBe(8000);
    }
})->group('concurrency');
