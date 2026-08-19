<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use AichaDigital\Larabill\Services\ServiceLifecycleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-956 D12 — settling unconsumed time measures on the EMITTED line.
 *
 * A contract revision is a legitimate act (D3) and only affects periods not
 * yet emitted (D4). That promise is false unless the settlement of an already
 * emitted period measures on what was actually invoiced, not on the price the
 * contract carries today.
 *
 * The artefact that documents the settlement, and whether there is one at all,
 * is NOT decided here — see docs/ADR-014.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->customer = TestUser::factory()->create();

    UserTaxProfile::factory()->create([
        'owner_user_id' => $this->customer->id,
        'tax_id'        => 'ES12345678Z',
        'valid_from'    => now()->subYear(),
        'valid_until'   => null,
    ]);

    $this->lifecycle = new ServiceLifecycleService;
});

it('settles on the amount actually invoiced, not on a price revised afterwards', function () {
    // The spec scenario, in euros: a period is invoiced at 24.00; the consumer
    // then revises the contract to 99.00 (legitimate, D3); the customer
    // cancels inside the already invoiced period. What must be refunded is a
    // share of what the customer PAID.
    $this->travelTo('2026-02-01');

    $article = Article::factory()->monthly(2400)->create(['tax_group_id' => null]);

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'         => $this->customer->id,
        'article_id'          => $article->id,
        'billing_frequency'   => BillingFrequency::MONTHLY,
        'status'              => ServiceStatus::ACTIVE,
        'next_billing_date'   => now(),
        'effective_price'     => cents(2400),
        'current_override_id' => null,
    ]);

    $results = (new RecurringBillingService(new PricingService))->processRecurringBilling(now());
    expect($results['invoices'])->toHaveCount(1);

    // The revision lands AFTER the period was invoiced.
    $article->prices()->firstOrFail()->update(['price' => cents(9900)]);
    $service->refresh()->updateEffectivePrice();
    $service->update(['refund_unused' => true]);

    expect($service->fresh()->effective_price->unscaledValue())->toBe(9900);

    // Day 11 of a 28-day period: 18 unused days.
    $this->travelTo('2026-02-11');

    // 2400 * 18 / 28 = 1542.86 -> 1543. Measured on the revised price it would
    // be 9900 * 18 / 28 = 6364, money the customer never paid.
    expect($this->lifecycle->calculateRefund($service->fresh()))->toBe(1543);
});

it('falls back to the contract price when no emitted line covers the period', function () {
    // A service imported from a previous system: larabill never invoiced that
    // period, so there is no line to measure on. This is an indicative figure
    // for the consumer, not a fiscal document, so it does not throw.
    $this->travelTo('2026-02-11');

    $article = Article::factory()->monthly(2400)->create(['tax_group_id' => null]);

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $this->customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => '2026-03-01',
        'effective_price'   => cents(2400),
        'refund_unused'     => true,
    ]);

    expect($this->lifecycle->calculateRefund($service))->toBe(1543);
});
