<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Ramsey\Uuid\Uuid;

/**
 * Migration Helper for User ID Type Detection
 *
 * Provides agnostic support for different User ID types:
 * - int (unsignedBigInteger)
 * - uuid (string char 36)
 * - uuid_binary (binary 16)
 * - ulid (string char 26)
 * - ulid_binary (binary 26)
 */
class MigrationHelper
{
    /**
     * Add a user_id column with the appropriate type based on configuration.
     *
     * Automatically detects User ID type if not configured.
     */
    public static function userIdColumn(
        Blueprint $table,
        string $columnName = 'user_id',
        bool $nullable = false
    ): void {
        $idType = static::getUserIdType();

        $column = match ($idType) {
            'uuid'        => $table->uuid($columnName),
            'uuid_binary' => $table->binary($columnName, 16),
            'ulid'        => $table->ulid($columnName),
            'ulid_binary' => $table->binary($columnName, 26),
            default       => $table->unsignedBigInteger($columnName),
        };

        if ($nullable) {
            $column->nullable();
        }

        $table->index($columnName);
    }

    /**
     * Get the configured user ID type, with auto-detection fallback.
     */
    public static function getUserIdType(): string
    {
        $configured = config('larabill.user_id_type', 'int');

        // If explicitly configured, use it
        if ($configured !== 'int' && $configured !== 'auto') {
            return $configured;
        }

        // Try auto-detection if users table exists
        if (Schema::hasTable('users')) {
            $detected = static::detectUserIdType();
            if ($detected) {
                return $detected;
            }
        }

        // Default to int (standard Laravel)
        return 'int';
    }

    /**
     * Auto-detect the User ID type from the existing users table.
     */
    public static function detectUserIdType(): ?string
    {
        try {
            // Get column definition from users table
            $connection = DB::connection();
            $driver     = $connection->getDriverName();

            if ($driver === 'mysql') {
                $column = DB::selectOne(
                    "SHOW COLUMNS FROM users WHERE Field = 'id'"
                );

                if (! $column) {
                    return null;
                }

                $type = strtolower($column->Type);

                // Detect based on column type
                if (str_contains($type, 'bigint')) {
                    return 'int';
                }

                if (str_contains($type, 'binary')) {
                    // Check size to differentiate UUID from ULID
                    if (str_contains($type, 'binary(16)')) {
                        return 'uuid_binary';
                    }
                    if (str_contains($type, 'binary(26)')) {
                        return 'ulid_binary';
                    }

                    return 'uuid_binary'; // Default for binary
                }

                if (str_contains($type, 'char') || str_contains($type, 'varchar')) {
                    // Try to get a sample ID to determine UUID vs ULID
                    $user = DB::table('users')->first();
                    if ($user && isset($user->id)) {
                        if (Uuid::isValid($user->id)) {
                            return 'uuid';
                        }
                        // ULID pattern: 26 chars, alphanumeric
                        if (strlen($user->id) === 26 && ctype_alnum($user->id)) {
                            return 'ulid';
                        }
                    }

                    // Default to UUID for string columns
                    return 'uuid';
                }
            } elseif ($driver === 'pgsql') {
                $column = DB::selectOne(
                    "SELECT data_type, character_maximum_length 
                     FROM information_schema.columns 
                     WHERE table_name = 'users' AND column_name = 'id'"
                );

                if (! $column) {
                    return null;
                }

                $type = strtolower($column->data_type);

                if ($type === 'bigint' || $type === 'integer') {
                    return 'int';
                }

                if ($type === 'uuid') {
                    return 'uuid';
                }

                if ($type === 'bytea') {
                    // PostgreSQL binary type - check length with sample
                    $user = DB::table('users')->first();
                    if ($user && isset($user->id)) {
                        $length = strlen($user->id);
                        if ($length === 16) {
                            return 'uuid_binary';
                        }
                        if ($length === 26) {
                            return 'ulid_binary';
                        }
                    }

                    return 'uuid_binary';
                }

                if ($type === 'character varying' || $type === 'character') {
                    $user = DB::table('users')->first();
                    if ($user && isset($user->id)) {
                        if (Uuid::isValid($user->id)) {
                            return 'uuid';
                        }
                        if (strlen($user->id) === 26) {
                            return 'ulid';
                        }
                    }

                    return 'uuid';
                }
            } elseif ($driver === 'sqlite') {
                // SQLite doesn't have strict types, check sample data
                $user = DB::table('users')->first();
                if ($user && isset($user->id)) {
                    if (is_int($user->id)) {
                        return 'int';
                    }
                    if (is_string($user->id)) {
                        if (Uuid::isValid($user->id)) {
                            return 'uuid';
                        }
                        if (strlen($user->id) === 26) {
                            return 'ulid';
                        }
                    }
                }

                return 'int'; // SQLite default
            }

            return null;
        } catch (\Exception $e) {
            // Silently fail, return null
            return null;
        }
    }

    /**
     * Get human-readable description of ID type.
     */
    public static function getIdTypeDescription(string $idType): string
    {
        return match ($idType) {
            'int'         => 'Integer (unsignedBigInteger) - Standard Laravel default',
            'uuid'        => 'UUID String (char 36) - Human readable, larger storage',
            'uuid_binary' => 'UUID Binary (16 bytes) - Most efficient UUID storage',
            'ulid'        => 'ULID String (char 26) - Sortable, human readable',
            'ulid_binary' => 'ULID Binary (26 bytes) - Sortable, efficient storage',
            default       => 'Unknown ID type',
        };
    }

    /**
     * Validate if a given ID type is supported.
     */
    public static function isSupportedIdType(string $idType): bool
    {
        return in_array($idType, [
            'int',
            'uuid',
            'uuid_binary',
            'ulid',
            'ulid_binary',
        ], true);
    }

    /**
     * Add an agnostic ID column (non-user foreign key).
     *
     * Uses the same ID type as user_id for consistency.
     * Useful for columns like client_id, customer_id, etc.
     */
    public static function agnosticIdColumn(
        Blueprint $table,
        string $columnName,
        bool $nullable = false,
        bool $index = false
    ): void {
        $idType = static::getUserIdType();

        $column = match ($idType) {
            'uuid'        => $table->uuid($columnName),
            'uuid_binary' => $table->binary($columnName, 16),
            'ulid'        => $table->ulid($columnName),
            'ulid_binary' => $table->binary($columnName, 26),
            default       => $table->unsignedBigInteger($columnName),
        };

        if ($nullable) {
            $column->nullable();
        }

        if ($index) {
            $table->index($columnName);
        }
    }
}
