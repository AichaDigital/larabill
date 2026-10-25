<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * AID-962 (spec §9 ii, §10; §13 rows 37-39) — the race backstop of
 * PaymentService::process(), built without a fork. The `catch` of process()
 * is reached only when the idempotency key is committed by ANOTHER
 * transaction after the key pre-check returned nothing and before the
 * payment INSERT hits the UNIQUE. The test builds exactly that window:
 *
 *   - Trigger: a query listener on the `testing` connection fires on the key
 *     pre-check of process() — the SELECT on `idempotency_key` that runs
 *     right AFTER Ledger::lockInvoices() (row 40). register() runs its own
 *     pre-check first, in its own committed transaction, so on that path the
 *     listener fires on the SECOND key SELECT; receive() has only one.
 *   - Duplicate: inserted through a SECOND connection (`testing_second`) in
 *     autocommit, so it is committed and survives the first transaction's
 *     rollback (row 38). It is a realistic winner: POSTED, same key, payer,
 *     currency and amount, and one ACTIVE full allocation per requested
 *     invoice — but another `paid_at` and `reference`, so ReplayPolicy::Full
 *     must refuse it and ReplayPolicy::Legacy must accept it.
 *   - `SET SESSION foreign_key_checks = 0` on the second connection ONLY:
 *     InnoDB's FK check on the pivot `invoice_id` takes a shared lock on the
 *     invoice rows the first transaction holds FOR UPDATE, and this process
 *     is single-threaded — the INSERT would wait until
 *     innodb_lock_wait_timeout (measured: SQLSTATE HY000 1205 on both
 *     engines, the `cannot reach` test below). The invoices exist, so the
 *     rows are valid; the flag only skips the parent lock. It simulates a
 *     winner that committed "just before", which is the shape of the real
 *     race (spec row 39).
 *
 * The first transaction's read view is fixed by the pre-check (its first
 * consistent read), so its outstanding sums still see zero allocations and
 * it proceeds to the INSERT; the `catch` re-reads AFTER DB::transaction()
 * rolled back, in autocommit, and sees the committed winner.
 *
 * SENSITIVITY (measured 2026-10-25, MySQL 8.4.10 and MariaDB 11.4.13): the
 * backstop comparing with ReplayPolicy::Full regardless of the policy turns
 * the register()/Legacy test red on both engines (forKey instead of the
 * winner); comparing with ReplayPolicy::Legacy regardless turns the Full
 * test red (MySQL 8.4.10). Green on both with the policy passed through.
 *
 * Known limit (not pinned here): inside a CALLER transaction the catch runs
 * in the caller's read view, fixed before the winner committed, so it cannot
 * see the winner and refuses with forKey even on a Legacy match (measured on
 * both engines; a locking re-read of the payment alone does not change it,
 * the allocation map is a plain read too). Fail-safe — nothing is recorded
 * twice — and outside the OUTERMOST-transaction contract of process().
 */
function aid962ArmRace(string $billable, string $key, array $invoices, int $amountCents, int $fireOnKeySelect): object
{
    config(['database.connections.testing_second' => config('database.connections.testing')]);

    $state = (object) ['keySelects' => 0, 'inserted' => 0, 'paymentId' => null];

    DB::connection('testing')->listen(function (QueryExecuted $query) use ($state, $billable, $key, $invoices, $amountCents, $fireOnKeySelect): void {
        if ($query->connectionName !== 'testing'
            || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
            || ! str_contains($query->sql, 'idempotency_key')) {
            return;
        }

        if (++$state->keySelects !== $fireOnKeySelect) {
            return;
        }

        $second = DB::connection('testing_second');
        $second->statement('SET SESSION foreign_key_checks = 0');

        $id     = (string) Str::orderedUuid();
        $paidAt = CarbonImmutable::parse('2026-01-02 03:04:05');
        $second->table('grouped_payments')->insert([
            'id'               => $id,
            'billable_user_id' => $billable,
            'amount'           => $amountCents,
            'currency'         => 'EUR',
            'paid_at'          => $paidAt,
            'reference'        => 'RACED-WINNER',
            'idempotency_key'  => $key,
            'status'           => 0, // POSTED
            'method'           => PaymentMethod::Card->value,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        foreach ($invoices as $invoice) {
            $second->table('grouped_payment_invoice')->insert([
                'grouped_payment_id' => $id,
                'invoice_id'         => $invoice->id,
                'applied_amount'     => $invoice->total_amount->unscaledValue(),
                'previous_status'    => InvoiceStatus::SENT->value,
                'previous_paid_at'   => null,
                'active_invoice_id'  => $invoice->id,
                'status'             => 0, // ACTIVE
                'applied_at'         => $paidAt,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $second->statement('SET SESSION foreign_key_checks = 1');

        $state->inserted++;
        $state->paymentId = $id;
    });

    return $state;
}

/** @return array{0: string, 1: list<Invoice>} */
function aid962RaceInvoices(string $owner, string $billable): array
{
    $invoices = [];
    foreach ([6000, 4000] as $cents) {
        $invoices[] = Invoice::factory()->sent()->create([
            'user_id'      => $owner, 'billable_user_id' => $billable,
            'total_amount' => cents($cents), 'is_immutable' => false, 'paid_at' => null, 'due_date' => null,
        ]);
    }

    return [$billable, $invoices];
}

describe('AID-962 — replay backstop under a committed race', function () {

    afterEach(function () {
        // Runs even when setUp() skipped (no MySQL env): no app, no facade root then.
        if (MysqlIntegrationTestCase::mysqlEnvAvailable()) {
            DB::disconnect('testing_second');
        }
    });

    it('Legacy (register): the catch returns the committed winner', function () {
        $this->bootstrap();
        [$billable, $invoices] = aid962RaceInvoices($this->seedUser(), $this->seedUser());
        $key                   = 'race-legacy-'.Str::random(8);
        // register() runs its own key pre-check first; process()'s is the second.
        $race = aid962ArmRace($billable, $key, $invoices, 10000, fireOnKeySelect: 2);

        $result = app(GroupedPaymentService::class)->register(
            $billable, array_map(fn (Invoice $i): string => (string) $i->id, $invoices),
            now()->subDay()->startOfSecond(), cents(10000), 'EUR', 'TRF-OURS', $key,
        );

        // Three key SELECTs: register()'s pre-check, process()'s (the trigger), and the catch's re-read.
        expect($race->inserted)->toBe(1)
            ->and($race->keySelects)->toBe(3)
            ->and($result->id)->toBe($race->paymentId)
            ->and($result->reference)->toBe('RACED-WINNER')
            ->and(GroupedPayment::where('idempotency_key', $key)->count())->toBe(1)
            ->and(DB::table('grouped_payment_invoice')->count())->toBe(2);
    });

    it('Full (receive with an explicit key and another receivedAt): the catch refuses with forKey', function () {
        $this->bootstrap();
        [$billable, $invoices] = aid962RaceInvoices($this->seedUser(), $this->seedUser());
        $key                   = 'race-full-'.Str::random(8);
        $race                  = aid962ArmRace($billable, $key, $invoices, 10000, fireOnKeySelect: 1);

        expect(fn () => app(PaymentService::class)->receive(
            $billable, cents(10000), 'EUR', now()->subDay()->startOfSecond(), PaymentMethod::Card, 'RACED-WINNER', null,
            array_map(fn (Invoice $i): Allocation => new Allocation((string) $i->id, $i->total_amount), $invoices),
            idempotencyKey: $key,
        ))->toThrow(IdempotencyConflictException::class, IdempotencyConflictException::forKey($key)->getMessage());

        // Two key SELECTs: process()'s pre-check (the trigger) and the catch's re-read.
        expect($race->inserted)->toBe(1)
            ->and($race->keySelects)->toBe(2)
            ->and(GroupedPayment::where('idempotency_key', $key)->count())->toBe(1)
            ->and(GroupedPayment::where('idempotency_key', $key)->value('id'))->toBe($race->paymentId)
            ->and(DB::table('grouped_payment_invoice')->count())->toBe(2);
    });

    it('cannot reach the catch with FK checks on: the pivot INSERT waits on the locked invoice', function () {
        $this->bootstrap();
        [$billable, $invoices] = aid962RaceInvoices($this->seedUser(), $this->seedUser());
        config(['database.connections.testing_second' => config('database.connections.testing')]);

        $code = null;
        DB::transaction(function () use ($invoices, &$code): void {
            Invoice::query()->whereKey($invoices[0]->id)->lockForUpdate()->first();

            $second = DB::connection('testing_second');
            $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $paymentId = (string) Str::orderedUuid();
            $second->table('grouped_payments')->insert([
                'id'         => $paymentId, 'billable_user_id' => $invoices[0]->billable_user_id, 'amount' => 6000, 'currency' => 'EUR',
                'paid_at'    => now(), 'idempotency_key' => 'race-fk-'.Str::random(8), 'status' => 0, 'method' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            try {
                $second->table('grouped_payment_invoice')->insert([
                    'grouped_payment_id' => $paymentId, 'invoice_id' => $invoices[0]->id, 'applied_amount' => 6000,
                    'previous_status'    => InvoiceStatus::SENT->value, 'active_invoice_id' => $invoices[0]->id,
                    'created_at'         => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                $code = $e->errorInfo[1] ?? null;
            }
        });

        expect($code)->toBe(1205); // ER_LOCK_WAIT_TIMEOUT — MySQL 8.4 and MariaDB 11.4
    });

});
