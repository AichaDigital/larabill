<?php

declare(strict_types=1);

use AichaDigital\Larabill\Actions\ProcessPendingCancellations;
use AichaDigital\Larabill\Actions\ProcessServiceExpiration;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');
});

// ProcessServiceExpiration tests
it('can process expired services via action', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->create();

    ArticleServiceStatus::factory()->count(2)->create([
        'customer_id' => $customer->id,
        'article_id'  => $article->id,
        'status'      => AgreementStatus::ACTIVE,
        'expires_at'  => now()->subDay(),
    ]);

    $result = ProcessServiceExpiration::run();

    expect($result['success'])->toBeTrue()
        ->and($result['expired'])->toBe(2);
});

it('returns zero when no services are expired', function () {
    $result = ProcessServiceExpiration::run();

    expect($result['success'])->toBeTrue()
        ->and($result['expired'])->toBe(0);
});

// ProcessPendingCancellations tests
it('can process pending cancellations via action', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->count(3)->create([
        'customer_id'        => $customer->id,
        'article_id'         => $article->id,
        'billing_unit'       => 'month', 'billing_quantity' => 1,
        'status'             => AgreementStatus::ACTIVE,
        'close_scheduled_at' => now()->subDay(),
    ]);

    $result = ProcessPendingCancellations::run();

    expect($result['success'])->toBeTrue()
        ->and($result['cancelled'])->toBe(3);
});

it('returns zero when no cancellations are pending', function () {
    $result = ProcessPendingCancellations::run();

    expect($result['success'])->toBeTrue()
        ->and($result['cancelled'])->toBe(0);
});
