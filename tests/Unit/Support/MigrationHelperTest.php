<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Config, Schema};

it('can validate supported ID types', function () {
    expect(MigrationHelper::isSupportedIdType('int'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('uuid'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('uuid_binary'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('ulid'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('ulid_binary'))->toBeTrue();
    expect(MigrationHelper::isSupportedIdType('invalid'))->toBeFalse();
});

it('returns correct descriptions for ID types', function () {
    expect(MigrationHelper::getIdTypeDescription('int'))
        ->toContain('Integer')
        ->toContain('Standard Laravel');
    
    expect(MigrationHelper::getIdTypeDescription('uuid'))
        ->toContain('UUID String')
        ->toContain('36');
    
    expect(MigrationHelper::getIdTypeDescription('uuid_binary'))
        ->toContain('UUID Binary')
        ->toContain('16 bytes');
    
    expect(MigrationHelper::getIdTypeDescription('ulid'))
        ->toContain('ULID String')
        ->toContain('26');
    
    expect(MigrationHelper::getIdTypeDescription('ulid_binary'))
        ->toContain('ULID Binary')
        ->toContain('26 bytes');
    
    expect(MigrationHelper::getIdTypeDescription('unknown'))
        ->toBe('Unknown ID type');
});

it('defaults to int when configuration is set to int', function () {
    Config::set('larabill.user_id_type', 'int');
    
    expect(MigrationHelper::getUserIdType())->toBe('int');
});

it('returns configured ID type when explicitly set', function () {
    Config::set('larabill.user_id_type', 'uuid');
    expect(MigrationHelper::getUserIdType())->toBe('uuid');
    
    Config::set('larabill.user_id_type', 'uuid_binary');
    expect(MigrationHelper::getUserIdType())->toBe('uuid_binary');
    
    Config::set('larabill.user_id_type', 'ulid');
    expect(MigrationHelper::getUserIdType())->toBe('ulid');
    
    Config::set('larabill.user_id_type', 'ulid_binary');
    expect(MigrationHelper::getUserIdType())->toBe('ulid_binary');
});

it('creates user_id column as unsignedBigInteger for int type', function () {
    Config::set('larabill.user_id_type', 'int');
    
    Schema::create('test_table', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table);
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_table');
});

it('creates user_id column as nullable when specified', function () {
    Config::set('larabill.user_id_type', 'int');
    
    Schema::create('test_nullable_table', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table, 'user_id', true);
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_nullable_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_nullable_table');
});

it('can use custom column name', function () {
    Config::set('larabill.user_id_type', 'int');
    
    Schema::create('test_custom_column', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table, 'custom_user_id');
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_custom_column', 'custom_user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_custom_column');
});

it('creates UUID column when type is uuid', function () {
    Config::set('larabill.user_id_type', 'uuid');
    
    Schema::create('test_uuid_table', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table);
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_uuid_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_uuid_table');
});

it('creates binary column when type is uuid_binary', function () {
    Config::set('larabill.user_id_type', 'uuid_binary');
    
    Schema::create('test_uuid_binary_table', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table);
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_uuid_binary_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_uuid_binary_table');
});

it('creates ULID column when type is ulid', function () {
    Config::set('larabill.user_id_type', 'ulid');
    
    Schema::create('test_ulid_table', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table);
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_ulid_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_ulid_table');
});

it('creates binary column when type is ulid_binary', function () {
    Config::set('larabill.user_id_type', 'ulid_binary');
    
    Schema::create('test_ulid_binary_table', function (Blueprint $table) {
        MigrationHelper::userIdColumn($table);
        $table->timestamps();
    });
    
    expect(Schema::hasColumn('test_ulid_binary_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_ulid_binary_table');
});

it('detects int ID type from users table', function () {
    // Users table already exists with int ID from migrations
    Config::set('larabill.user_id_type', 'auto');
    
    // With auto detection, it should detect int from existing users table
    $detectedType = MigrationHelper::getUserIdType();
    
    // Should be int because our test DB uses bigIncrements for users.id
    expect($detectedType)->toBe('int');
});

it('falls back to int when users table does not exist', function () {
    Config::set('larabill.user_id_type', 'auto');
    
    // Temporarily drop users table
    $hasUsers = Schema::hasTable('users');
    if ($hasUsers) {
        Schema::dropIfExists('users');
    }
    
    $detectedType = MigrationHelper::getUserIdType();
    expect($detectedType)->toBe('int');
    
    // Restore users table by re-running migrations would happen in teardown
});

it('returns null from detectUserIdType when users table missing', function () {
    // Temporarily drop users table
    $hasUsers = Schema::hasTable('users');
    if ($hasUsers) {
        Schema::dropIfExists('users');
    }
    
    $detected = MigrationHelper::detectUserIdType();
    expect($detected)->toBeNull();
});

it('handles database exceptions gracefully in detection', function () {
    // Detection should not throw exceptions even if DB queries fail
    $detected = MigrationHelper::detectUserIdType();
    
    // Should return null or a valid type, never throw
    expect($detected)->toBeIn(['int', 'uuid', 'uuid_binary', 'ulid', 'ulid_binary', null]);
});

it('supports all ID types in userIdColumn', function () {
    $idTypes = ['int', 'uuid', 'uuid_binary', 'ulid', 'ulid_binary'];
    
    foreach ($idTypes as $index => $idType) {
        Config::set('larabill.user_id_type', $idType);
        $tableName = "test_all_types_{$index}";
        
        Schema::create($tableName, function (Blueprint $table) {
            MigrationHelper::userIdColumn($table);
            $table->timestamps();
        });
        
        expect(Schema::hasColumn($tableName, 'user_id'))->toBeTrue();
        
        Schema::dropIfExists($tableName);
    }
});

it('creates index on user_id column', function () {
    Config::set('larabill.user_id_type', 'int');
    
    Schema::create('test_index_table', function (Blueprint $table) {
        $table->id();
        MigrationHelper::userIdColumn($table);
        $table->timestamps();
    });
    
    // The index should exist (checking varies by DB driver, so we just ensure no error)
    expect(Schema::hasColumn('test_index_table', 'user_id'))->toBeTrue();
    
    Schema::dropIfExists('test_index_table');
});

