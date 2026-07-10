<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-390 — end-to-end fork proof for correlative invoice numbering.
 *
 * `tests/Unit/Services/InvoiceNumberingServiceTest` covers the sequencing
 * logic deterministically. This is the empirical confirmation under REAL
 * concurrency, exercising the two hardened invariants:
 *
 * 1. FIRST USE of a series: N OS processes call generateNumber() on a series
 *    that does not exist yet. Exactly ONE control row must exist afterwards
 *    (the unique index now bites — the scope sentinel replaced NULL) and the
 *    issued numbers must be a strict correlative 1..N. Losers of the creation
 *    race recover via the UniqueConstraintViolationException catch + locked
 *    re-read; gap-lock deadlocks are retried by DB::transaction(..., 3).
 *
 * 2. STEADY STATE: N processes increment an existing control. lockForUpdate
 *    serializes them; numbers must continue the sequence without gaps or
 *    duplicates.
 *
 * Why it can't use RefreshDatabase: the forked children must read rows the
 * parent committed, on their own connections — a transactional RefreshDatabase
 * would hide them. This file binds to MysqlIntegrationTestCase (tests/Pest.php).
 *
 * Gated: runs only with RUN_CONCURRENCY_IT=1, pcntl available, and the
 * LARABILL_TEST_MYSQL_* env. Excluded from the phpunit testsuites, so the CI
 * `pest` run never loads it. Run on demand:
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
 * Fork $childCount processes, each generating one number for the series, and
 * return [successCount, uncontrolledCount, issuedNumbers[]].
 *
 * @return array{0: int, 1: int, 2: list<string>}
 */
function aid390ForkGenerate(string $prefix, int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid390_'.Str::random(8);
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
                $number = app(InvoiceNumberingService::class)
                    ->generateNumber($prefix, InvoiceSerieType::INVOICE->value);
                file_put_contents($resultDir.'/'.getmypid().'.num', (string) $number);
                exit(0);
            } catch (Throwable) {
                exit(1); // UNCONTROLLED — the hardening failed to converge
            }
        }

        $pids[] = $pid;
    }

    $success = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        $code === 0 ? $success++ : $uncontrolled++;
    }

    $numbers = [];
    foreach (glob($resultDir.'/*.num') ?: [] as $file) {
        $numbers[] = (string) file_get_contents($file);
        unlink($file);
    }
    rmdir($resultDir);

    return [$success, $uncontrolled, $numbers];
}

it('concurrent FIRST USE of a series converges to one control and a strict 1..N sequence', function () {
    $childCount = 6;
    $prefix     = 'FORKNEW';

    expect(InvoiceSeriesControl::where('prefix', $prefix)->count())->toBe(0);

    [$success, $uncontrolled, $numbers] = aid390ForkGenerate($prefix, $childCount);

    // Every child converged — no raw QueryException / deadlock escaped.
    expect($uncontrolled)->toBe(0)
        ->and($success)->toBe($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // The invariant: exactly ONE control for the series, on the global scope.
    $controls = InvoiceSeriesControl::where('prefix', $prefix)->get();
    expect($controls)->toHaveCount(1)
        ->and($controls->first()->user_id)->toBe(InvoiceSeriesControl::GLOBAL_SCOPE)
        ->and($controls->first()->last_number)->toBe($childCount);

    // Strict correlative sequence 1..N — no duplicates, no gaps.
    $issued = collect($numbers)
        ->map(fn (string $n): int => (int) mb_substr($n, -6))
        ->sort()
        ->values()
        ->all();
    expect($issued)->toBe(range(1, $childCount));
})->group('concurrency');

it('concurrent increments on an EXISTING control continue the sequence without duplicates', function () {
    $childCount = 6;
    $prefix     = 'FORKSTEADY';

    // Committed fixture: the control already exists with 3 numbers issued.
    InvoiceSeriesControl::create([
        'prefix'            => $prefix,
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

    [$success, $uncontrolled, $numbers] = aid390ForkGenerate($prefix, $childCount);

    expect($uncontrolled)->toBe(0)
        ->and($success)->toBe($childCount);

    DB::purge('testing');

    $controls = InvoiceSeriesControl::where('prefix', $prefix)->get();
    expect($controls)->toHaveCount(1)
        ->and($controls->first()->last_number)->toBe(3 + $childCount);

    $issued = collect($numbers)
        ->map(fn (string $n): int => (int) mb_substr($n, -6))
        ->sort()
        ->values()
        ->all();
    expect($issued)->toBe(range(4, 3 + $childCount));
})->group('concurrency');
