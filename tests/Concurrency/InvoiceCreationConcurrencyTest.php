<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-570 — end-to-end fork proof that concurrent createInvoice() calls on a
 * VIRGIN series converge: every caller gets an invoice, numbering stays
 * strictly correlative, and nobody dies on a raw DeadlockException.
 *
 * The numbering first-use hardening (AID-390) retries gap-lock deadlocks via
 * DB::transaction(..., 3) — but inside createInvoice()'s outer transaction it
 * runs as a SAVEPOINT, and an InnoDB deadlock aborts the WHOLE transaction:
 * the inner retry cannot save it, so the exception escaped raw. The cure is
 * retrying at the OUTERMOST boundary, whose closure is DB-only end to end.
 *
 * Same harness rules as the other fork tests: no RefreshDatabase, bound to
 * MysqlIntegrationTestCase, triple-gated. Run on demand:
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
 * Fork $childCount processes each creating ONE invoice on the same (virgin)
 * series, and return [convergedCount, uncontrolledCount, fiscalNumbers[],
 * childErrors[]].
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid570ForkCreate(string $userId, int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid570_'.Str::random(8);
    mkdir($resultDir, 0755, true);

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
                $invoice = app(InvoiceService::class)->createInvoice([
                    'billable_user_id' => $userId,
                    'items'            => [],
                ]);
                file_put_contents($resultDir.'/'.getmypid().'.num', (string) $invoice->fiscal_number);
                exit(0);
            } catch (Throwable $e) {
                // UNCONTROLLED — surface the reason to the parent for the diff.
                file_put_contents($resultDir.'/'.getmypid().'.err', $e::class.': '.$e->getMessage());
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

    $numbers = [];
    foreach (glob($resultDir.'/*.num') ?: [] as $file) {
        $numbers[] = (string) file_get_contents($file);
        unlink($file);
    }

    $errors = [];
    foreach (glob($resultDir.'/*.err') ?: [] as $file) {
        $errors[] = (string) file_get_contents($file);
        unlink($file);
    }
    rmdir($resultDir);

    return [$converged, $uncontrolled, $numbers, $errors];
}

it('concurrent invoice creations on a VIRGIN series all converge with strict correlative numbers', function () {
    $childCount = 6;

    // Committed fixtures on the parent connection. NO invoice is created here:
    // the series control must not exist yet — first use IS the scenario.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);
    $userId = $this->seedUser();

    expect(InvoiceSeriesControl::query()->count())->toBe(0);

    [$converged, $uncontrolled, $numbers, $errors] = aid570ForkCreate($userId, $childCount);

    // Every caller converged — the losers of the first-use race retried,
    // nobody died on a raw DeadlockException.
    expect($errors)->toBe([])
        ->and($uncontrolled)->toBe(0)
        ->and($converged)->toBe($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // One control row for the series, counter at N.
    $controls = InvoiceSeriesControl::query()->where('serie', InvoiceSerieType::INVOICE->value)->get();
    expect($controls)->toHaveCount(1)
        ->and($controls->first()->last_number)->toBe($childCount);

    // N invoices with a strict correlative 1..N — no duplicates, no gaps.
    $issued = Invoice::query()
        ->where('serie', InvoiceSerieType::INVOICE->value)
        ->pluck('series_number')
        ->map(fn ($n): int => (int) $n)
        ->sort()
        ->values()
        ->all();
    expect($issued)->toBe(range(1, $childCount))
        ->and(count(array_unique($numbers)))->toBe($childCount);
})->group('concurrency');
