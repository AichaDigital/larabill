<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * AID-962 — upgrade-path contract of migration 053 (payment allocations and
 * customer credit, spec §3.5).
 *
 * Base = every manifest migration EXCEPT the one under test (the 8.2.0
 * schema). Seeds RAW 8.2-shaped rows — three payments over three invoices:
 *
 *   - P1 POSTED, pivot (I1, 12100) with `active_invoice_id = I1`;
 *   - P2 REVERSED (with its audit trio), pivot (I2, 5000) with
 *     `active_invoice_id = NULL` — exactly what 8.x `reverse()` leaves;
 *   - P3 POSTED, pivot (I3, 5000) with `active_invoice_id = NULL` — the
 *     writer-less anomaly.
 *
 * Then runs 053 and asserts the relaxation (both UNIQUEs gone, plain
 * replacements present), the backfill (the NULL marker becomes REVERSED,
 * with the parent's audit trio or the visible `backfill-8.3` reason), the
 * `method` default, the new `credit_movements` table with its CHECK and FK
 * rules, and `invoice_items.deducts_invoice_id` — plus the model-level reads
 * of §3.5 over the backfilled rows (`amountPaid()`, `amountOutstanding()`,
 * `unallocatedAmount()`).
 */

const LARABILL_AID962_MIGRATION = '2026_10_24_000001_add_allocations_and_credit_movements.php';

const LARABILL_AID962_ISSUER   = '01936df0-0000-7000-8000-000000962a01';
const LARABILL_AID962_BILLED   = '01936df0-0000-7000-8000-000000962b02';
const LARABILL_AID962_REVERSER = '01936df0-0000-7000-8000-000000962c03';

const LARABILL_AID962_I1 = '01936df0-0000-7000-8000-000000962101';
const LARABILL_AID962_I2 = '01936df0-0000-7000-8000-000000962102';
const LARABILL_AID962_I3 = '01936df0-0000-7000-8000-000000962103';

const LARABILL_AID962_P1 = '01936df0-0000-7000-8000-000000962201';
const LARABILL_AID962_P2 = '01936df0-0000-7000-8000-000000962202';
const LARABILL_AID962_P3 = '01936df0-0000-7000-8000-000000962203';

const LARABILL_AID962_P2_REVERSED_AT = '2026-09-01 10:00:00';
const LARABILL_AID962_P3_ROW_UPDATED = '2026-08-15 09:30:00';

// Distinct from the parent's reversed_at, so the copy from the parent cannot
// be confused with a copy from the row's own updated_at.
const LARABILL_AID962_P2_ROW_UPDATED = '2026-09-05 12:00:00';

function aid962MigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID962_MIGRATION;
}

function runAid962Migration(): void
{
    $exit = Artisan::call('migrate', [
        '--database' => 'testing',
        '--path'     => aid962MigrationPath(),
        '--realpath' => true,
    ]);

    expect($exit)->toBe(0);
}

function rollbackAid962Migration(): void
{
    $exit = Artisan::call('migrate:rollback', [
        '--database' => 'testing',
        '--path'     => aid962MigrationPath(),
        '--realpath' => true,
        '--step'     => 1,
    ]);

    expect($exit)->toBe(0);
}

/**
 * Named-CHECK presence via information_schema (ADR-016 §8.2 lookup pattern).
 */
function aid962CheckExists(string $table, string $constraint): bool
{
    $row = DB::selectOne(
        'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
        [$table, $constraint, 'CHECK'],
    );

    return ((int) ($row->aggregate ?? 0)) > 0;
}

/**
 * The ON DELETE rule of the foreign key that a column carries, or null when
 * the column carries no foreign key.
 */
function aid962ForeignKeyDeleteRule(string $table, string $column): ?string
{
    $key = DB::selectOne(
        'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL',
        [$table, $column],
    );

    if ($key === null) {
        return null;
    }

    $rule = DB::selectOne(
        'SELECT DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
        [$table, $key->name],
    );

    return $rule === null ? null : strtoupper((string) $rule->rule);
}

/**
 * One ISSUED invoice in the 8.2.0 shape (raw insert, money as integers).
 */
function aid962SeedInvoice(string $id, int $number, int $totalAmount, InvoiceStatus $status): void
{
    $now = now();

    DB::table('invoices')->insert([
        'id'                       => $id,
        'fiscal_number'            => sprintf('FAC-2026-%06d', $number),
        'prefix'                   => 'FAC', 'serie' => InvoiceSerieType::INVOICE->value, 'series_number' => $number, 'fiscal_year' => 2026,
        'invoice_date'             => sprintf('2026-09-%02d', $number), 'issued_at' => $now,
        'status'                   => $status->value,
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'fiscal_submission_status' => FiscalSubmissionStatus::NOT_REQUIRED->value,
        'user_id'                  => LARABILL_AID962_ISSUER,
        'billable_user_id'         => LARABILL_AID962_BILLED,
        'taxable_amount'           => $totalAmount, 'total_tax_amount' => 0, 'total_amount' => $totalAmount,
        'created_at'               => $now, 'updated_at' => $now,
    ]);
}

beforeEach(function () {
    // ── Base schema: the 8.2.0 install (everything but 053) ─────────────────
    /** @var array{migrations: array<int, array{name: string, in_base: bool}>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Contract/release-migration-manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $migrationsDir           = dirname(__DIR__, 3).'/database/migrations';
    $this->aid962BaseDir     = sys_get_temp_dir().'/larabill-aid962-base-'.Str::random(8);
    File::ensureDirectoryExists($this->aid962BaseDir);

    foreach ($manifest['migrations'] as $entry) {
        if ($entry['name'] === LARABILL_AID962_MIGRATION) {
            continue;
        }

        copy("{$migrationsDir}/{$entry['name']}", "{$this->aid962BaseDir}/{$entry['name']}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->aid962BaseDir, '--realpath' => true])
        ->assertExitCode(0);

    // 8.2.0 sanity: both UNIQUEs are still in place.
    expect($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_grouped_payment_id_invoice_id_unique'))
        ->toBe(['grouped_payment_id', 'invoice_id'])
        ->and($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_unique'))
        ->toBe(['active_invoice_id']);

    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_AID962_ISSUER, 'name' => 'Issuer', 'email' => 'aid962-issuer@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['id' => LARABILL_AID962_BILLED, 'name' => 'Billed', 'email' => 'aid962-billed@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['id' => LARABILL_AID962_REVERSER, 'name' => 'Reverser', 'email' => 'aid962-reverser@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    aid962SeedInvoice(LARABILL_AID962_I1, 1, 12100, InvoiceStatus::PAID);
    aid962SeedInvoice(LARABILL_AID962_I2, 2, 5000, InvoiceStatus::SENT);
    aid962SeedInvoice(LARABILL_AID962_I3, 3, 5000, InvoiceStatus::PAID);

    $payment = fn (string $id, int $amount, GroupedPaymentStatus $status, array $extra = []): array => array_merge([
        'id'               => $id,
        'billable_user_id' => LARABILL_AID962_BILLED,
        'amount'           => $amount,
        'currency'         => 'EUR',
        'paid_at'          => '2026-09-01 08:00:00',
        'idempotency_key'  => 'aid962-'.$id,
        'status'           => $status->value,
        'created_at'       => $now, 'updated_at' => $now,
    ], $extra);

    DB::table('grouped_payments')->insert([
        $payment(LARABILL_AID962_P1, 12100, GroupedPaymentStatus::POSTED, ['reversed_at' => null, 'reversed_by' => null, 'reverse_reason' => null]),
        $payment(LARABILL_AID962_P2, 5000, GroupedPaymentStatus::REVERSED, [
            'reversed_at'    => LARABILL_AID962_P2_REVERSED_AT,
            'reversed_by'    => LARABILL_AID962_REVERSER,
            'reverse_reason' => 'customer dispute',
        ]),
        $payment(LARABILL_AID962_P3, 5000, GroupedPaymentStatus::POSTED, ['reversed_at' => null, 'reversed_by' => null, 'reverse_reason' => null]),
    ]);

    DB::table('grouped_payment_invoice')->insert([
        [
            'grouped_payment_id' => LARABILL_AID962_P1, 'invoice_id' => LARABILL_AID962_I1,
            'applied_amount'     => 12100, 'previous_status' => InvoiceStatus::SENT->value, 'previous_paid_at' => null,
            'active_invoice_id'  => LARABILL_AID962_I1,
            'created_at'         => $now, 'updated_at' => $now,
        ],
        [
            'grouped_payment_id' => LARABILL_AID962_P2, 'invoice_id' => LARABILL_AID962_I2,
            'applied_amount'     => 5000, 'previous_status' => InvoiceStatus::SENT->value, 'previous_paid_at' => null,
            'active_invoice_id'  => null,
            'created_at'         => $now, 'updated_at' => LARABILL_AID962_P2_ROW_UPDATED,
        ],
        [
            'grouped_payment_id' => LARABILL_AID962_P3, 'invoice_id' => LARABILL_AID962_I3,
            'applied_amount'     => 5000, 'previous_status' => InvoiceStatus::SENT->value, 'previous_paid_at' => null,
            'active_invoice_id'  => null,
            'created_at'         => $now, 'updated_at' => LARABILL_AID962_P3_ROW_UPDATED,
        ],
    ]);
});

afterEach(function () {
    if (isset($this->aid962BaseDir) && is_dir($this->aid962BaseDir)) {
        File::deleteDirectory($this->aid962BaseDir);
    }
});

it('relaxes the pivot, backfills reversed rows and creates credit_movements', function () {
    runAid962Migration();

    expect($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_grouped_payment_id_invoice_id_unique'))->toBe([])
        ->and($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_unique'))->toBe([])
        ->and($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_payment_invoice_index'))->toBe(['grouped_payment_id', 'invoice_id'])
        ->and($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_index'))->toBe(['active_invoice_id']);

    // Legacy payments read method = Card (the default documents AID-961).
    expect(DB::table('grouped_payments')->pluck('method')->map(fn ($m): int => (int) $m)->unique()->values()->all())->toBe([1]);

    $p1Row = DB::table('grouped_payment_invoice')->where('grouped_payment_id', LARABILL_AID962_P1)->first();
    expect((int) $p1Row->status)->toBe(0)
        ->and($p1Row->active_invoice_id)->toBe(LARABILL_AID962_I1)
        ->and($p1Row->reversed_at)->toBeNull()
        ->and($p1Row->reverse_reason)->toBeNull();

    // The row 8.x reverse() left behind: REVERSED with the parent's audit trio.
    $p2Row = DB::table('grouped_payment_invoice')->where('grouped_payment_id', LARABILL_AID962_P2)->first();
    expect((int) $p2Row->status)->toBe(1)
        ->and($p2Row->reversed_at)->toBe(LARABILL_AID962_P2_REVERSED_AT)
        ->and($p2Row->reversed_at)->not->toBe($p2Row->updated_at)
        ->and($p2Row->reversed_by)->toBe(LARABILL_AID962_REVERSER)
        ->and($p2Row->reverse_reason)->toBe('customer dispute');

    // The writer-less anomaly: REVERSED, visible, never silenced.
    $p3Row = DB::table('grouped_payment_invoice')->where('grouped_payment_id', LARABILL_AID962_P3)->first();
    expect((int) $p3Row->status)->toBe(1)
        ->and($p3Row->reverse_reason)->toBe('backfill-8.3')
        ->and($p3Row->reversed_at)->toBe(LARABILL_AID962_P3_ROW_UPDATED)
        ->and($p3Row->reversed_at)->toBe($p3Row->updated_at)
        ->and($p3Row->reversed_by)->toBeNull();

    // Model-level reads over the backfilled rows (§3.5): only ACTIVE rows sum.
    expect(Invoice::query()->findOrFail(LARABILL_AID962_I1)->amountPaid()->unscaledValue())->toBe(12100)
        ->and(GroupedPayment::query()->findOrFail(LARABILL_AID962_P1)->unallocatedAmount()->isZero())->toBeTrue();

    foreach ([LARABILL_AID962_I2, LARABILL_AID962_I3] as $invoiceId) {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        expect($invoice->amountPaid()->isZero())->toBeTrue("{$invoiceId} sums a reversed row")
            ->and($invoice->amountOutstanding()->unscaledValue())->toBe(5000)
            ->and($invoice->amountOutstanding()->isEqualTo($invoice->total_amount))->toBeTrue();
    }

    expect(Schema::hasTable('credit_movements'))->toBeTrue()
        ->and(aid962CheckExists('credit_movements', 'larabill_money_domain_credit_movements_amount'))->toBeTrue()
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'grouped_payment_id'))->toBe('RESTRICT')
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'allocation_id'))->toBe('RESTRICT')
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'consumes_movement_id'))->toBe('RESTRICT')
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'advance_invoice_id'))->toBe('SET NULL')
        ->and($this->getIndexColumns('credit_movements', 'credit_movements_billable_user_id_currency_occurred_at_index'))
        ->toBe(['billable_user_id', 'currency', 'occurred_at'])
        ->and(Schema::hasColumn('invoice_items', 'deducts_invoice_id'))->toBeTrue()
        ->and(aid962ForeignKeyDeleteRule('invoice_items', 'deducts_invoice_id'))->toBe('SET NULL');

    // The named CHECK enforces the AEAT domain on raw writes.
    expect(fn () => DB::table('credit_movements')->insert([
        'id'                 => (string) Str::orderedUuid(),
        'billable_user_id'   => LARABILL_AID962_BILLED,
        'currency'           => 'EUR',
        'kind'               => 0,
        'amount'             => 100000000000000,
        'grouped_payment_id' => LARABILL_AID962_P1,
        'occurred_at'        => now(),
        'created_at'         => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('re-runs up() as a no-op over its own postcondition', function () {
    runAid962Migration();

    $migration = require aid962MigrationPath();
    $migration->up();

    expect($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_payment_invoice_index'))->toBe(['grouped_payment_id', 'invoice_id'])
        ->and(aid962CheckExists('credit_movements', 'larabill_money_domain_credit_movements_amount'))->toBeTrue()
        ->and(DB::table('grouped_payment_invoice')->where('grouped_payment_id', LARABILL_AID962_P2)->value('reverse_reason'))->toBe('customer dispute');
});

it('rolls back cleanly over legacy data (UNIQUEs restored)', function () {
    runAid962Migration();
    rollbackAid962Migration();

    expect($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_grouped_payment_id_invoice_id_unique'))
        ->toBe(['grouped_payment_id', 'invoice_id'])
        ->and($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_unique'))
        ->toBe(['active_invoice_id'])
        ->and($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_payment_invoice_index'))->toBe([])
        ->and($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_index'))->toBe([])
        ->and(Schema::hasTable('credit_movements'))->toBeFalse()
        ->and(Schema::hasColumn('invoice_items', 'deducts_invoice_id'))->toBeFalse()
        ->and(Schema::hasColumn('grouped_payments', 'method'))->toBeFalse()
        ->and(Schema::hasColumn('grouped_payments', 'recorded_by'))->toBeFalse();

    foreach (['status', 'applied_at', 'reversed_at', 'reversed_by', 'reverse_reason'] as $column) {
        expect(Schema::hasColumn('grouped_payment_invoice', $column))->toBeFalse("pivot column {$column} survived down()");
    }

    // The legacy rows survive the round trip.
    expect(DB::table('grouped_payment_invoice')->count())->toBe(3);
});
