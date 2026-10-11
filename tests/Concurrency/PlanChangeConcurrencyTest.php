<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PlanChangeService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-953 slice 3 (ADR-015 §3.5 / §6, gate round 6 P1) — end-to-end fork
 * proof for the plan-change atomic/idempotent boundary.
 *
 * The serial eligibility tests (tests/Unit/Services/PlanChangeServiceTest)
 * do NOT prove the boundary: what must hold is that under REAL concurrency
 * two OS processes racing `changePlan()` on the SAME active agreement
 * produce exactly ONE successor contract and exactly ONE liquidation
 * artifact. The loser revalidates INSIDE the FOR UPDATE lock (the same
 * pattern as the emission boundary, RecurringBillingService::emitWithinBoundary),
 * sees the row already `closed`, and aborts WITHOUT issuing anything — an
 * idempotent no-op, never a second contract or a second abono.
 *
 * Same harness rules as the other fork tests (AID-390/AID-700/AID-836/
 * guarantee): no RefreshDatabase (children must read parent-committed rows
 * on their own connections), bound to MysqlIntegrationTestCase
 * (tests/Pest.php), released on an absolute-time barrier (STD-004). Run on
 * demand:
 *   RUN_CONCURRENCY_IT=1 LARABILL_TEST_MYSQL_*=… vendor/bin/pest tests/Concurrency
 */
beforeEach(function () {
    if (getenv('RUN_CONCURRENCY_IT') !== '1') {
        test()->markTestSkipped('concurrency IT disabled (set RUN_CONCURRENCY_IT=1)');
    }

    // Anti-skip guard (AID-264/AID-258): the gate is ARMED — from here, every
    // precondition that used to skip must FAIL loud, because a gated run that
    // reports skips proved nothing.
    if (! function_exists('pcntl_fork')) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the pcntl extension is unavailable — the concurrency gate would silently prove nothing.');
    }

    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->fail('RUN_CONCURRENCY_IT=1 armed but the LARABILL_TEST_MYSQL_* env is not configured — the concurrency gate would silently prove nothing.');
    }

    // Schema committed via artisan migrate (no RefreshDatabase wraps this).
    $this->bootstrap();
});

/**
 * The full fixture: active agreement on a tax-free old plan, the NEW plan
 * product, and the emitted invoice whose unconsumed proportion the change
 * liquidates. Returns [agreement, newPlan, sourceInvoice].
 *
 * Built on the raw models (no ArticleServiceStatusFactory: its definition
 * resolves the configured user model's factory, and this harness's
 * consumer-shaped MysqlUser has none — the customer id is inserted directly).
 *
 * @return array{0: ArticleServiceStatus, 1: Article, 2: Invoice}
 */
function planChangeForkFixture(): array
{
    $billable = test()->seedUser();

    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    // Tax-free articles: the abono line carries zero tax, so the race stays
    // focused on the boundary, not the tax math.
    $oldPlan  = Article::factory()->service()->monthly(10000)->create();
    $newPlan  = Article::factory()->service()->monthly(5000)->create();

    $agreement = ArticleServiceStatus::query()->create([
        'customer_id'      => $billable,
        'article_id'       => $oldPlan->id,
        'started_at'       => now()->toDateString(),
        'status'           => AgreementStatus::ACTIVE,
        'modality'         => 'recurring',
        'renewal'          => 'auto',
        'billing_unit'     => 'month',
        'billing_quantity' => 1,
        'effective_price'  => FixedDecimal::ofUnscaled(10000, 2),
        'metadata'         => [],
    ]);

    $source = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $billable,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            [
                'article_id'  => $oldPlan->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Fork fixture service period',
            ],
        ],
    ], ['make_immutable' => true]);

    return [$agreement, $newPlan, $source];
}

it('exactly one successor and one liquidation survive two racers on the same agreement', function () {
    [$agreement, $newPlan, $source] = planChangeForkFixture();

    $childCount = 2; // The boundary is pairwise: winner issues, loser no-ops.
    $resultDir  = sys_get_temp_dir().'/larabill_planchange_'.Str::random(8);
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
            // Never share the parent's PDO across the fork.
            DB::purge('testing');
            time_sleep_until($startAt);

            try {
                $successor = app(PlanChangeService::class)->changePlan(
                    $agreement,
                    $newPlan,
                    new BillingInterval('month', 1),
                    settleInvoice: $source,
                    settleAmount: 5000,
                );

                file_put_contents(
                    $resultDir.'/'.getmypid().($successor === null ? '.noop' : '.successor'),
                    (string) ($successor?->id ?? ''),
                );
                exit(0); // winner (issued) or loser (idempotent no-op) — both controlled
            } catch (Throwable $e) {
                // Record the real cause: a mute exit(1) turns every failure
                // into "children did not converge", which is undiagnosable.
                file_put_contents(
                    $resultDir.'/'.getmypid().'.err',
                    get_class($e).': '.$e->getMessage()
                );
                exit(1); // UNCONTROLLED — the invariant failed to converge
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

    if ($uncontrolled > 0) {
        $errors = glob($resultDir.'/*.err') ?: [];
        $detail = implode("\n", array_map(fn (string $f): string => basename($f).': '.(string) file_get_contents($f), $errors));
        test()->fail("$uncontrolled of $childCount children died uncontrolled:\n$detail");
    }
    expect($converged)->toBe($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // THE INVARIANT: exactly ONE successor contract for the predecessor.
    $successors = ArticleServiceStatus::query()->whereKeyNot($agreement->id)->get();
    expect($successors)->toHaveCount(1);
    $successor = $successors->first();
    expect($successor->article_id)->toBe($newPlan->id)
        ->and($successor->customer_id)->toBe($agreement->customer_id);

    // …and exactly ONE liquidation artifact: an ordinary F1 abono
    // (rectifies_invoice_id NULL), never a second one and never an R1.
    $abonos = Invoice::query()->whereKeyNot($source->id)->get();
    expect($abonos)->toHaveCount(1)
        ->and($abonos->first()->serie)->toBe(InvoiceSerieType::INVOICE)
        ->and($abonos->first()->rectifies_invoice_id)->toBeNull();

    // The predecessor closed exactly once.
    expect($agreement->fresh()->status)->toBe(AgreementStatus::CLOSED);

    // The winner reported the successor; the loser aborted as a no-op
    // (inside the lock, seeing the row already closed). At least one of
    // each outcome must exist for the race to have actually happened.
    $successorIds = array_values(array_filter(array_map(
        fn (string $f): string => trim((string) file_get_contents($f)),
        glob($resultDir.'/*.successor') ?: []
    )));
    expect($successorIds)->not->toBeEmpty()
        ->and($successorIds)->each->toBe((string) $successor->id)
        ->and(glob($resultDir.'/*.noop') ?: [])->not->toBeEmpty();
})->group('concurrency');
