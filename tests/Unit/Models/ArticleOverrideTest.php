<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('can create an article override', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->create();

    $override = ArticleOverride::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'custom_price' => cents(2400), // €24.00 in base100
    ]);

    expect($override->customer_id)->toBe($customer->id)
        ->and($override->article_id)->toBe($article->id)
        ->and($override->custom_price->unscaledValue())->toBe(2400)
        ->and($override->exists)->toBeTrue();
});

it('creates customer id with the configured agnostic type', function () {
    expect(Schema::getColumnType('article_overrides', 'customer_id'))->not->toBe('integer');
});

it('can create and scope overrides for UUID customer IDs', function () {
    $customerId      = (string) Str::uuid();
    $otherCustomerId = (string) Str::uuid();
    $article         = Article::factory()->create();

    $override = ArticleOverride::factory()
        ->forCustomer($customerId)
        ->forArticle($article)
        ->create();

    ArticleOverride::factory()
        ->forCustomer($otherCustomerId)
        ->forArticle($article)
        ->create();

    $overrides = ArticleOverride::forCustomer($customerId)->get();

    expect($override->customer_id)->toBe($customerId)
        ->and($overrides)->toHaveCount(1)
        ->and($overrides->first()->id)->toBe($override->id);
});

it('casts custom_price to integer (Base100Int)', function () {
    $override = ArticleOverride::factory()->create(['custom_price' => cents(2400)]);

    expect($override->custom_price->unscaledValue())->toBe(2400);
});

it('casts dates correctly', function () {
    $override = ArticleOverride::factory()->create([
        'valid_from' => '2024-01-01',
        'valid_to'   => '2024-12-31',
    ]);

    expect($override->valid_from)->toBeInstanceOf(Carbon::class)
        ->and($override->valid_to)->toBeInstanceOf(Carbon::class)
        ->and($override->valid_from->format('Y-m-d'))->toBe('2024-01-01')
        ->and($override->valid_to->format('Y-m-d'))->toBe('2024-12-31');
});

it('casts is_active to boolean', function () {
    $override = ArticleOverride::factory()->active()->create();

    expect($override->is_active)->toBeTrue();
});

it('has article relationship', function () {
    $article  = Article::factory()->create();
    $override = ArticleOverride::factory()->create(['article_id' => $article->id]);

    expect($override->article)->toBeInstanceOf(Article::class)
        ->and($override->article->id)->toBe($article->id);
});

it('has customer relationship', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();

    $override = ArticleOverride::factory()->create(['customer_id' => $customer->id]);

    expect($override->customer)->not->toBeNull()
        ->and($override->customer->id)->toBe($customer->id);
});

it('scopes active overrides', function () {
    ArticleOverride::factory()->active()->count(2)->create();
    ArticleOverride::factory()->inactive()->count(3)->create();

    $active = ArticleOverride::active()->get();

    expect($active)->toHaveCount(2);
});

it('scopes overrides valid at specific date', function () {
    ArticleOverride::factory()->create([
        'valid_from' => now()->subDays(10),
        'valid_to'   => now()->addDays(10),
    ]);

    ArticleOverride::factory()->create([
        'valid_from' => now()->addDays(5),
        'valid_to'   => now()->addDays(15),
    ]);

    $valid = ArticleOverride::validAt(now())->get();

    expect($valid)->toHaveCount(1);
});

it('scopes overrides valid at date with null boundaries', function () {
    ArticleOverride::factory()->create([
        'valid_from' => null,
        'valid_to'   => null,
    ]);

    $valid = ArticleOverride::validAt(now())->get();

    expect($valid)->toHaveCount(1);
});

it('scopes for customer', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer1 = $userModel::factory()->create();
    $customer2 = $userModel::factory()->create();

    ArticleOverride::factory()->count(2)->create(['customer_id' => $customer1->id]);
    ArticleOverride::factory()->count(3)->create(['customer_id' => $customer2->id]);

    $overrides = ArticleOverride::forCustomer($customer1->id)->get();

    expect($overrides)->toHaveCount(2);
});

it('scopes for article', function () {
    $article1 = Article::factory()->create();
    $article2 = Article::factory()->create();

    ArticleOverride::factory()->count(2)->create(['article_id' => $article1->id]);
    ArticleOverride::factory()->count(3)->create(['article_id' => $article2->id]);

    $overrides = ArticleOverride::forArticle($article1->id)->get();

    expect($overrides)->toHaveCount(2);
});

it('checks if override is valid at specific date', function () {
    $override = ArticleOverride::factory()->create([
        'is_active'  => true,
        'valid_from' => now()->subDays(5),
        'valid_to'   => now()->addDays(5),
    ]);

    expect($override->isValidAt(now()))->toBeTrue()
        ->and($override->isValidAt(now()->subDays(10)))->toBeFalse()
        ->and($override->isValidAt(now()->addDays(10)))->toBeFalse();
});

it('returns false when override is inactive', function () {
    $override = ArticleOverride::factory()->inactive()->create([
        'valid_from' => now()->subDays(5),
        'valid_to'   => now()->addDays(5),
    ]);

    expect($override->isValidAt(now()))->toBeFalse();
});

it('checks if override is currently valid', function () {
    $valid = ArticleOverride::factory()->active()->create([
        'valid_from' => now()->subDay(),
        'valid_to'   => now()->addDay(),
    ]);

    $expired = ArticleOverride::factory()->expired()->create();

    expect($valid->isCurrentlyValid())->toBeTrue()
        ->and($expired->isCurrentlyValid())->toBeFalse();
});

it('checks if override is expired', function () {
    $expired = ArticleOverride::factory()->expired()->create();
    $active  = ArticleOverride::factory()->active()->create(['valid_to' => null]);
    $pending = ArticleOverride::factory()->pending()->create();

    expect($expired->isExpired())->toBeTrue()
        ->and($active->isExpired())->toBeFalse()
        ->and($pending->isExpired())->toBeFalse();
});

it('calculates days until expiration', function () {
    $override = ArticleOverride::factory()->create([
        'valid_to' => now()->addDays(10),
    ]);

    expect($override->daysUntilExpiration())->toBe(9); // Carbon includes current day in diff
});

it('returns null days until expiration when no expiration date', function () {
    $override = ArticleOverride::factory()->create(['valid_to' => null]);

    expect($override->daysUntilExpiration())->toBeNull();
});

it('returns null days until expiration when already expired', function () {
    $override = ArticleOverride::factory()->expired()->create();

    expect($override->daysUntilExpiration())->toBeNull();
});

it('calculates discount amount', function () {
    $article = Article::factory()->monthly(2900)->create();

    $override = ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'custom_price' => cents(2400),
    ]);

    expect($override->getDiscountAmount(BillingFrequency::MONTHLY))->toEqual(500);
});

it('calculates discount percentage', function () {
    $article = Article::factory()->monthly(2900)->create();

    $override = ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'custom_price' => cents(2320), // 20% discount
    ]);

    $percentage = $override->getDiscountPercentage(BillingFrequency::MONTHLY);

    expect($percentage)->toBeInt()
        ->and($percentage)->toBe(20);
});

it('returns zero discount percentage when base price is zero', function () {
    $article = Article::factory()->withoutPrices()->create();

    $override = ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'custom_price' => cents(0),
    ]);

    expect($override->getDiscountPercentage(BillingFrequency::MONTHLY))->toBe(0);
});

it('factory creates active override by default', function () {
    $override = ArticleOverride::factory()->create();

    expect($override->is_active)->toBeTrue()
        ->and($override->valid_from)->not->toBeNull()
        ->and($override->valid_to)->toBeNull();
});

it('factory can create inactive override', function () {
    $override = ArticleOverride::factory()->inactive()->create();

    expect($override->is_active)->toBeFalse();
});

it('factory can create expired override', function () {
    $override = ArticleOverride::factory()->expired()->create();

    expect($override->isExpired())->toBeTrue();
});

it('factory can create pending override', function () {
    $override = ArticleOverride::factory()->pending()->create();

    expect($override->valid_from)->toBeInstanceOf(Carbon::class)
        ->and($override->valid_from->isFuture())->toBeTrue();
});

it('factory can create override valid for specific days', function () {
    $override = ArticleOverride::factory()->validFor(30)->create();

    $days = $override->daysUntilExpiration();

    expect($days)->toBeGreaterThanOrEqual(29)
        ->and($days)->toBeLessThanOrEqual(30);
});

it('factory can create override with specific discount', function () {
    $article = Article::factory()->monthly(10000)->create(); // €100 in base100

    $override = ArticleOverride::factory()
        ->forArticle($article)
        ->withDiscount(20) // Uses MONTHLY by default
        ->create();

    expect($override->custom_price->unscaledValue())->toBe(8000); // €80 (20% off) in base100
});
