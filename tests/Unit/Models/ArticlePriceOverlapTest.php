<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
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

it('rejects creating a price that overlaps an active one', function () {
    aid601SeedPrice($this->article, null, null);

    expect(fn () => aid601SeedPrice($this->article, null, null))
        ->toThrow(OverlappingArticlePriceException::class);
});

it('allows saving a row without changing its own range', function () {
    $price = aid601SeedPrice($this->article, null, null);

    $price->price = FixedDecimal::ofUnscaled(3900, 2);
    $price->save();

    expect($price->fresh()->price->unscaledValue())->toBe(3900);
});

it('rejects reactivating a row that overlaps an active one', function () {
    $dormant = aid601SeedPrice($this->article, null, null, active: false);
    aid601SeedPrice($this->article, null, null);

    $dormant->is_active = true;

    expect(fn () => $dormant->save())->toThrow(OverlappingArticlePriceException::class);
});

it('allows saving an inactive row that would overlap', function () {
    aid601SeedPrice($this->article, null, null);

    $dormant = aid601SeedPrice($this->article, null, null, active: false);

    expect($dormant->exists)->toBeTrue();
});

it('allows disjoint price history', function () {
    aid601SeedPrice($this->article, '2026-01-01', '2026-06-30');
    aid601SeedPrice($this->article, '2026-07-01', null);

    expect(ArticlePrice::query()->where('article_id', $this->article->id)->count())->toBe(2);
});

it('names the conflicting rows and the diagnose command in the message', function () {
    $existing = aid601SeedPrice($this->article, '2026-01-01', null);

    expect(fn () => aid601SeedPrice($this->article, '2026-06-01', null))
        ->toThrow(
            OverlappingArticlePriceException::class,
            "conflicts with active price(s) [#{$existing->id} 2026-01-01→open]",
        );
});

it('survives the consumer delete-then-create pattern', function () {
    // Regression for the reference consumer (Castris ArticleEdit::save):
    // it deletes the ACTIVE rows and re-creates one per frequency.
    aid601SeedPrice($this->article, null, null);

    $this->article->prices()->where('is_active', true)->delete();
    $recreated = aid601SeedPrice($this->article, null, null);

    expect($recreated->exists)->toBeTrue();
});
