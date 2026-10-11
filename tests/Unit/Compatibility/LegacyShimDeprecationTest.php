<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Events\ServiceCancelled;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Services\ArticlePriceService;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * ADR-015 §5 — the deprecated shim layer emits `E_USER_DEPRECATED` on its
 * delegating public paths (the §5 mapping is the contract; shims are a
 * compatibility surface, not a second engine). Each test observes the notice
 * through a custom error handler — no `@`-suppression — and asserts the
 * forwarding result is unchanged: the shim must only ADD the notice.
 *
 * Internal code never calls these paths, so nothing internal emits.
 */

/**
 * Capture E_USER_DEPRECATED notices raised by $callback through a custom
 * error handler (Pest suite-global namespace: uniquely prefixed, AID-502).
 *
 * @param  callable(): mixed  $callback
 * @return array<int, string>
 */
function legacyShimDeprecations(callable $callback): array
{
    $notices = [];

    set_error_handler(function (int $errno, string $errstr) use (&$notices): bool {
        if ($errno === E_USER_DEPRECATED) {
            $notices[] = $errstr;

            return true;
        }

        return false;
    });

    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $notices;
}

it('emits E_USER_DEPRECATED and forwards on ArticleServiceStatus::cancel()', function () {
    Event::fake([ServiceCancelled::class, ServiceClosed::class]);

    $article = Article::factory()->service()->monthly(2900)->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addMonth(),
    ]);
    $legacyCause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');

    $notices = legacyShimDeprecations(
        fn () => $service->cancel(CancellationType::IMMEDIATE, 'Customer request'),
    );

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('ArticleServiceStatus::cancel()')
        ->toContain('UPGRADE-7.0.md');

    // Forwarding unchanged: same close path, seeded cause, legacy event.
    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($legacyCause->id);
    Event::assertDispatched(ServiceCancelled::class, fn ($event) => $event->type === CancellationType::IMMEDIATE);
    // The shim forwards through the close path, so the new-model event still fires.
    Event::assertDispatched(ServiceClosed::class);
});

it('emits E_USER_DEPRECATED and forwards on ServiceLifecycleService::cancel()', function () {
    Event::fake([ServiceCancelled::class, ServiceClosed::class]);

    $article = Article::factory()->service()->monthly(2900)->create();
    $service = ArticleServiceStatus::factory()->create([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addMonth(),
    ]);
    $legacyCause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');

    $notices = legacyShimDeprecations(
        fn () => (new ServiceLifecycleService)->cancel($service, CancellationType::IMMEDIATE, 'Customer request'),
    );

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('ServiceLifecycleService::cancel()')
        ->toContain('UPGRADE-7.0.md');

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($legacyCause->id);
    Event::assertDispatched(ServiceCancelled::class, fn ($event) => $event->type === CancellationType::IMMEDIATE);
    // The shim forwards through the close path, so the new-model event still fires.
    Event::assertDispatched(ServiceClosed::class);
});

it('emits E_USER_DEPRECATED and forwards on ArticlePriceService::setPrice()', function () {
    $article = Article::factory()->withoutPrices()->create();

    /** @var ArticlePrice|null $price */
    $price   = null;
    $notices = legacyShimDeprecations(function () use (&$price, $article): void {
        $price = app(ArticlePriceService::class)->setPrice(
            $article,
            BillingFrequency::MONTHLY,
            FixedDecimal::ofUnscaled(2900, 2),
        );
    });

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('setPrice()')
        ->toContain('setIntervalPrice()')
        ->toContain('UPGRADE-7.0.md')
        // Forwarding unchanged: the interval-keyed row is written.
        ->and($price)->toBeInstanceOf(ArticlePrice::class)
        ->and($price->billing_unit)->toBe('month')
        ->and($price->billing_quantity)->toBe(1)
        ->and($price->price->unscaledValue())->toBe(2900);
});

it('emits E_USER_DEPRECATED and forwards on PricingService::getEffectivePrice()', function () {
    $article = Article::factory()->service()->monthly(2900)->create();

    $effectivePrice = null;
    $notices        = legacyShimDeprecations(function () use (&$effectivePrice, $article): void {
        $effectivePrice = app(PricingService::class)->getEffectivePrice($article, BillingFrequency::MONTHLY, null);
    });

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('getEffectivePrice()')
        ->toContain('getEffectivePriceForInterval()')
        ->toContain('UPGRADE-7.0.md')
        ->and($effectivePrice)->toBe(2900.0);
});

it('emits E_USER_DEPRECATED and forwards on PricingService::createPricingDetails()', function () {
    $article = Article::factory()->service()->monthly(2900)->create();

    $details = null;
    $notices = legacyShimDeprecations(function () use (&$details, $article): void {
        $details = app(PricingService::class)->createPricingDetails($article, BillingFrequency::MONTHLY, null);
    });

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('createPricingDetails()')
        ->toContain('createPricingDetailsForInterval()')
        ->toContain('UPGRADE-7.0.md')
        ->and($details->basePrice)->toBe(2900.0);
});

it('emits E_USER_DEPRECATED and forwards on Article::isRecurring()', function () {
    $recurring    = Article::factory()->service()->monthly(2900)->create();
    $notRecurring = Article::factory()->service()->withoutPrices()->create();

    $isRecurring   = null;
    $notices       = legacyShimDeprecations(function () use (&$isRecurring, $recurring, $notRecurring): void {
        $isRecurring = $recurring->isRecurring() && ! $notRecurring->isRecurring();
    });

    expect($notices)->toHaveCount(2)
        ->and($notices[0])->toContain('isRecurring()')
        ->toContain('UPGRADE-7.0.md')
        ->and($isRecurring)->toBeTrue();
});

it('emits E_USER_DEPRECATED on the Article::recurring() scope', function () {
    Article::factory()->service()->monthly(2900)->create();
    Article::factory()->service()->withoutPrices()->create();

    $notices = legacyShimDeprecations(
        fn () => Article::query()->recurring()->count(),
    );

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toContain('scopeRecurring()')
        ->toContain('UPGRADE-7.0.md');
});

it('emits E_USER_DEPRECATED and forwards on ArticleOverride::getDiscountAmount()/getDiscountPercentage()', function () {
    $article  = Article::factory()->service()->monthly(2900)->create();
    $override = ArticleOverride::factory()->forArticle($article)->withDiscount(10)->create();

    $amount     = null;
    $percentage = null;
    $notices    = legacyShimDeprecations(function () use (&$amount, &$percentage, $override): void {
        $amount     = $override->getDiscountAmount(BillingFrequency::MONTHLY);
        $percentage = $override->getDiscountPercentage(BillingFrequency::MONTHLY);
    });

    expect($notices)->toHaveCount(2)
        ->and($notices[0])->toContain('getDiscountAmount()')
        ->toContain('UPGRADE-7.0.md')
        // Forwarding unchanged: 10% of 2900 (base-100 cents read).
        ->and($amount)->toBe(290.0)
        ->and($percentage)->toBe(10);
});
