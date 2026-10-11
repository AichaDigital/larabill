<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('can create a service status', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->service()->monthly(2900)->create();

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'         => $customer->id,
        'article_id'          => $article->id,
        'billing_unit'        => 'month', 'billing_quantity' => 1,
        'instance_identifier' => 'example.com',
    ]);

    expect($service->customer_id)->toBe($customer->id)
        ->and($service->article_id)->toBe($article->id)
        ->and($service->instance_identifier)->toBe('example.com')
        ->and($service->exists)->toBeTrue();
});

it('creates customer id with the configured agnostic type', function () {
    expect(Schema::getColumnType('article_service_status', 'customer_id'))->not->toBe('integer');
});

it('can create and scope service statuses for UUID customer IDs', function () {
    $customerId      = (string) Str::uuid();
    $otherCustomerId = (string) Str::uuid();
    $article         = Article::factory()->service()->monthly(2900)->create();

    $service = ArticleServiceStatus::factory()
        ->forCustomer($customerId)
        ->forArticle($article, BillingFrequency::MONTHLY)
        ->create();

    ArticleServiceStatus::factory()
        ->forCustomer($otherCustomerId)
        ->forArticle($article, BillingFrequency::MONTHLY)
        ->create();

    $services = ArticleServiceStatus::forCustomer($customerId)->get();

    expect($service->customer_id)->toBe($customerId)
        ->and($services)->toHaveCount(1)
        ->and($services->first()->id)->toBe($service->id);
});

it('casts status to enum', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    expect($service->status)->toBeInstanceOf(AgreementStatus::class)
        ->and($service->status)->toBe(AgreementStatus::ACTIVE);
});

it('retires the cancellation_type column — the original type survives in the cancellation metadata', function () {
    expect(Schema::hasColumn('article_service_status', 'cancellation_type'))->toBeFalse();

    $service = ArticleServiceStatus::factory()->create(['next_billing_date' => now()->addMonth()]);
    $service->cancel(CancellationType::IMMEDIATE);

    // The legacy @api event payload keeps the type; the closure metadata
    // carries it for the deferred-materialisation re-dispatch (ADR-015 §5).
    expect($service->fresh()->metadata['cancellation']['type'])->toBe(CancellationType::IMMEDIATE->value);
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
    $service = ArticleServiceStatus::factory()->create(['effective_price' => cents(2900)]);

    expect($service->effective_price->unscaledValue())->toBe(2900);
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
    $article = Article::factory()->service()->monthly(2900)->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
    ]);

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
    $article1 = Article::factory()->service()->monthly(2900)->create();
    $article2 = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->count(2)->create([
        'article_id'        => $article1->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
    ]);
    ArticleServiceStatus::factory()->count(3)->create([
        'article_id'        => $article2->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
    ]);

    $services = ArticleServiceStatus::forArticle($article1->id)->get();

    expect($services)->toHaveCount(2);
});

it('scopes with specific status', function () {
    ArticleServiceStatus::factory()->active()->count(2)->create();
    ArticleServiceStatus::factory()->suspended()->count(3)->create();

    $suspended = ArticleServiceStatus::withStatus(AgreementStatus::SUSPENDED)->get();

    expect($suspended)->toHaveCount(3);
});

it('can activate a service', function () {
    $service = ArticleServiceStatus::factory()->pending()->create();

    $service->activate();

    expect($service->fresh()->status)->toBe(AgreementStatus::ACTIVE);
});

it('can suspend a service through the close/suspend API', function () {
    $service = ArticleServiceStatus::factory()->active()->create();
    $cause   = AgreementCause::findActive(AgreementCause::SCOPE_SUSPENDED, 'unpaid');

    $service->suspend($cause, 'Payment failed');

    expect($service->fresh()->status)->toBe(AgreementStatus::SUSPENDED)
        ->and($service->fresh()->closure_cause_id)->toBe($cause->id)
        ->and($service->fresh()->metadata['suspension']['reason'])->toBe('Payment failed');
});

it('can cancel a service immediately (shim forwards to close with the legacy_cancellation cause)', function () {
    $legacyCause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');
    $service     = ArticleServiceStatus::factory()->active()->create([
        'next_billing_date' => now()->addMonth(),
    ]);

    $service->cancel(CancellationType::IMMEDIATE, 'Customer request');

    $fresh = $service->fresh();

    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($legacyCause->id)
        ->and($fresh->refund_unused)->toBeFalse()
        ->and($fresh->next_billing_date)->toBeNull()
        ->and($fresh->cancellation_effective_at->isToday())->toBeTrue()
        ->and($fresh->metadata['cancellation']['type'])->toBe(CancellationType::IMMEDIATE->value);
});

it('can cancel a service at end of period (scheduled close through the shim)', function () {
    $legacyCause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');
    $nextBilling = now()->addMonth();
    $service     = ArticleServiceStatus::factory()->active()->create([
        'next_billing_date' => $nextBilling,
    ]);

    $service->cancel(CancellationType::END_OF_PERIOD, 'Customer request');

    $fresh = $service->fresh();

    expect($fresh->status)->toBe(AgreementStatus::ACTIVE) // Stays active until the scheduled date
        ->and($fresh->close_scheduled_at->format('Y-m-d'))->toBe($nextBilling->format('Y-m-d'))
        ->and($fresh->closure_cause_id)->toBe($legacyCause->id)
        ->and($fresh->refund_unused)->toBeFalse()
        ->and($fresh->cancellation_effective_at->format('Y-m-d'))->toBe($nextBilling->format('Y-m-d'));
});

it('can cancel a service with notice period (scheduled close, refund flag kept)', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    $service->cancel(CancellationType::NOTICE_PERIOD, 'Customer request');

    $fresh = $service->fresh();

    expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
        ->and($fresh->close_scheduled_at)->not->toBeNull()
        ->and($fresh->metadata['cancellation']['type'])->toBe(CancellationType::NOTICE_PERIOD->value)
        ->and($fresh->refund_unused)->toBeTrue()
        ->and($fresh->cancellation_effective_at)->not->toBeNull();
});

it('can expire a service — expiry CLOSES with the fulfilment cause (ADR-015 §4.2 rewire)', function () {
    $service = ArticleServiceStatus::factory()->active()->create();

    $service->expire();

    $fulfilment = AgreementCause::query()->where('key', 'fulfilment')->firstOrFail();

    expect($service->fresh()->status)->toBe(AgreementStatus::CLOSED)
        ->and($service->fresh()->closure_cause_id)->toBe($fulfilment->id)
        ->and($service->fresh()->next_billing_date)->toBeNull();
});

it('calculates next billing date monthly', function () {
    $article = Article::factory()->monthly(2900)->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'next_billing_date' => Carbon::parse('2024-01-15'),
    ]);

    $nextDate = $service->calculateNextBillingDate();

    expect($nextDate->format('Y-m-d'))->toBe('2024-02-15');
});

it('calculates next billing date quarterly', function () {
    $article = Article::factory()->quarterly(7900)->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 3,
        'next_billing_date' => Carbon::parse('2024-01-15'),
    ]);

    $nextDate = $service->calculateNextBillingDate();

    expect($nextDate->format('Y-m-d'))->toBe('2024-04-15');
});

it('calculates next billing date yearly', function () {
    $article = Article::factory()->yearly(29000)->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'billing_unit'      => 'year', 'billing_quantity' => 1,
        'next_billing_date' => Carbon::parse('2024-01-15'),
    ]);

    $nextDate = $service->calculateNextBillingDate();

    expect($nextDate->format('Y-m-d'))->toBe('2025-01-15');
});

it('updates effective price from override', function () {
    $userModel = config('larabill.user_model', 'App\\Models\\User');
    $customer  = $userModel::factory()->create();
    $article   = Article::factory()->monthly(2900)->create();

    $override = ArticleOverride::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'custom_price' => cents(2400),
    ]);

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'effective_price'   => cents(2900),
    ]);

    $service->updateEffectivePrice();

    expect($service->fresh()->effective_price->unscaledValue())->toBe(2400)
        ->and($service->fresh()->current_override_id)->toBe($override->id);
});

it('updates effective price to base when no override', function () {
    $article = Article::factory()->monthly(2900)->create();

    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'billing_unit'      => 'month', 'billing_quantity' => 1,
        'effective_price'   => cents(2400),
    ]);

    $service->updateEffectivePrice();

    expect($service->fresh()->effective_price->unscaledValue())->toBe(2900)
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

it('resolves the closureCause relation (ADR-015 slice A, additive)', function () {
    $cause = AgreementCause::query()->where('key', 'fraud')->firstOrFail();

    $service = ArticleServiceStatus::factory()->create([
        'closure_cause_id' => $cause->id,
    ]);

    expect($service->closureCause->is($cause))->toBeTrue()
        ->and($service->closure_cause_id)->toBe($cause->id);
});

it('keeps closureCause nullable — a closed agreement without cause is a legal state (ADR-015 §2.2)', function () {
    $service = ArticleServiceStatus::factory()->create(['closure_cause_id' => null]);

    expect($service->closureCause)->toBeNull()
        ->and($service->closure_cause_id)->toBeNull();
});
