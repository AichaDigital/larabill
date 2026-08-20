<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = TestUser::factory()->create();
    $this->article  = Article::factory()->create();
});

/**
 * Seed an active override for the given range.
 *
 * The unscaled price is a parameter, defaulted to the value every existing
 * caller already relied on: a test that pits two overrides against each other
 * needs to tell them apart, and with a shared price an assertion on the
 * resolved amount passes whichever row wins.
 */
function aid974SeedResolutionOverride(Article $article, string|int $customerId, ?string $from, ?string $to, int $unscaledPrice = 2400): ArticleOverride
{
    return ArticleOverride::factory()->for($article)->create([
        'customer_id'  => $customerId,
        'custom_price' => FixedDecimal::ofUnscaled($unscaledPrice, 2),
        'valid_from'   => $from,
        'valid_to'     => $to,
        'is_active'    => true,
    ]);
}

/**
 * Seed an active, open-ended override with an EXPLICIT primary key.
 *
 * Auto-increment hands out ids in insertion order, which is exactly what the
 * tie-break test below must NOT rely on: SQLite's traversal then agrees with
 * `id DESC` by accident and the clause becomes untestable.
 */
function aid974SeedResolutionOverrideWithId(Article $article, string|int $customerId, int $id, int $unscaledPrice): ArticleOverride
{
    return ArticleOverride::factory()->for($article)->create([
        'id'           => $id,
        'customer_id'  => $customerId,
        'custom_price' => FixedDecimal::ofUnscaled($unscaledPrice, 2),
        'valid_from'   => null,
        'valid_to'     => null,
        'is_active'    => true,
    ]);
}

it('ignores an expired override', function () {
    aid974SeedResolutionOverride($this->article, $this->customer->id, '2020-01-01', '2020-12-31');

    expect($this->article->getActiveOverrideFor($this->customer->id))->toBeNull();
});

it('ignores an override that is not valid yet', function () {
    aid974SeedResolutionOverride($this->article, $this->customer->id, '2099-01-01', null);

    expect($this->article->getActiveOverrideFor($this->customer->id))->toBeNull();
});

it('picks the most recent valid_from among valid candidates', function () {
    // Legacy duplicate, seeded WITHOUT events because the saving hook would
    // reject the second row. This is exactly the state the deterministic read
    // exists to tolerate.
    $older = aid974SeedResolutionOverride($this->article, $this->customer->id, '2026-01-01', null);
    $newer = ArticleOverride::withoutEvents(fn () => aid974SeedResolutionOverride($this->article, $this->customer->id, '2026-06-01', null));

    expect($this->article->fresh()->getActiveOverrideFor($this->customer->id)->getKey())->toBe($newer->getKey())
        ->and($older->getKey())->not->toBe($newer->getKey());
});

it('breaks a tie on id when both have an open start', function () {
    // Two NULL valid_from values: the unique index
    // (customer_id, article_id, valid_from) does not deduplicate NULLs. With
    // two equal NON-NULL dates it would collide and the fixture would be
    // unseedable.
    $first  = aid974SeedResolutionOverride($this->article, $this->customer->id, null, null);
    $second = ArticleOverride::withoutEvents(fn () => aid974SeedResolutionOverride($this->article, $this->customer->id, null, null));

    expect($this->article->fresh()->getActiveOverrideFor($this->customer->id)->getKey())
        ->toBe(max($first->getKey(), $second->getKey()));
});

it('breaks a tie on id when the lower id was written last', function () {
    // Insertion order inverted against id order: the row that must win (id 900)
    // is written FIRST and the loser (id 100) LAST, so the outcome cannot be
    // read off the insertion sequence.
    //
    // HONEST LIMIT, MEASURED: this test does NOT discriminate against deleting
    // ->orderByDesc('id') — see the SQL test below for why no data fixture can.
    // It pins the promise the CHANGELOG makes ("id breaks ties") and becomes the
    // discriminator the day the index that currently supplies that order changes.
    //
    // Both rows are legacy duplicates the resolver exists to tolerate, so the
    // second is seeded withoutEvents — the saving hook rejects it.
    $winner = aid974SeedResolutionOverrideWithId($this->article, $this->customer->id, 900, 2400);
    $loser  = ArticleOverride::withoutEvents(fn () => aid974SeedResolutionOverrideWithId($this->article, $this->customer->id, 100, 1000));

    $resolved = $this->article->fresh()->getActiveOverrideFor($this->customer->id);

    // Distinct prices: with the helper's shared default the price assertion
    // would pass whichever row won, which is decoration, not a gate.
    expect($loser->getKey())->toBeLessThan($winner->getKey());
    expect($resolved?->getKey())->toBe($winner->getKey());
    expect($resolved?->custom_price->unscaledValue())->toBe(2400);
});

it('emits the id tie-break in the resolver query', function () {
    // The id tie-break cannot be pinned by data, and that was MEASURED, not
    // assumed. Both engines satisfy `ORDER BY valid_from DESC` with a BACKWARD
    // INDEX SCAN of customer_article_override_unique (customer_id, article_id,
    // valid_from) — SQLite: "SEARCH ... USING INDEX customer_article_override_unique";
    // MySQL 8.0.46: "Using where; Backward index scan" — and the trailing column
    // of that index is the primary key. Within a tie the engine therefore hands
    // back the highest id on its own: with ->orderByDesc('id') deleted, the whole
    // SQLite suite stayed green and MySQL returned the same row, with the ids
    // forced apart from insertion order in both.
    //
    // That agreement is a coincidence of one index and one chosen plan, both of
    // which this package can change. So the clause is pinned where it IS
    // observable — in the SQL the resolver emits — and this test is the one that
    // goes red when it disappears.
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->article->resolveOverrideFor($this->customer->id, Carbon::parse('2026-03-15'));

    // Identifier quoting differs per grammar; the ORDER BY terms do not.
    $resolverQuery = collect($statements)
        ->map(fn (string $sql): string => str_replace(['"', '`'], '', $sql))
        ->first(fn (string $sql): bool => str_contains($sql, 'from article_overrides'));

    expect($resolverQuery)->not->toBeNull();
    expect($resolverQuery)->toContain('order by valid_from desc, id desc');
});

it('resolves at an explicit instant, not only now', function () {
    // The plain `Carbon\Carbon` imported above is the point, not an accident:
    // it is what scopeValidAt(), scopeOverlapping() and setOverride() take, and
    // typing $at as the Illuminate subclass would reject it with a TypeError.
    aid974SeedResolutionOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect($this->article->resolveOverrideFor($this->customer->id, Carbon::parse('2026-03-15')))->not->toBeNull()
        ->and($this->article->resolveOverrideFor($this->customer->id, Carbon::parse('2027-03-15')))->toBeNull();
});

it('prefers the most recent valid_from even when it has the lower id', function () {
    // Inverted seeding order from "picks the most recent valid_from among
    // valid candidates": here the MORE recent valid_from is seeded FIRST (so
    // it gets the LOWER id) and the LESS recent one SECOND (higher id). An
    // id-only ordering would therefore pick the wrong row; only
    // ->orderByDesc('valid_from') can discriminate here.
    $newer = aid974SeedResolutionOverride($this->article, $this->customer->id, '2026-06-01', null);
    $older = ArticleOverride::withoutEvents(fn () => aid974SeedResolutionOverride($this->article, $this->customer->id, '2026-01-01', null));

    expect($newer->getKey())->toBeLessThan($older->getKey())
        ->and($this->article->fresh()->getActiveOverrideFor($this->customer->id)->getKey())->toBe($newer->getKey());
});

it('applies validity through every public pricing entry point', function () {
    // The base price is pinned away from the helper's 2400: ArticleFactory
    // seeds the default MONTHLY price with faker(1000..50000), and a collision
    // would let these assertions pass with and without the fix.
    $article = Article::factory()->monthly(9900)->create();
    aid974SeedResolutionOverride($article, $this->customer->id, '2020-01-01', '2020-12-31');

    // No withOverride() state here: it would seed a second, always-overlapping
    // override for the same pair, which the Task 3 hook now rejects.
    $service = ArticleServiceStatus::factory()
        ->forCustomer($this->customer->id)
        ->forArticle($article, BillingFrequency::MONTHLY)
        ->create();

    $pricing = new PricingService;
    $article = $article->fresh();

    expect($pricing->getActiveOverride($article, $this->customer->id))->toBeNull()
        ->and($pricing->hasActiveOverride($article, $this->customer->id))->toBeFalse()
        ->and($pricing->getEffectivePrice($article, BillingFrequency::MONTHLY, $this->customer->id))->toBe(9900.0)
        ->and($pricing->createPricingDetails($article, BillingFrequency::MONTHLY, $this->customer->id)->pricingRule)->toBe('base_price')
        ->and($pricing->getEffectivePriceForService($service))->toBe(9900.0)
        ->and($pricing->createPricingDetailsForService($service)->pricingRule)->toBe('base_price');
});

it('applies the deterministic order through the pricing service', function () {
    // DO NOT "unify" this fixture with the inverted ones above and below. The
    // COINCIDENT seeding here is deliberate: date order and id order agree, so
    // this test discriminates against "there is no ordering at all" — which is
    // the defect on the PricingService side — and NOT against "valid_from
    // outranks id", which is pinned at model level by 'prefers the most recent
    // valid_from even when it has the lower id'. Measured, not assumed: under
    // the mutation that deletes ->orderByDesc('valid_from') this test stays
    // GREEN while the two inverted ones go red. Collapsing them into one shape
    // loses a gate whichever shape survives.
    //
    // Legacy duplicate seeded withoutEvents: the Task 3 hook rejects the second
    // row. The LESS recent start is inserted FIRST, so an unordered read hands
    // back the wrong row and the assertion discriminates.
    $article = Article::factory()->monthly(9900)->create();
    $older   = aid974SeedResolutionOverride($article, $this->customer->id, '2026-01-01', null);
    $newer   = ArticleOverride::withoutEvents(fn () => aid974SeedResolutionOverride($article, $this->customer->id, '2026-06-01', null));

    expect($older->getKey())->toBeLessThan($newer->getKey())
        ->and((new PricingService)->getActiveOverride($article->fresh(), $this->customer->id)?->getKey())
        ->toBe($newer->getKey());
});

it('freezes the deterministic winner into the contract on revision', function () {
    // One layer above "prefers the most recent valid_from even when it has the
    // lower id": a contract revision must store the row the resolver picks AND
    // that row's price. Seeding is inverted for the same reason — the more
    // recent start goes in FIRST and takes the LOWER id, so an id-only ordering
    // would freeze the wrong override. The two rows also carry DIFFERENT prices:
    // with the helper's shared default the price assertion would pass whichever
    // row won, which is decoration, not a gate.
    $article = Article::factory()->monthly(2900)->create();
    $newer   = aid974SeedResolutionOverride($article, $this->customer->id, '2026-06-01', null, 2400);
    $older   = ArticleOverride::withoutEvents(fn () => aid974SeedResolutionOverride($article, $this->customer->id, '2026-01-01', null, 1000));

    // Explicit create() instead of the withOverride() factory state: that state
    // seeds a second, always-overlapping override for the same pair, which the
    // Task 3 hook now rejects. The pre-revision price is pinned away from both
    // overrides, so the revision has to move it.
    $service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $this->customer->id,
        'article_id'        => $article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'effective_price'   => cents(2900),
    ]);

    $service->fresh()->updateEffectivePrice();
    $revised = $service->fresh();

    expect($newer->getKey())->toBeLessThan($older->getKey())
        ->and($revised->current_override_id)->toBe($newer->getKey())
        ->and($revised->effective_price->unscaledValue())->toBe($newer->custom_price->unscaledValue());
});
