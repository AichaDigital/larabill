<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * AID-601: legacy databases may already hold overlapping prices. Reading must
 * be predictable there — most recent wins — instead of returning whatever the
 * index walk yields. Rows are seeded through the query builder on purpose:
 * the saving hook would (correctly) reject them.
 */
it('returns the most recent price when legacy duplicates exist', function () {
    $article = Article::factory()->withoutPrices()->create();

    // Every row must be CURRENTLY valid: activePrices() already excludes future
    // starts, so a tomorrow-dated row would be filtered out and would prove
    // nothing about ordering. Dates are relative so the fixture cannot rot.
    $rows = [
        ['valid_from' => null,                            'price' => 1000],
        ['valid_from' => now()->subYear()->toDateString(), 'price' => 2000],
        ['valid_from' => now()->subMonth()->toDateString(), 'price' => 3000],
    ];

    foreach ($rows as $row) {
        DB::table('article_prices')->insert([
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'price'             => $row['price'],
            'valid_from'        => $row['valid_from'],
            'valid_to'          => null,
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // getPriceFor() is typed ?float, and PHP widens the int from
    // unscaledValue(), so assert against a float — as ArticleTest does.
    expect($article->getPriceFor(BillingFrequency::MONTHLY))->toBe(3000.0);
});

it('is a no-op on valid single-price data', function () {
    $article = Article::factory()->monthly(2900)->create();

    expect($article->getPriceFor(BillingFrequency::MONTHLY))->toBe(2900.0);
});
