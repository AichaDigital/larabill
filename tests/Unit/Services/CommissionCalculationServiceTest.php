<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Article, Commission};
use AichaDigital\Larabill\Services\CommissionCalculationService;

beforeEach(function () {
    $this->commissionService = app(CommissionCalculationService::class);
});

it('can calculate global commission', function () {
    Commission::factory()->create([
        'level' => 'global',
        'type' => 'percentage',
        'rate' => 10.0,
        'is_active' => true,
        'valid_from' => now()->subDay(),
        'valid_until' => null,
    ]);

    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00,
        articleId: null,
        productGroup: null,
        date: now()
    );

    expect($amount)->toBe(10.00);
});

it('can calculate product commission', function () {
    $article = Article::factory()->create();

    Commission::factory()->create([
        'level' => 'product',
        'article_id' => $article->id,
        'type' => 'percentage',
        'rate' => 15.0,
        'is_active' => true,
        'valid_from' => now()->subDay(),
    ]);

    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00,
        articleId: $article->id,
        productGroup: null,
        date: now()
    );

    expect($amount)->toBe(15.00);
});

it('prioritizes product over global commission', function () {
    $article = Article::factory()->create();

    // Global commission
    Commission::factory()->create([
        'level' => 'global',
        'type' => 'percentage',
        'rate' => 10.0,
        'is_active' => true,
        'valid_from' => now()->subDay(),
    ]);

    // Product commission (higher priority)
    Commission::factory()->create([
        'level' => 'product',
        'article_id' => $article->id,
        'type' => 'percentage',
        'rate' => 20.0,
        'is_active' => true,
        'valid_from' => now()->subDay(),
    ]);

    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00,
        articleId: $article->id,
        productGroup: null,
        date: now()
    );

    // Should use product commission (20%), not global (10%)
    expect($amount)->toBe(20.00);
});

it('can calculate product group commission', function () {
    Commission::factory()->create([
        'level' => 'product_group',
        'product_group' => 'hosting',
        'type' => 'percentage',
        'rate' => 12.5,
        'is_active' => true,
        'valid_from' => now()->subDay(),
    ]);

    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00,
        articleId: null,
        productGroup: 'hosting',
        date: now()
    );

    expect($amount)->toBe(12.50);
});

it('returns zero when no active commission found', function () {
    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00,
        articleId: null,
        productGroup: null,
        date: now()
    );

    expect($amount)->toBe(0.00);
});

it('respects commission date range', function () {
    Commission::factory()->create([
        'level' => 'global',
        'type' => 'percentage',
        'rate' => 10.0,
        'is_active' => true,
        'valid_from' => now()->subMonth(),
        'valid_until' => now()->subDay(),
    ]);

    // Commission expired, should return 0
    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00,
        articleId: null,
        productGroup: null,
        date: now()
    );

    expect($amount)->toBe(0.00);
});

it('can calculate fixed amount commission', function () {
    Commission::factory()->create([
        'level' => 'global',
        'type' => 'fixed',
        'rate' => 5.00,
        'is_active' => true,
        'valid_from' => now()->subDay(),
    ]);

    $amount = $this->commissionService->calculateCommission(
        baseAmount: 100.00, // Base amount doesn't matter for fixed
        articleId: null,
        productGroup: null,
        date: now()
    );

    expect($amount)->toBe(5.00);
});

