<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\InvalidBillingIntervalException;
use AichaDigital\Larabill\Exceptions\LegacyCadenceUnsupportedException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Services\ArticlePriceService;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ADR-015 §5 / STABILITY.md §3 — the ONE qualified narrowing of the 7.0 shim
 * layer (gate rounds 2-3): `WEEKLY` (1) and `BIWEEKLY` (2) inputs reaching the
 * legacy bridge throw the typed `LegacyCadenceUnsupportedException` instead of
 * forwarding. Every legacy-typed path funnels through
 * `BillingInterval::fromLegacyFrequency()`, so the bridge is the single choke
 * point; the is-a compatibility (`extends InvalidBillingIntervalException`)
 * keeps existing catch-sites working. Out-of-domain values (outside 0-9) keep
 * the plain `InvalidBillingIntervalException`.
 */

it('throws the typed exception for WEEKLY via the legacy bridge', function () {
    BillingInterval::fromLegacyFrequency(BillingFrequency::WEEKLY->value);
})->throws(LegacyCadenceUnsupportedException::class);

it('throws the typed exception for BIWEEKLY via the legacy bridge', function () {
    BillingInterval::fromLegacyFrequency(BillingFrequency::BIWEEKLY->value);
})->throws(LegacyCadenceUnsupportedException::class);

it('keeps is-a compatibility with InvalidBillingIntervalException', function () {
    try {
        BillingInterval::fromLegacyFrequency(BillingFrequency::WEEKLY->value);
        $this->fail('Expected LegacyCadenceUnsupportedException');
    } catch (InvalidBillingIntervalException $e) {
        expect($e)->toBeInstanceOf(LegacyCadenceUnsupportedException::class);
    }
});

it('carries the STABILITY §3 remediation text', function () {
    $message = LegacyCadenceUnsupportedException::forLegacyFrequency(BillingFrequency::WEEKLY->value)->getMessage();

    expect($message)
        ->toContain('1-12')
        ->toContain('1-10')
        ->toContain('AID-895')
        ->toContain('preflight')
        ->toContain('UPGRADE-7.0.md')
        ->toContain('weekly');
});

it('throws through the setPrice() legacy path for WEEKLY', function () {
    $article = Article::factory()->withoutPrices()->create();
    $service = app(ArticlePriceService::class);

    $service->setPrice($article, BillingFrequency::WEEKLY, FixedDecimal::ofUnscaled(100, 2));
})->throws(LegacyCadenceUnsupportedException::class);

it('throws through the Article::getPriceFor() legacy path for WEEKLY and BIWEEKLY', function (BillingFrequency $frequency) {
    $article = Article::factory()->withoutPrices()->create();

    $article->getPriceFor($frequency);
})->throws(LegacyCadenceUnsupportedException::class)->with([
    'WEEKLY'   => BillingFrequency::WEEKLY,
    'BIWEEKLY' => BillingFrequency::BIWEEKLY,
]);

it('keeps the plain InvalidBillingIntervalException for out-of-domain values', function (int $frequency) {
    try {
        BillingInterval::fromLegacyFrequency($frequency);
        $this->fail('Expected InvalidBillingIntervalException');
    } catch (InvalidBillingIntervalException $e) {
        expect($e)->not->toBeInstanceOf(LegacyCadenceUnsupportedException::class);
    }
})->with([
    'out of domain above' => [10],
    'out of domain below' => [-1],
]);
