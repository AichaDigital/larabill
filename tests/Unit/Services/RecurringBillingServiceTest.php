<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{BillingFrequency, ServiceStatus};
use AichaDigital\Larabill\Models\{Article, ArticlePrice, ArticleServiceStatus, Invoice};
use AichaDigital\Larabill\Services\{PricingService, RecurringBillingService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service   = new RecurringBillingService(new PricingService);
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');
});

describe('RecurringBillingService basic processing', function () {
    it('processes recurring billing for services due', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()
            ->monthly(2900)
            ->create();

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::ACTIVE,
            'next_billing_date' => now()->addDays(7), // Due in 7 days
            'effective_price'   => 2900,
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['processed'])->toBe(1)
            ->and($results['failed'])->toBe(0)
            ->and($results['invoices'])->toHaveCount(1);
    });

    it('skips services not due yet', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::ACTIVE,
            'next_billing_date' => now()->addDays(30), // Not due yet
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['processed'])->toBe(0)
            ->and($results['skipped'])->toBe(1)
            ->and($results['invoices'])->toHaveCount(0);
    });

    it('supports dry-run mode without creating invoices', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => now()->addDays(7),
        ]);

        $initialCount = Invoice::count();
        $results      = $this->service->processRecurringBilling(now(), true);

        expect($results['processed'])->toBe(1)
            ->and($results['invoices'])->toHaveCount(0)
            ->and(Invoice::count())->toBe($initialCount);
    });

    it('only processes billable services', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        // Active service (should be processed)
        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::ACTIVE,
            'next_billing_date' => now()->addDays(7),
        ]);

        // Cancelled service (should NOT be processed)
        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::CANCELLED,
            'next_billing_date' => now()->addDays(7),
        ]);

        // Suspended service (should NOT be processed)
        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::SUSPENDED,
            'next_billing_date' => now()->addDays(7),
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['processed'])->toBe(1);
    });
});

describe('RecurringBillingService days_in_advance configuration', function () {
    it('respects global days_in_advance configuration', function () {
        config(['larabill.recurring_billing.days_in_advance' => 7]);

        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => now()->addDays(7),
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['processed'])->toBe(1);
    });

    it('respects ArticlePrice-specific days_in_advance override', function () {
        config(['larabill.recurring_billing.days_in_advance' => 7]);

        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->withoutPrices()->create();

        // Create price with specific days_in_advance
        ArticlePrice::factory()->for($article)->create([
            'billing_frequency'       => BillingFrequency::MONTHLY,
            'price'                   => 2900,
            'billing_days_in_advance' => 15,
        ]);

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => now()->addDays(15),
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['processed'])->toBe(1);
    });
});

describe('RecurringBillingService billing period calculations', function () {
    it('calculates monthly billing period correctly', function () {
        $customer        = $this->userModel::factory()->create();
        $article         = Article::factory()->monthly(2900)->create();
        $nextBillingDate = Carbon::parse('2024-01-15');

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => $nextBillingDate,
        ]);

        $this->service->processRecurringBilling(Carbon::parse('2024-01-08'));

        $invoice = Invoice::latest()->first();
        expect($invoice)->not->toBeNull();

        $item = $invoice->items()->first();
        expect($item->service_date_from->toDateString())->toBe('2024-01-15')
            ->and($item->service_date_to->toDateString())->toBe('2024-02-14');
    });

    it('calculates quarterly billing period correctly', function () {
        $customer        = $this->userModel::factory()->create();
        $article         = Article::factory()->quarterly(7900)->create();
        $nextBillingDate = Carbon::parse('2024-01-01');

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::QUARTERLY,
            'next_billing_date' => $nextBillingDate,
        ]);

        $this->service->processRecurringBilling(now());

        $invoice = Invoice::latest()->first();
        $item    = $invoice->items()->first();

        expect($item->service_date_from->toDateString())->toBe('2024-01-01')
            ->and($item->service_date_to->toDateString())->toBe('2024-03-31');
    });

    it('calculates yearly billing period correctly', function () {
        $customer        = $this->userModel::factory()->create();
        $article         = Article::factory()->yearly(29000)->create();
        $nextBillingDate = Carbon::parse('2024-01-15');

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::YEARLY,
            'next_billing_date' => $nextBillingDate,
        ]);

        $this->service->processRecurringBilling(Carbon::parse('2024-01-08'));

        $invoice = Invoice::latest()->first();
        $item    = $invoice->items()->first();

        expect($item->service_date_from->toDateString())->toBe('2024-01-15')
            ->and($item->service_date_to->toDateString())->toBe('2025-01-14');
    });

    it('calculates weekly billing period correctly', function () {
        $customer        = $this->userModel::factory()->create();
        $article         = Article::factory()->create();

        ArticlePrice::factory()->for($article)->weekly()->create(['price' => 500]);

        $nextBillingDate = Carbon::parse('2024-01-15');

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::WEEKLY,
            'next_billing_date' => $nextBillingDate,
        ]);

        $this->service->processRecurringBilling(Carbon::parse('2024-01-08'));

        $invoice = Invoice::latest()->first();
        $item    = $invoice->items()->first();

        expect($item->service_date_from->toDateString())->toBe('2024-01-15')
            ->and($item->service_date_to->toDateString())->toBe('2024-01-21');
    });
});

describe('RecurringBillingService next_billing_date updates', function () {
    it('updates next_billing_date after invoice creation', function () {
        $customer     = $this->userModel::factory()->create();
        $article      = Article::factory()->monthly(2900)->create();
        $originalDate = Carbon::parse('2024-01-15');

        $service = ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => $originalDate,
        ]);

        $this->service->processRecurringBilling(Carbon::parse('2024-01-08'));

        $service->refresh();

        expect($service->next_billing_date->toDateString())->toBe('2024-02-15');
    });
});

describe('RecurringBillingService pricing', function () {
    it('applies customer price overrides to invoices', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        \AichaDigital\Larabill\Models\ArticleOverride::factory()->create([
            'article_id'   => $article->id,
            'customer_id'  => $customer->id,
            'custom_price' => 2400,
            'is_active'    => true,
        ]);

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => now()->addDays(7),
        ]);

        $this->service->processRecurringBilling(now());

        $invoice = Invoice::latest()->first();
        $item    = $invoice->items()->first();

        expect($item->unit_price)->toBe(2400);
    });
});

describe('RecurringBillingService metadata', function () {
    it('creates invoice with comprehensive metadata', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        $service = ArticleServiceStatus::factory()->create([
            'customer_id'         => $customer->id,
            'article_id'          => $article->id,
            'billing_frequency'   => BillingFrequency::MONTHLY,
            'instance_identifier' => 'example.com',
            'next_billing_date'   => now()->addDays(7),
        ]);

        $this->service->processRecurringBilling(now());

        $invoice = Invoice::latest()->first();
        $item    = $invoice->items()->first();

        expect($item->metadata)->toHaveKeys([
            'source_reference',
            'pricing_details',
            'billing_details',
        ])
            ->and($item->metadata['source_reference']['type'])->toBe('article_service')
            ->and($item->metadata['source_reference']['article_id'])->toBe($article->id)
            ->and($item->metadata['source_reference']['service_status_id'])->toBe($service->id)
            ->and($item->metadata['source_reference']['instance_identifier'])->toBe('example.com');
    });
});

describe('RecurringBillingService error handling', function () {
    it('handles errors gracefully and continues processing', function () {
        $customer = $this->userModel::factory()->create();
        $article  = Article::factory()->monthly(2900)->create();

        // Create a service that will fail (invalid data)
        ArticleServiceStatus::factory()->create([
            'customer_id'       => 99999, // Non-existent customer
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => now()->addDays(7),
        ]);

        // Create a valid service
        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'next_billing_date' => now()->addDays(7),
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['processed'])->toBeGreaterThan(0)
            ->and($results['failed'])->toBeGreaterThan(0)
            ->and($results['errors'])->not->toBeEmpty();
    });
});
