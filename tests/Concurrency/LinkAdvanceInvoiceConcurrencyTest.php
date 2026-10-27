<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-1778 spec §5.6 — fork proofs of the 9.0 link contract (R24, R27).
 *
 * R24 — one advance, two entries, two connections: the advance invoice row
 * X lock (FIRST statement of the link's transaction) is the serialization
 * point; the loser's conflict read sees the committed winner and throws
 * `CreditMovementAlreadyLinkedException::forAdvance()` BEFORE any UPDATE,
 * so the loser's own row stays NULL (J1). Run in BOTH supported contexts of
 * the K1 caller contract: (i) each linker as the OUTERMOST transaction;
 * (ii) each linker inside a caller wrapper (`DB::transaction`) that made NO
 * prior read. The unsupported context (a caller's prior plain read fixing
 * the REPEATABLE READ snapshot) is deliberately NOT tested: it is the
 * documented caller contract, not an enforced guard. Sensitivity: the
 * mutation that must fail context (ii) is a plain read of the entry row
 * before the invoice lock when `DB::transactionLevel() > 1` — the loser's
 * conflict read then sees the stale empty set and links anyway.
 *
 * R27 — link vs `CustomerCredit::apply()` onto the SAME advance invoice,
 * released through a deterministic rendezvous (J8: both sides record their
 * in-flight marker and wait for the peer's marker before calling, so the
 * invoice X lock is contended by construction; the parent asserts both
 * markers exist — a serial execution fails loud). Under the 9.0 narrowing
 * the same-invoice pair is mutually exclusive by that lock: the linker only
 * writes on a doc-DRAFT advance, the applier only funds a doc-ISSUED one,
 * and both checks run under the same exclusive row lock — so exactly one
 * side writes (measured: link:ok + apply:notIssued, whichever order takes
 * the lock). Whichever loser may surface SQLSTATE 40001/1213 RETRIES inline
 * and must converge to a controlled outcome — the docblock's retry contract
 * pinned on the NEW lock order instead of assumed. The classic two-party
 * deadlock cycle (linker's X(entry) + S(invoice) vs applier's X(invoice) +
 * S(entry)) belonged to the 8.3.0 lock order, where the UPDATE came first;
 * on the new order the invoice lock is taken before the UPDATE, and the
 * R24 race plus the existing PaymentAllocationConcurrencyTest apply races
 * cover the entry-row contention.
 *
 * Same harness rules as the other fork tests: no RefreshDatabase (children
 * read parent-committed rows on their own connections), bound to
 * MysqlIntegrationTestCase (tests/Pest.php), released on the J8 rendezvous
 * plus the STD-004 absolute-time barrier. Child count 2 is the race's own size (one advance, two
 * contenders): the lock is a single row lock held for microseconds, far
 * below the innodb_lock_wait_timeout ceiling, so no floor/ceiling sweep
 * applies — the discriminating mutation is the K1 stale read above, which
 * fails context (ii) at ANY child count.
 *
 * Run on demand:
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

/**
 * Fork one child per role, release them through the J8 rendezvous (each
 * child writes its in-flight marker, waits for the peer's marker, then
 * calls), classify each exit, and return [tally, in-flight markers seen].
 * The parent later asserts the markers exist: both sides were in flight
 * before either committed — a serial run cannot produce them both.
 *
 * @param  array<string, Closure(): string>  $bodies  role → body returning its success tag
 * @param  array<string, list<string>>  $controlled  role → controlled exception messages tolerated (classified by class, tagged `role:refused`)
 * @return array{0: array<string, int>, 1: bool}
 */
function aid1778LinkForkRace(array $bodies, array $controlled): array
{
    $dir = sys_get_temp_dir().'/larabill_link_'.Str::random(8);
    mkdir($dir, 0755, true);

    $roles = array_keys($bodies);

    // STD-004: absolute-time barrier. Combined with the J8 rendezvous below:
    // every child is released at ONE wall-clock instant, AFTER both in-flight
    // markers exist — the race actually runs, never a staggered start.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    foreach ($bodies as $role => $body) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing'); // never share the parent's PDO across the fork

            try {
                // J8 rendezvous: record in-flight, wait for the peer's marker,
                // then release all children at the same absolute instant.
                file_put_contents("{$dir}/{$role}.inflight", '1');
                $deadline = microtime(true) + 5.0;
                foreach ($roles as $peer) {
                    if ($peer === $role) {
                        continue;
                    }
                    while (! file_exists("{$dir}/{$peer}.inflight")) {
                        if (microtime(true) > $deadline) {
                            file_put_contents("{$dir}/{$role}.err", "{$role}: rendezvous timeout waiting for {$peer}");
                            exit(1);
                        }
                        usleep(1000);
                    }
                }
                time_sleep_until($startAt);

                $tag = $body();
                file_put_contents("{$dir}/{$role}.tag", "{$role}:{$tag}");
                exit(0);
            } catch (QueryException $e) {
                // SQLSTATE 40001 / error 1213: the deadlock victim RETRIES
                // once (the docblock's consumer contract) and must converge.
                if (str_contains($e->getMessage(), '1213') || str_contains($e->getMessage(), '40001') || (int) $e->getCode() === 40001) {
                    try {
                        $tag = $body();
                        file_put_contents("{$dir}/{$role}.tag", "{$role}:retried-{$tag}");
                        exit(0);
                    } catch (Throwable $retried) {
                        if (aid1778LinkClassify($retried, $controlled[$role] ?? []) !== null) {
                            file_put_contents("{$dir}/{$role}.tag", "{$role}:retried-refused");
                            exit(2);
                        }

                        file_put_contents("{$dir}/{$role}.err", "{$role} retry: ".get_class($retried).': '.$retried->getMessage());
                        exit(1);
                    }
                }

                file_put_contents("{$dir}/{$role}.err", "{$role}: ".get_class($e).': '.$e->getMessage());
                exit(1);
            } catch (Throwable $e) {
                if (aid1778LinkClassify($e, $controlled[$role] ?? []) !== null) {
                    file_put_contents("{$dir}/{$role}.tag", "{$role}:refused");
                    exit(2);
                }

                file_put_contents("{$dir}/{$role}.err", "{$role}: ".get_class($e).': '.$e->getMessage());
                exit(1);
            }
        }

        $pids[$role] = $pid;
    }

    $uncontrolled = 0;
    foreach ($pids as $role => $pid) {
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

    $overlap = count(glob($dir.'/*.inflight') ?: []) === count($roles);

    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    DB::purge('testing'); // the parent re-reads committed state on a fresh connection

    return [$tally, $overlap];
}

/** The controlled outcome a domain exception stands for, or null (uncontrolled). */
function aid1778LinkClassify(Throwable $e, array $toleratedMessages): ?bool
{
    if ($e instanceof CreditMovementAlreadyLinkedException || $e instanceof InvalidAdvanceInvoiceException) {
        return true;
    }

    foreach ($toleratedMessages as $needle) {
        if (str_contains($e->getMessage(), $needle)) {
            return true;
        }
    }

    return null;
}
/**
 * An unnumbered DRAFT advance and two live SURPLUS entries of one customer.
 *
 * @return array{0: Invoice, 1: CreditMovement, 2: CreditMovement}
 */
function aid1778LinkFixture(string $owner, string $billable): array
{
    $advance = Invoice::factory()->unissuedDraft()->create([
        'user_id'          => $owner,
        'billable_user_id' => $billable,
        'total_amount'     => cents(5000),
        'is_immutable'     => false,
        'paid_at'          => null,
        'due_date'         => null,
    ]);
    $entries = [
        CreditMovement::factory()->create([
            'billable_user_id'   => $billable,
            'grouped_payment_id' => GroupedPayment::factory()->create(['billable_user_id' => $billable])->id,
        ]),
        CreditMovement::factory()->create([
            'billable_user_id'   => $billable,
            'grouped_payment_id' => GroupedPayment::factory()->create(['billable_user_id' => $billable])->id,
        ]),
    ];

    return [$advance, ...$entries];
}

it('R24 (outermost): two concurrent links of one draft advance — exactly one winner, the loser stays NULL', function () {
    $owner                       = $this->seedUser();
    $billable                    = $this->seedUser();
    [$advance, $entryA, $entryB] = aid1778LinkFixture($owner, $billable);

    [$tally, $overlap] = aid1778LinkForkRace(
        [
            'linkA' => function () use ($entryA, $advance): string {
                $entryA->linkAdvanceInvoice($advance); // outermost: the method's own transaction

                return 'ok';
            },
            'linkB' => function () use ($entryB, $advance): string {
                $entryB->linkAdvanceInvoice($advance);

                return 'ok';
            },
        ],
        ['linkA' => [], 'linkB' => []],
    );

    // J8: both contenders were in flight — the race actually ran.
    expect($overlap)->toBeTrue();

    // Exactly one winner; the loser is the typed advance-side refusal (J1).
    $expected = isset($tally['linkA:ok'])
        ? ['linkA:ok' => 1, 'linkB:refused' => 1]
        : ['linkA:refused' => 1, 'linkB:ok' => 1];
    expect($tally)->toBe($expected);

    // Exactly ONE movement row carries the link; the LOSER's row stays NULL.
    $linked = CreditMovement::query()->whereNotNull('advance_invoice_id')->get();
    expect($linked)->toHaveCount(1)
        ->and($linked->sole()->advance_invoice_id)->toBe($advance->id)
        ->and($linked->sole()->is($entryA) || $linked->sole()->is($entryB))->toBeTrue()
        ->and($entryA->fresh()->advance_invoice_id === $entryB->fresh()->advance_invoice_id)->toBeFalse();
})->group('concurrency');

it('R24 (wrapper with no prior read): the K1 supported context converges to one winner too', function () {
    $owner                       = $this->seedUser();
    $billable                    = $this->seedUser();
    [$advance, $entryA, $entryB] = aid1778LinkFixture($owner, $billable);

    [$tally, $overlap] = aid1778LinkForkRace(
        [
            'linkA' => function () use ($entryA, $advance): string {
                // The K1 supported wrapper: a caller transaction that made
                // NO prior read before delegating to the link.
                DB::transaction(function () use ($entryA, $advance): void {
                    $entryA->linkAdvanceInvoice($advance);
                });

                return 'ok';
            },
            'linkB' => function () use ($entryB, $advance): string {
                DB::transaction(function () use ($entryB, $advance): void {
                    $entryB->linkAdvanceInvoice($advance);
                });

                return 'ok';
            },
        ],
        ['linkA' => [], 'linkB' => []],
    );

    expect($overlap)->toBeTrue();

    $expected = isset($tally['linkA:ok'])
        ? ['linkA:ok' => 1, 'linkB:refused' => 1]
        : ['linkA:refused' => 1, 'linkB:ok' => 1];
    expect($tally)->toBe($expected);

    $linked = CreditMovement::query()->whereNotNull('advance_invoice_id')->get();
    expect($linked)->toHaveCount(1)
        ->and($linked->sole()->advance_invoice_id)->toBe($advance->id);
})->group('concurrency');

it('R27: link vs CustomerCredit::apply() on the same advance — overlap happens, only the link writes, a 40001 loser retries and converges', function () {
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    [$advance, $entry] = (function () use ($owner, $billable): array {
        $advance = Invoice::factory()->unissuedDraft()->create([
            'user_id'          => $owner,
            'billable_user_id' => $billable,
            'total_amount'     => cents(5000),
            'is_immutable'     => false,
            'paid_at'          => null,
            'due_date'         => null,
        ]);
        app(PaymentService::class)->receive(
            $billable, cents(5000), 'EUR', now(), PaymentMethod::BankTransfer, 'r27-topup', null,
            [], CreditMovementKind::TOP_UP,
        );
        $entry = CreditMovement::query()
            ->where('billable_user_id', $billable)
            ->entries()->sole();

        return [$advance, $entry];
    })();

    [$tally, $overlap] = aid1778LinkForkRace(
        [
            'link' => function () use ($entry, $advance): string {
                $entry->linkAdvanceInvoice($advance);

                return 'ok';
            },
            'apply' => function () use ($billable, $advance): string {
                app(CustomerCredit::class)->apply($billable, $advance, cents(5000));

                return 'ok';
            },
        ],
        [
            // The applier's controlled refusal: a doc-DRAFT advance is not
            // issued (Ledger::assertPayable → notIssued).
            'link'  => [],
            'apply' => ['is not issued'],
        ],
    );

    // J8: the invoice X lock was contended by both sides.
    expect($overlap)->toBeTrue();

    // The 9.0 narrowing makes the same-invoice pair mutually exclusive: the
    // link only writes on a doc-DRAFT advance, the apply only funds a
    // doc-ISSUED one — both checks run under the same exclusive lock.
    expect($tally)->toBe(['apply:refused' => 1, 'link:ok' => 1]);

    // Both final states valid: link exclusivity held; no half-written money.
    $linked = CreditMovement::query()->whereNotNull('advance_invoice_id')->get();
    expect($linked)->toHaveCount(1)
        ->and($linked->sole()->id)->toBe($entry->id)
        ->and($linked->sole()->advance_invoice_id)->toBe($advance->id)
        ->and(PaymentAllocation::query()->where('invoice_id', $advance->id)->count())->toBe(0) // apply() funded nothing on the advance
        ->and((int) CreditMovement::query()->where('consumes_movement_id', $entry->id)->count())->toBe(0)
        ->and($advance->fresh()->invoice_document_status->value)->toBe(0) // still DRAFT on the document axis
        ->and($entry->remaining()->unscaledValue())->toBe(5000);
})->group('concurrency');
