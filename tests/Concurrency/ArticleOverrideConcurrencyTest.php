<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\OverlappingArticleOverrideException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Services\ArticleOverrideService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-974 — fork proof for the customer override non-overlap invariant.
 *
 * This is the ONLY test that judges the parent-row lock in
 * ArticleOverrideService. The sequential tests of the write path
 * (tests/Unit/Services/ArticleOverrideServiceTest) get their rejection from
 * the model's saving hook, and they run on SQLite, where Laravel compiles
 * `FOR UPDATE` to an EMPTY STRING — the lock is not even emitted there.
 *
 * N processes call setOverride() over the SAME (customer, article) pair at the
 * same wall-clock instant, all with valid_from = null. Exactly ONE must
 * persist; every loser must be rejected with OverlappingArticleOverrideException.
 * Without the lock each process validates before any other writes, and several
 * rows land — the hook cannot see a row that is not committed yet.
 *
 * WHY valid_from = null: with distinct dates the unique index
 * (customer_id, article_id, valid_from) would serialise the writers by itself
 * and mask the defect. NULLs do not deduplicate in a MySQL/MariaDB unique
 * index, so the lock is the only thing that can decide the race.
 *
 * WHY THE FIXTURE STARTS WITH ZERO OVERRIDES: seeding a previous override
 * would make the hook reject all N children WITH OR WITHOUT the lock, and the
 * test would stay green against the neutralised service — perfect decoration
 * over the one gate that judges it. What is under test is N concurrent FIRST
 * inserts of which exactly one must win.
 *
 * EXIT-CODE SEMANTICS DIFFER FROM THE SIBLING TEST: here a rejected loser is
 * the CORRECT outcome, not a failure, so both 'written' and 'rejected' exit 0
 * and only an unexpected Throwable exits 1. Mapping the losers to
 * "uncontrolled" would turn every red into "N children did not converge",
 * which is the undiagnosable failure STD-004 exists to forbid.
 *
 * Calibration (2026-08-20, both engines measured locally — the sensitivity
 * runs had the `lockForUpdate()` line of ArticleOverrideService REMOVED, and
 * the count below is the number of rows that reached the table):
 * - FLOOR — 2 children, on BOTH engines. MySQL 8.0.46 (driver `mysql`): 5/5
 *   red rounds at N=2 (2 rows each), 5/5 at N=3 (3 rows), 5/5 at N=6 (6
 *   rows). MariaDB 11.4.12 with driver `mysql`: 3/3 red at N=2 (2 rows), 3/3
 *   at N=3 (3 rows), 3/3 at N=6 (6 rows); with driver `mariadb`: 3/3 red at
 *   N=2 (2 rows), 3/3 at N=6 (6, 6 and 4 rows — the only round where the
 *   engine happened to serialise two writers by itself, and still 4 rows
 *   where the invariant allows one). Neither engine masks this failure mode,
 *   so — unlike AID-700, where MariaDB discriminated and MySQL masked, and
 *   AID-836, where it was the other way round — there is no engine
 *   divergence to record here.
 * - CEILING — not timeout-bound. innodb_lock_wait_timeout is 50 s on both
 *   engines, and a loser only waits for the winner's single INSERT plus each
 *   preceding loser's fast rejection. Measured WITH the lock, the whole test
 *   is flat in N: MySQL 1.98 s at N=2, 2.00 s at 6, 2.23 s at 24, 2.11 s at
 *   48 (of which 1 s is the barrier and ~0.9 s the harness bootstrap);
 *   MariaDB 10.34 s at N=2, 10.65 s at 6, 10.18 s at 24, whose ~9 s constant
 *   is that engine's cost for the 32 migrations of bootstrap(), not the fork
 *   phase. The constraint is wall-clock cost, and it barely moves.
 * - DELIVERED — 6 children: 3x the measured floor for contention margin on
 *   slower runners, far below any ceiling (48 still passes on MySQL). Do NOT
 *   raise it "for safety" without re-measuring; overridable via
 *   LARABILL_CONCURRENCY_FORKS.
 *
 * Same harness rules as the other fork tests (AID-390/AID-700/AID-836): no
 * RefreshDatabase (children must read parent-committed rows on their own
 * connections), bound to MysqlIntegrationTestCase by tests/Pest.php, gated on
 * RUN_CONCURRENCY_IT + pcntl + the MySQL env, released on an absolute-time
 * barrier. CI runs it against every engine of the db-integration matrix.
 * Run on demand:
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
 * docblock: floor 2 (measured on both engines), delivered 6, ceiling not
 * timeout-bound.
 */
function aid974ForkCount(): int
{
    return (int) (getenv('LARABILL_CONCURRENCY_FORKS') ?: 6);
}

/**
 * Fork $childCount processes, each calling setOverride() for the SAME
 * (customer, article) pair with an open range, and return
 * [uncontrolledCount, outcomes[], errors[]].
 *
 * An outcome is 'written' (this child persisted the override) or 'rejected'
 * (the invariant turned it away) — both are controlled exits. Anything else
 * is an uncontrolled exit whose real exception is recorded for the parent.
 *
 * @return array{0: int, 1: list<string>, 2: list<string>}
 */
function aid974ForkSetOverride(int $articleId, string $customerId, int $childCount): array
{
    $resultDir = sys_get_temp_dir().'/larabill_aid974_'.Str::random(8);
    mkdir($resultDir, 0755, true);

    // Absolute-time barrier (STD-004): forking in a loop starts children as
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

            try {
                // Read the article BEFORE the barrier: it opens this child's
                // own connection, so the barrier releases into the contended
                // write instead of into a TCP handshake.
                $article = Article::query()->findOrFail($articleId);

                time_sleep_until($startAt);

                app(ArticleOverrideService::class)->setOverride(
                    $article,
                    $customerId,
                    FixedDecimal::ofUnscaled(1900, 2),
                );

                file_put_contents($resultDir.'/'.getmypid().'.out', 'written');
                exit(0);
            } catch (OverlappingArticleOverrideException) {
                // CONTROLLED: this is what a loser is supposed to get.
                file_put_contents($resultDir.'/'.getmypid().'.out', 'rejected');
                exit(0);
            } catch (Throwable $e) {
                // Record the real cause: a mute exit(1) turns every failure
                // into "N children did not converge" (STD-004 condition 2).
                file_put_contents(
                    $resultDir.'/'.getmypid().'.err',
                    get_class($e).': '.$e->getMessage()
                );
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;

        if ($code !== 0) {
            $uncontrolled++;
        }
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

    return [$uncontrolled, $outcomes, $errors];
}

it('serialises concurrent first overrides so exactly one survives', function () {
    $childCount = aid974ForkCount();

    // Committed fixtures on the parent connection. customer_id is a plain UUID
    // column (MigrationHelper::agnosticIdColumn), but seeding a real user keeps
    // the fixture consumer-shaped.
    $customerId = $this->seedUser();
    $article    = Article::factory()->withoutPrices()->create();

    // The pair starts with NO override: what is raced is N FIRST inserts.
    // A previous row would have the hook reject everyone with or without the
    // lock, and this gate would be decoration.
    expect(ArticleOverride::query()->count())->toBe(0);

    [$uncontrolled, $outcomes, $errors] = aid974ForkSetOverride($article->id, $customerId, $childCount);

    // Comparing the error list against [] surfaces the child failure reasons
    // on red instead of a bare count.
    expect($errors)->toBe([])
        ->and($uncontrolled)->toBe(0)
        ->and($outcomes)->toHaveCount($childCount);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // THE INVARIANT: one row for the pair, whatever the children reported.
    expect(
        ArticleOverride::query()
            ->where('customer_id', $customerId)
            ->where('article_id', $article->id)
            ->count()
    )->toBe(1);

    // And the children agree on who won: one writer, everybody else rejected.
    // Both halves were shown sensitive separately (the chained expect above
    // short-circuits, so the sensitivity run was repeated with this block
    // first): with the lock removed each is red on its own.
    $written  = count(array_filter($outcomes, fn (string $o): bool => $o === 'written'));
    $rejected = count(array_filter($outcomes, fn (string $o): bool => $o === 'rejected'));

    expect($written)->toBe(1)
        ->and($rejected)->toBe($childCount - 1);
})->group('concurrency');
