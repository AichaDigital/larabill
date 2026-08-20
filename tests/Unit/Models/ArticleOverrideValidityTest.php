<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-974 D2bis: valid_from/valid_to are `date` columns. The window is
 * INCLUSIVE and day-grain at both ends.
 */
beforeEach(function () {
    $this->article  = Article::factory()->withoutPrices()->create();
    $this->customer = TestUser::factory()->create();
});

/** Seed an active override for the given range. */
function aid974SeedOverride(Article $article, string|int $customerId, ?string $from, ?string $to): ArticleOverride
{
    return ArticleOverride::factory()->for($article)->create([
        'customer_id'  => $customerId,
        'custom_price' => FixedDecimal::ofUnscaled(2400, 2),
        'valid_from'   => $from,
        'valid_to'     => $to,
        'is_active'    => true,
    ]);
}

it('keeps an override valid for the whole of its last day', function () {
    aid974SeedOverride($this->article, $this->customer->id, null, '2026-08-19');

    // 21:08 on the last day: still in force. Before the fix both MySQL and
    // SQLite reported EXPIRED here, and for different reasons.
    $found = ArticleOverride::query()
        ->validAt(Carbon::parse('2026-08-19 21:08:00'))
        ->get();

    expect($found)->toHaveCount(1);
});

it('keeps an override valid from the very start of its first day', function () {
    aid974SeedOverride($this->article, $this->customer->id, '2026-08-19', null);

    expect(ArticleOverride::query()->validAt(Carbon::parse('2026-08-19 00:00:01'))->get())->toHaveCount(1);
});

it('expires an override the day after its last day', function () {
    aid974SeedOverride($this->article, $this->customer->id, null, '2026-08-19');

    expect(ArticleOverride::query()->validAt(Carbon::parse('2026-08-20 00:00:01'))->get())->toBeEmpty();
});

it('agrees with the model predicate on all three borders', function () {
    $override = aid974SeedOverride($this->article, $this->customer->id, '2026-08-10', '2026-08-19');

    // The spec (§D2bis) names THREE predicates — isValidAt(), isCurrentlyValid()
    // and isExpired() — and requires the same verdict as the scope for the same
    // data. daysUntilExpiration() rides on isExpired(), so it is pinned here too.
    //
    // Every assertion stands on its own expect(): a chained expectation
    // short-circuits on the first failure, so a sensitivity run over a chain
    // would only ever measure the first border.
    $borders = [
        // instant,             in force, expired, days until expiration
        ['2026-08-10 09:00:00', true,     false,   9],
        ['2026-08-19 21:08:00', true,     false,   0],
        ['2026-08-20 00:00:01', false,    true,    null],
    ];

    foreach ($borders as [$instant, $inForce, $expired, $daysLeft]) {
        $at = Carbon::parse($instant);
        $this->travelTo($at);

        $byQuery = ArticleOverride::query()->whereKey($override->getKey())->validAt($at)->exists();

        expect($byQuery)->toBe($inForce, "query disagrees at {$instant}");
        expect($override->isValidAt($at))->toBe($inForce, "isValidAt() disagrees at {$instant}");
        expect($override->isCurrentlyValid())->toBe($inForce, "isCurrentlyValid() disagrees at {$instant}");
        expect($override->isExpired())->toBe($expired, "isExpired() disagrees at {$instant}");
        expect($override->daysUntilExpiration())->toBe($daysLeft, "daysUntilExpiration() disagrees at {$instant}");
    }

    $this->travelBack();
});

it('never reports a row the resolver is applying as expired', function () {
    // The measured contradiction: with valid_to = today, the resolver finds the
    // row, isCurrentlyValid() said true and isExpired() said true at the same
    // time, and daysUntilExpiration() returned null for a row being applied.
    $override = aid974SeedOverride($this->article, $this->customer->id, null, '2026-08-20');

    $this->travelTo(Carbon::parse('2026-08-20 14:32:11'));

    expect($this->article->fresh()->getActiveOverrideFor($this->customer->id)?->getKey())->toBe($override->getKey());
    expect($override->isCurrentlyValid())->toBeTrue();
    expect($override->isExpired())->toBeFalse();
    expect($override->daysUntilExpiration())->toBe(0);

    $this->travelBack();
});
