<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-1856 (spec 2026-11-06, §7 T14) — appliers and linkers over the same
 * credit entries, in separate processes.
 *
 * The invariant. CustomerCredit::apply() never spends an entry linked to an
 * advance invoice, and it decides that on the entry's LOCKED row. So for
 * every link that commits there is a clean edge: an apply() either committed
 * its exit on the entry before the link (the link waited for it), or it
 * leaves the entry alone afterwards. Each linker therefore reads what is
 * left of its entry right after its link commits and writes it down; when
 * every process has finished, what is left of that entry must STILL be that
 * figure. There are no refunds and no reversals in the workload, so nothing
 * else may lower it.
 *
 * The workload. One customer, AID1856_ENTRIES entries of 10,00 each. Applier
 * processes apply 1,00 at a time to their own invoice until the consumable
 * credit runs out; they serialize on the customer's payment locks, so at any
 * moment one of them is inside its transaction, between its plan and its
 * commit, on the entry at the head of the FIFO. Linker processes repeatedly
 * link the oldest entry that is still unlinked and has something left — the
 * one an applier is consuming right now — each time with a fresh advance
 * DRAFT. Two linkers that pick the same entry are a controlled refusal.
 *
 * Sensitivity (measured 2026-11-06 with the locking read of
 * Ledger::lockFundingEntries() replaced by a plain read): the invariant
 * breaks on every run — MySQL 8.4.10, 6 of 6 rounds at each of 1+1, 2+1,
 * 3+2 and 4+3 processes; MariaDB 11.4.13, 4 of 4 rounds at 1+1 and at 4+3 —
 * always as «1000 at link, 900 now»: an applier that had planned the entry
 * committed its exit after the link. With the lock: green on both engines.
 * The deterministic proof of the mechanism is
 * tests/Integration/Mysql/AdvanceBackedCreditRaceTest.php (T12, T13); this
 * test adds the load: no lost update, no half-written call, and what the
 * lock order does under real contention.
 *
 * Same harness rules as the other fork tests: no RefreshDatabase, bound to
 * MysqlIntegrationTestCase (tests/Pest.php), children released through a
 * rendezvous plus an absolute-time barrier, each child's real failure
 * written to a file and shown by the parent.
 *
 * Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
const AID1856_ENTRIES     = 12;
const AID1856_ENTRY_CENTS = 1000;
const AID1856_APPLY_CENTS = 100;

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

/**
 * [appliers, linkers]. Default 4 + 3; LARABILL_CONCURRENCY_FORKS=n runs n
 * appliers and n − 1 linkers (at least one).
 *
 * FLOOR, measured with the entry lock removed: 1 applier + 1 linker already
 * breaks the invariant on every round, on both engines (the linker aims at
 * the entry the applier is consuming, and the applier's window between its
 * plan and its commit is most of its transaction).
 *
 * CEILING, computed: appliers serialize on the customer's payment locks, so
 * the last one waits (appliers − 1) × the cost of one apply(), and a linker
 * waits for at most one apply(). Measured cost of one apply() over this
 * fixture's 12 entries: 16.6 ms on MySQL 8.4.10, 23.2 ms on MariaDB 11.4.13;
 * innodb_lock_wait_timeout is 50 s on both. The wait reaches the timeout
 * near two thousand appliers — the default is three orders of magnitude
 * below it. Do not raise the count «to be safe»: 4 + 3 is not there to make
 * the race likelier (1 + 1 is enough), it is there so appliers also contend
 * with each other while links land.
 *
 * @return array{0: int, 1: int}
 */
function aid1856ForkCount(): array
{
    $override = getenv('LARABILL_CONCURRENCY_FORKS');

    return $override ? [(int) $override, max(1, (int) $override - 1)] : [4, 3];
}

/**
 * Fork one child per role, release them together (rendezvous + absolute-time
 * barrier) and collect what each one reports.
 *
 * @param  array<string, Closure(): array<string, mixed>>  $bodies
 * @return array<string, array<string, mixed>> role → its report
 */
function aid1856ForkRun(array $bodies): array
{
    $dir = sys_get_temp_dir().'/larabill_aid1856_'.Str::random(8);
    mkdir($dir, 0755, true);

    $roles   = array_keys($bodies);
    $startAt = microtime(true) + 1.5;

    $pids = [];
    foreach ($bodies as $role => $body) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing'); // never share the parent's PDO across the fork

            try {
                file_put_contents("{$dir}/{$role}.inflight", '1');
                $deadline = microtime(true) + 10.0;
                foreach ($roles as $peer) {
                    while (! file_exists("{$dir}/{$peer}.inflight")) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException("rendezvous timeout waiting for {$peer}");
                        }
                        usleep(1000);
                    }
                }
                time_sleep_until($startAt);

                file_put_contents("{$dir}/{$role}.json", json_encode($body(), JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $e) {
                file_put_contents("{$dir}/{$role}.err", "{$role}: ".$e::class.': '.$e->getMessage());
                exit(1);
            }
        }

        $pids[$role] = $pid;
    }

    $died = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            $died++;
        }
    }

    $errors  = array_map(fn (string $file): string => (string) file_get_contents($file), glob($dir.'/*.err') ?: []);
    $reports = [];
    foreach (glob($dir.'/*.json') ?: [] as $file) {
        $reports[basename($file, '.json')] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    DB::purge('testing'); // the parent re-reads committed state on a fresh connection

    if ($died > 0 || $errors !== []) {
        test()->fail("{$died} of ".count($roles)." children died:\n".implode("\n", $errors));
    }

    return $reports;
}

/** SQLSTATE 40001 (deadlock victim, 1213) or a lock wait timeout (1205): nothing was written, the caller retries. */
function aid1856IsRetryable(QueryException $e): bool
{
    return in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
}

it('never lowers what is left of an entry once its link to an advance has committed (T14)', function () {
    [$appliers, $linkers] = aid1856ForkCount();

    $customer = $this->seedUser();
    $issuer   = $this->seedUser();
    $configId = CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id;

    for ($i = 0; $i < AID1856_ENTRIES; $i++) {
        app(PaymentService::class)->receive(
            $customer, cents(AID1856_ENTRY_CENTS), 'EUR', now()->subDays(AID1856_ENTRIES - $i)->startOfSecond(),
            PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
        );
    }

    $invoices = [];
    for ($i = 0; $i < $appliers; $i++) {
        $invoices[] = Invoice::factory()->sent()->create([
            'user_id'                  => $issuer, 'billable_user_id' => $customer,
            'total_amount'             => cents(AID1856_ENTRIES * AID1856_ENTRY_CENTS), 'is_immutable' => false, 'paid_at' => null, 'due_date' => null,
            'company_fiscal_config_id' => $configId,
        ])->id;
    }

    // One advance DRAFT per possible link, dealt to the linkers up front (an advance backs ONE entry).
    $advances = [];
    for ($i = 0; $i < $linkers; $i++) {
        for ($j = 0; $j < AID1856_ENTRIES; $j++) {
            $advances[$i][] = Invoice::factory()->unissuedDraft()->create(['billable_user_id' => $customer, 'user_id' => $issuer])->id;
        }
    }

    $bodies = [];

    for ($i = 0; $i < $appliers; $i++) {
        $invoiceId = $invoices[$i];

        $bodies["applier{$i}"] = function () use ($customer, $invoiceId): array {
            $report = ['applied' => 0, 'retried' => 0];

            while (true) {
                try {
                    app(CustomerCredit::class)->apply($customer, Invoice::query()->findOrFail($invoiceId), cents(AID1856_APPLY_CENTS));
                    $report['applied']++;
                } catch (InsufficientCreditException) {
                    return $report; // no consumable credit left: every entry is spent or linked
                } catch (QueryException $e) {
                    if (! aid1856IsRetryable($e) || ++$report['retried'] > 50) {
                        throw $e;
                    }
                }
            }
        };
    }

    for ($i = 0; $i < $linkers; $i++) {
        $pool = $advances[$i];

        $bodies["linker{$i}"] = function () use ($customer, $pool, $i): array {
            mt_srand(1856 + $i);
            $report = ['links' => [], 'lost' => 0, 'retried' => 0];

            foreach ($pool as $advanceId) {
                usleep(mt_rand(0, 4000));

                // The head of the FIFO: the oldest entry still unlinked with something left.
                $head = CreditMovement::query()
                    ->where('billable_user_id', $customer)
                    ->active()->entries()->notAdvanceBacked()
                    ->orderBy('occurred_at')->orderBy('id')
                    ->get()
                    ->first(fn (CreditMovement $entry): bool => $entry->remaining()->isPositive());

                if ($head === null) {
                    break; // nothing left to link
                }

                try {
                    $head->linkAdvanceInvoice(Invoice::query()->findOrFail($advanceId));
                } catch (CreditMovementAlreadyLinkedException) {
                    $report['lost']++; // another linker took this entry first

                    continue;
                } catch (QueryException $e) {
                    if (! aid1856IsRetryable($e) || ++$report['retried'] > 50) {
                        throw $e;
                    }

                    continue;
                }

                // The link is committed. From here on, no apply() may take this entry.
                $report['links'][] = [
                    'entry'     => $head->id,
                    'remaining' => CreditMovement::query()->findOrFail($head->id)->remaining()->unscaledValue(),
                ];
            }

            return $report;
        };
    }

    $reports = aid1856ForkRun($bodies);

    expect($reports)->toHaveCount($appliers + $linkers);

    $links   = [];
    $applied = 0;
    foreach ($reports as $role => $report) {
        $applied += $report['applied'] ?? 0;
        foreach ($report['links'] ?? [] as $link) {
            $links[] = $link;
        }
    }

    // The race was real: both kinds of process did their work.
    expect($applied)->toBeGreaterThan(0)
        ->and($links)->not->toBeEmpty();

    // THE INVARIANT: what each linker saw right after its commit is what is left now.
    $dropped = [];
    foreach ($links as $link) {
        $now = CreditMovement::query()->findOrFail($link['entry'])->remaining()->unscaledValue();
        if ($now !== $link['remaining']) {
            $dropped[] = "{$link['entry']}: {$link['remaining']} at link, {$now} now";
        }
    }

    expect($dropped)->toBe([]);

    // Nothing half-written and no lost update: every allocation is funded to the cent by
    // its own exits, no entry is consumed past its amount, and the ledger adds up.
    $exitsByAllocation = CreditMovement::query()
        ->where('kind', CreditMovementKind::APPLIED->value)->active()
        ->get()
        ->groupBy('allocation_id')
        ->map(fn ($exits): int => -(int) $exits->sum(fn (CreditMovement $exit): int => $exit->amount->unscaledValue()));

    $allocations = PaymentAllocation::query()->get();

    expect($allocations)->toHaveCount($applied)
        ->and($allocations->every(fn (PaymentAllocation $row): bool => ($exitsByAllocation[$row->id] ?? null) === $row->applied_amount->unscaledValue()))->toBeTrue()
        ->and(CreditMovement::query()->entries()->get()->every(fn (CreditMovement $entry): bool => ! $entry->remaining()->isNegative()))->toBeTrue()
        ->and(CustomerCredit::for($customer, 'EUR')->balance()->unscaledValue())->toBe(AID1856_ENTRIES * AID1856_ENTRY_CENTS - $applied * AID1856_APPLY_CENTS);

    // Every applier stopped because the consumable credit ran out, not because it gave up.
    expect(CustomerCredit::for($customer, 'EUR')->breakdown()->consumable->unscaledValue())->toBe(0);
});
