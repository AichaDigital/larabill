<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/.

/**
 * Minimal article row for the FK on article_service_status.article_id.
 */
function seedOriginKeyArticle(): int
{
    return (int) DB::table('articles')->insertGetId([
        'code'       => 'IMP-SUP',
        'name'       => json_encode(['es' => 'Import support fixture', 'en' => 'Import support fixture']),
        'item_type'  => 'S',
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/*
 * ADR-015 §2.5 — the `origin_key` idempotency UNIQUE index on the REAL engine
 * (AID-895 slice, spec §7 task 6).
 *
 * SQLite "proves" nothing about index enforcement subtleties on production
 * engines (lesson: SQLite does not preserve composite-index shapes or
 * lengths). The importer's re-run contract (re-import UPDATES, never
 * duplicates) hangs on this UNIQUE index, so its existence AND its rejecting
 * behaviour are proven here, on MySQL 8.4 / MariaDB 11.4.
 */

describe('article_service_status.origin_key UNIQUE (AID-895 import idempotency, real engine)', function () {
    it('carries a unique index on origin_key alone', function () {
        $this->bootstrap();

        $indexes = collect(Schema::getIndexListing('article_service_status'));
        $unique  = $indexes->first(fn (string $name) => str_contains($name, 'origin_key'));

        expect($unique)->not->toBeNull('No index on article_service_status.origin_key');

        // UNIQUE index columns: origin_key, and nothing else.
        expect($this->getUniqueIndexColumns('article_service_status', (string) $unique))
            ->toBe(['origin_key']);
    });

    it('rejects a duplicate origin_key with a real integrity violation', function () {
        $this->bootstrap();
        $userId     = $this->seedUser();
        $articleId  = seedOriginKeyArticle();

        $insert = function () use ($userId, $articleId): void {
            DB::table('article_service_status')->insert([
                'customer_id'       => $userId,
                'article_id'        => $articleId,
                'started_at'        => now()->toDateString(),
                'status'            => 'active',
                'modality'          => 'recurring',
                'account_status'    => 'up_to_date',
                'renewal'           => 'auto',
                'refund_unused'     => false,
                'origin_key'        => 'whmcs:tblhosting:4711',
                'effective_price'   => 2900,
                'next_billing_date' => now()->addMonth()->toDateString(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        };

        $insert();

        expect(fn () => $insert())->toThrow(QueryException::class)
            ->and(DB::table('article_service_status')->where('origin_key', 'whmcs:tblhosting:4711')->count())->toBe(1);
    });

    it('admits many NULL origin_keys (native agreements never imported do not collide)', function () {
        $this->bootstrap();
        $userId    = $this->seedUser();
        $articleId = seedOriginKeyArticle();

        foreach (range(1, 3) as $i) {
            DB::table('article_service_status')->insert([
                'customer_id'       => $userId,
                'article_id'        => $articleId,
                'started_at'        => now()->toDateString(),
                'status'            => 'active',
                'modality'          => 'recurring',
                'account_status'    => 'up_to_date',
                'renewal'           => 'auto',
                'refund_unused'     => false,
                'origin_key'        => null,
                'effective_price'   => 2900,
                'next_billing_date' => now()->addMonth()->toDateString(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        expect(DB::table('article_service_status')->whereNull('origin_key')->count())->toBe(3);
    });
});
