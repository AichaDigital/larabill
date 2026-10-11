<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementModality;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\RecurringBillingService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ADR-015 slice 1 (AID-953) — modality + renewal behaviour on the emission
 * gate and the scheduling advance (spec §3.2 tail, §3.3):
 *
 *   - `non_billable` modality never emits (a legitimate state, refused
 *     quietly — §2.5 Free Account import);
 *   - `one_time` emits exactly once AT CONTRACTING and the recurrent engine
 *     never re-bills it: the NULL cadence is outside the engine's domain,
 *     and reaching the gate is a data-contract violation that fails loud;
 *   - `renewal = closed_term`: the gate never bills a period reaching
 *     beyond `expires_at` (the same shape as the T2 `close_scheduled_at`
 *     exclusion), and the engine never advances `next_billing_date` past
 *     `expires_at`; expiry closure itself stays with T2's single locked
 *     materialiser (`processScheduledClosures()` — not duplicated here);
 *   - `renewal = auto` advances exactly as today (scheduling invariance).
 */

function modalityAgreement(array $attributes = []): ArticleServiceStatus
{
    $article = Article::factory()->service()->monthly(2900)->create();

    return ArticleServiceStatus::factory()->create(array_merge([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addDay(),
    ], $attributes));
}

/**
 * Read-only probe exposing the engine's private emission gate — the gate
 * decision is the contract under test, not the invoicing (same reflection
 * anchor style as ScheduledClosuresTest).
 */
function modalityEmissionGate(ArticleServiceStatus $service): bool
{
    $engine = app(RecurringBillingService::class);
    $gate   = new ReflectionMethod($engine, 'shouldGenerateInvoice');

    return (bool) $gate->invoke($engine, $service, now());
}

/**
 * Read-only probe for the protected advance, so the renewal branch is
 * exercised in isolation from the full emission boundary.
 */
function modalityAdvance(ArticleServiceStatus $service): void
{
    $engine  = app(RecurringBillingService::class);
    $advance = new ReflectionMethod($engine, 'updateNextBillingDate');

    $advance->invoke($engine, $service);
}

it('never bills a non_billable agreement (modality gate, §3.2 tail)', function () {
    // In-window and due on purpose: the refusal must come from the gate,
    // not from the window query (§2.5 Free Account import contract).
    $service = modalityAgreement([
        'modality' => AgreementModality::NON_BILLABLE,
    ]);

    expect($service->modality)->toBe(AgreementModality::NON_BILLABLE)
        ->and($service->isRecurring())->toBeFalse()
        ->and(modalityEmissionGate($service))->toBeFalse();
});

it('keeps a healthy one_time agreement outside the engine entirely (emits exactly once at contracting)', function () {
    // Healthy shape: a one-time agreement carries no next_billing_date —
    // the contracting emission is not the recurrent engine's business.
    $service = modalityAgreement([
        'modality'          => AgreementModality::ONE_TIME,
        'billing_unit'      => null,
        'billing_quantity'  => null,
        'next_billing_date' => null,
    ]);

    $results = app(RecurringBillingService::class)->processRecurringBilling(now());

    expect($results['processed'])->toBe(0)
        ->and($results['skipped'])->toBe(0)
        ->and($results['failed'])->toBe(0)
        ->and($service->fresh()->next_billing_date)->toBeNull()
        ->and($service->fresh()->isRecurring())->toBeFalse();
});

it('fails loud when a one_time agreement reaches the recurrent engine (corrupt data contract)', function () {
    // A one_time agreement WITH a next_billing_date can only come from a
    // consumer violating the contracting contract: the NULL cadence is
    // outside the engine's domain and the violation is never approximated.
    $service = modalityAgreement([
        'modality'         => AgreementModality::ONE_TIME,
        'billing_unit'     => null,
        'billing_quantity' => null,
    ]);

    expect(fn () => modalityEmissionGate($service))->toThrow(RuntimeException::class);
});

it('also fails loud on a NULL cadence regardless of the stored modality value', function () {
    // Post-migration the backfill makes `one_time ⟺ NULL cadence`, but no
    // DB constraint enforces the pair: a raw recurring row with NULL
    // cadence is equally unschedulable and equally loud.
    $service = modalityAgreement([
        'modality'         => AgreementModality::RECURRING,
        'billing_unit'     => null,
        'billing_quantity' => null,
    ]);

    expect(fn () => modalityEmissionGate($service))->toThrow(RuntimeException::class);
});

it('refuses a closed_term period reaching beyond expires_at, and still bills one that ends inside the term (§3.3)', function () {
    // Monthly cadence: the period starting tomorrow ends one month out —
    // beyond an expiry next week.
    $beyond = modalityAgreement(['renewal' => 'closed_term', 'expires_at' => now()->addWeek()]);

    // Same cadence, term two months out: the period ends inside the term.
    $within = modalityAgreement(['renewal' => 'closed_term', 'expires_at' => now()->addMonths(2)]);

    // Boundary: the period ends EXACTLY at expires_at — reaching beyond is
    // strict, so the last period ending on the term date is still billed.
    $boundary = modalityAgreement(['renewal' => 'closed_term', 'expires_at' => now()->addMonth()]);

    expect(modalityEmissionGate($beyond))->toBeFalse()
        ->and(modalityEmissionGate($within))->toBeTrue()
        ->and(modalityEmissionGate($boundary))->toBeTrue();
});

it('composes the closed-term exclusion with the T2 scheduled-close exclusion (either refuses)', function () {
    // Schedule two months out (alone it would still bill this period);
    // expiry next week refuses — and vice versa.
    $expiryRefuses = modalityAgreement([
        'renewal'            => 'closed_term',
        'close_scheduled_at' => now()->addMonths(2),
        'expires_at'         => now()->addWeek(),
    ]);

    $scheduleRefuses = modalityAgreement([
        'renewal'            => 'closed_term',
        'close_scheduled_at' => now()->addWeek(),
        'expires_at'         => now()->addMonths(2),
    ]);

    expect(modalityEmissionGate($expiryRefuses))->toBeFalse()
        ->and(modalityEmissionGate($scheduleRefuses))->toBeFalse();
});

it('advances an auto agreement exactly as today (scheduling invariance anchor)', function () {
    $service  = modalityAgreement(['renewal' => 'auto']);
    $expected = $service->next_billing_date->copy()->addMonth()->toDateString();

    modalityAdvance($service);

    expect($service->fresh()->next_billing_date->toDateString())->toBe($expected)
        ->and($service->fresh()->renewal)->toBe('auto');
});

it('never advances a closed_term agreement past expires_at (§3.3)', function () {
    // The computed next period start would fall beyond the term end: no
    // further billable period exists, so the advance clears
    // next_billing_date instead of planting a phantom date past the term.
    $past = modalityAgreement(['renewal' => 'closed_term', 'expires_at' => now()->addWeek()]);
    modalityAdvance($past);
    expect($past->fresh()->next_billing_date)->toBeNull();

    // A next period that still fits inside the term advances normally.
    $within   = modalityAgreement(['renewal' => 'closed_term', 'expires_at' => now()->addMonths(2)]);
    $expected = $within->next_billing_date->copy()->addMonth()->toDateString();
    modalityAdvance($within);
    expect($within->fresh()->next_billing_date->toDateString())->toBe($expected);
});

it('emits the last billable period of a closed_term agreement through the full boundary and leaves no phantom date', function () {
    // Full emission machinery (canonical path): receiver profile, fiscal
    // config, series — same anchors as RecurringBillingEmissionTest.
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $customer = TestUser::factory()->create();

    UserTaxProfile::factory()->create([
        'owner_user_id' => $customer->id,
        'tax_id'        => 'ES12345678Z',
        'valid_from'    => now()->subYear(),
        'valid_until'   => null,
    ]);

    // The last period that fits the term: it starts two months ago and
    // ends EXACTLY at expires_at (reaching beyond is strict, so the period
    // ending on the term date is still billed) — and the computed next
    // start falls one day PAST the term, so the real boundary must clear
    // the date instead of planting a phantom billing day beyond expires_at.
    $nextBilling = now()->subMonths(2)->startOfDay();

    $service = modalityAgreement([
        'customer_id'       => $customer->id,
        'renewal'           => 'closed_term',
        'expires_at'        => $nextBilling->copy()->addMonth()->subDay(),
        'next_billing_date' => $nextBilling,
    ]);

    $results = app(RecurringBillingService::class)->processRecurringBilling(now());

    expect($results['processed'])->toBe(1)
        ->and($results['failed'])->toBe(0)
        // Expiry closure is T2's materialiser's business — the engine only
        // refuses to schedule anything beyond the term.
        ->and($service->fresh()->status)->toBe(AgreementStatus::ACTIVE)
        ->and($service->fresh()->next_billing_date)->toBeNull();
});
