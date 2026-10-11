<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\InvalidBillingIntervalException;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Services\ArticlePriceService;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
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

describe('setIntervalPrice — the ADR-015 cadence entry point (AID-952)', function () {
    it('writes a price in the interval domain and stores the cadence columns', function () {
        $price = $this->service->setIntervalPrice(
            $this->article,
            new BillingInterval('month', 3),
            FixedDecimal::ofUnscaled(8100, 2),
        );

        expect($price->exists)->toBeTrue()
            ->and($price->billing_unit)->toBe('month')
            ->and($price->billing_quantity)->toBe(3);
    });

    it('writes a NULL-cadence one-time price from a null interval', function () {
        $price = $this->service->setIntervalPrice(
            $this->article,
            null,
            FixedDecimal::ofUnscaled(5000, 2),
        );

        expect($price->billing_unit)->toBeNull()
            ->and($price->billing_quantity)->toBeNull();
    });

    it('refuses an overlap through the interval entry point (same guarantee as setPrice)', function () {
        $this->service->setIntervalPrice($this->article, new BillingInterval('month', 1), FixedDecimal::ofUnscaled(2900, 2));

        expect(fn () => $this->service->setIntervalPrice(
            $this->article,
            new BillingInterval('month', 1),
            FixedDecimal::ofUnscaled(3900, 2),
        ))->toThrow(OverlappingArticlePriceException::class);
    });

    it('treats a NULL cadence as its own cadence for the overlap guarantee', function () {
        $this->service->setIntervalPrice($this->article, null, FixedDecimal::ofUnscaled(5000, 2));

        expect(fn () => $this->service->setIntervalPrice(
            $this->article,
            null,
            FixedDecimal::ofUnscaled(6000, 2),
        ))->toThrow(OverlappingArticlePriceException::class);
    });

    it('does not confuse a NULL-cadence price with a month/1 price', function () {
        $this->service->setIntervalPrice($this->article, null, FixedDecimal::ofUnscaled(5000, 2));

        $monthly = $this->service->setIntervalPrice($this->article, new BillingInterval('month', 1), FixedDecimal::ofUnscaled(2900, 2));

        expect($monthly->exists)->toBeTrue()
            ->and($this->article->prices()->count())->toBe(2);
    });
});

describe('setPrice — preserved legacy signature over the new domain (ADR-015)', function () {
    it('forwards to the interval domain and lands the mapped cadence', function () {
        $price = $this->service->setPrice($this->article, BillingFrequency::QUARTERLY, FixedDecimal::ofUnscaled(8100, 2));

        expect($price->billing_unit)->toBe('month')
            ->and($price->billing_quantity)->toBe(3);
    });

    it('forwards ONE_TIME as a NULL cadence', function () {
        $price = $this->service->setPrice($this->article, BillingFrequency::ONE_TIME, FixedDecimal::ofUnscaled(5000, 2));

        expect($price->billing_unit)->toBeNull()
            ->and($price->billing_quantity)->toBeNull();
    });

    it('refuses WEEKLY and BIWEEKLY — the cadence the new domain cannot represent', function (BillingFrequency $frequency) {
        $this->service->setPrice($this->article, $frequency, FixedDecimal::ofUnscaled(2900, 2));
    })->throws(InvalidBillingIntervalException::class)->with([
        'WEEKLY'   => BillingFrequency::WEEKLY,
        'BIWEEKLY' => BillingFrequency::BIWEEKLY,
    ]);

    it('keeps the overlap guarantee through the forwarded path', function () {
        $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(2900, 2));

        expect(fn () => $this->service->setPrice($this->article, BillingFrequency::MONTHLY, FixedDecimal::ofUnscaled(3900, 2)))
            ->toThrow(OverlappingArticlePriceException::class);
    });
});
