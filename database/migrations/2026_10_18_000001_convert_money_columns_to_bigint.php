<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * AID-1527 — money columns int → signed bigint + AEAT numeric domain.
     *
     * The money columns were born `->integer()` (signed int, ceiling
     * 2,147,483,647 cents = 21,474,836.47 €) and no later ALTER touched
     * them; no index, unique or FK references any of them, so the ALTER is
     * index-neutral (§1). The AEAT domain (S8) is 12 integer digits + 2
     * decimals = ±99,999,999,999,999 cents, applied PER COLUMN (§2 matrix):
     * fiscal-document amounts get a named CHECK; accumulators
     * (`eu_sales_thresholds.*`), the aggregate (`grouped_payments.amount`)
     * and the quantity column get NO CHECK — a per-document bound is not an
     * aggregate bound (gate finding 5). Negatives MUST survive: the bound is
     * symmetric (rectificatives/credit lines carry negative amounts), and
     * zero is valid everywhere (AID-956).
     *
     * DDL execution is DELIBERATE and hand-written (round-2 finding 4):
     * Laravel compiles ONE statement per `->change()` column — it does NOT
     * group them — so each table is rebuilt here with ONE grouped
     * `DB::statement('ALTER TABLE ...')` holding all its missing clauses
     * together. MySQL 8.4 type changes require COPY (no concurrent DML);
     * MariaDB documents online COPY with brief exclusive locks — the rebuild
     * is per table and the time scales with row count. Recovery = re-run
     * (resumable by introspection, below).
     *
     * Resumability is at POSTCONDITION level, not table level (round-3
     * finding 3): each column's DATA_TYPE and each CHECK's presence are
     * introspected independently and ONLY the missing clauses are composed.
     * The immediate mixed state is the payment pivot — its column is ALREADY
     * bigint while its CHECK is missing — so skip-on-column-type would lose
     * the enforcement and replay would double-add. The five-state matrix:
     *
     *   | Existing state          | Remaining work       |
     *   |-------------------------|----------------------|
     *   | Int columns, no CHECK   | MODIFY + ADD CHECK   |
     *   | Bigint columns, no CHECK| ADD CHECK only       |
     *   | Int columns, CHECK      | MODIFY only          |
     *   | Everything satisfied    | Skip (no-op)         |
     *   | Mixed within a table    | Only missing clauses |
     *
     * LOUD PREFLIGHT (ADR-016 pattern) before any DDL: read-only counts of
     * rows with `ABS(col) > 99999999999999` per AEAT-class column — plain
     * SQL, no information_schema trickery — aborting with per-column counts
     * and the repair path when non-zero. This especially covers
     * `grouped_payment_invoice.applied_amount`, which was ALREADY bigint on
     * standard schemas, so over-domain pivot rows are possible there
     * (round-3 finding 4). The preflight runs only when work remains: with
     * every postcondition satisfied the migration is a clean no-op
     * (`artisan migrate` re-runs stay idempotent), and a named CHECK already
     * present guards all future writes.
     *
     * Engine split (§2.3, honest limit): the named CHECKs land on
     * MySQL/MariaDB only — SQLite cannot ALTER-add a CHECK — so on SQLite
     * this migration is a deliberate no-op: SQLite INTEGER storage is
     * already 64-bit and the application-layer enforcer shipped with the
     * domain is its only guard there. Bulk/SQL writes on SQLite remain
     * outside the guarantee.
     */
    private const AEAT_MAX_CENTS = 99999999999999;

    /**
     * §1 widening inventory — 18 columns across 9 tables. The tail after
     * `MODIFY COLUMN `col`` preserves NOT NULL / DEFAULT / COMMENT verbatim
     * from each column's create migration. MODIFY without FIRST/AFTER keeps
     * the column position.
     *
     * @var array<string, array<string, string>> table => column => definition tail
     */
    private const WIDENINGS = [
        'invoices' => [
            'taxable_amount'   => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: €12.34 = 1234. Taxable amount before tax'",
            'total_tax_amount' => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: Sum of all tax amounts from items'",
            'total_amount'     => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: taxable_amount + total_tax_amount'",
        ],
        'invoice_items' => [
            'quantity'         => "bigint NOT NULL DEFAULT 100 COMMENT 'Base-100: 1 unit = 100, 2.5 units = 250'",
            'unit_price'       => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: price per unit measure'",
            'taxable_amount'   => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: taxable base = quantity * unit_price (before tax)'",
            'total_tax_amount' => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: Suma de todos los impuestos aplicados'",
            'total_amount'     => "bigint NOT NULL DEFAULT 0 COMMENT 'Base-100: total line = taxable_amount + total_tax_amount'",
        ],
        'article_prices' => [
            'price' => "bigint NOT NULL COMMENT 'Price in Base100 format (€12.34 = 1234)'",
        ],
        'article_overrides' => [
            'custom_price' => "bigint NOT NULL COMMENT 'Custom price in Base100 format'",
        ],
        'article_service_status' => [
            'effective_price' => "bigint NOT NULL COMMENT 'Currently applied price in Base100 (from ArticlePrice or override)'",
        ],
        'articles' => [
            'cost_price' => "bigint NULL COMMENT 'Cost price for margin calculations (Base100)'",
        ],
        'commissions' => [
            'rate'       => "bigint NOT NULL COMMENT 'Base-100 minor units: 2050 = 20.50% o €20.50 fijo (FixedDecimalCast:2)'",
            'min_amount' => "bigint NULL COMMENT 'Base-100 minor units: monto mínimo para aplicar comisión (FixedDecimalCast:2)'",
            'max_amount' => "bigint NULL COMMENT 'Base-100 minor units: monto máximo para comisión (FixedDecimalCast:2)'",
        ],
        'eu_sales_thresholds' => [
            // Accumulator / policy values: NO AEAT cap (§2) — an accumulator
            // of valid documents has no per-document ceiling. Defaults are
            // preserved verbatim; the create migration carries no COMMENT.
            'total_amount'     => 'bigint NOT NULL DEFAULT 0',
            'threshold_amount' => 'bigint NOT NULL DEFAULT 1000000',
        ],
        'article_guarantee_operations' => [
            'amount' => "bigint NOT NULL COMMENT 'Refund amount in Base-100 (FixedDecimalCast:2 at model level), strictly positive; the rectificative issues it negated'",
        ],
    ];

    /**
     * §2 AEAT-class columns — one named domain CHECK per column. Includes
     * `grouped_payment_invoice.applied_amount`, which is CHECK-only here
     * (already bigint since 2026-06-27, but per-document and unguarded: the
     * pivot insert path bypasses every cast — round-2 finding 3).
     *
     * The dual-purpose `commissions.rate` (G1) widens WITH the bound: a
     * base-100 percentage can never approach 1e14, so the bound never bites
     * % semantics, and it protects the FIXED rows, which ARE money.
     *
     * @var array<string, array<int, string>> table => [columns]
     */
    private const AEAT_CHECK_COLUMNS = [
        'invoices'                     => ['taxable_amount', 'total_tax_amount', 'total_amount'],
        'invoice_items'                => ['unit_price', 'taxable_amount', 'total_tax_amount', 'total_amount'],
        'article_prices'               => ['price'],
        'article_overrides'            => ['custom_price'],
        'article_service_status'       => ['effective_price'],
        'articles'                     => ['cost_price'],
        'commissions'                  => ['min_amount', 'max_amount', 'rate'],
        'article_guarantee_operations' => ['amount'],
        'grouped_payment_invoice'      => ['applied_amount'],
    ];

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite: the application enforcer is the only enforcement (§2.3)
        }

        $work = $this->pendingWork();

        if ($work === []) {
            return; // every postcondition holds — resumable no-op (§3 matrix)
        }

        // LOUD PREFLIGHT before any DDL (§3 / ADR-016 pattern).
        $this->preflightAeatDomain();

        foreach ($work as $table => $clauses) {
            DB::statement(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
        }
    }

    /**
     * Loud refusal (§3): rollback after widening is impossible without data
     * loss once values exceed the signed-int ceiling — documented, never
     * silent.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'larabill[AID-1527]: down() is not supported — the money-column widening (int → bigint) '
            .'cannot be rolled back without data loss once values exceed the signed-int ceiling. '
            .'Restore from backup if you truly need the pre-widening schema.'
        );
    }

    /**
     * Compose ONLY the missing clauses, per table (§3 resumability matrix).
     *
     * @return array<string, array<int, string>> table => SQL clauses still missing
     */
    private function pendingWork(): array
    {
        $work = [];

        foreach (self::WIDENINGS as $table => $columns) {
            foreach ($columns as $column => $tail) {
                if ($this->columnDataType($table, $column) !== 'bigint') {
                    $work[$table][] = sprintf('MODIFY COLUMN `%s` %s', $column, $tail);
                }
            }
        }

        foreach (self::AEAT_CHECK_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! $this->moneyCheckExists($table, $column)) {
                    $work[$table][] = sprintf(
                        'ADD CONSTRAINT `%s` CHECK (`%s` BETWEEN -%d AND %d)',
                        self::checkName($table, $column),
                        $column,
                        self::AEAT_MAX_CENTS,
                        self::AEAT_MAX_CENTS,
                    );
                }
            }
        }

        return $work;
    }

    private function columnDataType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return $row === null ? null : strtolower((string) $row->DATA_TYPE);
    }

    /**
     * Named-CHECK presence via information_schema (ADR-016 §8.2 precedent:
     * the `larabill_invoice_document_axis_coherence` backfill CHECK).
     */
    private function moneyCheckExists(string $table, string $column): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, self::checkName($table, $column), 'CHECK'],
        );

        return ((int) ($result->aggregate ?? 0)) > 0;
    }

    private static function checkName(string $table, string $column): string
    {
        return "larabill_money_domain_{$table}_{$column}";
    }

    /**
     * Read-only count of rows exceeding the AEAT bound per AEAT-class
     * column (plain SQL — information_schema-agnostic), with the
     * `grouped_payment_invoice.applied_amount` pivot explicitly covered:
     * it was ALREADY bigint on standard schemas, so over-domain rows are
     * possible there unlike the old-int columns (round-3 finding 4).
     */
    private function preflightAeatDomain(): void
    {
        $overDomain = [];

        foreach (self::AEAT_CHECK_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // Two-sided comparison, never ABS(): the implementation-gate
                // probe reproduced ABS() overflowing BIGINT on a legacy
                // applied_amount = -9223372036854775808 (SQLSTATE 22003, code
                // 1690, both engines) — fail-closed, but it loses the
                // per-column counts and repair message the preflight exists
                // to deliver. The signed minimum has no positive mirror, so
                // the lower bound uses the strict -MAX comparison.
                $count = (int) (DB::selectOne(
                    sprintf(
                        'SELECT COUNT(*) AS aggregate FROM `%s` WHERE `%s` < ? OR `%s` > ?',
                        $table,
                        $column,
                        $column,
                    ),
                    [-self::AEAT_MAX_CENTS, self::AEAT_MAX_CENTS],
                )->aggregate ?? 0);

                if ($count > 0) {
                    $overDomain[] = sprintf('%s.%s: %d row(s)', $table, $column, $count);
                }
            }
        }

        if ($overDomain !== []) {
            throw new RuntimeException(sprintf(
                'larabill[AID-1527] preflight: the AEAT-class column(s) below hold rows above the '
                .'±%d-cent domain (AEAT 12 integer digits + 2 decimals). The named CHECK this migration '
                ."adds would be violated by the existing data, so the migration aborts.\nOver-domain rows:\n  - %s\n"
                .'Repair path: export and cap or archive the offending rows (a per-document amount cannot '
                .'exceed the AEAT bound), then re-run `php artisan migrate` — the migration is resumable '
                .'and re-introspects every column and constraint.',
                self::AEAT_MAX_CENTS,
                implode("\n  - ", $overDomain),
            ));
        }
    }
};
