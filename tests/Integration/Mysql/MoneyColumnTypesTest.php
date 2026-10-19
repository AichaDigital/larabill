<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

/**
 * AID-240 — "nada decimal en BD" contract for the parallel money/rate systems
 * that AID-237 (Base100Int → FixedDecimal) left untouched.
 *
 * Every monetary/rate column that the package owns must be an integer type
 * (base-100 minor units), never `decimal`. SQLite collapses both to NUMERIC,
 * so this invariant can only be proven against real MySQL.
 *
 * AID-1527 extends the contract: the money columns are widened to SIGNED
 * BIGINT (the int ceiling of 21,474,836.47 € blocked the consumer), and every
 * AEAT-class column (spec §2 matrix) carries a named domain CHECK
 * (`larabill_money_domain_<table>_<column>`, ±99,999,999,999,999 cents —
 * AEAT 12 integer digits + 2 decimals). Accumulators and quantities get NO
 * CHECK: a per-document bound is not an aggregate bound (gate finding 5).
 */

/** AEAT symmetric bound in cents: 12 integer digits + 2 decimals (S8). */
const LARABILL_AEAT_MAX_CENTS = 99_999_999_999_999;

/**
 * AID-1527 §1 widening inventory — 18 columns across 9 tables.
 *
 * @var array<string, array<int, string>> table => [columns]
 */
const LARABILL_MONEY_WIDEN_COLUMNS = [
    'invoices'                      => ['taxable_amount', 'total_tax_amount', 'total_amount'],
    'invoice_items'                 => ['quantity', 'unit_price', 'taxable_amount', 'total_tax_amount', 'total_amount'],
    'article_prices'                => ['price'],
    'article_overrides'             => ['custom_price'],
    'article_service_status'        => ['effective_price'],
    'articles'                      => ['cost_price'],
    'commissions'                   => ['min_amount', 'max_amount', 'rate'],
    'eu_sales_thresholds'           => ['total_amount', 'threshold_amount'],
    'article_guarantee_operations'  => ['amount'],
];

/**
 * AID-1527 §2 AEAT-class columns — one named domain CHECK per column,
 * INCLUDING the CHECK-only pivot `grouped_payment_invoice.applied_amount`
 * (already bigint since 2026-06-27, but per-document and unguarded: the
 * pivot insert path bypasses every cast — round-2 finding 3).
 *
 * NOT here (per the §2 matrix): `eu_sales_thresholds.*` (accumulators),
 * `grouped_payments.amount` (aggregate), `invoice_items.quantity` (×100,
 * not money).
 *
 * @var array<string, array<int, string>> table => [columns]
 */
const LARABILL_AEAT_CHECK_COLUMNS = [
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

/**
 * Named-CHECK presence via information_schema (same lookup pattern as the
 * ADR-016 §8.2 backfill CHECK): TABLE_CONSTRAINTS for the typed row,
 * CHECK_CONSTRAINTS for the actual clause. Prefixed name — file-level Pest
 * helpers live in the suite's global namespace.
 */
function larabillMoneyDomainCheckExists(string $table, string $column): bool
{
    $row = DB::selectOne(
        'SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc
         JOIN information_schema.CHECK_CONSTRAINTS cc
           ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
          AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
           AND tc.TABLE_NAME = ?
           AND tc.CONSTRAINT_NAME = ?
           AND tc.CONSTRAINT_TYPE = ?',
        [$table, "larabill_money_domain_{$table}_{$column}", 'CHECK'],
    );

    return $row !== null;
}

/**
 * Execute $fn while recording every `ALTER TABLE` statement the default
 * connection executes. The §3 operational claims are about EXECUTED DDL, not
 * final schema state (implementation-gate finding 4: the no-op and
 * only-missing-clause tests passed against an always-rebuild mutant because
 * they only inspected the end state).
 *
 * @return list<string> the exact SQL of every executed ALTER TABLE
 */
function larabillRecordAlterStatements(callable $fn): array
{
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_starts_with(strtolower($query->sql), 'alter table')) {
            $statements[] = $query->sql;
        }
    });

    $fn();

    return $statements;
}

describe('AID-240 — no decimal columns in package money/rate fields (MySQL)', function () {

    it('stores commissions.rate as an integer, not decimal', function () {
        $this->bootstrap();

        // AID-1527: the dual-purpose rate column (G1) was widened WITH the
        // AEAT symmetric bound — still an integer type, never decimal.
        expect($this->getMysqlColumnType('commissions', 'rate'))->toBe('bigint');
    });

    it('stores eu_sales_thresholds amounts as integers, not decimals', function () {
        $this->bootstrap();

        // AID-1527 widens both to bigint WITHOUT an AEAT cap (§2: they
        // accumulate N valid documents) — still integer, never decimal.
        expect($this->getMysqlColumnType('eu_sales_thresholds', 'total_amount'))->toBe('bigint');
        expect($this->getMysqlColumnType('eu_sales_thresholds', 'threshold_amount'))->toBe('bigint');
    });

});

describe('AID-1527 — money columns signed bigint + AEAT domain CHECKs (MySQL)', function () {

    it('stores every §1 money column as signed bigint', function () {
        $this->bootstrap();

        $notBigint = [];
        foreach (LARABILL_MONEY_WIDEN_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $type = $this->getMysqlColumnType($table, $column);
                if ($type !== 'bigint') {
                    $notBigint[] = "{$table}.{$column} is '{$type}'";
                }
            }
        }

        expect($notBigint)->toBe(
            [],
            'These money columns are not signed bigint: '.implode(', ', $notBigint)
        );
    });

    it('carries a named AEAT domain CHECK on every AEAT-class column', function () {
        $this->bootstrap();

        $missing = [];
        foreach (LARABILL_AEAT_CHECK_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! larabillMoneyDomainCheckExists($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        expect($missing)->toBe(
            [],
            'These AEAT-class columns lack their larabill_money_domain_* CHECK: '.implode(', ', $missing)
        );
    });

    it('declares NO AEAT domain CHECK on accumulator, aggregate and quantity columns (§2 matrix)', function () {
        $this->bootstrap();

        // A per-document bound is not an aggregate bound: capping an
        // accumulator would reject a legitimate sum of individually valid
        // amounts, and a scale-2 quantity is not money (G2).
        foreach ([
            'invoice_items.quantity',
            'eu_sales_thresholds.total_amount',
            'eu_sales_thresholds.threshold_amount',
            'grouped_payments.amount',
        ] as $pointer) {
            [$table, $column] = explode('.', $pointer);

            expect(larabillMoneyDomainCheckExists($table, $column))
                ->toBeFalse("{$pointer} must NOT carry an AEAT domain CHECK (§2 matrix)");
        }
    });

});

describe('AID-1527 §3 — migration machinery: resumable postconditions + loud preflight (MySQL)', function () {

    /** Fresh-migrated schema, then the migration object for direct re-runs. */
    function larabillMoneyMigration(): object
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_18_000001_convert_money_columns_to_bigint.php';
        expect($migration)->toBeObject();

        return $migration;
    }

    it('is a clean no-op when every postcondition already holds (skip state) — ZERO DDL executed', function () {
        $this->bootstrap();

        // Re-running the migration body must neither duplicate a named
        // CHECK (MySQL would reject the duplicate) nor touch the columns.
        // The claim is EXECUTED DDL, not final state (finding 4).
        $statements = larabillRecordAlterStatements(fn () => larabillMoneyMigration()->up());

        expect($statements)->toBe(
            [],
            'The satisfied state must execute ZERO statements, got: '.implode(' | ', $statements)
        );

        foreach (LARABILL_AEAT_CHECK_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                expect(larabillMoneyDomainCheckExists($table, $column))->toBeTrue("{$table}.{$column}");
            }
        }
    });

    it('composes ONLY the missing clause in the mixed state (bigint column, CHECK dropped) — one ADD CONSTRAINT statement, never a MODIFY', function () {
        $this->bootstrap();

        // Simulate the §3 matrix's immediate mixed state: the column is
        // already bigint while its CHECK is missing (the pivot's natural
        // pre-migration shape; here forced on commissions.rate). DROP
        // CONSTRAINT is the portable form — MariaDB rejects DROP CHECK.
        DB::statement('ALTER TABLE `commissions` DROP CONSTRAINT `larabill_money_domain_commissions_rate`');
        expect(larabillMoneyDomainCheckExists('commissions', 'rate'))->toBeFalse();

        $statements = larabillRecordAlterStatements(fn () => larabillMoneyMigration()->up());

        expect(larabillMoneyDomainCheckExists('commissions', 'rate'))->toBeTrue();
        // The MODIFY path must NOT have re-run: the column is still the
        // widened type and the statement did not double-apply (no error).
        expect($this->getMysqlColumnType('commissions', 'rate'))->toBe('bigint');

        // The executed DDL itself: exactly ONE grouped statement, holding
        // the missing ADD CONSTRAINT and NO MODIFY (always-modify mutant).
        expect(count($statements))->toBe(1, 'Expected exactly one ALTER, got: '.implode(' | ', $statements))
            ->and($statements[0])->toContain('ADD CONSTRAINT `larabill_money_domain_commissions_rate`')
            ->and($statements[0])->not->toContain('MODIFY');
    });

    it('emits exactly ONE grouped statement per touched table holding BOTH its MODIFY and ADD CONSTRAINT clauses in the mixed state (split-DDL mutant)', function () {
        $this->bootstrap();

        // article_prices: force BOTH kinds of work — revert the column type
        // (MODIFY needed) and drop its CHECK (ADD CONSTRAINT needed).
        DB::statement("ALTER TABLE `article_prices` MODIFY `price` INT NOT NULL COMMENT 'boundary probe: reverted to int'");
        DB::statement('ALTER TABLE `article_prices` DROP CONSTRAINT `larabill_money_domain_article_prices_price`');

        // commissions: ADD-CONSTRAINT-only work — all three CHECKs dropped.
        foreach (['min_amount', 'max_amount', 'rate'] as $column) {
            DB::statement("ALTER TABLE `commissions` DROP CONSTRAINT `larabill_money_domain_commissions_{$column}`");
        }

        $statements = larabillRecordAlterStatements(fn () => larabillMoneyMigration()->up());

        expect(count($statements))->toBe(2, 'Expected ONE grouped ALTER per touched table, got: '.implode(' | ', $statements));

        $priceStatement       = null;
        $commissionsStatement = null;
        foreach ($statements as $sql) {
            if (str_contains($sql, '`article_prices`')) {
                $priceStatement = $sql;
            }
            if (str_contains($sql, '`commissions`')) {
                $commissionsStatement = $sql;
            }
        }

        // article_prices: one statement carrying BOTH clauses together.
        expect($priceStatement)->toContain('MODIFY COLUMN `price`')
            ->and($priceStatement)->toContain('ADD CONSTRAINT `larabill_money_domain_article_prices_price`');

        // commissions: one grouped statement for ALL THREE missing CHECKs.
        expect($commissionsStatement)->not->toContain('MODIFY')
            ->and(substr_count($commissionsStatement, 'ADD CONSTRAINT'))->toBe(3);

        // Final state holds too: the type is restored and every CHECK back.
        expect($this->getMysqlColumnType('article_prices', 'price'))->toBe('bigint')
            ->and(larabillMoneyDomainCheckExists('article_prices', 'price'))->toBeTrue()
            ->and(larabillMoneyDomainCheckExists('commissions', 'min_amount'))->toBeTrue()
            ->and(larabillMoneyDomainCheckExists('commissions', 'max_amount'))->toBeTrue()
            ->and(larabillMoneyDomainCheckExists('commissions', 'rate'))->toBeTrue();
    });

    it('refuses down() LOUDLY with a RuntimeException (silent-down mutant)', function () {
        $this->bootstrap();

        larabillMoneyMigration()->down();
    })->throws(RuntimeException::class, 'down() is not supported');

    it('aborts LOUD with per-column counts before any DDL when over-domain rows exist', function () {
        $this->bootstrap();

        // Raw bypass write (no cast involved): drop the CHECK (portable
        // DROP CONSTRAINT — MariaDB rejects DROP CHECK), plant an
        // over-domain row, then the migration must refuse to add the CHECK
        // over violating data — with the per-column count in the message.
        DB::statement('ALTER TABLE `commissions` DROP CONSTRAINT `larabill_money_domain_commissions_rate`');
        DB::table('commissions')->insert([
            'level' => 0, 'rate' => 100_000_000_000_001, 'valid_from' => '2026-01-01',
            'name'  => 'preflight-probe', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The signed-minimum pivot row is the regression fixture for the
        // two-sided preflight bound (implementation-gate round 2): the
        // shipped ABS(col) > bound probe overflowed BIGINT on exactly
        // applied_amount = PHP_INT_MIN (SQLSTATE 22003/1690, both engines)
        // and failed closed WITHOUT the per-column diagnostic. The pivot
        // column was ALREADY bigint on standard schemas, so this row shape
        // is reachable on consumers. Drop the pivot CHECK, seed the raw
        // parent chain, plant PHP_INT_MIN — the preflight must name the
        // column instead of dying on the probe itself.
        $userId    = $this->seedUser();
        $invoiceId = (string) Str::orderedUuid();
        $paymentId = (string) Str::orderedUuid();
        // §8.5 transitional shape: a numbered fiscal row must carry the
        // document axis ISSUED + a submission stamp, or the ADR-016 §8.2
        // CHECK rejects it before the preflight ever runs (the same
        // stamp/fixture coupling the v8.1.0 release measured).
        DB::table('invoices')->insert([
            'id'                       => $invoiceId, 'prefix' => 'FAC', 'serie' => 1, 'status' => 2,
            'fiscal_number'            => 'FAC-2026-000001', 'series_number' => 1,
            'fiscal_year'              => (int) date('Y'), 'invoice_date' => now()->toDateString(),
            'issued_at'                => now(),
            'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
            'fiscal_submission_status' => FiscalSubmissionStatus::NOT_REQUIRED->value,
            'user_id'                  => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('grouped_payments')->insert([
            'id'               => $paymentId, 'paid_at' => now(), 'reference' => 'preflight-min',
            'billable_user_id' => $userId, 'amount' => 0,
            'idempotency_key'  => 'aid1527-preflight-min',
            'created_at'       => now(), 'updated_at' => now(),
        ]);
        DB::statement('ALTER TABLE `grouped_payment_invoice` DROP CONSTRAINT `larabill_money_domain_grouped_payment_invoice_applied_amount`');
        DB::table('grouped_payment_invoice')->insert([
            'grouped_payment_id' => $paymentId, 'invoice_id' => $invoiceId,
            'applied_amount'     => PHP_INT_MIN, 'previous_status' => 2,
            'created_at'         => now(), 'updated_at' => now(),
        ]);

        larabillMoneyMigration()->up();
    })->throws(RuntimeException::class, 'grouped_payment_invoice.applied_amount: 1 row(s)');

});
