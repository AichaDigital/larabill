<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

describe('MigrationHelper', function () {
    afterEach(function () {
        Schema::dropIfExists('test_migration_helper');
    });

    it('creates uuid column when configured as uuid', function () {
        config(['larabill.user_id_type' => 'uuid']);

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();

        $columnType = Schema::getColumnType('test_migration_helper', 'user_id');

        expect($columnType)->toBeIn(['string', 'guid', 'varchar']);
    });

    it('creates integer column when configured as int', function () {
        config(['larabill.user_id_type' => 'int']);

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();

        $columnType = Schema::getColumnType('test_migration_helper', 'user_id');

        expect($columnType)->toBeIn(['integer', 'bigint']);
    });

    it('creates ulid column when configured as ulid', function () {
        config(['larabill.user_id_type' => 'ulid']);

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();

        $columnType = Schema::getColumnType('test_migration_helper', 'user_id');

        expect($columnType)->toBeIn(['string', 'guid', 'varchar']);
    });

    it('creates nullable column when requested', function () {
        config(['larabill.user_id_type' => 'uuid']);

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id', nullable: true);
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();

        $columns      = Schema::getColumns('test_migration_helper');
        $userIdColumn = collect($columns)->firstWhere('name', 'user_id');

        expect($userIdColumn)->not->toBeNull();
        expect($userIdColumn['nullable'])->toBeTrue();
    });

    it('validates supported id types', function () {
        expect(MigrationHelper::isSupportedIdType('uuid'))->toBeTrue();
        expect(MigrationHelper::isSupportedIdType('int'))->toBeTrue();
        expect(MigrationHelper::isSupportedIdType('ulid'))->toBeTrue();

        expect(MigrationHelper::isSupportedIdType('binary'))->toBeFalse();
        expect(MigrationHelper::isSupportedIdType('invalid'))->toBeFalse();
    });

    it('returns human-readable descriptions', function () {
        $intDescription = MigrationHelper::getIdTypeDescription('int');
        expect($intDescription)->toContain('Integer');

        $uuidDescription = MigrationHelper::getIdTypeDescription('uuid');
        expect($uuidDescription)->toContain('UUID');

        $ulidDescription = MigrationHelper::getIdTypeDescription('ulid');
        expect($ulidDescription)->toContain('ULID');

        $unknownDescription = MigrationHelper::getIdTypeDescription('unknown');
        expect($unknownDescription)->toBe('Unknown ID type');
    });

    it('reads config priority correctly', function () {
        config(['larabill.user_id_type' => 'int']);
        expect(MigrationHelper::getUserIdType())->toBe('int');

        config(['larabill.user_id_type' => 'auto']);
        $type = MigrationHelper::getUserIdType();
        expect($type)->toBeIn(['int', 'uuid', 'ulid']);
    });

    it('creates agnostic id column with index', function () {
        config(['larabill.user_id_type' => 'uuid']);

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::agnosticIdColumn($table, 'client_id', nullable: true, index: true);
        });

        expect(Schema::hasColumn('test_migration_helper', 'client_id'))->toBeTrue();

        $columns        = Schema::getColumns('test_migration_helper');
        $clientIdColumn = collect($columns)->firstWhere('name', 'client_id');

        expect($clientIdColumn)->not->toBeNull();
        expect($clientIdColumn['nullable'])->toBeTrue();

        $indexes          = Schema::getIndexes('test_migration_helper');
        $hasClientIdIndex = collect($indexes)->contains(function ($index) {
            return in_array('client_id', $index['columns']);
        });

        expect($hasClientIdIndex)->toBeTrue();
    });

    it('defaults to uuid when config is not set', function () {
        $larabillConfig = config('larabill');
        unset($larabillConfig['user_id_type']);
        config(['larabill' => $larabillConfig]);

        $type = MigrationHelper::getUserIdType();

        expect($type)->toBe('uuid');
    });
});
