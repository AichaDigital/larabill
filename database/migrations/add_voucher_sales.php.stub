<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AID-1825 — the voucher sale (minimal scope §1, §1a): balance contracted to
 * consume services, sold through a numbered invoice and consumed against it.
 *
 * Four additive pieces, all nullable or new, none touching 9.1 data:
 *
 *   1. `credit_operations` — one row per keyed ledger operation
 *      (consumption, and whatever later keyed operation needs replay by
 *      key). `operation_key` is UNIQUE: the same key replays, a different
 *      payload under the same key conflicts on `fingerprint`.
 *   2. `voucher_sale_claims` — the claim a payment places on a proforma at
 *      S1 (one per proforma), carrying the stage of the sale and the FROZEN
 *      EXPECTATION the later stages revalidate (amount, currency, billable
 *      user), the sale mark (`issued_by_sale`) and whether the optional hook
 *      ran. `operation_key` is the sale's idempotency key.
 *   3. `credit_movements.sale_invoice_id` — the voucher backing of an ENTRY
 *      (the issued voucher-sale invoice that declared its VAT); the existing
 *      `advance_invoice_id` keeps meaning exactly what it means in 9.1.
 *      `credit_operation_id`, `service_ref` and `service_description` carry
 *      a consumption exit's key and the service it paid for.
 *   4. `invoices.sale_claim_id` — the invoice born of a claimed sale, the
 *      row every integrity read starts from (so a lost claim is still
 *      listed).
 *
 * No backfill: a 9.1 database has no voucher, no claim and no consumption.
 * Idempotent and resumable (every DDL step is guarded), as the upgrade
 * policy of AID-398 requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCreditOperations();
        $this->createVoucherSaleClaims();
        $this->addVoucherColumnsToCreditMovements();
        $this->addSaleClaimToInvoices();
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'sale_claim_id')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(['sale_claim_id']);
                $table->dropUnique(['sale_claim_id']);
                $table->dropColumn('sale_claim_id');
            });
        }

        foreach (['sale_invoice_id', 'credit_operation_id'] as $column) {
            if (Schema::hasColumn('credit_movements', $column)) {
                Schema::table('credit_movements', function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                    $table->dropColumn($column);
                });
            }
        }

        foreach (['service_ref', 'service_description'] as $column) {
            if (Schema::hasColumn('credit_movements', $column)) {
                Schema::table('credit_movements', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('voucher_sale_claims');
        Schema::dropIfExists('credit_operations');
    }

    /**
     * Resumability: the schema builder compiles a table's columns, each
     * unique/index and each foreign key as SEPARATE statements, so a run
     * interrupted between them leaves a table or column without one of its
     * constraints. Tables and columns are guarded by existence, and every
     * unique, index and foreign key is then ensured ON ITS OWN, so a rerun
     * adds only what is missing (AID-398; implementation gate round 2).
     */
    private function createCreditOperations(): void
    {
        if (! Schema::hasTable('credit_operations')) {
            Schema::create('credit_operations', function (Blueprint $table): void {
                $table->uuid('id')->primary()->comment('UUID v7 primary key');
                $table->string('operation_key', 191)->comment('Consumer-declared idempotency key of the ledger operation');
                $table->unsignedTinyInteger('kind')->comment('CreditOperationKind: 0=consumption');
                MigrationHelper::userIdColumn($table, 'billable_user_id');
                $table->string('fingerprint', 64)->comment('sha256 of the immutable payload; a replay with another payload conflicts');
                $table->dateTime('recorded_at');
                $table->timestamps();
            });
        }

        $this->ensureUnique('credit_operations', 'operation_key');
    }

    private function createVoucherSaleClaims(): void
    {
        if (! Schema::hasTable('voucher_sale_claims')) {
            Schema::create('voucher_sale_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary()->comment('UUID v7 primary key');
                $table->uuid('proforma_id')->comment('The claimed proforma — one claim per proforma');
                $table->uuid('grouped_payment_id')->comment('The payment that funds the sale');
                $table->string('operation_key', 191)->comment('The sale idempotency key (the consumer-declared payment event)');
                $table->unsignedTinyInteger('stage')->default(0)->comment('VoucherSaleStage: 0=pending, 1=issuing, 2=backed');

                $table->bigInteger('expected_amount')->comment('Base-100 — frozen at S1, revalidated at S3a and S3b');
                $table->char('expected_currency', 3);
                MigrationHelper::userIdColumn($table, 'expected_billable_user_id');

                $table->boolean('issued_by_sale')->default(false)->comment('The sale mark: issue() ran inside sellVoucher()');
                $table->boolean('hook_ran')->default(false)->comment('The optional issuance hook ran inside the issue transaction');

                $table->dateTime('issuing_at')->nullable();
                $table->dateTime('backed_at')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureUnique('voucher_sale_claims', 'proforma_id');
        $this->ensureUnique('voucher_sale_claims', 'operation_key');
        $this->ensureIndex('voucher_sale_claims', 'stage');
        $this->ensureIndex('voucher_sale_claims', 'grouped_payment_id');
        $this->ensureForeign('voucher_sale_claims', 'proforma_id', 'invoices');
        $this->ensureForeign('voucher_sale_claims', 'grouped_payment_id', 'grouped_payments');
    }

    private function addVoucherColumnsToCreditMovements(): void
    {
        Schema::table('credit_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_movements', 'sale_invoice_id')) {
                $table->uuid('sale_invoice_id')->nullable()->comment('Voucher backing of an entry: the issued voucher-sale invoice (AID-1825)');
            }

            if (! Schema::hasColumn('credit_movements', 'credit_operation_id')) {
                $table->uuid('credit_operation_id')->nullable()->comment('The keyed operation that created this exit (consumptions)');
            }

            if (! Schema::hasColumn('credit_movements', 'service_ref')) {
                $table->string('service_ref')->nullable()->comment('Consumption exits: the consumer reference of the service paid');
            }

            if (! Schema::hasColumn('credit_movements', 'service_description')) {
                $table->string('service_description')->nullable();
            }
        });

        $this->ensureIndex('credit_movements', 'sale_invoice_id');
        $this->ensureIndex('credit_movements', 'credit_operation_id');
        $this->ensureForeign('credit_movements', 'sale_invoice_id', 'invoices');
        $this->ensureForeign('credit_movements', 'credit_operation_id', 'credit_operations');
    }

    private function addSaleClaimToInvoices(): void
    {
        if (! Schema::hasColumn('invoices', 'sale_claim_id')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->uuid('sale_claim_id')->nullable()->comment('The voucher-sale claim this invoice was born of (AID-1825)');
            });
        }

        $this->ensureUnique('invoices', 'sale_claim_id');
        $this->ensureForeign('invoices', 'sale_claim_id', 'voucher_sale_claims');
    }

    /** `<table>_<column>_unique`, added only when no unique index covers exactly that column. */
    private function ensureUnique(string $table, string $column): void
    {
        if ($this->hasIndexOn($table, $column, unique: true)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->unique($column));
    }

    /** `<table>_<column>_index`, added only when no index (unique or not) covers exactly that column. */
    private function ensureIndex(string $table, string $column): void
    {
        if ($this->hasIndexOn($table, $column, unique: false)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($column));
    }

    /**
     * `<table>_<column>_foreign` → `<references>.id`, RESTRICT on delete,
     * added only when no foreign key exists on that column. SQLite cannot
     * add a foreign key to an existing table (the builder skips it), so the
     * check is not attempted there — the fresh-install path on SQLite is the
     * test suite, where the constraint is not relied upon.
     */
    private function ensureForeign(string $table, string $column, string $references): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (Schema::getForeignKeys($table) as $fk) {
            if (($fk['columns'] ?? []) === [$column]) {
                return;
            }
        }

        Schema::table($table, fn (Blueprint $t) => $t->foreign($column)->references('id')->on($references)->restrictOnDelete());
    }

    private function hasIndexOn(string $table, string $column, bool $unique): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === [$column] && (! $unique || ($index['unique'] ?? false) === true)) {
                return true;
            }
        }

        return false;
    }
};
