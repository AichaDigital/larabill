<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\OverlappingArticleOverrideException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Services\ArticleOverrideService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Write path of the ArticleOverride non-overlap invariant (AID-974 D5).
 *
 * SCOPE OF WHAT THESE TESTS PROVE: the rejection of an overlapping range, the
 * rollback that follows it, and the range guard. They say NOTHING about the
 * parent-row lock: a sequential test cannot exercise two writers, so the
 * rejection below is delivered by the model's saving hook. The lock is proven
 * by the fork test (AID-974 D7), not here.
 */

beforeEach(function () {
    $this->service  = app(ArticleOverrideService::class);
    $this->article  = Article::factory()->withoutPrices()->create();
    $this->customer = TestUser::factory()->create();
});

it('creates an active override', function () {
    $override = $this->service->setOverride(
        $this->article,
        $this->customer->id,
        FixedDecimal::ofUnscaled(2400, 2),
    );

    expect($override->exists)->toBeTrue()
        ->and($override->custom_price->unscaledValue())->toBe(2400)
        ->and($override->is_active)->toBeTrue();
});

it('refuses an overlapping range and leaves no row behind', function () {
    $this->service->setOverride(
        $this->article,
        $this->customer->id,
        FixedDecimal::ofUnscaled(2400, 2),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-06-30'),
    );

    $before = ArticleOverride::query()->count();

    expect(fn () => $this->service->setOverride(
        $this->article,
        $this->customer->id,
        FixedDecimal::ofUnscaled(1000, 2),
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-12-31'),
    ))->toThrow(OverlappingArticleOverrideException::class);

    expect(ArticleOverride::query()->count())->toBe($before);
});

it('refuses an inverted range', function () {
    expect(fn () => $this->service->setOverride(
        $this->article,
        $this->customer->id,
        FixedDecimal::ofUnscaled(2400, 2),
        Carbon::parse('2026-12-31'),
        Carbon::parse('2026-01-01'),
    ))->toThrow(InvalidArgumentException::class);
});

it('accepts a range whose ends fall on the same day', function () {
    // valid_from/valid_to are `date` columns and every comparison of them in
    // the package is day-grain (AID-974 D2bis). Both ends below land on the
    // same stored date, so this is a valid one-day range even though the
    // instant of valid_from is later than the instant of valid_to.
    $override = $this->service->setOverride(
        $this->article,
        $this->customer->id,
        FixedDecimal::ofUnscaled(2400, 2),
        Carbon::parse('2026-01-01 23:00:00'),
        Carbon::parse('2026-01-01 08:00:00'),
    );

    expect($override->exists)->toBeTrue()
        ->and($override->valid_from->toDateString())->toBe('2026-01-01')
        ->and($override->valid_to->toDateString())->toBe('2026-01-01');
});
