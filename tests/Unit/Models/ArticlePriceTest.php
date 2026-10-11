<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\InvalidBillingIntervalException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ArticlePrice creation', function () {
    it('can create an article price', function () {
        $article = Article::factory()->withoutPrices()->create();
        $price   = ArticlePrice::factory()->for($article)->create([
            'price' => cents(2900),
        ]);

        expect($price->exists)->toBeTrue()
            ->and($price->billing_unit)->toBe('month')
            ->and($price->billing_quantity)->toBe(1)
            ->and($price->price->unscaledValue())->toBe(2900);
    });

    it('stores the cadence as unit and quantity columns', function () {
        $price = ArticlePrice::factory()->quarterly()->create();

        expect($price->billing_unit)->toBe('month')
            ->and($price->billing_quantity)->toBe(3)
            ->and($price->interval()?->label())->toBe('3 months');
    });

    it('stores a one-time price as the NULL cadence', function () {
        $price = ArticlePrice::factory()->oneTime()->create();

        expect($price->billing_unit)->toBeNull()
            ->and($price->billing_quantity)->toBeNull()
            ->and($price->interval())->toBeNull();
    });

    it('casts price to Base100Int (integer)', function () {
        $price = ArticlePrice::factory()->create(['price' => cents(2900)]);

        expect($price->price->unscaledValue())->toBe(2900);
    });

    it('casts dates correctly', function () {
        $price = ArticlePrice::factory()->create([
            'valid_from' => '2024-01-01',
            'valid_to'   => '2024-12-31',
        ]);

        expect($price->valid_from)->toBeInstanceOf(Carbon::class)
            ->and($price->valid_to)->toBeInstanceOf(Carbon::class)
            ->and($price->valid_from->format('Y-m-d'))->toBe('2024-01-01')
            ->and($price->valid_to->format('Y-m-d'))->toBe('2024-12-31');
    });

    it('casts is_active to boolean', function () {
        $price = ArticlePrice::factory()->active()->create();

        expect($price->is_active)->toBeBool()
            ->and($price->is_active)->toBeTrue();
    });
});

describe('ArticlePrice relationships', function () {
    it('belongs to article', function () {
        $article = Article::factory()->withoutPrices()->create();
        $price   = ArticlePrice::factory()->for($article)->create();

        expect($price->article)->toBeInstanceOf(Article::class)
            ->and($price->article->id)->toBe($article->id);
    });
});

describe('ArticlePrice scopes', function () {
    it('scopes active prices', function () {
        ArticlePrice::factory()->active()->count(2)->create();
        ArticlePrice::factory()->inactive()->create();

        $active = ArticlePrice::active()->get();

        expect($active)->toHaveCount(2);
    });

    it('scopes valid at specific date', function () {
        // Price valid from Jan to June
        ArticlePrice::factory()->create([
            'valid_from' => Carbon::parse('2024-01-01'),
            'valid_to'   => Carbon::parse('2024-06-30'),
        ]);

        // Price valid from July to December
        ArticlePrice::factory()->create([
            'valid_from' => Carbon::parse('2024-07-01'),
            'valid_to'   => Carbon::parse('2024-12-31'),
        ]);

        // Price with no validity dates (always valid)
        ArticlePrice::factory()->create([
            'valid_from' => null,
            'valid_to'   => null,
        ]);

        $marchPrices    = ArticlePrice::validAt(Carbon::parse('2024-03-15'))->get();
        $augustPrices   = ArticlePrice::validAt(Carbon::parse('2024-08-15'))->get();

        expect($marchPrices)->toHaveCount(2)   // Jan-June + always valid
            ->and($augustPrices)->toHaveCount(2); // July-Dec + always valid
    });

    it('scopes currently valid', function () {
        // Currently valid
        ArticlePrice::factory()->currentlyValid()->create();

        // Expired
        ArticlePrice::factory()->expired()->create();

        // Future
        ArticlePrice::factory()->future()->create();

        $current = ArticlePrice::currentlyValid()->get();

        expect($current)->toHaveCount(1);
    });

    it('keeps a price valid on its LAST valid day (valid_to is a date, not a datetime — AID-1592)', function () {
        // valid_to is a DATE cast: midnight of the last valid day. Comparing
        // it against now() with a time component dropped the price at the
        // first second of that day — the pre-existing 6.x defect.
        ArticlePrice::factory()->create([
            'valid_from' => now()->subMonth(),
            'valid_to'   => Carbon::today(),
            'is_active'  => true,
        ]);

        // Expired yesterday and future remain excluded — the boundary is
        // day-granular, not just widened.
        ArticlePrice::factory()->expired()->create();
        ArticlePrice::factory()->future()->create();

        $current = ArticlePrice::currentlyValid()->get();

        expect($current)->toHaveCount(1)
            ->and($current->first()->valid_to->toDateString())->toBe(Carbon::today()->toDateString());
    });

    it('keeps the price on Article::activePrices() on its LAST valid day too (AID-1592, second site)', function () {
        Carbon::setTestNow(Carbon::today()->setTime(11, 30)); // mid-morning: now() > midnight of today

        ArticlePrice::factory()->create([
            'valid_from' => now()->subMonth(),
            'valid_to'   => Carbon::today(),
            'is_active'  => true,
        ]);

        $active = Article::factory()->create()->activePrices()->get();

        expect($active)->toHaveCount(1);
    })->after(fn () => Carbon::setTestNow());

    it('scopes by cadence', function () {
        ArticlePrice::factory()->monthly()->count(2)->create();
        ArticlePrice::factory()->yearly()->create();

        $monthly = ArticlePrice::forInterval(new BillingInterval('month', 1))->get();
        $yearly  = ArticlePrice::forInterval(new BillingInterval('year', 1))->get();

        expect($monthly)->toHaveCount(2)
            ->and($yearly)->toHaveCount(1);
    });

    it('scopes the NULL cadence exactly, without matching month/1 rows', function () {
        ArticlePrice::factory()->oneTime()->create();
        ArticlePrice::factory()->monthly()->create();

        $oneTime = ArticlePrice::forInterval(null)->get();

        expect($oneTime)->toHaveCount(1)
            ->and($oneTime->first()->billing_unit)->toBeNull();
    });

    it('scopes by article', function () {
        $article1 = Article::factory()->withoutPrices()->create();
        $article2 = Article::factory()->withoutPrices()->create();

        // Distinct frequencies: two prices of the same article and frequency
        // may never be active at once (AID-601).
        ArticlePrice::factory()->for($article1)->monthly()->create();
        ArticlePrice::factory()->for($article1)->yearly()->create();
        ArticlePrice::factory()->for($article2)->create();

        $prices = ArticlePrice::forArticle($article1->id)->get();

        expect($prices)->toHaveCount(2);
    });
});

describe('ArticlePrice validity', function () {
    it('checks if valid at specific date', function () {
        $price = ArticlePrice::factory()->create([
            'valid_from' => Carbon::parse('2024-01-01'),
            'valid_to'   => Carbon::parse('2024-06-30'),
            'is_active'  => true,
        ]);

        expect($price->isValidAt(Carbon::parse('2024-03-15')))->toBeTrue()
            ->and($price->isValidAt(Carbon::parse('2024-08-15')))->toBeFalse()
            ->and($price->isValidAt(Carbon::parse('2023-12-15')))->toBeFalse();
    });

    it('returns false when inactive regardless of dates', function () {
        $price = ArticlePrice::factory()->create([
            'valid_from' => null,
            'valid_to'   => null,
            'is_active'  => false,
        ]);

        expect($price->isValidAt(Carbon::now()))->toBeFalse();
    });

    it('checks if currently valid', function () {
        $valid   = ArticlePrice::factory()->currentlyValid()->create();
        $expired = ArticlePrice::factory()->expired()->create();
        $future  = ArticlePrice::factory()->future()->create();

        expect($valid->isCurrentlyValid())->toBeTrue()
            ->and($expired->isCurrentlyValid())->toBeFalse()
            ->and($future->isCurrentlyValid())->toBeFalse();
    });

    it('checks if expired', function () {
        $expired    = ArticlePrice::factory()->expired()->create();
        $valid      = ArticlePrice::factory()->currentlyValid()->create();
        $noEndDate  = ArticlePrice::factory()->create(['valid_to' => null]);

        expect($expired->isExpired())->toBeTrue()
            ->and($valid->isExpired())->toBeFalse()
            ->and($noEndDate->isExpired())->toBeFalse();
    });

    it('calculates days until expiration', function () {
        $expiresIn30Days = ArticlePrice::factory()->create([
            'valid_to' => Carbon::now()->addDays(30),
        ]);

        $noExpiration = ArticlePrice::factory()->create([
            'valid_to' => null,
        ]);

        $expired = ArticlePrice::factory()->expired()->create();

        // Allow 29-30 days due to time precision
        expect($expiresIn30Days->daysUntilExpiration())->toBeGreaterThanOrEqual(29)
            ->and($expiresIn30Days->daysUntilExpiration())->toBeLessThanOrEqual(30)
            ->and($noExpiration->daysUntilExpiration())->toBeNull()
            ->and($expired->daysUntilExpiration())->toBeNull();
    });
});

describe('ArticlePrice calculations', function () {
    it('calculates monthly equivalent for month-based frequencies', function () {
        $monthly   = ArticlePrice::factory()->monthly()->create(['price' => cents(2900)]);
        $quarterly = ArticlePrice::factory()->quarterly()->create(['price' => cents(8100)]);
        $yearly    = ArticlePrice::factory()->yearly()->create(['price' => cents(29000)]);

        expect($monthly->getMonthlyEquivalent())->toEqual(2900.0)
            ->and($quarterly->getMonthlyEquivalent())->toEqual(2700.0)  // 8100/3
            ->and($yearly->getMonthlyEquivalent())->toBeGreaterThan(2400.0); // 29000/12 ≈ 2416
    });

    it('returns null for one-time frequency monthly equivalent', function () {
        $oneTime = ArticlePrice::factory()->oneTime()->create(['price' => cents(5000)]);

        expect($oneTime->getMonthlyEquivalent())->toBeNull();
    });

    it('calculates yearly equivalent', function () {
        $monthly = ArticlePrice::factory()->monthly()->create(['price' => cents(2900)]);
        $yearly  = ArticlePrice::factory()->yearly()->create(['price' => cents(29000)]);

        expect($monthly->getYearlyEquivalent())->toBeGreaterThan(35000.0) // 2900 * 365/30 ≈ 35283
            ->and($yearly->getYearlyEquivalent())->toEqual(29000.0);
    });

    it('returns null for one-time frequency yearly equivalent', function () {
        $oneTime = ArticlePrice::factory()->oneTime()->create(['price' => cents(5000)]);

        expect($oneTime->getYearlyEquivalent())->toBeNull();
    });
});

describe('ArticlePrice factory', function () {
    it('creates prices with every legacy-representable cadence', function () {
        $oneTime    = ArticlePrice::factory()->oneTime()->create();
        $monthly    = ArticlePrice::factory()->monthly()->create();
        $bimonthly  = ArticlePrice::factory()->bimonthly()->create();
        $quarterly  = ArticlePrice::factory()->quarterly()->create();
        $semiannual = ArticlePrice::factory()->semiannual()->create();
        $yearly     = ArticlePrice::factory()->yearly()->create();
        $biennial   = ArticlePrice::factory()->biennial()->create();
        $triennial  = ArticlePrice::factory()->triennial()->create();

        expect([$oneTime->billing_unit, $oneTime->billing_quantity])->toBe([null, null])
            ->and([$monthly->billing_unit, $monthly->billing_quantity])->toBe(['month', 1])
            ->and([$bimonthly->billing_unit, $bimonthly->billing_quantity])->toBe(['month', 2])
            ->and([$quarterly->billing_unit, $quarterly->billing_quantity])->toBe(['month', 3])
            ->and([$semiannual->billing_unit, $semiannual->billing_quantity])->toBe(['month', 6])
            ->and([$yearly->billing_unit, $yearly->billing_quantity])->toBe(['year', 1])
            ->and([$biennial->billing_unit, $biennial->billing_quantity])->toBe(['year', 2])
            ->and([$triennial->billing_unit, $triennial->billing_quantity])->toBe(['year', 3]);
    });

    it('rejects the retired WEEKLY and BIWEEKLY cadences at the seeding boundary', function (BillingFrequency $frequency) {
        ArticlePrice::factory()->frequency($frequency)->make();
    })->throws(InvalidBillingIntervalException::class)->with([
        'WEEKLY'   => BillingFrequency::WEEKLY,
        'BIWEEKLY' => BillingFrequency::BIWEEKLY,
    ]);

    it('can set validity periods', function () {
        $from = Carbon::parse('2024-01-01');
        $to   = Carbon::parse('2024-12-31');

        $price = ArticlePrice::factory()->validBetween($from, $to)->create();

        expect($price->valid_from->format('Y-m-d'))->toBe('2024-01-01')
            ->and($price->valid_to->format('Y-m-d'))->toBe('2024-12-31');
    });

    it('can set billing days in advance', function () {
        $price = ArticlePrice::factory()->daysInAdvance(7)->create();

        expect($price->billing_days_in_advance)->toBe(7);
    });
});
