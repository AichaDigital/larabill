<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use Illuminate\Support\Facades\DB;

/**
 * AID-273 — end-to-end fork proof for the grouped-payment idempotency invariant.
 *
 * `tests/Feature/GroupedPayment/RegisterTest` covers idempotency deterministically
 * (replay a key in-process → same posted payment). This is the empirical
 * confirmation under REAL concurrency: N OS processes call register() with the
 * same idempotency key + same payload at once, and we assert the invariant holds —
 * exactly ONE grouped_payment exists, exactly one pivot row per invoice, each
 * invoice marked PAID once, and no child ever propagates an UNCONTROLLED error
 * (a raw QueryException escaping the backstop at GroupedPaymentService::register
 * would be exit 1). Convergence is guaranteed by the combination of the
 * lockForUpdate on the invoices, the unique(idempotency_key) constraint, the
 * unique(active_invoice_id) backstop, and the QueryException catch.
 *
 * Why it can't use RefreshDatabase: the forked children must read rows the parent
 * committed, on their own connections — a transactional RefreshDatabase would hide
 * them. This file binds to MysqlIntegrationTestCase (see tests/Pest.php), which
 * builds the schema committed via `artisan migrate` over a real MySQL connection
 * (SQLite :memory: is not shared across forks).
 *
 * Gated: runs only with RUN_CONCURRENCY_IT=1, pcntl available, and the
 * LARABILL_TEST_MYSQL_* env (the MySQL case skips otherwise). The file is excluded
 * from the phpunit testsuite, so the CI `pest` run never loads it. Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl extension not available');
    }

    // Schema + test users table, committed (no RefreshDatabase wraps this).
    $this->bootstrap();
});

it('concurrent register() with the same key + payload converges to exactly one payment', function () {
    $childCount = 8;

    // Committed fixtures: one billable user, two eligible SENT invoices, one shared key.
    $owner    = $this->seedUser();
    $billable = $this->seedUser();

    $invA = Invoice::factory()->sent()->create([
        'user_id'      => $owner, 'billable_user_id' => $billable,
        'total_amount' => cents(6000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    $invB = Invoice::factory()->sent()->create([
        'user_id'      => $owner, 'billable_user_id' => $billable,
        'total_amount' => cents(4000), 'is_immutable' => false, 'paid_at' => null,
    ]);

    $invoiceIds = [(string) $invA->id, (string) $invB->id];
    $key        = 'aid273-concurrency-fixed-key';

    // Fork one child per slot; each calls register() with the SAME key + payload.
    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');

            try {
                app(GroupedPaymentService::class)->register(
                    billableUserId: $billable,
                    invoiceIds: $invoiceIds,
                    paidAt: now(),
                    amount: cents(10000),
                    currency: 'EUR',
                    idempotencyKey: $key,
                );
                exit(0); // converged to the posted payment (idempotent winner or replay)
            } catch (IdempotencyConflictException|GroupedPaymentValidationException) {
                exit(2); // controlled loss under the race (key spent / already actively paid)
            } catch (Throwable) {
                exit(1); // UNCONTROLLED — a raw QueryException escaped the backstop
            }
        }

        $pids[] = $pid;
    }

    // Parent: classify every child's exit code.
    $converged = $controlled = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        match ($code) {
            0       => $converged++,
            2       => $controlled++,
            default => $uncontrolled++,
        };
    }

    // No child crashed with an uncontrolled error (the backstop held).
    expect($uncontrolled)->toBe(0);
    // At least one child produced/observed the posted payment.
    expect($converged)->toBeGreaterThan(0);

    // Re-read committed state on a fresh connection to see what the children wrote.
    DB::purge('testing');

    // The invariant: exactly ONE payment for the key, posted.
    $payments = GroupedPayment::where('idempotency_key', $key)->get();
    expect($payments)->toHaveCount(1);
    expect($payments->first()->status)->toBe(GroupedPaymentStatus::POSTED);

    // Exactly one pivot row per invoice — no duplication.
    expect(DB::table('grouped_payment_invoice')->where('grouped_payment_id', $payments->first()->id)->count())->toBe(2);

    // Both invoices marked PAID exactly once.
    expect(Invoice::whereIn('id', $invoiceIds)->where('status', InvoiceStatus::PAID->value)->count())->toBe(2);
})->group('concurrency');

it('forces the QueryException backstop: same key, disjoint payloads → one winner, the rest rejected', function () {
    // This scenario EXERCISES the catch(QueryException) backstop directly. Each
    // child registers its OWN invoice under a SHARED idempotency key. Because the
    // invoices are disjoint, there is no lockForUpdate contention to serialize
    // them: all children pass the "key not seen yet" read and race to INSERT
    // grouped_payments. unique(idempotency_key) lets one win; every loser's
    // QueryException is caught (GroupedPaymentService::register), the winner is
    // re-read, the payload does NOT match (different invoice) → it throws a
    // controlled IdempotencyConflictException. The invariant: exactly one payment,
    // and never a raw QueryException escaping to the caller.
    $childCount = 8;
    $billable   = $this->seedUser();
    $owner      = $this->seedUser();
    $key        = 'aid273-shared-key-disjoint-payloads';

    // One eligible invoice per child (disjoint payloads, same amount).
    $invoiceIds = [];
    for ($i = 0; $i < $childCount; $i++) {
        $invoiceIds[] = (string) Invoice::factory()->sent()->create([
            'user_id'      => $owner, 'billable_user_id' => $billable,
            'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
        ])->id;
    }

    $pids = [];
    foreach ($invoiceIds as $invoiceId) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('testing');

            try {
                app(GroupedPaymentService::class)->register(
                    billableUserId: $billable,
                    invoiceIds: [$invoiceId],
                    paidAt: now(),
                    amount: cents(5000),
                    currency: 'EUR',
                    idempotencyKey: $key,
                );
                exit(0); // this child won the key
            } catch (IdempotencyConflictException) {
                exit(2); // lost the key race, payload differs → controlled conflict
            } catch (Throwable) {
                exit(1); // UNCONTROLLED — a raw QueryException escaped the backstop
            }
        }

        $pids[] = $pid;
    }

    $winners = $conflicts = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        match ($code) {
            0       => $winners++,
            2       => $conflicts++,
            default => $uncontrolled++,
        };
    }

    // The backstop converted every losing QueryException into a controlled conflict.
    expect($uncontrolled)->toBe(0);
    // Exactly one child won the shared key; everyone else was rejected.
    expect($winners)->toBe(1);
    expect($conflicts)->toBe($childCount - 1);

    // Exactly one posted payment exists for the key.
    DB::purge('testing');
    expect(GroupedPayment::where('idempotency_key', $key)->count())->toBe(1);
})->group('concurrency');
