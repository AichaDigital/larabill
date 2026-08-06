<?php

declare(strict_types=1);

use AichaDigital\Larabill\Actions\ProcessRecurringBilling;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\RecurringBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // AID-836: recurring emission goes through the canonical path — it
    // needs an active CompanyFiscalConfig and a fiscally identified receiver.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->makeCustomer = function () {
        $userModel = config('larabill.user_model', 'App\\Models\\User');
        $customer  = $userModel::factory()->create();

        UserTaxProfile::factory()->create([
            'owner_user_id' => $customer->id,
            'tax_id'        => 'ES'.fake()->numerify('B########'),
            'valid_from'    => now()->subYear(),
            'valid_until'   => null,
        ]);

        return $customer;
    };
});

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
    $customer  = ($this->makeCustomer)();
    $article   = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addDays(7),
    ]);

    $results = ProcessRecurringBilling::run();

    expect($results['processed'])->toBe(1)
        ->and($results['failed'])->toBe(0);
});

it('supports custom date parameter', function () {
    $customer  = ($this->makeCustomer)();
    $article   = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => Carbon::parse('2024-01-31'),
    ]);

    $results = ProcessRecurringBilling::run(Carbon::parse('2024-01-24'));

    expect($results['processed'])->toBe(1);
});

it('supports dry-run mode', function () {
    $customer  = ($this->makeCustomer)();
    $article   = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addDays(7),
    ]);

    $initialCount = Invoice::count();
    $results      = ProcessRecurringBilling::run(now(), true); // dry-run

    expect($results['processed'])->toBe(1)
        ->and($results['invoices'])->toHaveCount(0)
        ->and(Invoice::count())->toBe($initialCount);
});
