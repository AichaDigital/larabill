<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\OverlappingArticleOverrideException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = TestUser::factory()->create();
    $this->article  = Article::factory()->create();
});

/** Seed an active override for the given range. */
function aid974SeedOverlapOverride(Article $article, string|int $customerId, ?string $from, ?string $to): ArticleOverride
{
    return ArticleOverride::factory()->for($article)->create([
        'customer_id'  => $customerId,
        'custom_price' => FixedDecimal::ofUnscaled(2400, 2),
        'valid_from'   => $from,
        'valid_to'     => $to,
        'is_active'    => true,
    ]);
}

/**
 * Create an override straight through the model, with exactly the given keys.
 *
 * Deliberately NOT the factory: its definition always sets `is_active`, so it
 * cannot reproduce a write that leaves the key OUT — which is precisely the
 * hole the four cases below pin (AID-974).
 *
 * @param  array<string, mixed>  $attributes
 */
function aid974CreateOverrideWith(Article $article, string|int $customerId, array $attributes): ArticleOverride
{
    return ArticleOverride::create([
        'article_id'   => $article->getKey(),
        'customer_id'  => $customerId,
        'custom_price' => FixedDecimal::ofUnscaled(2400, 2),
        ...$attributes,
    ]);
}

it('detects two intersecting ranges', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    $conflicts = ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'))
        ->get();

    expect($conflicts)->toHaveCount(1);
});

it('does not treat disjoint ranges as a conflict', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'))
        ->get())->toBeEmpty();
});

it('treats NULL as an open end on both sides', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, null, null);

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2030-01-01'), null)
        ->get())->toHaveCount(1);
});

it('ignores inactive rows', function () {
    $o = aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');
    $o->update(['is_active' => false]);

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))
        ->get())->toBeEmpty();
});

it('ignores other customers and other articles', function () {
    $other = TestUser::factory()->create();
    aid974SeedOverlapOverride($this->article, $other->id, '2026-01-01', '2026-06-30');

    expect(ArticleOverride::query()
        ->overlapping($this->customer->id, $this->article->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'))
        ->get())->toBeEmpty();
});

it('refuses to save an overlapping active override', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-06-01', '2026-12-31'))
        ->toThrow(OverlappingArticleOverrideException::class);
});

it('allows saving disjoint ranges', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-07-01', '2026-12-31'))
        ->not->toThrow(OverlappingArticleOverrideException::class);
});

it('lets an existing override be saved again without changing its range', function () {
    // Without self-exclusion the row detects itself as a conflict and
    // overrides become uneditable (AID-974 D4).
    $override = aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => $override->update(['reason' => 'renegotiated']))
        ->not->toThrow(OverlappingArticleOverrideException::class);
});

it('refuses to reactivate an inactive override onto an occupied range', function () {
    $old = aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');
    $old->update(['is_active' => false]);
    // valid_from differs from $old so the unique index
    // (customer_id, article_id, valid_from) does not collide; the range still
    // overlaps [2026-01-01 .. 2026-06-30], which is what this test has to prove.
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-03-01', '2026-06-30');

    expect(fn () => $old->update(['is_active' => true]))
        ->toThrow(OverlappingArticleOverrideException::class);
});

it('names the conflicting id and range in the message', function () {
    $first = aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    try {
        aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-06-01', '2026-12-31');
        test()->fail('expected OverlappingArticleOverrideException');
    } catch (OverlappingArticleOverrideException $e) {
        expect($e->getMessage())->toContain((string) $first->getKey())
            ->and($e->getMessage())->toContain('2026-06-30');
    }
});

// --- The four is_active shapes the saving hook has to tell apart (AID-974) ---
//
// The hook used to bail out on `! $override->is_active`. With the key absent
// the cast reads back null, `! null` is true, and the hook returned BEFORE
// running the overlap query — then the schema default stamped is_active = 1.
// Measured before the fix: two active overlapping rows, no exception.

it('rejects an overlapping override that omits is_active', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => aid974CreateOverrideWith($this->article, $this->customer->id, [
        'valid_from' => '2026-06-01',
        'valid_to'   => '2026-12-31',
    ]))->toThrow(OverlappingArticleOverrideException::class);
});

it('defaults is_active to true in memory when the key is omitted', function () {
    // The other half of the same fix, on its own: the schema stamps 1, so the
    // model has to say the same thing without a round-trip. Otherwise every
    // predicate that reads is_active — isValidAt(), the hook itself — judges a
    // row the database considers active as inactive.
    $override = aid974CreateOverrideWith($this->article, $this->customer->id, [
        'valid_from' => '2026-01-01',
        'valid_to'   => '2026-06-30',
    ]);

    expect($override->is_active)->toBeTrue();
});

it('rejects an overlapping override whose is_active is explicitly null', function () {
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    expect(fn () => aid974CreateOverrideWith($this->article, $this->customer->id, [
        'valid_from' => '2026-06-01',
        'valid_to'   => '2026-12-31',
        'is_active'  => null,
    ]))->toThrow(OverlappingArticleOverrideException::class);
});

it('accepts an overlapping override that is explicitly inactive', function () {
    // Deactivating stays legitimate: only an explicit false is a deactivation.
    aid974SeedOverlapOverride($this->article, $this->customer->id, '2026-01-01', '2026-06-30');

    $inactive = aid974CreateOverrideWith($this->article, $this->customer->id, [
        'valid_from' => '2026-06-01',
        'valid_to'   => '2026-12-31',
        'is_active'  => false,
    ]);

    expect($inactive->exists)->toBeTrue();
    expect($inactive->is_active)->toBeFalse();
});
