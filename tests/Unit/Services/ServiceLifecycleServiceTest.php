<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Events\ServiceActivated;
use AichaDigital\Larabill\Events\ServiceCancelled;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Events\ServiceSuspended;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
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
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::PENDING,
        'started_at'        => now()->subDay(),
    ]);

    // Clear started_at to test auto-setting
    $originalStartedAt = $service->started_at;

    $this->service->activate($service);

    $service->refresh();
    expect($service->status)->toBe(AgreementStatus::ACTIVE)
        ->and($service->started_at)->not->toBeNull();

    Event::assertDispatched(ServiceActivated::class, function ($event) use ($service) {
        return $event->service->id === $service->id;
    });
});

it('can suspend a service with a suspension cause', function () {
    Event::fake([ServiceSuspended::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
    ]);

    $cause = AgreementCause::findActive(AgreementCause::SCOPE_SUSPENDED, 'unpaid');
    $this->service->suspend($service, $cause, 'Payment overdue');

    $service->refresh();
    expect($service->status)->toBe(AgreementStatus::SUSPENDED)
        ->and($service->closure_cause_id)->toBe($cause->id)
        ->and($service->metadata)->toHaveKey('suspension')
        ->and($service->metadata['suspension']['reason'])->toBe('Payment overdue');

    Event::assertDispatched(ServiceSuspended::class, function ($event) use ($service) {
        return $event->service->id === $service->id
            && $event->reason      === 'Payment overdue';
    });
});

it('can cancel a service immediately (shim forwards to the close path)', function () {
    Event::fake([ServiceCancelled::class, ServiceClosed::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
    ]);

    $this->service->cancel($service, CancellationType::IMMEDIATE, 'Customer request');

    $service->refresh();
    expect($service->status)->toBe(AgreementStatus::CLOSED)
        ->and($service->closure_cause_id)->toBe(
            AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation')->id
        )
        ->and($service->refund_unused)->toBeFalse() // IMMEDIATE does NOT require refund
        ->and($service->metadata)->toHaveKey('cancellation')
        ->and($service->metadata['cancellation']['type'])->toBe(CancellationType::IMMEDIATE->value);

    Event::assertDispatched(ServiceCancelled::class);
    Event::assertDispatched(ServiceClosed::class);
});

it('can cancel a service at end of period (scheduled close)', function () {
    Event::fake([ServiceCancelled::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
        'next_billing_date' => now()->addMonth(),
    ]);

    $this->service->cancel($service, CancellationType::END_OF_PERIOD);

    $service->refresh();
    expect($service->status)->toBe(AgreementStatus::ACTIVE) // Still active until end of period
        ->and($service->close_scheduled_at->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($service->metadata['cancellation']['type'])->toBe(CancellationType::END_OF_PERIOD->value)
        ->and($service->cancellation_effective_at->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($service->refund_unused)->toBeFalse();

    Event::assertDispatched(ServiceCancelled::class);
});

it('can cancel a service with notice period (its own metadata route, verbatim)', function () {
    Event::fake([ServiceCancelled::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create([
        'metadata' => ['notice_period_days' => 15],
    ]);
    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
    ]);

    $this->service->cancel($service, CancellationType::NOTICE_PERIOD);

    $service->refresh();
    expect($service->status)->toBe(AgreementStatus::ACTIVE)
        ->and($service->close_scheduled_at->toDateString())->toBe(now()->addDays(15)->toDateString())
        ->and($service->cancellation_effective_at->toDateString())->toBe(now()->addDays(15)->toDateString());

    Event::assertDispatched(ServiceCancelled::class);
});

it('can expire a service — expiry closes with the fulfilment cause (§4.2 rewire)', function () {
    Event::fake([ServiceClosed::class]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->create();
    $service  = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
        'expires_at'        => now()->subDay(),
    ]);

    $this->service->expire($service);

    $service->refresh();
    expect($service->status)->toBe(AgreementStatus::CLOSED)
        ->and($service->closure_cause_id)->toBe(
            AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'fulfilment')->id
        );

    Event::assertDispatched(ServiceClosed::class);
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
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
        'next_billing_date' => $nextBillingDate,
        'effective_price'   => cents(3000),
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
        'billing_unit'      => 'month', 'billing_quantity' => 1,
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
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
        'expires_at'        => now()->subDay(),
    ]);

    // Create 1 not expired
    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'status'            => AgreementStatus::ACTIVE,
        'expires_at'        => now()->addMonth(),
    ]);

    $count = $this->service->processExpiredServices();

    expect($count)->toBe(3);

    $expiredServices = ArticleServiceStatus::query()
        ->where('status', AgreementStatus::CLOSED)
        ->count();

    expect($expiredServices)->toBe(3);
});

it('can process pending cancellations — the scheduled closures of the §3.2 materialiser', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    // Create 2 services with a past-due scheduled close (the migrated
    // deferred-closure shape: active, dated, cause NULL)
    ArticleServiceStatus::factory()->count(2)->create([
        'customer_id'        => $customer->id,
        'article_id'         => $article->id,
        'billing_unit'       => 'month', 'billing_quantity' => 1,
        'status'             => AgreementStatus::ACTIVE,
        'close_scheduled_at' => now()->subDay(),
    ]);

    // Create 1 with a future scheduled close
    ArticleServiceStatus::factory()->create([
        'customer_id'        => $customer->id,
        'article_id'         => $article->id,
        'billing_unit'       => 'month', 'billing_quantity' => 1,
        'status'             => AgreementStatus::ACTIVE,
        'close_scheduled_at' => now()->addMonth(),
    ]);

    $count = $this->service->processPendingCancellations();

    expect($count)->toBe(2);

    $closedServices = ArticleServiceStatus::query()
        ->where('status', AgreementStatus::CLOSED)
        ->count();

    expect($closedServices)->toBe(2);
});
