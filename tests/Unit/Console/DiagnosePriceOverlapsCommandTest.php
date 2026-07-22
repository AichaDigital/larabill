<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * AID-601: the pre-upgrade gate for consumers. Rows are seeded through the
 * query builder because the saving hook rejects overlaps by design.
 */
function aid601InsertRawPrice(int $articleId, ?string $from, ?string $to, int $price = 2900, bool $active = true): void
{
    DB::table('article_prices')->insert([
        'article_id'        => $articleId,
        'billing_frequency' => BillingFrequency::MONTHLY->value,
        'price'             => $price,
        'valid_from'        => $from,
        'valid_to'          => $to,
        'is_active'         => $active,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

it('exits zero on a clean database', function () {
    Article::factory()->monthly(2900)->create();

    $this->artisan('larabill:diagnose-price-overlaps')
        ->expectsOutputToContain('No overlapping article prices found')
        ->assertExitCode(0);
});

it('exits non-zero and reports the overlapping pair', function () {
    $article = Article::factory()->withoutPrices()->create();
    aid601InsertRawPrice($article->id, null, null);
    aid601InsertRawPrice($article->id, '2026-01-01', null);

    $this->artisan('larabill:diagnose-price-overlaps')
        ->expectsOutputToContain('1 overlapping pair')
        ->assertExitCode(1);
});

it('does not report inactive rows', function () {
    $article = Article::factory()->withoutPrices()->create();
    aid601InsertRawPrice($article->id, null, null);
    aid601InsertRawPrice($article->id, null, null, active: false);

    $this->artisan('larabill:diagnose-price-overlaps')->assertExitCode(0);
});

it('does not report disjoint price history', function () {
    $article = Article::factory()->withoutPrices()->create();
    aid601InsertRawPrice($article->id, '2026-01-01', '2026-06-30');
    aid601InsertRawPrice($article->id, '2026-07-01', null);

    $this->artisan('larabill:diagnose-price-overlaps')->assertExitCode(0);
});
