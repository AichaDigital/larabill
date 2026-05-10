<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

describe('MigrationHelper (UUID-first per ADR-006)', function () {
    afterEach(function () {
        Schema::dropIfExists('test_migration_helper');
    });

    it('creates a UUID user_id column', function () {
        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
        expect(Schema::getColumnType('test_migration_helper', 'user_id'))
            ->toBeIn(['string', 'guid', 'varchar']);
    });

    it('creates a nullable UUID column when requested', function () {
        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id', nullable: true);
        });

        $columns      = Schema::getColumns('test_migration_helper');
        $userIdColumn = collect($columns)->firstWhere('name', 'user_id');

        expect($userIdColumn)->not->toBeNull();
        expect($userIdColumn['nullable'])->toBeTrue();
    });

    it('honors a custom column name', function () {
        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'created_by_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'created_by_id'))->toBeTrue();
    });

    it('creates an agnostic UUID id column with index', function () {
        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::agnosticIdColumn($table, 'partner_id', nullable: true, index: true);
        });

        $columns         = Schema::getColumns('test_migration_helper');
        $partnerIdColumn = collect($columns)->firstWhere('name', 'partner_id');

        expect($partnerIdColumn)->not->toBeNull();
        expect($partnerIdColumn['nullable'])->toBeTrue();

        $indexes           = Schema::getIndexes('test_migration_helper');
        $hasPartnerIdIndex = collect($indexes)->contains(
            fn ($index) => in_array('partner_id', $index['columns'])
        );

        expect($hasPartnerIdIndex)->toBeTrue();
    });
});
