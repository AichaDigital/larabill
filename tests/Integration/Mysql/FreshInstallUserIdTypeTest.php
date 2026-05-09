<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

describe('Larabill fresh install on MySQL — agnostic user_id_type contract', function () {

    it('installs full schema cleanly with the configured user_id_type', function (string $idType) {
        $this->bootstrapForUserIdType($idType);

        // ─────────────────────────────────────────────────────────────────────
        // 1. Column types reflect the configured id type across the schema.
        // ─────────────────────────────────────────────────────────────────────
        $expectedType = match ($idType) {
            'int'   => 'bigint',
            'uuid'  => 'char',
            'ulid'  => 'char',
        };

        $expectedLength = match ($idType) {
            'int'   => null,
            'uuid'  => 36,
            'ulid'  => 26,
        };

        // Tables that take customer_id via MigrationHelper::agnosticIdColumn().
        expect($this->getMysqlColumnType('article_overrides', 'customer_id'))->toBe($expectedType);
        expect($this->getMysqlColumnLength('article_overrides', 'customer_id'))->toBe($expectedLength);

        expect($this->getMysqlColumnType('article_service_status', 'customer_id'))->toBe($expectedType);
        expect($this->getMysqlColumnLength('article_service_status', 'customer_id'))->toBe($expectedLength);

        // Tables that take user_id (or owner_user_id) via MigrationHelper::userIdColumn().
        expect($this->getMysqlColumnType('invoices', 'user_id'))->toBe($expectedType);
        expect($this->getMysqlColumnLength('invoices', 'user_id'))->toBe($expectedLength);

        expect($this->getMysqlColumnType('user_tax_profiles', 'owner_user_id'))->toBe($expectedType);
        expect($this->getMysqlColumnLength('user_tax_profiles', 'owner_user_id'))->toBe($expectedLength);

        expect($this->getMysqlColumnType('roi_queries', 'user_id'))->toBe($expectedType);
        expect($this->getMysqlColumnLength('roi_queries', 'user_id'))->toBe($expectedLength);

        // ─────────────────────────────────────────────────────────────────────
        // 2. Composite UNIQUE indexes exist with customer_id at position 0.
        // ─────────────────────────────────────────────────────────────────────
        expect($this->getUniqueIndexColumns('article_overrides', 'customer_article_override_unique'))
            ->toBe(['customer_id', 'article_id', 'valid_from']);

        expect($this->getUniqueIndexColumns('article_service_status', 'customer_article_instance_unique'))
            ->toBe(['customer_id', 'article_id', 'instance_identifier']);

        // ─────────────────────────────────────────────────────────────────────
        // 3. Smoke: insert + uniqueness enforcement with a value of the right type.
        // ─────────────────────────────────────────────────────────────────────
        $articleId = DB::table('articles')->insertGetId([
            'code'       => 'HOST-PRO',
            'name'       => json_encode(['es' => 'Hosting profesional', 'en' => 'Pro hosting']),
            'item_type'  => 'S',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = match ($idType) {
            'int'  => 12345,
            'uuid' => Uuid::uuid7()->toString(),
            'ulid' => (string) Str::ulid(),
        };

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
    })->with(['int', 'uuid', 'ulid']);

});
