<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Events\ServiceCancelled;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * ADR-014 Enmienda 1 convergence (ADR-015 §3.2, §5) — the two legacy
 * `cancel()` implementations (`ArticleServiceStatus::cancel()` and
 * `ServiceLifecycleService::cancel()`) now forward to the SAME close path
 * with the seeded `legacy_cancellation` cause, and BOTH dispatch the legacy
 * `@api` `ServiceCancelled` event with the original `CancellationType`.
 *
 * The per-path timing (gate round 8, P1 #1) is pinned verbatim: the model
 * path reads `metadata.service.cancellation_policy.notice_days`, the service
 * path reads `metadata.notice_period_days` — there is no abstracted "legacy
 * notice computation". The third replica site (the factory `cancelled()`
 * state) aligns to the same semantics.
 */

beforeEach(function () {
    $this->lifecycle   = new ServiceLifecycleService;
    $this->legacyCause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');
});

function convergenceArticle(array $metadata = []): Article
{
    return Article::factory()->service()->monthly(2900)->create(['metadata' => $metadata]);
}

it('forwards both cancel() implementations to the same close path with the legacy_cancellation cause (immediate)', function () {
    Event::fake([ServiceCancelled::class, ServiceClosed::class]);

    $article = convergenceArticle();

    $viaModel = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addMonth(),
    ]);
    $viaService = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addMonth(),
    ]);

    $viaModel->cancel(CancellationType::IMMEDIATE, 'Customer request');
    $this->lifecycle->cancel($viaService, CancellationType::IMMEDIATE, 'Customer request');

    // One route, one shape: closed + legacy_cancellation, same date semantics.
    $modelRow   = $viaModel->fresh();
    $serviceRow = $viaService->fresh();

    expect($modelRow->status)->toBe(AgreementStatus::CLOSED)
        ->and($serviceRow->status)->toBe(AgreementStatus::CLOSED)
        ->and($modelRow->closure_cause_id)->toBe($this->legacyCause->id)
        ->and($serviceRow->closure_cause_id)->toBe($this->legacyCause->id)
        ->and($modelRow->next_billing_date)->toBeNull()
        ->and($serviceRow->next_billing_date)->toBeNull()
        ->and($modelRow->cancellation_effective_at->isToday())->toBeTrue()
        ->and($serviceRow->cancellation_effective_at->isToday())->toBeTrue()
        // The original CancellationType survives in the metadata — no column,
        // no invented commercial cause (§2.1, §2.2).
        ->and($modelRow->metadata['cancellation']['type'])->toBe(CancellationType::IMMEDIATE->value)
        ->and($serviceRow->metadata['cancellation']['type'])->toBe(CancellationType::IMMEDIATE->value);

    // BOTH paths dispatch the legacy @api event with the original type…
    Event::assertDispatched(ServiceCancelled::class, 2);
    Event::assertDispatched(ServiceCancelled::class, function ($event) use ($viaModel) {
        return $event->service->id === $viaModel->id && $event->type === CancellationType::IMMEDIATE;
    });
    Event::assertDispatched(ServiceCancelled::class, function ($event) use ($viaService) {
        return $event->service->id === $viaService->id && $event->type === CancellationType::IMMEDIATE;
    });
    // …and the close path itself dispatches the new event.
    Event::assertDispatched(ServiceClosed::class, 2);
});

it('schedules the close on END_OF_PERIOD for both paths: stays active with close_scheduled_at', function () {
    Event::fake([ServiceCancelled::class]);

    $article   = convergenceArticle();
    $nextMonth = now()->addMonth();

    $viaModel = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => $nextMonth,
    ]);
    $viaService = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => $nextMonth,
    ]);

    $viaModel->cancel(CancellationType::END_OF_PERIOD, 'End of term');
    $this->lifecycle->cancel($viaService, CancellationType::END_OF_PERIOD);

    $modelRow   = $viaModel->fresh();
    $serviceRow = $viaService->fresh();

    foreach ([$modelRow, $serviceRow] as $row) {
        expect($row->status)->toBe(AgreementStatus::ACTIVE) // stays active until the scheduled date
            ->and($row->close_scheduled_at->toDateString())->toBe($nextMonth->toDateString())
            ->and($row->closure_cause_id)->toBe($this->legacyCause->id)
            ->and($row->cancellation_effective_at->toDateString())->toBe($nextMonth->toDateString())
            ->and($row->metadata['cancellation']['type'])->toBe(CancellationType::END_OF_PERIOD->value);
    }

    Event::assertDispatched(ServiceCancelled::class, 2);
});

it('preserves each path\'s OWN legacy notice computation verbatim (gate round 8, P1 #1)', function () {
    // Both metadata routes seeded with DIFFERENT values: the model path must
    // read service.cancellation_policy.notice_days (5), the service path
    // must read notice_period_days (40). No shared abstraction.
    $article = convergenceArticle([
        'service'            => ['cancellation_policy' => ['notice_days' => 5]],
        'notice_period_days' => 40,
    ]);

    $viaModel   = ArticleServiceStatus::factory()->create(['article_id' => $article->id]);
    $viaService = ArticleServiceStatus::factory()->create(['article_id' => $article->id]);

    $viaModel->cancel(CancellationType::NOTICE_PERIOD, 'Notice');
    $this->lifecycle->cancel($viaService, CancellationType::NOTICE_PERIOD);

    expect($viaModel->fresh()->close_scheduled_at->toDateString())->toBe(now()->addDays(5)->toDateString())
        ->and($viaService->fresh()->close_scheduled_at->toDateString())->toBe(now()->addDays(40)->toDateString())
        ->and($viaModel->fresh()->closure_cause_id)->toBe($this->legacyCause->id)
        ->and($viaService->fresh()->closure_cause_id)->toBe($this->legacyCause->id);
});

it('materialises a deferred closure from the shim path with the stored cause and dispatches the legacy event', function () {
    Event::fake([ServiceCancelled::class, ServiceClosed::class]);

    $article = convergenceArticle();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addDays(5),
    ]);

    // Schedule via the shim, then travel past the scheduled date.
    $service->cancel(CancellationType::END_OF_PERIOD, 'End of term');
    $this->travel(10)->days();

    $result = (new ServiceLifecycleService)->processScheduledClosures();

    expect($result->materialised)->toBe(1);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($this->legacyCause->id)
        // The materialiser clears the schedule once it has been honoured.
        ->and($fresh->close_scheduled_at)->toBeNull();

    // On materialisation too (§5, gate round 10 P1): the shim path dispatched
    // the legacy event at request time AND the scheduler re-dispatches it —
    // existing listeners keep receiving their cancellations.
    Event::assertDispatched(ServiceCancelled::class, 2);
    Event::assertDispatched(ServiceClosed::class, 1); // the materialisation only
});

it('aligns the factory cancelled() state (third replica site) to the same semantics', function () {
    $row = ArticleServiceStatus::factory()->cancelled(CancellationType::NOTICE_PERIOD)->create();

    expect($row->status)->toBe(AgreementStatus::CLOSED)
        ->and($row->closure_cause_id)->toBe($this->legacyCause->id)
        ->and($row->metadata['cancellation']['type'])->toBe(CancellationType::NOTICE_PERIOD->value)
        ->and($row->refund_unused)->toBeTrue(); // NOTICE_PERIOD requires refund
});
