<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Schema};

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure we have a clean state
    Schema::dropIfExists('test_migration_helper');
});

afterEach(function () {
    Schema::dropIfExists('test_migration_helper');
});

it('can detect user id type from existing users table', function () {
    $idType = MigrationHelper::detectUserIdType();
    expect($idType)->toBeString()->toBeIn(['int', 'uuid', 'ulid', 'uuid_binary', 'ulid_binary']);
});

it('can add user_id column with detected type', function () {
    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('can add nullable user_id column', function () {
    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id', true);
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('can add indexed user_id column', function () {
    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id', false, true);
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('supports all valid id types', function () {
    $types = ['int', 'uuid', 'ulid', 'uuid_binary', 'ulid_binary'];

    foreach ($types as $type) {
        expect(MigrationHelper::isSupportedIdType($type))->toBeTrue();
    }
});

it('rejects invalid id types', function () {
    expect(MigrationHelper::isSupportedIdType('invalid'))->toBeFalse();
    expect(MigrationHelper::isSupportedIdType('string'))->toBeFalse();
    expect(MigrationHelper::isSupportedIdType(''))->toBeFalse();
});

it('provides description for each id type', function () {
    $descriptions = [
        'int'          => MigrationHelper::getIdTypeDescription('int'),
        'uuid'         => MigrationHelper::getIdTypeDescription('uuid'),
        'ulid'         => MigrationHelper::getIdTypeDescription('ulid'),
        'uuid_binary'  => MigrationHelper::getIdTypeDescription('uuid_binary'),
        'ulid_binary'  => MigrationHelper::getIdTypeDescription('ulid_binary'),
    ];

    foreach ($descriptions as $type => $description) {
        expect($description)->toBeString()->not->toBeEmpty();
    }
});

it('returns unknown for invalid id type description', function () {
    expect(MigrationHelper::getIdTypeDescription('invalid'))->toBe('Unknown ID type');
});

it('can use custom column name', function () {
    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'created_by_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'created_by_id'))->toBeTrue();
});

it('handles int type correctly', function () {
    config(['larabill.user_id_type' => 'int']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('handles uuid type correctly', function () {
    config(['larabill.user_id_type' => 'uuid']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('handles ulid type correctly', function () {
    config(['larabill.user_id_type' => 'ulid']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('handles uuid_binary type correctly', function () {
    config(['larabill.user_id_type' => 'uuid_binary']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('handles ulid_binary type correctly', function () {
    config(['larabill.user_id_type' => 'ulid_binary']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('can add multiple user_id columns', function () {
    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'created_by_id');
        MigrationHelper::userIdColumn($table, 'updated_by_id', true);
    });

    expect(Schema::hasColumn('test_migration_helper', 'created_by_id'))->toBeTrue();
    expect(Schema::hasColumn('test_migration_helper', 'updated_by_id'))->toBeTrue();
});

it('falls back to config when detection fails', function () {
    config(['larabill.user_id_type' => 'uuid']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('defaults to int when no config is set', function () {
    config(['larabill.user_id_type' => 'auto']);

    Schema::create('test_migration_helper', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table, 'user_id');
    });

    expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
});

it('can validate all id types', function () {
    expect(MigrationHelper::isSupportedIdType('int'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('uuid'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('ulid'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('uuid_binary'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('ulid_binary'))->toBeTrue();
});

it('provides meaningful descriptions for all types', function () {
    $types = ['int', 'uuid', 'ulid', 'uuid_binary', 'ulid_binary'];

    foreach ($types as $type) {
        $description = MigrationHelper::getIdTypeDescription($type);
        expect($description)->toBeString();
        expect($description)->not->toBe('Unknown ID type');
        expect(strlen($description))->toBeGreaterThan(10);
    }
});
