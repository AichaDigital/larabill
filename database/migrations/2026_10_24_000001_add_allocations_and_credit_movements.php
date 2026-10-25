<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\AllocationStatusBackfill;
use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AID-962 — payment allocations, partial collection and customer credit
 * (larabill 8.3.0, spec §3).
 *
 * Four schema changes in one migration:
 *
 *   1. `grouped_payments` gains `method` (default 1 = `PaymentMethod::Card`:
 *      every existing row was born on the card path, AID-961, so the default
 *      documents it without a backfill) and `recorded_by`.
 *   2. `grouped_payment_invoice` becomes a first-class allocation row:
 *      `status` (default 0 = `AllocationStatus::ACTIVE`), `applied_at`,
 *      `reversed_at`, `reversed_by`, `reverse_reason` — and its two UNIQUEs
 *      are RELAXED to plain indexes (see below).
 *   3. New table `credit_movements` — the customer-credit ledger, with the
 *      AEAT money-domain CHECK of 8.2.0 on `amount` (a consumer may insert
 *      bypassing the cast).
 *   4. `invoice_items.deducts_invoice_id` — line → advance-invoice reference
 *      (DECISION 4), a column and not metadata JSON so it can be queried.
 *
 * BACKFILL (same migration, mandatory): 8.x `reverse()` KEEPS the pivot rows
 * and only sets `active_invoice_id = NULL`. With the bare `status` default,
 * every already-reversed allocation would resurrect as ACTIVE. The predicate
 * is the column every 8.x reader used as the marker, never the parent's
 * state: every row with `active_invoice_id IS NULL` becomes REVERSED —
 * copying the parent's audit trio when the parent is REVERSED, and with
 * `reversed_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)` +
 * `reverse_reason = 'backfill-8.3'` when the parent is POSTED (a state with
 * no known writer), so that anomaly stays visible in the data. Both UPDATEs
 * are portable query-builder statements (correlated subqueries, no UPDATE …
 * JOIN: the latter does not compile on SQLite, where this migration also
 * runs), shared with the `larabill:backfill-allocation-status` command
 * (AllocationStatusBackfill): between `migrate` and the code switch an 8.2
 * reversal leaves an ACTIVE row under a REVERSED payment, and the command
 * re-applies the backfill after the switch.
 *
 * THE RELAXATION — the only non-additive change, declared here and in the
 * CHANGELOG. With partial payments two live payments may cover the same
 * invoice (same `active_invoice_id`), and one payment may be re-allocated
 * to an invoice after an allocation reversal (same pair). Both UNIQUEs are
 * retired; uniqueness of a live allocation moves to the invoice row lock.
 * The plain replacement of each is created BEFORE its UNIQUE is dropped:
 * the `grouped_payment_id` FK leans on the composite UNIQUE and MySQL
 * refuses to drop an index a FK needs. Dropping a constraint only RELAXES:
 * no existing row is rejected, no data is transformed — the DDL class
 * STABILITY.md rule 2 admits in a minor (precedent: v7.1.0, AID-1611).
 *
 * RESUMABLE: MySQL DDL is not transactional, so every step is guarded by
 * introspection (`Schema::hasColumn`/`hasIndex`/`hasTable`, information_schema
 * for the CHECK) and a partial run repeats cleanly.
 */
return new class extends Migration
{
    /**
     * AEAT numeric domain (12 integer digits + 2 decimals), the same bound
     * as `2026_10_18_000001_convert_money_columns_to_bigint` (AID-1527).
     */
    private const AEAT_MAX_CENTS = 99999999999999;

    private const PIVOT = 'grouped_payment_invoice';

    private const PAIR_UNIQUE = 'grouped_payment_invoice_grouped_payment_id_invoice_id_unique';

    private const PAIR_INDEX = 'grouped_payment_invoice_payment_invoice_index';

    private const ACTIVE_UNIQUE = 'grouped_payment_invoice_active_invoice_id_unique';

    private const ACTIVE_INDEX = 'grouped_payment_invoice_active_invoice_id_index';

    private const AMOUNT_CHECK = 'larabill_money_domain_credit_movements_amount';

    public function up(): void
    {
        $this->addPivotColumns();
        $this->relaxPivotUniques();
        $this->backfillReversedAllocations();
        $this->addPaymentColumns();
        $this->createCreditMovements();
        $this->addDeductsInvoiceId();
    }

    /**
     * Reverse the migration. The two UNIQUEs are re-created FIRST, before any
     * other reversal step: on a database that accumulated valid 8.3 rows the
     * old constraints forbid (two live allocations of one invoice, or the same
     * payment/invoice pair re-allocated after a reversal), re-creating them
     * fails loud with the duplicate key listed by the engine — and nothing has
     * been dropped yet. The honest rollback for a relaxation is the previous
     * release's backup, not a silent dedup that would invent which allocation
     * "loses".
     */
    public function down(): void
    {
        $this->restorePivotUniques();

        if (Schema::hasColumn('invoice_items', 'deducts_invoice_id')) {
            Schema::table('invoice_items', function (Blueprint $table): void {
                $table->dropForeign(['deducts_invoice_id']);
                $table->dropIndex(['deducts_invoice_id']);
                $table->dropColumn('deducts_invoice_id');
            });
        }

        Schema::dropIfExists('credit_movements');

        foreach (['recorded_by', 'method'] as $column) {
            $this->dropColumnWithIndex('grouped_payments', $column);
        }

        foreach (['reverse_reason', 'reversed_by', 'reversed_at', 'applied_at', 'status'] as $column) {
            $this->dropColumnWithIndex(self::PIVOT, $column);
        }
    }

    private function addPivotColumns(): void
    {
        Schema::table(self::PIVOT, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::PIVOT, 'status')) {
                $table->unsignedTinyInteger('status')->default(0)->comment('AllocationStatus: 0=active, 1=reversed');
            }

            if (! Schema::hasColumn(self::PIVOT, 'applied_at')) {
                $table->dateTime('applied_at')->nullable()->comment('Date the allocation settles the invoice; legacy rows read payment.paid_at');
            }

            if (! Schema::hasColumn(self::PIVOT, 'reversed_at')) {
                $table->dateTime('reversed_at')->nullable();
            }

            if (! Schema::hasColumn(self::PIVOT, 'reversed_by')) {
                // Reversal actor — char(36) + index, no hard FK (may be a system actor).
                MigrationHelper::userIdColumn($table, 'reversed_by', nullable: true);
            }

            if (! Schema::hasColumn(self::PIVOT, 'reverse_reason')) {
                $table->string('reverse_reason')->nullable();
            }
        });
    }

    /**
     * Plain index first, UNIQUE second — for each of the two constraints.
     */
    private function relaxPivotUniques(): void
    {
        if (! Schema::hasIndex(self::PIVOT, self::PAIR_INDEX)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->index(['grouped_payment_id', 'invoice_id'], self::PAIR_INDEX);
            });
        }

        if (Schema::hasIndex(self::PIVOT, self::PAIR_UNIQUE)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->dropUnique(self::PAIR_UNIQUE);
            });
        }

        if (! Schema::hasIndex(self::PIVOT, self::ACTIVE_INDEX)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->index('active_invoice_id', self::ACTIVE_INDEX);
            });
        }

        if (Schema::hasIndex(self::PIVOT, self::ACTIVE_UNIQUE)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->dropUnique(self::ACTIVE_UNIQUE);
            });
        }
    }

    /**
     * Mirror of relaxPivotUniques(): UNIQUE first, then drop its plain index
     * (the FK can lean on the UNIQUE again once it exists).
     */
    private function restorePivotUniques(): void
    {
        if (! Schema::hasIndex(self::PIVOT, self::PAIR_UNIQUE)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->unique(['grouped_payment_id', 'invoice_id'], self::PAIR_UNIQUE);
            });
        }

        if (! Schema::hasIndex(self::PIVOT, self::ACTIVE_UNIQUE)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->unique('active_invoice_id', self::ACTIVE_UNIQUE);
            });
        }

        if (Schema::hasIndex(self::PIVOT, self::PAIR_INDEX)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->dropIndex(self::PAIR_INDEX);
            });
        }

        if (Schema::hasIndex(self::PIVOT, self::ACTIVE_INDEX)) {
            Schema::table(self::PIVOT, function (Blueprint $table): void {
                $table->dropIndex(self::ACTIVE_INDEX);
            });
        }
    }

    /**
     * The NULL marker becomes REVERSED (spec §3.2). Re-runs are no-ops: both
     * statements only touch rows still ACTIVE. The statements live in
     * AllocationStatusBackfill so `larabill:backfill-allocation-status` can
     * re-apply them after the code switch (the deploy window).
     */
    private function backfillReversedAllocations(): void
    {
        AllocationStatusBackfill::run();
    }

    private function addPaymentColumns(): void
    {
        Schema::table('grouped_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('grouped_payments', 'method')) {
                $table->unsignedTinyInteger('method')->default(1)->comment('PaymentMethod: 0=bank_transfer, 1=card, 2=cash, 3=other; legacy rows are card (AID-961)');
            }

            if (! Schema::hasColumn('grouped_payments', 'recorded_by')) {
                // Recording actor — char(36) + index, no hard FK (as reversed_by).
                MigrationHelper::userIdColumn($table, 'recorded_by', nullable: true);
            }
        });
    }

    private function createCreditMovements(): void
    {
        if (! Schema::hasTable('credit_movements')) {
            Schema::create('credit_movements', function (Blueprint $table): void {
                $table->uuid('id')->primary()->comment('UUID v7 primary key');

                // Customer owning the credit. char(36) + index, no hard FK.
                MigrationHelper::userIdColumn($table, 'billable_user_id');

                $table->char('currency', 3)->comment('ISO 4217 — credit balance is per (customer, currency)');
                $table->unsignedTinyInteger('kind')->comment('CreditMovementKind: 0=surplus, 1=top_up (in, > 0); 2=applied, 3=refunded (out, < 0)');
                $table->bigInteger('amount')->comment('Base-100, signed: entries > 0, exits < 0');

                $table->foreignUuid('grouped_payment_id')
                    ->comment('Payment the money comes from; exits inherit the consumed entry\'s payment')
                    ->constrained('grouped_payments')
                    ->restrictOnDelete();

                $table->unsignedBigInteger('allocation_id')->nullable()->comment('grouped_payment_invoice.id: consuming allocation (applied) or reversed allocation (surplus)');
                $table->foreign('allocation_id')->references('id')->on(self::PIVOT)->restrictOnDelete();

                $table->foreignUuid('consumes_movement_id')
                    ->nullable()
                    ->comment('Exits: the entry they consume (FIFO per entry)')
                    ->constrained('credit_movements')
                    ->restrictOnDelete();

                $table->foreignUuid('advance_invoice_id')
                    ->nullable()
                    ->comment('Advance invoice documenting an entry (DECISION 4); written by the consumer')
                    ->constrained('invoices')
                    ->nullOnDelete();

                $table->unsignedTinyInteger('method')->nullable()->comment('PaymentMethod of the money leaving on a refunded movement; null otherwise');

                MigrationHelper::userIdColumn($table, 'recorded_by', nullable: true);

                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('occurred_at')->comment('FIFO order; ties broken by the v7 id');
                $table->dateTime('reversed_at')->nullable();

                MigrationHelper::userIdColumn($table, 'reversed_by', nullable: true);

                $table->string('reverse_reason')->nullable();
                $table->timestamps();

                $table->index(['billable_user_id', 'currency', 'occurred_at']);
                $table->index('consumes_movement_id');
                $table->index('grouped_payment_id');
                $table->index('allocation_id');
            });
        }

        // Named AEAT-domain CHECK — MySQL/MariaDB only: SQLite cannot
        // ALTER-add a CHECK (same engine split as AID-1527 §2.3).
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && ! $this->amountCheckExists()) {
            DB::statement(sprintf(
                'ALTER TABLE `credit_movements` ADD CONSTRAINT `%s` CHECK (`amount` BETWEEN -%d AND %d)',
                self::AMOUNT_CHECK,
                self::AEAT_MAX_CENTS,
                self::AEAT_MAX_CENTS,
            ));
        }
    }

    private function addDeductsInvoiceId(): void
    {
        if (Schema::hasColumn('invoice_items', 'deducts_invoice_id')) {
            return;
        }

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->foreignUuid('deducts_invoice_id')
                ->nullable()
                ->comment('Advance invoice this line deducts (DECISION 4)')
                ->constrained('invoices')
                ->nullOnDelete();

            $table->index('deducts_invoice_id');
        });
    }

    private function amountCheckExists(): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            ['credit_movements', self::AMOUNT_CHECK, 'CHECK'],
        );

        return ((int) ($result->aggregate ?? 0)) > 0;
    }

    /**
     * Drop a column, dropping first the single-column index userIdColumn()
     * gives it (SQLite refuses to drop an indexed column).
     */
    private function dropColumnWithIndex(string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $column): void {
            $index = "{$tableName}_{$column}_index";

            if (Schema::hasIndex($tableName, $index)) {
                $table->dropIndex($index);
            }

            $table->dropColumn($column);
        });
    }
};
