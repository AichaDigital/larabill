<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-601: the overlap condition is the single source of truth shared by the
 * saving hook and the write service. NULL is an open end in both directions.
 */
beforeEach(function () {
    $this->article = Article::factory()->withoutPrices()->create();
});

/** Seeds one active MONTHLY price with the given range. */
function aid601SeedPrice(Article $article, ?string $from, ?string $to, bool $active = true): ArticlePrice
{
    return ArticlePrice::factory()->for($article)->create([
        'billing_frequency' => BillingFrequency::MONTHLY,
        'price'             => FixedDecimal::ofUnscaled(2900, 2),
        'valid_from'        => $from,
        'valid_to'          => $to,
        'is_active'         => $active,
    ]);
}

/** Runs the scope for a MONTHLY candidate with the given range. */
function aid601Overlaps(Article $article, ?string $from, ?string $to): int
{
    return ArticlePrice::query()
        ->overlapping(
            $article->id,
            BillingFrequency::MONTHLY,
            $from === null ? null : Carbon::parse($from),
            $to   === null ? null : Carbon::parse($to),
        )
        ->count();
}

it('treats two fully open ranges as overlapping', function () {
    aid601SeedPrice($this->article, null, null);

    expect(aid601Overlaps($this->article, null, null))->toBe(1);
});

it('treats an open-ended existing range as overlapping any later candidate', function () {
    aid601SeedPrice($this->article, '2026-01-01', null);

    expect(aid601Overlaps($this->article, '2027-06-01', null))->toBe(1);
});

it('treats an open-start candidate as overlapping a dated existing range', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-12-31');

    expect(aid601Overlaps($this->article, null, '2026-03-01'))->toBe(1);
});

it('detects partially overlapping closed ranges', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');

    expect(aid601Overlaps($this->article, '2026-06-01', '2026-12-31'))->toBe(1);
});

it('treats ranges touching on a single day as overlapping', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');

    expect(aid601Overlaps($this->article, '2026-06-30', '2026-12-31'))->toBe(1);
});

it('allows disjoint closed ranges (legitimate price history)', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');

    expect(aid601Overlaps($this->article, '2026-07-01', '2026-12-31'))->toBe(0);
});

it('allows a candidate that ends before an open-start existing range', function () {
    aid601SeedPrice($this->article, '2026-07-01', null);

    expect(aid601Overlaps($this->article, '2026-01-01', '2026-06-30'))->toBe(0);
});

it('ignores inactive rows entirely', function () {
    aid601SeedPrice($this->article, null, null, active: false);

    expect(aid601Overlaps($this->article, null, null))->toBe(0);
});

it('ignores rows of another billing frequency', function () {
    ArticlePrice::factory()->for($this->article)->create([
        'billing_frequency' => BillingFrequency::YEARLY,
        'price'             => FixedDecimal::ofUnscaled(29000, 2),
        'valid_from'        => null,
        'valid_to'          => null,
    ]);

    expect(aid601Overlaps($this->article, null, null))->toBe(0);
});

it('ignores rows of another article', function () {
    aid601SeedPrice(Article::factory()->withoutPrices()->create(), null, null);

    expect(aid601Overlaps($this->article, null, null))->toBe(0);
});
