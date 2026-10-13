<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * AID-1613 — scheduling invariance at long cadences (year/4-10).
 *
 * The 7.0 cadence domain admits years 1-10 and the reference consumer
 * (clientes, MR !336) imports 4-, 5- and 9-year domain records at their
 * EXACT cadence. After the cutover these are live contracts: the recurrent
 * engine must schedule a year/9 agreement with exactly the same semantics
 * as a year/1 one — same window query, same advance math
 * (`BillingInterval::addToDate()`), same renewal clearing for closed terms.
 */

beforeEach(function () {
    Carbon::setTestNow(new Carbon('2026-03-01 10:00:00'));

    // AID-836: the recurring flow emits through the canonical path, which
    // requires an active CompanyFiscalConfig and a fiscally identified
    // receiver — exactly like any real consumer installation.
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->service   = new RecurringBillingService(new PricingService);
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');

    $this->makeLongCadenceCustomer = function () {
        $customer = $this->userModel::factory()->create();

        UserTaxProfile::factory()->create([
            'owner_user_id' => $customer->id,
            'tax_id'        => 'ES'.fake()->numerify('B########'),
            'valid_from'    => now()->subYear(),
            'valid_until'   => null,
        ]);

        return $customer;
    };
});

function longCadenceAgreement(string $customerId, int $quantity, array $overrides = []): ArticleServiceStatus
{
    $article = Article::factory()->monthly(2900)->create();

    return ArticleServiceStatus::factory()->create(array_merge([
        'customer_id'       => $customerId,
        'article_id'        => $article->id,
        'billing_unit'      => 'year', 'billing_quantity' => $quantity,
        'status'            => AgreementStatus::ACTIVE,
        'next_billing_date' => now()->copy(),
        'effective_price'   => cents(2900),
    ], $overrides));
}

describe('recurring emission and next-due advance at year/4-10', function () {
    it('emits on a due long-cadence agreement and advances the next due by exactly N years', function (int $quantity, string $expectedDate) {
        $customer = ($this->makeLongCadenceCustomer)();
        longCadenceAgreement($customer->id, $quantity);

        $results = $this->service->processRecurringBilling(now());

        expect($results['invoices'])->toHaveCount(1);

        $invoice = Invoice::findOrFail($results['invoices'][0]);
        expect($invoice->status)->toBe(InvoiceStatus::SENT);

        $agreement = ArticleServiceStatus::where('customer_id', $customer->id)->first();
        expect($agreement->next_billing_date?->toDateString())->toBe($expectedDate)
            ->and($agreement->status)->toBe(AgreementStatus::ACTIVE);
    })->with([
        'year/4'  => [4, '2030-03-01'],
        'year/5'  => [5, '2031-03-01'],
        'year/6'  => [6, '2032-03-01'],
        'year/7'  => [7, '2033-03-01'],
        'year/8'  => [8, '2034-03-01'],
        'year/9'  => [9, '2035-03-01'],
        'year/10' => [10, '2036-03-01'],
    ]);

    it('does not double-emit inside the same long period (the next due is years away)', function (int $quantity) {
        $customer = ($this->makeLongCadenceCustomer)();
        longCadenceAgreement($customer->id, $quantity);

        $this->service->processRecurringBilling(now());
        $results = $this->service->processRecurringBilling(now()->addMonths(6));

        expect($results['invoices'])->toBeEmpty();
    })->with([
        'year/4'  => [4],
        'year/9'  => [9],
        'year/10' => [10],
    ]);

    it('never bills a closed-term year/5 agreement whose period reaches beyond the term', function () {
        // ADR-015 §3.3 emission gate: a closed-term agreement is not billed
        // for a period that would extend past expires_at. At year/5 with a
        // +2-year term, the very next period (+5 years) reaches beyond it —
        // the engine refuses the emission quietly and the next due stays
        // where it was (clearing is the closure materialiser's business).
        $customer = ($this->makeLongCadenceCustomer)();
        longCadenceAgreement($customer->id, 5, [
            'expires_at' => now()->copy()->addYears(2),
        ]);

        $results = $this->service->processRecurringBilling(now());

        expect($results['invoices'])->toBeEmpty();

        $agreement = ArticleServiceStatus::where('customer_id', $customer->id)->first();
        expect($agreement->next_billing_date?->toDateString())->toBe('2026-03-01')
            ->and($agreement->status)->toBe(AgreementStatus::ACTIVE);
    });
});
