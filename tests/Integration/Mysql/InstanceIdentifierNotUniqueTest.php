<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/.

/**
 * Minimal article row for the FK on article_service_status.article_id.
 * (Helper name UNIQUE across the suite — AID-502.)
 */
function seedInstanceLabelArticle(): int
{
    return (int) DB::table('articles')->insertGetId([
        'code'       => 'INST-LBL',
        'name'       => json_encode(['es' => 'Instance label fixture', 'en' => 'Instance label fixture']),
        'item_type'  => 'S',
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/*
 * AID-1611 — the instance identifier is a LABEL, not an identity, proven on
 * the REAL engine (MySQL 8.4 / MariaDB 11.4; SQLite does not preserve
 * composite-index shapes).
 *
 * The retired `customer_article_instance_unique` constraint covered CLOSED
 * agreements too, so a terminal contract permanently blocked re-contracting
 * the same (customer, article, instance) triple. After the migration the
 * same triple inserts freely — a second live contract and a re-contract
 * after a closed one are BOTH accepted, because enforcing "one live contract
 * per resource" is the vertical's/consumer's rule, not the package's
 * (operator decision 2026-10-11, option A; blocks the WHMCS importer,
 * AID-895). The plain composite index keeps the lookups fast.
 */

describe('article_service_status instance identifier is a label (AID-1611, real engine)', function () {
    it('carries the plain composite replacement index and NO unique constraint on the triple', function () {
        $this->bootstrap();

        expect($this->getUniqueIndexColumns('article_service_status', 'customer_article_instance_unique'))
            ->toBe([])
            ->and($this->getIndexColumns('article_service_status', 'customer_article_instance_index'))
            ->toBe(['customer_id', 'article_id', 'instance_identifier']);
    });

    it('admits a same-instance contract while another on the triple is CLOSED (re-hire / rescue)', function () {
        $this->bootstrap();
        $userId    = $this->seedUser();
        $articleId = seedInstanceLabelArticle();

        $insert = fn (string $instance): Closure => function () use ($userId, $articleId, $instance): void {
            DB::table('article_service_status')->insert([
                'customer_id'         => $userId,
                'article_id'          => $articleId,
                'instance_identifier' => $instance,
                'started_at'          => now()->toDateString(),
                'status'              => 'active',
                'modality'            => 'recurring',
                'account_status'      => 'up_to_date',
                'renewal'             => 'auto',
                'refund_unused'       => false,
                'effective_price'     => 2900,
                'next_billing_date'   => now()->addMonth()->toDateString(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        };

        // The original agreement, closed and terminal — the exact row the old
        // UNIQUE index kept colliding against.
        $insert('room-365.example.test')();
        $closedId = (int) DB::table('article_service_status')->max('id');
        DB::table('article_service_status')->where('id', $closedId)->update(['status' => 'closed']);

        // The re-contract: same triple, no exception.
        $insert('room-365.example.test')();

        expect(DB::table('article_service_status')
            ->where('instance_identifier', 'room-365.example.test')
            ->count())->toBe(2);
    });

    it('admits two LIVE contracts on the same instance (the one-live-contract rule is the consumer\'s)', function () {
        $this->bootstrap();
        $userId    = $this->seedUser();
        $articleId = seedInstanceLabelArticle();

        $insert = function () use ($userId, $articleId): void {
            DB::table('article_service_status')->insert([
                'customer_id'         => $userId,
                'article_id'          => $articleId,
                'instance_identifier' => 'hosting.example.test',
                'started_at'          => now()->toDateString(),
                'status'              => 'active',
                'modality'            => 'recurring',
                'account_status'      => 'up_to_date',
                'renewal'             => 'auto',
                'refund_unused'       => false,
                'effective_price'     => 2900,
                'next_billing_date'   => now()->addMonth()->toDateString(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        };

        expect(fn () => $insert())->not->toThrow(QueryException::class);

        $insert();

        expect(DB::table('article_service_status')
            ->where('instance_identifier', 'hosting.example.test')
            ->count())->toBe(2);
    });
});
