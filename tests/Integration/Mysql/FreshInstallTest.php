<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

describe('Larabill fresh install on MySQL — UUID-first contract (ADR-006)', function () {

    it('installs full schema cleanly with UUID v7 users.id', function () {
        $this->bootstrap();

        // ─────────────────────────────────────────────────────────────────────
        // 1. All user-keyed columns can hold a UUID v7.
        //
        //    AID-708: the assertion is semantic on purpose. `$table->uuid()`
        //    emits `char(36)` under MySqlGrammar but MariaDB's native `uuid`
        //    type under MariaDbGrammar on servers >= 10.7 — both are valid, and
        //    which one appears is Laravel's decision for the configured
        //    connection, not larabill's contract. Pinning the literal `char`
        //    froze a grammar detail and failed a perfectly good schema.
        // ─────────────────────────────────────────────────────────────────────

        // customer_id columns (via MigrationHelper::agnosticIdColumn()).
        $this->assertUuidCompatibleColumn('article_overrides', 'customer_id');
        $this->assertUuidCompatibleColumn('article_service_status', 'customer_id');

        // user_id / owner_user_id columns (via MigrationHelper::userIdColumn()).
        $this->assertUuidCompatibleColumn('invoices', 'user_id');
        $this->assertUuidCompatibleColumn('user_tax_profiles', 'owner_user_id');

        // ─────────────────────────────────────────────────────────────────────
        // 2. Composite UNIQUE indexes exist with customer_id at position 0.
        // ─────────────────────────────────────────────────────────────────────
        expect($this->getUniqueIndexColumns('article_overrides', 'customer_article_override_unique'))
            ->toBe(['customer_id', 'article_id', 'valid_from']);

        expect($this->getUniqueIndexColumns('article_service_status', 'customer_article_instance_unique'))
            ->toBe(['customer_id', 'article_id', 'instance_identifier']);

        // ─────────────────────────────────────────────────────────────────────
        // 2b. Fiscal series width matches the AEAT-derived contract (AID-429):
        //     varchar(50) = NumSerieFactura maxLength (60) − 10-digit correlative.
        //     Only MySQL proves real column widths — SQLite ignores them.
        // ─────────────────────────────────────────────────────────────────────
        expect($this->getMysqlColumnLength('invoices', 'prefix'))->toBe(50);
        expect($this->getMysqlColumnLength('invoice_series_control', 'prefix'))->toBe(50);

        // ─────────────────────────────────────────────────────────────────────
        // 3. Smoke: insert + uniqueness enforcement with a UUID v7 customer_id.
        // ─────────────────────────────────────────────────────────────────────
        $articleId = DB::table('articles')->insertGetId([
            'code'       => 'HOST-PRO',
            'name'       => json_encode(['es' => 'Hosting profesional', 'en' => 'Pro hosting']),
            'item_type'  => 'S',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = Uuid::uuid7()->toString();

        $row = [
            'customer_id'  => $customerId,
            'article_id'   => $articleId,
            'custom_price' => 1000,
            'valid_from'   => '2026-01-01',
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];

        DB::table('article_overrides')->insert($row);

        expect(DB::table('article_overrides')->count())->toBe(1);

        // Same composite key → must throw (UNIQUE actively enforced after install).
        $insertDuplicate = fn () => DB::table('article_overrides')->insert($row);
        expect($insertDuplicate)->toThrow(QueryException::class);
    });

});
