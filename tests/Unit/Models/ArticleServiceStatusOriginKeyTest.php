<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * ADR-015 §2.5 — import idempotency key on the agreement side (AID-895 slice,
 * spec §7 task 6).
 *
 * `origin_key` varchar(64) nullable + UNIQUE: the idempotency key of the
 * importer, format `whmcs:<source_table>:<source_id>` (the importer's
 * convention — NOT enforced in larabill). Re-importing an already-imported
 * record matches by `origin_key` and UPDATES instead of duplicating; larabill
 * provides the lookup, the find-or-update flow belongs to the importer.
 *
 * `external_reference` is NOT an idempotency key and stays untouched
 * (nullable, unindexed).
 */

it('has the origin_key column and keeps external_reference untouched (nullable, unindexed)', function () {
    expect(Schema::hasColumn('article_service_status', 'origin_key'))->toBeTrue()
        ->and(Schema::getColumnType('article_service_status', 'origin_key'))->toBeIn(['string', 'varchar'])
        ->and(Schema::hasColumn('article_service_status', 'external_reference'))->toBeTrue();

    // external_reference must NOT have gained an index (§2.5: it is not an
    // idempotency key).
    $indexes = collect(Schema::getIndexListing('article_service_status'));
    expect($indexes->contains(fn (string $name) => str_contains($name, 'external_reference')))->toBeFalse();
});

it('findByOriginKey resolves an imported agreement by its origin key', function () {
    $customer = TestUser::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    $service = ArticleServiceStatus::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'origin_key'   => 'whmcs:tblhosting:4711',
        'billing_unit' => 'month', 'billing_quantity' => 1,
    ]);

    $found = ArticleServiceStatus::findByOriginKey('whmcs:tblhosting:4711');

    expect($found)->not->toBeNull()
        ->and($found?->id)->toBe($service->id)
        ->and($found?->origin_key)->toBe('whmcs:tblhosting:4711');
});

it('findByOriginKey returns null for a NULL key and for an unmatched key (no accidental global match)', function () {
    $customer = TestUser::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    // A row with a NULL origin_key (never imported, or a native agreement).
    ArticleServiceStatus::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'billing_unit' => 'month', 'billing_quantity' => 1,
    ]);

    expect(ArticleServiceStatus::findByOriginKey(null))->toBeNull()
        ->and(ArticleServiceStatus::findByOriginKey('whmcs:tblhosting:does-not-exist'))->toBeNull();
});

it('scopeByOriginKey filters the query by the exact key', function () {
    $customer = TestUser::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    $target = ArticleServiceStatus::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'origin_key'   => 'whmcs:tbldomains:99',
        'billing_unit' => 'year', 'billing_quantity' => 1,
    ]);

    ArticleServiceStatus::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'origin_key'   => 'whmcs:tbldomains:100',
        'billing_unit' => 'year', 'billing_quantity' => 1,
    ]);

    $scoped = ArticleServiceStatus::byOriginKey('whmcs:tbldomains:99')->get();

    expect($scoped)->toHaveCount(1)
        ->and($scoped->first()?->id)->toBe($target->id);
});

it('rejects a duplicate origin_key (UNIQUE — SQLite sanity; the real-engine proof lives in Integration/Mysql)', function () {
    $customer = TestUser::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    ArticleServiceStatus::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'origin_key'   => 'whmcs:tblhosting:4711',
        'billing_unit' => 'month', 'billing_quantity' => 1,
    ]);

    ArticleServiceStatus::factory()->create([
        'customer_id'  => $customer->id,
        'article_id'   => $article->id,
        'origin_key'   => 'whmcs:tblhosting:4711',
        'billing_unit' => 'month', 'billing_quantity' => 1,
    ]);
})->throws(QueryException::class);

it('pins the import terminal state: suspended with NULL closure cause is a legal direct creation (§2.5 table)', function () {
    $customer = TestUser::factory()->create();
    $article  = Article::factory()->service()->monthly(2900)->create();

    // The importer records THAT the service ended; the WHMCS narrative lives
    // in consumer-side metadata, never as an invented closure_cause. The
    // close(+NULL cause) path is pinned in ArticleServiceStatusStateMachineTest;
    // this pins the suspended+NULL-cause import shape via direct creation.
    $service = ArticleServiceStatus::factory()->create([
        'customer_id'      => $customer->id,
        'article_id'       => $article->id,
        'origin_key'       => 'whmcs:tblhosting:7',
        'status'           => AgreementStatus::SUSPENDED,
        'closure_cause_id' => null,
        'billing_unit'     => 'month', 'billing_quantity' => 1,
    ]);

    expect($service->status)->toBe(AgreementStatus::SUSPENDED)
        ->and($service->closure_cause_id)->toBeNull()
        ->and($service->fresh()->status)->toBe(AgreementStatus::SUSPENDED);
});
