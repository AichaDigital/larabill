<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{BillingFrequency, CancellationType, ServiceStatus};
use AichaDigital\Larabill\Events\{ServiceActivated, ServiceCancelled, ServiceExpired, ServiceSuspended};
use AichaDigital\Larabill\Models\{Article, ArticleServiceStatus};
use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');
    $this->service   = new ServiceLifecycleService;
});

it('can activate a service', function () {
    Event::fake([ServiceActivated::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::PENDING,
        'started_at'        => now()->subDay(),
    ]);

    // Clear started_at to test auto-setting
    $originalStartedAt = $service->started_at;

    $this->service->activate($service);

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::ACTIVE)
        ->and($service->started_at)->not->toBeNull();

    Event::assertDispatched(ServiceActivated::class, function ($event) use ($service) {
        return $event->service->id === $service->id;
    });
});

it('can suspend a service with reason', function () {
    Event::fake([ServiceSuspended::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
    ]);

    $this->service->suspend($service, 'Payment overdue');

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::SUSPENDED)
        ->and($service->metadata)->toHaveKey('suspension')
        ->and($service->metadata['suspension']['reason'])->toBe('Payment overdue');

    Event::assertDispatched(ServiceSuspended::class, function ($event) use ($service) {
        return $event->service->id === $service->id
            && $event->reason      === 'Payment overdue';
    });
});

it('can cancel a service immediately', function () {
    Event::fake([ServiceCancelled::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
    ]);

    $this->service->cancel($service, CancellationType::IMMEDIATE, 'Customer request');

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::CANCELLED)
        ->and($service->cancellation_type)->toBe(CancellationType::IMMEDIATE)
        ->and($service->refund_unused)->toBeFalse() // IMMEDIATE does NOT require refund
        ->and($service->metadata)->toHaveKey('cancellation');

    Event::assertDispatched(ServiceCancelled::class);
});

it('can cancel a service at end of period', function () {
    Event::fake([ServiceCancelled::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addMonth(),
    ]);

    $this->service->cancel($service, CancellationType::END_OF_PERIOD);

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::ACTIVE) // Still active until end of period
        ->and($service->cancellation_type)->toBe(CancellationType::END_OF_PERIOD)
        ->and($service->cancellation_effective_at->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($service->refund_unused)->toBeFalse();

    Event::assertDispatched(ServiceCancelled::class);
});

it('can cancel a service with notice period', function () {
    Event::fake([ServiceCancelled::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create([
        'metadata' => ['notice_period_days' => 15],
    ]);
    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
    ]);

    $this->service->cancel($service, CancellationType::NOTICE_PERIOD);

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::ACTIVE)
        ->and($service->cancellation_type)->toBe(CancellationType::NOTICE_PERIOD)
        ->and($service->cancellation_effective_at->toDateString())->toBe(now()->addDays(15)->toDateString());

    Event::assertDispatched(ServiceCancelled::class);
});

it('can expire a service', function () {
    Event::fake([ServiceExpired::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'expires_at'        => now()->subDay(),
    ]);

    $this->service->expire($service);

    $service->refresh();
    expect($service->status)->toBe(ServiceStatus::EXPIRED);

    Event::assertDispatched(ServiceExpired::class);
});

it('can calculate refund for unused period', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(3000)->create();

    // Service billed on 1st of month, next billing in 30 days, cancelled after 10 days
    $lastBillingDate = now()->startOfMonth();
    $nextBillingDate = $lastBillingDate->copy()->addMonth();

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => $nextBillingDate,
        'effective_price'   => 3000,
        'refund_unused'     => true,
    ]);

    // Travel to 10 days into the period
    Carbon::setTestNow($lastBillingDate->copy()->addDays(10));

    $refund = $this->service->calculateRefund($service);

    // Unused: approximately 20 days out of ~30 = 66.67% = ~€20.00
    expect($refund)->toBeGreaterThan(1900)
        ->and($refund)->toBeLessThan(2100);

    Carbon::setTestNow();
});

it('returns null refund when refund_unused is false', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'refund_unused'     => false,
    ]);

    $refund = $this->service->calculateRefund($service);

    expect($refund)->toBeNull();
});

it('can process expired services', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->create();

    // Create 3 expired services
    ArticleServiceStatus::factory()->count(3)->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'expires_at'        => now()->subDay(),
    ]);

    // Create 1 not expired
    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'expires_at'        => now()->addMonth(),
    ]);

    $count = $this->service->processExpiredServices();

    expect($count)->toBe(3);

    $expiredServices = ArticleServiceStatus::query()
        ->where('status', ServiceStatus::EXPIRED)
        ->count();

    expect($expiredServices)->toBe(3);
});

it('can process pending cancellations', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    // Create 2 services with pending cancellations
    ArticleServiceStatus::factory()->count(2)->create([
        'customer_id'               => $customer->id,
        'article_id'                => $article->id,
        'billing_frequency'         => BillingFrequency::MONTHLY,
        'status'                    => ServiceStatus::ACTIVE,
        'cancellation_type'         => CancellationType::END_OF_PERIOD,
        'cancellation_effective_at' => now()->subDay(),
    ]);

    // Create 1 with future cancellation
    ArticleServiceStatus::factory()->create([
        'customer_id'               => $customer->id,
        'article_id'                => $article->id,
        'billing_frequency'         => BillingFrequency::MONTHLY,
        'status'                    => ServiceStatus::ACTIVE,
        'cancellation_type'         => CancellationType::END_OF_PERIOD,
        'cancellation_effective_at' => now()->addMonth(),
    ]);

    $count = $this->service->processPendingCancellations();

    expect($count)->toBe(2);

    $cancelledServices = ArticleServiceStatus::query()
        ->where('status', ServiceStatus::CANCELLED)
        ->count();

    expect($cancelledServices)->toBe(2);
});
