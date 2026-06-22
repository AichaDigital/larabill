<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service   = new PricingService;
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');
});

it('returns base price when no customer provided', function () {
    $article = Article::factory()->monthly(2900)->create();

    $price = $this->service->getEffectivePrice($article, BillingFrequency::MONTHLY, null);

    expect($price)->toBe(2900.0);
});

it('returns base price when customer has no override', function () {
    $article = Article::factory()->monthly(2900)->create();

    $price = $this->service->getEffectivePrice($article, BillingFrequency::MONTHLY, 999);

    expect($price)->toBe(2900.0);
});

it('returns override price when customer has active override', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->monthly(2900)->create();

    ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'customer_id'  => $customer->id,
        'custom_price' => cents(2400),
        'is_active'    => true,
    ]);

    $price = $this->service->getEffectivePrice($article, BillingFrequency::MONTHLY, $customer->id);

    expect($price)->toBe(2400.0);
});

it('returns override price for UUID customer IDs', function () {
    $customerId = (string) Str::uuid();
    $article    = Article::factory()->monthly(2900)->create();

    ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'customer_id'  => $customerId,
        'custom_price' => cents(2400),
        'is_active'    => true,
    ]);

    $price = $this->service->getEffectivePrice($article, BillingFrequency::MONTHLY, $customerId);

    expect($price)->toBe(2400.0);
});

it('gets active override for customer', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->create();

    $override = ArticleOverride::factory()->create([
        'article_id'  => $article->id,
        'customer_id' => $customer->id,
        'is_active'   => true,
    ]);

    $result = $this->service->getActiveOverride($article, $customer->id);

    expect($result)->toBeInstanceOf(ArticleOverride::class)
        ->and($result->id)->toBe($override->id);
});

it('returns null when no active override exists', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->create();

    $result = $this->service->getActiveOverride($article, $customer->id);

    expect($result)->toBeNull();
});

it('checks if customer has active override', function () {
    $customer1 = $this->userModel::factory()->create();
    $customer2 = $this->userModel::factory()->create();
    $article   = Article::factory()->create();

    ArticleOverride::factory()->create([
        'article_id'  => $article->id,
        'customer_id' => $customer1->id,
        'is_active'   => true,
    ]);

    expect($this->service->hasActiveOverride($article, $customer1->id))->toBeTrue()
        ->and($this->service->hasActiveOverride($article, $customer2->id))->toBeFalse();
});

it('calculates discount amount', function () {
    $discount = $this->service->calculateDiscountAmount(2900.0, 2400.0);

    expect($discount)->toBe(500.0);
});

it('returns zero discount when prices are equal', function () {
    $discount = $this->service->calculateDiscountAmount(2900.0, 2900.0);

    expect($discount)->toBe(0.0);
});

it('calculates discount percentage', function () {
    $percentage = $this->service->calculateDiscountPercentage(2900.0, 2400.0);

    expect($percentage)->toBe(17.24);
});

it('returns zero discount percentage when prices are equal', function () {
    $percentage = $this->service->calculateDiscountPercentage(2900.0, 2900.0);

    expect($percentage)->toBe(0.0);
});

it('returns zero discount percentage when base price is zero', function () {
    $percentage = $this->service->calculateDiscountPercentage(0.0, 0.0);

    expect($percentage)->toBe(0.0);
});

it('creates pricing details without override', function () {
    $article = Article::factory()->monthly(2900)->create();

    $details = $this->service->createPricingDetails($article, BillingFrequency::MONTHLY, null);

    expect($details->basePrice)->toBe(2900.0)
        ->and($details->appliedPrice)->toBe(2900.0)
        ->and($details->pricingRule)->toBe('base_price')
        ->and($details->discountAmount)->toBeNull()
        ->and($details->discountPercentage)->toBeNull()
        ->and($details->overrideId)->toBeNull();
});

it('creates pricing details with customer override', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->monthly(2900)->create();

    $override = ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'customer_id'  => $customer->id,
        'custom_price' => cents(2400),
        'is_active'    => true,
    ]);

    $details = $this->service->createPricingDetails($article, BillingFrequency::MONTHLY, $customer->id);

    expect($details->basePrice)->toBe(2900.0)
        ->and($details->appliedPrice)->toBe(2400.0)
        ->and($details->pricingRule)->toBe('customer_override')
        ->and($details->discountAmount)->toBe(500.0)
        ->and($details->discountPercentage)->toBe(17.24)
        ->and($details->overrideId)->toBe($override->id);
});

it('validates price is not negative', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => null,
    ]);

    expect($this->service->validatePrice($article, 2900.0))->toBeTrue()
        ->and($this->service->validatePrice($article, 0.0))->toBeTrue()
        ->and($this->service->validatePrice($article, -100.0))->toBeFalse();
});

it('validates price is above cost price', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => cents(1500),
    ]);

    expect($this->service->validatePrice($article, 2000.0))->toBeTrue()
        ->and($this->service->validatePrice($article, 1500.0))->toBeTrue()
        ->and($this->service->validatePrice($article, 1000.0))->toBeFalse();
});

it('calculates profit margin', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => cents(1500),
    ]);

    $margin = $this->service->calculateProfitMargin($article, 2400.0);

    expect($margin)->toBe(900.0);
});

it('returns null profit margin when no cost price', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => null,
    ]);

    $margin = $this->service->calculateProfitMargin($article, 2400.0);

    expect($margin)->toBeNull();
});

it('calculates profit margin percentage', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => cents(1500),
    ]);

    $percentage = $this->service->calculateProfitMarginPercentage($article, 2400.0);

    expect($percentage)->toBe(37.5);
});

it('returns null profit margin percentage when no cost price', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => null,
    ]);

    $percentage = $this->service->calculateProfitMarginPercentage($article, 2400.0);

    expect($percentage)->toBeNull();
});

it('returns null profit margin percentage when price is zero', function () {
    $article = Article::factory()->monthly(2900)->create([
        'cost_price' => cents(1500),
    ]);

    $percentage = $this->service->calculateProfitMarginPercentage($article, 0.0);

    expect($percentage)->toBeNull();
});

it('returns null when no price for frequency', function () {
    $article = Article::factory()->monthly(2900)->create();

    // Request yearly price but article only has monthly
    $price = $this->service->getEffectivePrice($article, BillingFrequency::YEARLY, null);

    expect($price)->toBeNull();
});

it('creates pricing details for service', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->monthly(2900)->create();

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
    ]);

    $details = $this->service->createPricingDetailsForService($service);

    expect($details->basePrice)->toBe(2900.0)
        ->and($details->appliedPrice)->toBe(2900.0)
        ->and($details->pricingRule)->toBe('base_price');
});
