<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{CancellationType, ServiceStatus};
use AichaDigital\Larabill\Models\{Article, ArticleOverride, ArticleServiceStatus};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a service status', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->service()->recurring()->create();

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'         => $customer->id,
        'article_id'          => $article->id,
        'instance_identifier' => 'example.com',
    ]);

    expect($service->customer_id)->toBe($customer->id)
        ->and($service->article_id)->toBe($article->id)
        ->and($service->instance_identifier)->toBe('example.com')
        ->and($service->exists)->toBeTrue();
});

it('casts status to enum', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    expect($service->status)->toBeInstanceOf(ServiceStatus::class)
        ->and($service->status)->toBe(ServiceStatus::ACTIVE);
});

it('casts cancellation_type to enum', function () {
    $service = ArticleServiceStatus::factory()->cancelled(CancellationType::IMMEDIATE)->create();

    expect($service->cancellation_type)->toBeInstanceOf(CancellationType::class)
        ->and($service->cancellation_type)->toBe(CancellationType::IMMEDIATE);
});

it('casts dates correctly', function () {
    $service = ArticleServiceStatus::factory()->create([
        'started_at'        => '2024-01-01',
        'next_billing_date' => '2024-02-01',
        'expires_at'        => '2025-01-01',
    ]);

    expect($service->started_at)->toBeInstanceOf(Carbon::class)
        ->and($service->next_billing_date)->toBeInstanceOf(Carbon::class)
        ->and($service->expires_at)->toBeInstanceOf(Carbon::class);
});

it('casts booleans correctly', function () {
    $service = ArticleServiceStatus::factory()->create(['refund_unused' => true]);

    expect($service->refund_unused)->toBeTrue();
});

it('casts effective_price to integer (Base100Int)', function () {
    $service = ArticleServiceStatus::factory()->create(['effective_price' => 2900]);

    expect($service->effective_price)->toBeInt()
        ->and($service->effective_price)->toBe(2900);
});

it('casts arrays correctly', function () {
    $service = ArticleServiceStatus::factory()->create([
        'instance_data' => ['key' => 'value'],
        'metadata'      => ['note' => 'test'],
    ]);

    expect($service->instance_data)->toBeArray()
        ->and($service->metadata)->toBeArray();
});

it('has article relationship', function () {
    $article = Article::factory()->service()->recurring()->create();
    $service = ArticleServiceStatus::factory()->create(['article_id' => $article->id]);

    expect($service->article)->toBeInstanceOf(Article::class)
        ->and($service->article->id)->toBe($article->id);
});

it('has customer relationship', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();

    $service = ArticleServiceStatus::factory()->create(['customer_id' => $customer->id]);

    expect($service->customer)->not->toBeNull()
        ->and($service->customer->id)->toBe($customer->id);
});

it('has current override relationship', function () {
    $override = ArticleOverride::factory()->create();

    $service = ArticleServiceStatus::factory()->create([
        'current_override_id' => $override->id,
    ]);

    expect($service->currentOverride)->toBeInstanceOf(ArticleOverride::class)
        ->and($service->currentOverride->id)->toBe($override->id);
});

it('scopes active services', function () {
    ArticleServiceStatus::factory()->active()->count(3)->create();
    ArticleServiceStatus::factory()->pending()->count(2)->create();
    ArticleServiceStatus::factory()->cancelled()->count(1)->create();

    $active = ArticleServiceStatus::active()->get();

    expect($active)->toHaveCount(3);
});

it('scopes services pending billing', function () {
    ArticleServiceStatus::factory()->active()->count(2)->create(['next_billing_date' => now()->addMonth()]);
    ArticleServiceStatus::factory()->pending()->create(); // No next_billing_date
    ArticleServiceStatus::factory()->cancelled()->create();

    $pending = ArticleServiceStatus::pendingBilling()->get();

    expect($pending)->toHaveCount(2);
});

it('scopes services due for billing', function () {
    ArticleServiceStatus::factory()->dueForBilling()->count(2)->create();
    ArticleServiceStatus::factory()->create(['next_billing_date' => now()->addWeek()]);

    $due = ArticleServiceStatus::dueForBilling()->get();

    expect($due)->toHaveCount(2);
});

it('scopes for customer', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer1 = $userModel::factory()->create();
    $customer2 = $userModel::factory()->create();

    ArticleServiceStatus::factory()->count(2)->create(['customer_id' => $customer1->id]);
    ArticleServiceStatus::factory()->count(3)->create(['customer_id' => $customer2->id]);

    $services = ArticleServiceStatus::forCustomer($customer1->id)->get();

    expect($services)->toHaveCount(2);
});

it('scopes for article', function () {
    $article1 = Article::factory()->service()->recurring()->create();
    $article2 = Article::factory()->service()->recurring()->create();

    ArticleServiceStatus::factory()->count(2)->create(['article_id' => $article1->id]);
    ArticleServiceStatus::factory()->count(3)->create(['article_id' => $article2->id]);

    $services = ArticleServiceStatus::forArticle($article1->id)->get();

    expect($services)->toHaveCount(2);
});

it('scopes with specific status', function () {
    ArticleServiceStatus::factory()->active()->count(2)->create();
    ArticleServiceStatus::factory()->suspended()->count(3)->create();

    $suspended = ArticleServiceStatus::withStatus(ServiceStatus::SUSPENDED)->get();

    expect($suspended)->toHaveCount(3);
});

it('can activate a service', function () {
    $service = ArticleServiceStatus::factory()->pending()->create();

    $service->activate();

    expect($service->fresh()->status)->toBe(ServiceStatus::ACTIVE);
});

it('can suspend a service', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    $service->suspend('Payment failed');

    expect($service->fresh()->status)->toBe(ServiceStatus::SUSPENDED)
        ->and($service->fresh()->metadata['suspension']['reason'])->toBe('Payment failed');
});

it('can cancel a service immediately', function () {
    $service = ArticleServiceStatus::factory()->active()->create([
        'next_billing_date' => now()->addMonth(),
    ]);

    $service->cancel(CancellationType::IMMEDIATE, 'Customer request');

    $fresh = $service->fresh();

    expect($fresh->status)->toBe(ServiceStatus::CANCELLED)
        ->and($fresh->cancellation_type)->toBe(CancellationType::IMMEDIATE)
        ->and($fresh->refund_unused)->toBeFalse()
        ->and($fresh->next_billing_date)->toBeNull()
        ->and($fresh->cancellation_effective_at->isToday())->toBeTrue();
});

it('can cancel a service at end of period', function () {
    $nextBilling = now()->addMonth();
    $service     = ArticleServiceStatus::factory()->active()->create([
        'next_billing_date' => $nextBilling,
    ]);

    $service->cancel(CancellationType::END_OF_PERIOD, 'Customer request');

    $fresh = $service->fresh();

    expect($fresh->status)->toBe(ServiceStatus::ACTIVE) // Stays active until end
        ->and($fresh->cancellation_type)->toBe(CancellationType::END_OF_PERIOD)
        ->and($fresh->refund_unused)->toBeFalse()
        ->and($fresh->cancellation_effective_at->format('Y-m-d'))->toBe($nextBilling->format('Y-m-d'));
});

it('can cancel a service with notice period', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    $service->cancel(CancellationType::NOTICE_PERIOD, 'Customer request');

    $fresh = $service->fresh();

    expect($fresh->cancellation_type)->toBe(CancellationType::NOTICE_PERIOD)
        ->and($fresh->refund_unused)->toBeTrue()
        ->and($fresh->cancellation_effective_at)->not->toBeNull();
});

it('can expire a service', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    $service->expire();

    expect($service->fresh()->status)->toBe(ServiceStatus::EXPIRED)
        ->and($service->fresh()->next_billing_date)->toBeNull();
});

it('calculates next billing date monthly', function () {
    $article = Article::factory()->monthly()->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => Carbon::parse('2024-01-15'),
    ]);

    $nextDate = $service->calculateNextBillingDate();

    expect($nextDate->format('Y-m-d'))->toBe('2024-02-15');
});

it('calculates next billing date quarterly', function () {
    $article = Article::factory()->quarterly()->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => Carbon::parse('2024-01-15'),
    ]);

    $nextDate = $service->calculateNextBillingDate();

    expect($nextDate->format('Y-m-d'))->toBe('2024-04-15');
});

it('calculates next billing date yearly', function () {
    $article = Article::factory()->yearly()->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => Carbon::parse('2024-01-15'),
    ]);

    $nextDate = $service->calculateNextBillingDate();

    expect($nextDate->format('Y-m-d'))->toBe('2025-01-15');
});

it('updates effective price from override', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->create(['base_price' => 2900]);

    $override = ArticleOverride::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'custom_price' => 2400,
    ]);

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'     => $customer->id,
        'article_id'      => $article->id,
        'effective_price' => 2900,
    ]);

    $service->updateEffectivePrice();

    expect($service->fresh()->effective_price)->toBe(2400)
        ->and($service->fresh()->current_override_id)->toBe($override->id);
});

it('updates effective price to base when no override', function () {
    $article = Article::factory()->create(['base_price' => 2900]);

    $service = ArticleServiceStatus::factory()->create([
        'article_id'      => $article->id,
        'effective_price' => 2400,
    ]);

    $service->updateEffectivePrice();

    expect($service->fresh()->effective_price)->toBe(2900)
        ->and($service->fresh()->current_override_id)->toBeNull();
});

it('checks if service should be billed', function () {
    $shouldBill    = ArticleServiceStatus::factory()->dueForBilling()->create();
    $shouldNotBill = ArticleServiceStatus::factory()->create(['next_billing_date' => now()->addWeek()]);
    $cancelled     = ArticleServiceStatus::factory()->cancelled()->create();

    expect($shouldBill->shouldBeBilled())->toBeTrue()
        ->and($shouldNotBill->shouldBeBilled())->toBeFalse()
        ->and($cancelled->shouldBeBilled())->toBeFalse();
});

it('calculates days until next billing', function () {
    $service = ArticleServiceStatus::factory()->create([
        'next_billing_date' => now()->addDays(10),
    ]);

    expect($service->getDaysUntilNextBilling())->toBe(9); // Carbon doesn't count current day
});

it('returns zero days when next billing is in past', function () {
    $service = ArticleServiceStatus::factory()->dueForBilling()->create();

    expect($service->getDaysUntilNextBilling())->toBe(0);
});

it('gets instance data by key', function () {
    $service = ArticleServiceStatus::factory()->create([
        'instance_data' => [
            'hosting' => [
                'domain' => 'example.com',
                'ip'     => '192.168.1.1',
            ],
        ],
    ]);

    expect($service->getInstanceData('hosting.domain'))->toBe('example.com')
        ->and($service->getInstanceData('hosting.ip'))->toBe('192.168.1.1')
        ->and($service->getInstanceData('nonexistent', 'default'))->toBe('default');
});

it('sets instance data by key', function () {
    $service = ArticleServiceStatus::factory()->create(['instance_data' => []]);

    $service->setInstanceData('hosting.domain', 'new-domain.com');

    expect($service->fresh()->instance_data['hosting']['domain'])->toBe('new-domain.com');
});

it('gets metadata by key', function () {
    $service = ArticleServiceStatus::factory()->create([
        'metadata' => [
            'notes' => ['first' => 'test note'],
        ],
    ]);

    expect($service->getMetadata('notes.first'))->toBe('test note')
        ->and($service->getMetadata('nonexistent', 'default'))->toBe('default');
});

it('sets metadata by key', function () {
    $service = ArticleServiceStatus::factory()->create(['metadata' => []]);

    $service->setMetadata('notes.first', 'new note');

    expect($service->fresh()->metadata['notes']['first'])->toBe('new note');
});

it('factory creates hosting service', function () {
    $service = ArticleServiceStatus::factory()->hosting('example.com')->create();

    expect($service->instance_identifier)->toBe('example.com')
        ->and($service->instance_name)->toContain('Hosting')
        ->and($service->instance_data['hosting']['domain'])->toBe('example.com');
});

it('factory creates domain service', function () {
    $service = ArticleServiceStatus::factory()->domain('myapp.com')->create();

    expect($service->instance_identifier)->toBe('myapp.com')
        ->and($service->instance_name)->toContain('Domain')
        ->and($service->instance_data['domain']['name'])->toBe('myapp.com');
});

it('factory creates service with external reference', function () {
    $service = ArticleServiceStatus::factory()
        ->withExternalReference('stripe_sub_123')
        ->create();

    expect($service->external_reference)->toBe('stripe_sub_123');
});
