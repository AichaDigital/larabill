<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migration Helper for User-related FK columns.
 *
 * Larabill is UUID-first (ADR-006). This helper declares UUID v7 columns for
 * any FK that references the consumer app's `users.id`, using Blueprint's
 * `uuid()` — the semantic API. The PHYSICAL representation is Laravel's
 * decision for the configured connection, not this package's:
 *
 *   - MySqlGrammar   → char(36), always.
 *   - MariaDbGrammar → MariaDB's native `uuid` type on servers >= 10.7,
 *                      char(36) below that.
 *
 * Both are valid and interchangeable for the package's purposes (AID-708).
 * Do NOT replace `uuid()` with an explicit `char($col, 36)` to pin the shape:
 * using a generic type where the framework offers a semantic one is a
 * deviation, and it would couple the schema to an engine decision the software
 * has no business making.
 *
 * Consumer apps must provide `users.id` as a UUID v7 column in any of those
 * representations. The `larabill:install` command runs a preflight check and
 * aborts if the column cannot hold one.
 *
 * See: docs/ADR-006-uuid-first-no-agnostic.md, docs/setup-uuid.md
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
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
