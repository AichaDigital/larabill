<?php

declare(strict_types=1);

use AichaDigital\Larabill\Actions\{ProcessPendingCancellations, ProcessServiceExpiration};
use AichaDigital\Larabill\Enums\{CancellationType, ServiceStatus};
use AichaDigital\Larabill\Models\{Article, ArticleServiceStatus};
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
        'status'      => ServiceStatus::ACTIVE,
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
        'customer_id'               => $customer->id,
        'article_id'                => $article->id,
        'billing_frequency'         => \AichaDigital\Larabill\Enums\BillingFrequency::MONTHLY,
        'status'                    => ServiceStatus::ACTIVE,
        'cancellation_type'         => CancellationType::END_OF_PERIOD,
        'cancellation_effective_at' => now()->subDay(),
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
