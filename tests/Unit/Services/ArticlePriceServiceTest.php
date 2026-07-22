<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Services\ArticlePriceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ArticlePriceService::class);
    $this->article = Article::factory()->withoutPrices()->create();
});

it('writes a price for a frequency that has none', function () {
    $price = $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(2900, 2),
    );

    expect($price->exists)->toBeTrue()
        ->and($price->price->unscaledValue())->toBe(2900)
        ->and($price->is_active)->toBeTrue();
});

it('refuses to write a price overlapping an active one', function () {
    $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(2900, 2));

    expect(fn () => $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(3900, 2),
    ))->toThrow(OverlappingArticlePriceException::class);
});

it('writes disjoint price history for the same frequency', function () {
    $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(2900, 2),
        validFrom: Carbon::parse('2026-01-01'),
        validTo: Carbon::parse('2026-12-31'),
    );

    $next = $this->service->setPrice(
        $this->article,
        BillingFrequency::MONTHLY,
        FixedDecimal::ofUnscaled(3900, 2),
        validFrom: Carbon::parse('2027-01-01'),
    );

    expect($next->exists)->toBeTrue()
        ->and($this->article->prices()->count())->toBe(2);
});

it('leaves nothing behind when the write is rejected', function () {
    $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(2900, 2));

    try {
        $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(3900, 2));
    } catch (OverlappingArticlePriceException) {
        // expected
    }

    expect($this->article->prices()->count())->toBe(1);
});
