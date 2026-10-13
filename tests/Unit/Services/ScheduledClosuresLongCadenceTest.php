<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * AID-1613 — closure and refund invariance at long cadences (year/4-10).
 *
 * `processScheduledClosures()` materialises deferred closures for any
 * agreement regardless of cadence, and `calculateRefund()` proportions over
 * the period bounded by `BillingInterval::subtractFromDate()` /
 * `next_billing_date`. After the cutover, imported year/4-9 domain records
 * are live contracts: a long cadence must close, count and refund with the
 * same semantics as the legacy-representable tail.
 */

beforeEach(function () {
    Carbon::setTestNow(new Carbon('2028-06-01 10:00:00'));

    $this->lifecycle = new ServiceLifecycleService;
});

function longCadenceClosureAgreement(int $quantity, array $overrides = []): ArticleServiceStatus
{
    $article = Article::factory()->service()->monthly(2900)->create();

    return ArticleServiceStatus::factory()->create(array_merge([
        'article_id'        => $article->id,
        'billing_unit'      => 'year', 'billing_quantity' => $quantity,
        'status'            => AgreementStatus::ACTIVE,
        'next_billing_date' => new Carbon('2030-06-01'),
    ], $overrides));
}

it('materialises a scheduled closure for a long-cadence agreement with its stored cause', function () {
    Event::fake([ServiceClosed::class]);

    $cause   = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'fulfilment');
    $service = longCadenceClosureAgreement(4, [
        'close_scheduled_at' => now()->copy()->subDay(),
        'closure_cause_id'   => $cause->id,
    ]);

    $result = $this->lifecycle->processScheduledClosures();

    expect($result->materialised)->toBe(1);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($cause->id)
        ->and($fresh->next_billing_date)->toBeNull();

    Event::assertDispatched(ServiceClosed::class);
});

it('refunds the unused fraction of a long period proportionally, HalfUp to the cent', function (int $quantity, int $effectivePriceCents) {
    // Fixed clock: today sits mid-period. For a year/N agreement whose next
    // due is 2030-06-01, the last billing date is 2030-N-06-01, so:
    //   total = days(2030-N-06-01 → 2030-06-01), unused = days(2028-06-01 → 2030-06-01) = 731.
    // Expected = HalfUp(price * unused / total) in cents — the documented
    // proportional refund, computed here from Carbon arithmetic only.
    $service = longCadenceClosureAgreement($quantity, [
        'refund_unused'   => true,
        'effective_price' => cents($effectivePriceCents),
    ]);

    $today           = now()->startOfDay();
    $nextBillingDate = (new Carbon('2030-06-01'))->startOfDay();
    $lastBillingDate = $nextBillingDate->copy()->subYears($quantity);

    $totalDays  = (int) $lastBillingDate->diffInDays($nextBillingDate);
    $unusedDays = (int) $today->diffInDays($nextBillingDate);

    expect($unusedDays)->toBeGreaterThan(0)
        ->and($totalDays)->toBeGreaterThan($unusedDays);

    $expected = intdiv(2 * $effectivePriceCents * $unusedDays + $totalDays, 2 * $totalDays);

    expect($this->lifecycle->calculateRefund($service))->toBe($expected);
})->with([
    'year/4'  => [4, 2900],
    'year/5'  => [5, 5900],
    'year/6'  => [6, 2900],
    'year/7'  => [7, 9900],
    'year/8'  => [8, 2900],
    'year/9'  => [9, 4500],
    'year/10' => [10, 2900],
]);

it('refunds nothing when the long-cadence agreement does not allow unused refunds', function () {
    $service = longCadenceClosureAgreement(9, [
        'refund_unused'   => false,
        'effective_price' => cents(2900),
    ]);

    expect($this->lifecycle->calculateRefund($service))->toBeNull();
});
