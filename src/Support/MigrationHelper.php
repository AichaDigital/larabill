<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migration Helper for User-related FK columns.
 *
 * Larabill is UUID-first (ADR-006). This helper emits UUID v7 char(36)
 * columns for any FK that references the consumer app's `users.id`.
 *
 * Consumer apps must provide `users.id` as UUID v7 char(36). The
 * `larabill:install` command runs a preflight check and aborts otherwise.
 *
 * See: docs/ADR-006-uuid-first-no-agnostic.md, docs/setup-uuid.md
 */
class MigrationHelper
{
    /**
     * Add a UUID column referencing `users.id` (or another user-keyed table).
     */
    public static function userIdColumn(
        Blueprint $table,
        string $columnName = 'user_id',
        bool $nullable = false
    ): void {
        $column = $table->uuid($columnName);

        if ($nullable) {
            $column->nullable();
        }

        $table->index($columnName);
    }

    /**
     * Add a UUID column for non-user FKs that share the user-keyed type
     * (e.g. `customer_id` when customers are users).
     */
    public static function agnosticIdColumn(
        Blueprint $table,
        string $columnName,
        bool $nullable = false,
        bool $index = false
    ): void {
        $column = $table->uuid($columnName);

        if ($nullable) {
            $column->nullable();
        }

        if ($index) {
            $table->index($columnName);
        }
    }
}
