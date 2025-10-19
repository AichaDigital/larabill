<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{BillingFrequency, ItemType};
use AichaDigital\Larabill\Models\{Article, ArticleOverride, ArticleServiceStatus, TaxGroup, UnitMeasure};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create an article', function () {
    $article = Article::factory()->create([
        'code' => 'TEST-001',
        'name' => 'Test Article',
    ]);

    expect($article->code)->toBe('TEST-001')
        ->and($article->name)->toBe('Test Article')
        ->and($article->exists)->toBeTrue();
});

it('casts item_type to enum', function () {
    $article = Article::factory()->service()->create();

    expect($article->item_type)->toBeInstanceOf(ItemType::class)
        ->and($article->item_type)->toBe(ItemType::SERVICE);
});

it('casts billing_frequency to enum', function () {
    $article = Article::factory()->monthly()->create();

    expect($article->billing_frequency)->toBeInstanceOf(BillingFrequency::class)
        ->and($article->billing_frequency)->toBe(BillingFrequency::MONTHLY);
});

it('casts prices to Base100 (numeric)', function () {
    $article = Article::factory()->create([
        'base_price' => 2900,
        'cost_price' => 1500,
    ]);

    expect($article->base_price)->toBeNumeric()
        ->and($article->base_price)->toBe(2900.0)
        ->and($article->cost_price)->toBeNumeric()
        ->and($article->cost_price)->toBe(1500.0);
});

it('casts booleans correctly', function () {
    $article = Article::factory()->recurring()->create();

    expect($article->is_recurring)->toBeTrue()
        ->and($article->is_active)->toBeTrue();
});

it('casts metadata to array', function () {
    $article = Article::factory()->create([
        'metadata' => ['key' => 'value'],
    ]);

    expect($article->metadata)->toBeArray()
        ->and($article->metadata)->toHaveKey('key')
        ->and($article->metadata['key'])->toBe('value');
});

it('has tax group relationship', function () {
    $taxGroup = TaxGroup::factory()->create();
    $article  = Article::factory()->create(['tax_group_id' => $taxGroup->id]);

    expect($article->taxGroup)->toBeInstanceOf(TaxGroup::class)
        ->and($article->taxGroup->id)->toBe($taxGroup->id);
});

it('has unit measure relationship', function () {
    $unitMeasure = UnitMeasure::factory()->create();
    $article     = Article::factory()->create(['unit_measure_id' => $unitMeasure->id]);

    expect($article->unitMeasure)->toBeInstanceOf(UnitMeasure::class)
        ->and($article->unitMeasure->id)->toBe($unitMeasure->id);
});

it('has overrides relationship', function () {
    $article = Article::factory()->create();
    ArticleOverride::factory()->count(3)->create(['article_id' => $article->id]);

    expect($article->overrides)->toHaveCount(3)
        ->and($article->overrides->first())->toBeInstanceOf(ArticleOverride::class);
});

it('has service statuses relationship', function () {
    $article = Article::factory()->service()->recurring()->create();
    ArticleServiceStatus::factory()->count(2)->create(['article_id' => $article->id]);

    expect($article->serviceStatuses)->toHaveCount(2)
        ->and($article->serviceStatuses->first())->toBeInstanceOf(ArticleServiceStatus::class);
});

it('scopes active articles', function () {
    Article::factory()->create(['is_active' => true]);
    Article::factory()->create(['is_active' => true]);
    Article::factory()->inactive()->create();

    $active = Article::active()->get();

    expect($active)->toHaveCount(2);
});

it('scopes goods', function () {
    Article::factory()->good()->count(2)->create();
    Article::factory()->service()->count(3)->create();

    $goods = Article::goods()->get();

    expect($goods)->toHaveCount(2)
        ->and($goods->first()->item_type)->toBe(ItemType::GOOD);
});

it('scopes services', function () {
    Article::factory()->service()->count(3)->create();
    Article::factory()->good()->count(2)->create();

    $services = Article::services()->get();

    expect($services)->toHaveCount(3)
        ->and($services->first()->item_type)->toBe(ItemType::SERVICE);
});

it('scopes recurring articles', function () {
    Article::factory()->recurring()->count(3)->create();
    Article::factory()->oneTime()->count(2)->create();

    $recurring = Article::recurring()->get();

    expect($recurring)->toHaveCount(3)
        ->and($recurring->first()->is_recurring)->toBeTrue();
});

it('scopes by category', function () {
    Article::factory()->category('hosting')->count(2)->create();
    Article::factory()->category('domains')->count(3)->create();

    $hosting = Article::byCategory('hosting')->get();

    expect($hosting)->toHaveCount(2)
        ->and($hosting->first()->category)->toBe('hosting');
});

it('identifies services correctly', function () {
    $service = Article::factory()->service()->create();
    $good    = Article::factory()->good()->create();

    expect($service->isService())->toBeTrue()
        ->and($good->isService())->toBeFalse();
});

it('identifies goods correctly', function () {
    $good    = Article::factory()->good()->create();
    $service = Article::factory()->service()->create();

    expect($good->isGood())->toBeTrue()
        ->and($service->isGood())->toBeFalse();
});

it('requires instance identifier for services by default', function () {
    $service = Article::factory()->service()->create();
    $good    = Article::factory()->good()->create();

    expect($service->requiresInstanceIdentifier())->toBeTrue()
        ->and($good->requiresInstanceIdentifier())->toBeFalse();
});

it('can override instance identifier requirement via metadata', function () {
    $service = Article::factory()->service()->create([
        'metadata' => [
            'service' => [
                'requires_instance' => false,
            ],
        ],
    ]);

    expect($service->requiresInstanceIdentifier())->toBeFalse();
});

it('validates instance identifier based on metadata rules', function () {
    $article = Article::factory()->create([
        'item_type' => ItemType::SERVICE,
        'metadata'  => [
            'service' => [
                'requires_instance'   => true,
                'instance_validation' => ['type' => 'domain'],
            ],
        ],
    ]);

    expect($article->validateInstanceIdentifier('example.com'))->toBeTrue()
        ->and($article->validateInstanceIdentifier('not-a-domain'))->toBeFalse()
        ->and($article->validateInstanceIdentifier(null))->toBeFalse();
});

it('returns base price when no customer provided', function () {
    $article = Article::factory()->create(['base_price' => 2900]);

    expect($article->getEffectivePriceFor(null))->toBe(2900);
});

it('returns base price when customer has no override', function () {
    $article = Article::factory()->create(['base_price' => 2900]);

    expect($article->getEffectivePriceFor(999))->toBe(2900);
});

it('returns override price when customer has active override', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();

    $article = Article::factory()->create(['base_price' => 2900]);

    ArticleOverride::factory()->create([
        'article_id'   => $article->id,
        'customer_id'  => $customer->id,
        'custom_price' => 2400,
        'is_active'    => true,
    ]);

    expect($article->getEffectivePriceFor($customer->id))->toBe(2400);
});

it('gets active override for customer', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();

    $article = Article::factory()->create();

    $override = ArticleOverride::factory()->create([
        'article_id'  => $article->id,
        'customer_id' => $customer->id,
    ]);

    $result = $article->getActiveOverrideFor($customer->id);

    expect($result)->toBeInstanceOf(ArticleOverride::class)
        ->and($result->id)->toBe($override->id);
});

it('returns null when customer has no active override', function () {
    $article = Article::factory()->create();

    expect($article->getActiveOverrideFor(999))->toBeNull();
});

it('checks if customer has active override', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();

    $article = Article::factory()->create();

    ArticleOverride::factory()->create([
        'article_id'  => $article->id,
        'customer_id' => $customer->id,
    ]);

    expect($article->hasActiveOverrideFor($customer->id))->toBeTrue()
        ->and($article->hasActiveOverrideFor(999))->toBeFalse();
});

it('calculates profit margin', function () {
    $article = Article::factory()->create([
        'base_price' => 2900,
        'cost_price' => 1500,
    ]);

    expect($article->getProfitMargin())->toBeFloat()
        ->and($article->getProfitMargin())->toBe(1400.0);
});

it('returns null profit margin when no cost price', function () {
    $article = Article::factory()->create([
        'base_price' => 2900,
        'cost_price' => null,
    ]);

    expect($article->getProfitMargin())->toBeNull();
});

it('calculates profit margin percentage', function () {
    $article = Article::factory()->create([
        'base_price' => 2900,
        'cost_price' => 1500,
    ]);

    $percentage = $article->getProfitMarginPercentage();

    expect($percentage)->toBeInt()
        ->and($percentage)->toBe(48); // (1400/2900) * 100 = 48.27 -> 48
});

it('returns null profit margin percentage when no cost price', function () {
    $article = Article::factory()->create([
        'base_price' => 2900,
        'cost_price' => null,
    ]);

    expect($article->getProfitMarginPercentage())->toBeNull();
});

it('returns null profit margin percentage when base price is zero', function () {
    $article = Article::factory()->create([
        'base_price' => 0,
        'cost_price' => 1500,
    ]);

    expect($article->getProfitMarginPercentage())->toBeNull();
});

it('soft deletes articles', function () {
    $article = Article::factory()->create();

    $article->delete();

    expect($article->trashed())->toBeTrue()
        ->and(Article::count())->toBe(0)
        ->and(Article::withTrashed()->count())->toBe(1);
});

it('can restore soft deleted articles', function () {
    $article = Article::factory()->create();
    $article->delete();

    $article->restore();

    expect($article->trashed())->toBeFalse()
        ->and(Article::count())->toBe(1);
});
