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
 *    re-read.
 *
 *    AID-700 hardened both this test and the code it covers. The service no
 *    longer takes a gap lock on the empty range, so there is no deadlock left
 *    to retry; and this test now releases its children on an absolute-time
 *    barrier at a fork count that actually discriminates (see
 *    aid390ForkCount()). Before that it ran 6 unsynchronised children and went
 *    green on both engines WITH the bug present.
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
 *
 * CI runs this against EVERY engine in the db-integration matrix (MySQL and
 * MariaDB), because the two diverge on locking and a single-engine gate can
 * only ever calibrate against one reality (AID-700).
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
 * Number of concurrent processes. AID-700 raised it from 6: at 6 the first-use
 * race went green on BOTH engines even with the gap-lock bug present, so the
 * test proved nothing. Measured on MariaDB 11.4, the bug starts showing at 20
 * and is near-total at 24 (69 of 72 processes died across three rounds), so 24
 * is the smallest count with real discriminating power. Overridable for local
 * stress runs.
 */
function aid390ForkCount(): int
{
    return (int) (getenv('LARABILL_CONCURRENCY_FORKS') ?: 24);
}

/**
 * Fork $childCount processes, each generating one number for the series, and
 * return [successCount, uncontrolledCount, issuedNumbers[], failures[]].
 *
 * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
 */
function aid390ForkGenerate(string $prefix, int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid390_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // AID-700: absolute-time barrier. Forking in a loop starts every child the
    // instant it is born, so on a fast host child 1 can commit before child N
    // even exists and the race is never actually run. Releasing all children at
    // one wall-clock instant is what makes this a concurrency test.
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
                $number = app(InvoiceNumberingService::class)
                    ->generateNumber($prefix, InvoiceSerieType::INVOICE->value);
                file_put_contents($resultDir.'/'.getmypid().'.num', (string) $number);
                exit(0);
            } catch (Throwable $e) {
                // Record the real cause: a mute exit(1) turns every failure into
                // "N children did not converge", which is what kept the AID-700
                // deadlock unexplained for weeks in the consumer.
                file_put_contents(
                    $resultDir.'/'.getmypid().'.err',
                    get_class($e).': '.$e->getMessage()
                );
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

    $failures = [];
    foreach (glob($resultDir.'/*.err') ?: [] as $file) {
        $failures[] = mb_substr((string) file_get_contents($file), 0, 300);
        unlink($file);
    }
    rmdir($resultDir);

    return [$success, $uncontrolled, $numbers, $failures];
}

it('concurrent FIRST USE of a series converges to one control and a strict 1..N sequence', function () {
    $childCount = aid390ForkCount();
    $prefix     = 'FORKNEW';

    expect(InvoiceSeriesControl::where('prefix', $prefix)->count())->toBe(0);

    [$success, $uncontrolled, $numbers, $failures] = aid390ForkGenerate($prefix, $childCount);

    // Every child converged — no raw QueryException / deadlock escaped.
    if ($uncontrolled > 0) {
        test()->fail(sprintf(
            "%d of %d processes failed to converge on first use.\nDistinct causes:\n%s",
            $uncontrolled,
            $childCount,
            implode("\n", array_unique($failures))
        ));
    }

    expect($success)->toBe($childCount);

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
    $childCount = aid390ForkCount();
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

    [$success, $uncontrolled, $numbers, $failures] = aid390ForkGenerate($prefix, $childCount);

    if ($uncontrolled > 0) {
        test()->fail(sprintf(
            "%d of %d processes failed to converge on the existing control.\nDistinct causes:\n%s",
            $uncontrolled,
            $childCount,
            implode("\n", array_unique($failures))
        ));
    }

    expect($success)->toBe($childCount);

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
