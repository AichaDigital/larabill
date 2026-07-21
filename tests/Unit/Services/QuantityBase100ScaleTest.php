<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression for AID-352: invoice line quantities are base-100 integers
 * (100 = 1.00 unit), as documented in README and TaxCalculationService.
 *
 * Two call sites wrote `1` (= 0.01 units) where a single unit was meant:
 * RecurringBillingService's generated line, and InvoiceService's default
 * when the caller omits `quantity`.
 */
beforeEach(function () {
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');
});

it('bills one full unit per recurring service line', function () {
    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'       => $customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addDays(7),
        'effective_price'   => cents(2900),
    ]);

    $results = (new RecurringBillingService(new PricingService))->processRecurringBilling(now());

    $item = Invoice::findOrFail($results['invoices'][0])->items->first();

    expect($item->quantity->unscaledValue())->toBe(100);
});

it('defaults an omitted quantity to one full unit', function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $customer = $this->userModel::factory()->create();
    $article  = Article::factory()->create(['tax_group_id' => null]);

    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $customer->id,
        'items'            => [
            [
                'article_id'  => $article->id,
                'base_price'  => 10000, // €100.00 in base100
                'description' => 'Line without an explicit quantity',
            ],
        ],
    ]);

    $item = $invoice->items->first();

    expect($item->quantity->unscaledValue())->toBe(100)
        ->and($item->taxable_amount->unscaledValue())->toBe(10000);
});
