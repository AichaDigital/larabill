<?php

declare(strict_types=1);

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use Illuminate\Support\Carbon;

/**
 * AID-974 D2bis — the override validity window is day-grain and inclusive.
 *
 * `article_overrides.valid_from` / `valid_to` are `date` columns. Comparing
 * them against a full instant (a `now()` carrying a time of day) makes an
 * override expire DURING its own last day, because the engine widens the
 * stored date to midnight before comparing. The spec states plainly that one
 * engine proves nothing about the other, and this is precisely the class of
 * bug SQLite cannot judge: it has no date type, stores whatever string it is
 * given and compares it lexicographically.
 *
 * The unit coverage of the fix (tests/Unit/Models/ArticleOverrideValidityTest)
 * is SQLite-bound by tests/Pest.php, so without this file the correction is
 * verified against a real engine exactly once, by hand, and nothing stops it
 * from regressing. Here it is pinned against MySQL/MariaDB, on the query path
 * (`scopeValidAt`, used by `Article::resolveOverrideFor`) and on the PHP path
 * (`ArticleOverride::isValidAt`), which must agree.
 *
 * Fixture note: the reference instant carries an explicit time of day
 * (14:32:11). `now()` almost always does too, but "almost always" is not a
 * gate — at exactly midnight the pre-fix comparison happened to be correct,
 * and a test that only fails after 00:00:01 is a flake waiting for a cron.
 */
describe('AID-974 — day-grain override validity (MySQL)', function () {

    it('keeps an override in force throughout its last day', function () {
        $this->bootstrap();

        $today     = Carbon::today();
        $afternoon = $today->copy()->setTime(14, 32, 11);

        $customerId = $this->seedUser();
        $article    = Article::factory()->withoutPrices()->create();

        $override = ArticleOverride::query()->create([
            'article_id'   => $article->getKey(),
            'customer_id'  => $customerId,
            'custom_price' => cents(2400),
            'valid_from'   => $today->copy()->subMonth(),
            'valid_to'     => $today,          // expires TODAY: still in force all day
            'reason'       => 'AID-974 day boundary',
            'is_active'    => true,
        ]);

        // Query path first, on purpose: it is the half only a real engine can
        // judge, and a chained expect() short-circuits on the first failure.
        expect($article->fresh()->resolveOverrideFor($customerId, $afternoon)?->getKey())
            ->toBe($override->getKey());

        // PHP path must reach the same verdict — the two implement one rule.
        expect($override->isValidAt($afternoon))->toBeTrue();
    });

    it('drops the override on the day after its last one', function () {
        $this->bootstrap();

        $today     = Carbon::today();
        $afternoon = $today->copy()->setTime(14, 32, 11);

        $customerId = $this->seedUser();
        $article    = Article::factory()->withoutPrices()->create();

        $override = ArticleOverride::query()->create([
            'article_id'   => $article->getKey(),
            'customer_id'  => $customerId,
            'custom_price' => cents(2400),
            'valid_from'   => $today->copy()->subMonth(),
            'valid_to'     => $today->copy()->subDay(),
            'reason'       => 'AID-974 day boundary',
            'is_active'    => true,
        ]);

        // Guards the other side of the correction: inclusive must not mean
        // "never expires". Note this one is NOT sensitive to the pre-fix
        // instant comparison — that bug expired overrides early, so a row
        // already past its window stayed excluded either way.
        expect($article->fresh()->resolveOverrideFor($customerId, $afternoon))->toBeNull();

        expect($override->isValidAt($afternoon))->toBeFalse();
    });

});
