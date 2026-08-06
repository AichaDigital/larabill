<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\RecurringEmissionHookContract;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AID-836 — end-to-end fork proof for the recurring emission boundary.
 *
 * N OS processes run processRecurringBilling() over the SAME service and
 * period at the same wall-clock instant. The boundary (lockForUpdate re-read
 * + expected-period revalidation, spec D5) must make exactly ONE of them
 * emit: one invoice, one next_billing_date advance, one consumer-hook
 * execution; every loser stands down as skipped. Without the boundary each
 * process emits its own invoice for the same period — duplicate fiscal
 * numbers consumed and the hook (Verifactu registration, OSS accumulation)
 * re-run per duplicate.
 *
 * Calibration (2026-08-06, spec §5 acceptance criterion — measured with the
 * boundary re-read neutralized: stale instance as-is, no lock, no
 * revalidation; WARM series control, see fixture note):
 * - FLOOR (MySQL 8.0): the duplicate reproduces at 2 children, 12/12 red
 *   rounds across counts 2/3/4/6 — every child emits its own invoice for
 *   the same period. Floor: 2.
 * - MariaDB 12.3 does NOT discriminate this failure mode even at 24
 *   children (2/2 green rounds at 12 and 24): its locking delays every
 *   loser past the winner's commit, their idempotency snapshot then sees
 *   the committed invoice and all 24 converge on the same id. Mirror image
 *   of the AID-700 divergence (there MariaDB discriminated, MySQL masked).
 *   The gate's discriminating engine is MySQL; the MariaDB leg of the CI
 *   matrix pins the convergence semantics instead. The b'/retry-safety
 *   halves of the boundary are covered deterministically by the SQLite
 *   suite (RecurringBillingEmissionTest).
 * - CEILING — the boundary serializes losers behind the winner's emission
 *   (sub-second) plus a fast skip each, against innodb_lock_wait_timeout
 *   (50 s on both engines measured): the last waiter sits well under a
 *   second at any sane count. The constraint is wall-clock cost, not the
 *   timeout.
 * - DELIVERED — 6 children: 3× the MySQL floor for contention margin on
 *   slower runners, far under the ceiling. Do NOT raise it "for safety"
 *   without re-measuring; overridable via LARABILL_CONCURRENCY_FORKS.
 *
 * Same harness rules as InvoiceNumberingConcurrencyTest (AID-390/AID-700):
 * no RefreshDatabase (children must read parent-committed rows on their own
 * connections), bound to MysqlIntegrationTestCase, triple-gated, released on
 * an absolute-time barrier. CI runs it against every engine in the
 * db-integration matrix. Run on demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl extension not available');
    }

    // Schema committed via artisan migrate (no RefreshDatabase wraps this).
    $this->bootstrap();
});

/**
 * Number of concurrent processes. See the calibration block in the file
 * docblock: floor 2 (measured), delivered 6, ceiling not timeout-bound.
 */
function aid836ForkCount(): int
{
    return (int) (getenv('LARABILL_CONCURRENCY_FORKS') ?: 6);
}

/**
 * Fork $childCount processes, each running a full recurring billing pass,
 * and return [convergedCount, uncontrolledCount, outcomes[], errors[]].
 * An outcome is the invoice id the child ended up with, or 'skipped' when
 * it stood down (period already processed by another child).
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid836ForkBill(int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid836_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // Absolute-time barrier (AID-700): forking in a loop starts children as
    // they are born; releasing them at one instant is what makes the race real.
    $startAt = microtime(true) + 1.0;

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Never share the parent's PDO across the fork.
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                $results = (new RecurringBillingService(new PricingService))
                    ->processRecurringBilling(now());

                if ($results['failed'] > 0) {
                    file_put_contents(
                        $resultDir.'/'.getmypid().'.err',
                        (string) json_encode($results['errors'])
                    );
                    exit(1); // UNCONTROLLED — the boundary failed instead of standing down
                }

                $outcome = $results['invoices'][0] ?? 'skipped';
                file_put_contents($resultDir.'/'.getmypid().'.out', (string) $outcome);
                exit(0);
            } catch (Throwable $e) {
                // Record the real cause: a mute exit(1) turns every failure
                // into "N children did not converge" (AID-700 lesson).
                file_put_contents(
                    $resultDir.'/'.getmypid().'.err',
                    get_class($e).': '.$e->getMessage()
                );
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $converged = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        $code === 0 ? $converged++ : $uncontrolled++;
    }

    $outcomes = [];
    foreach (glob($resultDir.'/*.out') ?: [] as $file) {
        $outcomes[] = (string) file_get_contents($file);
        unlink($file);
    }

    $errors = [];
    foreach (glob($resultDir.'/*.err') ?: [] as $file) {
        $errors[] = mb_substr((string) file_get_contents($file), 0, 300);
        unlink($file);
    }
    rmdir($resultDir);

    return [$converged, $uncontrolled, $outcomes, $errors];
}

it('concurrent recurring runs over the SAME service emit exactly one invoice, one advance and one hook call', function () {
    $childCount = aid836ForkCount();

    // Committed fixtures on the parent connection.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $userId = $this->seedUser();

    UserTaxProfile::factory()->create([
        'owner_user_id' => $userId,
        'tax_id'        => 'ES12345678Z',
        'valid_from'    => now()->subYear(),
        'valid_until'   => null,
    ]);

    // WARM series control (steady state): on a virgin series MariaDB's
    // first-use locking aborts every loser and their retry converges via the
    // idempotency snapshot even WITHOUT the boundary — the race only
    // discriminates on both engines once the control row exists, which is
    // also the production steady state (same finding as AID-554's warm
    // scenario / AID-700 engine divergence).
    InvoiceSeriesControl::create([
        'prefix'            => 'FAC',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'fiscal_year'       => now()->year,
        'fiscal_year_start' => now()->startOfYear()->toDateString(),
        'fiscal_year_end'   => now()->endOfYear()->toDateString(),
        'last_number'       => 3,
        'start_number'      => 1,
        'reset_annually'    => true,
        'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
        'is_active'         => true,
    ]);

    $article     = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
    $periodStart = now()->addDays(7)->startOfDay();

    // Model::create instead of the factory: ArticleServiceStatusFactory's
    // definition evaluates $userModel::factory(), and the harness MysqlUser
    // model deliberately has none (it is a consumer-shaped fixture).
    $service = ArticleServiceStatus::create([
        'customer_id'         => $userId,
        'article_id'          => $article->id,
        'instance_identifier' => 'fork-race.example.test',
        'started_at'          => now()->subMonth()->toDateString(),
        'billing_frequency'   => BillingFrequency::MONTHLY,
        'status'              => ServiceStatus::ACTIVE,
        'next_billing_date'   => $periodStart,
        'effective_price'     => cents(2900),
    ]);

    // DB-visible hook counter: the binding is inherited by the forked
    // children, and each execution INSERTs inside the emission boundary — so
    // only hook runs that actually committed leave a trace, which is exactly
    // the at-most-once contract under test.
    Schema::create('aid836_hook_calls', function (Blueprint $t): void {
        $t->id();
        $t->char('invoice_id', 36);
    });

    app()->instance(RecurringEmissionHookContract::class, new class implements RecurringEmissionHookContract
    {
        public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
        {
            DB::table('aid836_hook_calls')->insert(['invoice_id' => (string) $invoice->id]);
        }
    });

    [$converged, $uncontrolled, $outcomes, $errors] = aid836ForkBill($childCount);

    // Every child converged: the winner emitted, losers stood down. Comparing
    // the error list against [] surfaces the child failure reasons on red.
    expect($errors)->toBe([])
        ->and($uncontrolled)->toBe(0)
        ->and($converged)->toBe($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // The invariant: ONE invoice for the period, sealed, exactly one
    // correlative consumed on the warm control — no number burnt by losers.
    $invoices = Invoice::query()->get();
    expect($invoices)->toHaveCount(1);

    $invoice = $invoices->first();
    expect($invoice->series_number)->toBe(4)
        ->and($invoice->is_immutable)->toBeTrue()
        ->and($invoice->customer_snapshot)->not->toBeNull();

    expect(InvoiceSeriesControl::where('prefix', 'FAC')->firstOrFail()->last_number)->toBe(4);

    // Every non-skipped child reported THE invoice.
    $reportedIds = array_values(array_unique(array_filter($outcomes, fn (string $o) => $o !== 'skipped')));
    expect($reportedIds)->toBe([(string) $invoice->id]);

    // next_billing_date advanced exactly ONE period — not one per child.
    expect($service->fresh()->next_billing_date->toDateString())
        ->toBe($periodStart->copy()->addMonth()->toDateString());

    // The consumer hook ran exactly once.
    expect(DB::table('aid836_hook_calls')->count())->toBe(1);
})->group('concurrency');
