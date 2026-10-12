<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * AID-1611 — the instance identifier is a LABEL, not an identity
 * (operator decision 2026-10-11, option A).
 *
 * The old `customer_article_instance_unique` index made the instance name an
 * identity across the agreement's WHOLE lifetime, so a closed (terminal)
 * agreement blocked re-contracting the same resource: a re-hire, a rescue
 * after the low, the plan change A→B→A and the WHMCS import all collided
 * with the closed row. larabill no longer enforces instance-name uniqueness
 * — a contract's identity is its row id (and, for imported agreements, the
 * UNIQUE `origin_key`). Whether one resource may hold two live contracts is
 * the vertical's/consumer's rule, not the package's (ADR-015 division of
 * responsibilities). The plan change A→B→A case lives with the service:
 * tests/Unit/Services/PlanChangeServiceTest.php.
 */

/**
 * Re-contract the SAME (customer, article, instance) triple as an existing
 * agreement — the exact key the retired UNIQUE index governed.
 */
function recontractSameInstance(ArticleServiceStatus $agreement): ArticleServiceStatus
{
    return ArticleServiceStatus::factory()->create([
        'customer_id'         => $agreement->customer_id,
        'article_id'          => $agreement->article_id,
        'instance_identifier' => $agreement->instance_identifier,
    ]);
}

it('re-contracts the same instance after the previous agreement is closed', function () {
    $closed = ArticleServiceStatus::factory()->create([
        'instance_identifier' => 'room-365.example.test',
    ]);
    $closed->close(AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'client_request'));

    $fresh = recontractSameInstance($closed->fresh());

    expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
        ->and($fresh->instance_identifier)->toBe('room-365.example.test');
});

it('allows two live contracts on the same instance name — the one-live-contract rule is the consumer\'s', function () {
    $first  = ArticleServiceStatus::factory()->create([
        'instance_identifier' => 'hosting.example.test',
    ]);
    $second = recontractSameInstance($first);

    expect(ArticleServiceStatus::query()
        ->where('instance_identifier', 'hosting.example.test')
        ->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(AgreementStatus::ACTIVE)
        ->and($second->fresh()->status)->toBe(AgreementStatus::ACTIVE);
});
