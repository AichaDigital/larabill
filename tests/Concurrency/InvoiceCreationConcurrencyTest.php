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
 * AID-570 + ADR-016 §6.3/§7.1 (PR-2) — end-to-end fork proof that concurrent
 * createInvoice()+issue() cycles on a VIRGIN series converge: every caller
 * gets an ISSUED document, numbering stays strictly correlative, and nobody
 * dies on a raw DeadlockException.
 *
 * The numbering first-use hardening (AID-390) retries gap-lock deadlocks via
 * DB::transaction(..., 3) at the OUTERMOST boundary. Under the PR-2 lifecycle
 * the virgin-series race MOVED to `issue()` (§6.3: conversion consumes no
 * correlative and neither does a fiscal birth) — each child's `issue()` call
 * is its own outermost transaction, so the AID-570 retry contract holds on
 * the issuance path exactly where the spec says it does.
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
 * Fork $childCount processes each creating ONE unnumbered fiscal draft and
 * issuing it on the same (virgin) series, and return
 * [convergedCount, uncontrolledCount, fiscalNumbers[], childErrors[]].
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid570ForkCreateAndIssue(string $userId, int $childCount): array
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
                $service = app(InvoiceService::class);
                // Phase 1 (unnumbered DRAFT — consumes nothing) then phase 2
                // (issue() as its OWN outermost transaction: the AID-570
                // whole-unit retry contract lives here since PR-2, §6.3).
                $draft  = $service->createInvoice([
                    'billable_user_id' => $userId,
                    'items'            => [],
                ]);
                $issued = $service->issue($draft);
                file_put_contents($resultDir.'/'.getmypid().'.num', (string) $issued->fiscal_number);
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

it('concurrent create+issue cycles on a VIRGIN series all converge with strict correlative numbers', function () {
    $childCount = 6;

    // Committed fixtures on the parent connection. NO invoice is created here:
    // the series control must not exist yet — first use IS the scenario, and
    // under PR-2 that first use happens at issuance.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);
    $userId = $this->seedUser();

    expect(InvoiceSeriesControl::query()->count())->toBe(0);

    [$converged, $uncontrolled, $numbers, $errors] = aid570ForkCreateAndIssue($userId, $childCount);

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

    // N issued documents with a strict correlative 1..N — no duplicates, no gaps.
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
