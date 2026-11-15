<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AID-1997 — the one-off sale without vouchers (spec §6, revision 11-final):
 * a reservation claims a proforma for a direct sale, settles it through a
 * named C1 transition and a single allocation, and refunds it through a
 * rectifying invoice with no credit balance (D8 rev11).
 *
 * Three additive pieces, all nullable or new, none touching 9.5 data:
 *
 *   1. `sale_reservations` — one row per keyed sale attempt: the frozen
 *      expectation (amount, currency, billable user), the stage of the sale
 *      (Reserved/Paid/Issuing/Settled/Released), the payment and allocation
 *      links (UNIQUE), and the `issued_by_sale` mark. `operation_key` is
 *      UNIQUE: the same key replays, a different payload conflicts.
 *   2. `sale_refunds` — the durable refund intent (D8 rev11): the frozen
 *      payload (resolved gross, method, reference, refunded_at, reason), the
 *      stage (Intended/Refunded/Abandoned), the COMPLETE frozen fiscal plan
 *      (`bucket_plan` json: entries + frozen_sources) computed at R1, and
 *      the rectificative link (UNIQUE, written in the creation's phase-1
 *      commit). `refund_key` is UNIQUE.
 *   3. `invoices.sale_reservation_id` — the invoice born of the sale, and
 *      `invoices.sale_refund_id` — the durable draft owned by a refund
 *      intent from creation (R3-3). Both UNIQUE.
 *
 * No backfill: a 9.5 database has no reservation, no refund. Idempotent and
 * resumable (every DDL step is guarded; each unique, index and foreign key
 * is ensured on its own), as the upgrade policy of AID-398 requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createSaleReservations();
        $this->createSaleRefunds();
        $this->addSaleColumnsToInvoices();
    }

    public function down(): void
    {
        foreach (['sale_reservation_id', 'sale_refund_id'] as $column) {
            if (Schema::hasColumn('invoices', $column)) {
                Schema::table('invoices', function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                    $table->dropUnique([$column]);
                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('sale_refunds');
        Schema::dropIfExists('sale_reservations');
    }

    /**
     * Resumability: the schema builder compiles a table's columns, each
     * unique/index and each foreign key as SEPARATE statements, so a run
     * interrupted between them leaves a table or column without one of its
     * constraints. Tables and columns are guarded by existence, and every
     * unique, index and foreign key is then ensured ON ITS OWN (the AID-1825
     * pattern; AID-398).
     */
    private function createSaleReservations(): void
    {
        if (! Schema::hasTable('sale_reservations')) {
            Schema::create('sale_reservations', function (Blueprint $table): void {
                $table->uuid('id')->primary()->comment('UUID v7 primary key');
                $table->uuid('proforma_id')->comment('The reserved proforma');
                $table->string('operation_key', 191)->comment('Consumer-declared idempotency key of the sale');
                $table->unsignedTinyInteger('stage')->default(0)->comment('SaleReservationStage: 0=reserved, 1=paid, 2=issuing, 3=settled, 4=released');

                $table->bigInteger('expected_amount')->comment('Base-100 — frozen at reserve(), revalidated at S1/S3');
                $table->char('expected_currency', 3);
                MigrationHelper::userIdColumn($table, 'expected_billable_user_id');

                $table->uuid('grouped_payment_id')->nullable()->comment('The payment recorded at S1');
                // The settlement allocation's PK is an int autoincrement
                // (PaymentAllocation: $incrementing, int keyType), not a uuid.
                $table->unsignedBigInteger('allocation_id')->nullable()->comment('The settlement allocation written at S4');
                $table->boolean('issued_by_sale')->default(false)->comment('The sale mark: issue() ran inside settle() S3');

                $table->dateTime('paid_at')->nullable();
                $table->dateTime('issuing_at')->nullable();
                $table->dateTime('settled_at')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->string('released_by')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureUnique('sale_reservations', 'operation_key');
        $this->ensureUnique('sale_reservations', 'grouped_payment_id');
        $this->ensureUnique('sale_reservations', 'allocation_id');
        $this->ensureIndex('sale_reservations', 'proforma_id');
        $this->ensureIndex('sale_reservations', 'stage');
        $this->ensureForeign('sale_reservations', 'proforma_id', 'invoices');
        $this->ensureForeign('sale_reservations', 'grouped_payment_id', 'grouped_payments');
    }

    private function createSaleRefunds(): void
    {
        if (! Schema::hasTable('sale_refunds')) {
            Schema::create('sale_refunds', function (Blueprint $table): void {
                $table->uuid('id')->primary()->comment('UUID v7 primary key');
                $table->uuid('sale_reservation_id')->comment('The settled sale being refunded');
                $table->string('refund_key', 191)->comment("The refund's own idempotency key");
                $table->unsignedTinyInteger('stage')->default(0)->comment('SaleRefundStage: 0=intended, 1=refunded, 2=abandoned');

                $table->uuid('rectificative_invoice_id')->nullable()->comment('The refund-owned rectificative, linked in the creation phase-1 commit (R3-3)');
                $table->bigInteger('amount')->comment('Base-100 — the frozen RESOLVED gross, positive');
                $table->json('bucket_plan')->nullable()->comment('The COMPLETE frozen fiscal plan computed at R1 (D8 rev11: entries + frozen_sources); immutable once written');
                $table->char('currency', 3);

                $table->dateTime('refunded_at');
                $table->unsignedTinyInteger('method')->comment('PaymentMethod of the money out');
                $table->string('reference')->nullable();
                MigrationHelper::userIdColumn($table, 'recorded_by', nullable: true);
                $table->text('reason')->nullable()->comment('Free text, consumer audit only — never interpreted (D8)');
                $table->timestamps();
            });
        }

        $this->ensureUnique('sale_refunds', 'refund_key');
        $this->ensureUnique('sale_refunds', 'rectificative_invoice_id');
        $this->ensureIndex('sale_refunds', 'sale_reservation_id');
        $this->ensureIndex('sale_refunds', 'stage');
        $this->ensureForeign('sale_refunds', 'sale_reservation_id', 'sale_reservations');
        $this->ensureForeign('sale_refunds', 'rectificative_invoice_id', 'invoices');
    }

    private function addSaleColumnsToInvoices(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'sale_reservation_id')) {
                $table->uuid('sale_reservation_id')->nullable()->comment('The one-off sale reservation this invoice was born of (AID-1997)');
            }

            if (! Schema::hasColumn('invoices', 'sale_refund_id')) {
                $table->uuid('sale_refund_id')->nullable()->comment('The refund intent that owns this rectificative draft from creation (R3-3)');
            }
        });

        $this->ensureUnique('invoices', 'sale_reservation_id');
        $this->ensureUnique('invoices', 'sale_refund_id');
        $this->ensureForeign('invoices', 'sale_reservation_id', 'sale_reservations');
        $this->ensureForeign('invoices', 'sale_refund_id', 'sale_refunds');
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
            if (($index['columns'] ?? []) === [$column]) {
                return $unique ? ($index['unique'] ?? false) : true;
            }
        }

        return false;
    }
};
