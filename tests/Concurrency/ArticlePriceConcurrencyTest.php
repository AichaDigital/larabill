<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Services\ArticlePriceService;
use Illuminate\Support\Facades\DB;

/**
 * AID-601 — end-to-end fork proof for the article price non-overlap invariant.
 *
 * `tests/Unit/Models/ArticlePriceOverlapTest` covers the condition and the
 * saving hook deterministically. The hook alone is only a safety net: two
 * processes can both read "no conflict" and both write. This is the empirical
 * confirmation that ArticlePriceService closes that window.
 *
 * The lock is taken on the parent articles row, not on the prices: FOR UPDATE
 * only locks rows matching the WHERE clause, and the first price of a frequency
 * matches zero rows — locking "the prices of this (article, frequency)" would
 * not serialise concurrent first inserts at all.
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
 * Fork $childCount processes, each trying to write the SAME (article, MONTHLY)
 * price, and return [wroteCount, rejectedCount, uncontrolledCount].
 *
 * Exit codes carry the outcome: 0 wrote, 2 rejected by the invariant
 * (the controlled, expected loss), 1 anything else — a raw QueryException or
 * an escaped deadlock, which would mean the hardening did not converge.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function aid601ForkSetPrice(int $articleId, int $childCount): array
{
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
                $article = Article::query()->findOrFail($articleId);

                app(ArticlePriceService::class)->setPrice(
                    $article,
                    BillingFrequency::MONTHLY,
                    FixedDecimal::ofUnscaled(2900, 2),
                );

                exit(0);
            } catch (OverlappingArticlePriceException) {
                exit(2); // CONTROLLED — the invariant did its job
            } catch (Throwable) {
                exit(1); // UNCONTROLLED — the hardening failed to converge
            }
        }

        $pids[] = $pid;
    }

    $wrote = $rejected = $uncontrolled = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;

        match ($code) {
            0       => $wrote++,
            2       => $rejected++,
            default => $uncontrolled++,
        };
    }

    return [$wrote, $rejected, $uncontrolled];
}

it('serialises concurrent first writes so exactly one active price survives', function () {
    $childCount = 6;

    $article = Article::factory()->withoutPrices()->create();

    expect(ArticlePrice::where('article_id', $article->id)->count())->toBe(0);

    [$wrote, $rejected, $uncontrolled] = aid601ForkSetPrice($article->id, $childCount);

    // Every child converged: one won, the rest lost by the invariant, and
    // nobody died on a raw database error.
    expect($uncontrolled)->toBe(0)
        ->and($wrote)->toBe(1)
        ->and($rejected)->toBe($childCount - 1);

    // Re-read committed state on a fresh connection.
    DB::purge('testing');

    // The invariant: exactly ONE active price for that (article, frequency).
    expect(
        ArticlePrice::where('article_id', $article->id)
            ->where('billing_frequency', BillingFrequency::MONTHLY)
            ->where('is_active', true)
            ->count()
    )->toBe(1);
})->group('concurrency');
