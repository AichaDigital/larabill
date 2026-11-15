<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one-off sale's verification primitive (AID-1997 spec §4.5 rev11,
 * owner decision A; closes rounds 8-9's callback seams).
 *
 * A bare PDO SELECT on the document's exact WRITE connection — below
 * Laravel's entire event system. No Eloquent model (no `retrieved`),
 * no Connection wrapper (no `QueryExecuted`, no `StatementPrepared` —
 * the wrapper dispatches them only on its own fetch path), no read/write
 * PDO split (the write PDO by construction). Comparisons are of persisted
 * columns as returned by the database.
 *
 * Used by EVERY verification read of the refund: R1's plan source, the
 * phase-1 line/header validation, the pre-number checks, the post-save
 * re-verification, R3's post-close check, the post-commit detection and
 * the flip transaction's last statement. It is the last word in every
 * refund transaction — nothing in the step it verifies follows it.
 *
 * The declared envelope (owner decision A): code executing in the
 * consumer's process can subvert any in-process package; the refund's
 * guarantee is DETECTION — a mismatch raises SaleIntegrityException and
 * the refund never completes over a forged document.
 *
 * @internal OneOffSaleService is the only caller.
 */
final class RawDocumentRead
{
    /**
     * Read one row's persisted columns through the bare write PDO.
     *
     * @param  string  $table  the table name (package-owned, never user input)
     * @param  string  $id  the row's uuid
     * @return array<string, mixed>|null the raw persisted columns, or null
     */
    public static function row(string $table, string $id): ?array
    {
        $pdo = DB::connection()->getPdo();

        $statement = $pdo->prepare('SELECT * FROM '.$table.' WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
