<?php

declare(strict_types=1);

use AichaDigital\Larabill\Actions\ProcessRecurringBilling;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Models\{Article, ArticleServiceStatus, Invoice};
use AichaDigital\Larabill\Services\RecurringBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has correct command signature', function () {
    $service = app(RecurringBillingService::class);
    $action  = new ProcessRecurringBilling($service);

    expect($action->commandSignature)->toBe('larabill:process-recurring {--date=} {--dry-run}');
});

it('has correct command description', function () {
    $service = app(RecurringBillingService::class);
    $action  = new ProcessRecurringBilling($service);

    expect($action->commandDescription)->toBe('Process recurring billing for services due');
});

it('can be executed as a direct call', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => \AichaDigital\Larabill\Enums\BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addDays(7),
    ]);

    $results = ProcessRecurringBilling::run();

    expect($results['processed'])->toBe(1)
        ->and($results['failed'])->toBe(0);
});

it('supports custom date parameter', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => \AichaDigital\Larabill\Enums\BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => Carbon::parse('2024-01-31'),
    ]);

    $results = ProcessRecurringBilling::run(Carbon::parse('2024-01-24'));

    expect($results['processed'])->toBe(1);
});

it('supports dry-run mode', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => \AichaDigital\Larabill\Enums\BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addDays(7),
    ]);

    $initialCount = Invoice::count();
    $results      = ProcessRecurringBilling::run(now(), true); // dry-run

    expect($results['processed'])->toBe(1)
        ->and($results['invoices'])->toHaveCount(0)
        ->and(Invoice::count())->toBe($initialCount);
});
