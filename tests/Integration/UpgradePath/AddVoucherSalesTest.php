<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * AID-1825 minimal scope §5 test 17 — the upgrade path of migration 055
 * (`add_voucher_sales`) on a 9.1 database: additive, no backfill, every
 * payment, allocation and credit entry byte for byte as seeded, every new
 * column NULL on them; idempotent (a second run changes nothing); and the
 * fresh-install shape identical to the upgraded one.
 */

const LARABILL_AID1825_MIGRATION = '2026_11_01_000001_add_voucher_sales.php';

const LARABILL_AID1825_BILLED = '01936df0-0000-7000-8000-000000182501';

const LARABILL_AID1825_PAYMENT = '01936df0-0000-7000-8000-000000182502';

const LARABILL_AID1825_INVOICE = '01936df0-0000-7000-8000-000000182503';

const LARABILL_AID1825_ADVANCE = '01936df0-0000-7000-8000-000000182504';

const LARABILL_AID1825_ENTRY = '01936df0-0000-7000-8000-000000182505';

const LARABILL_AID1825_ADVANCE_ENTRY = '01936df0-0000-7000-8000-000000182506';

function aid1825uRunMigration(): void
{
    $exit = Artisan::call('migrate', [
        '--database' => 'testing',
        '--path'     => dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1825_MIGRATION,
        '--realpath' => true,
    ]);

    expect($exit)->toBe(0);
}

function aid1825uSeedInvoice(string $id, int $number, int $total, InvoiceStatus $status, InvoiceDocumentStatus $doc): void
{
    DB::table('invoices')->insert([
        'id'                       => $id,
        'fiscal_number'            => $doc === InvoiceDocumentStatus::ISSUED ? sprintf('FAC-2026-%06d', $number) : null,
        'prefix'                   => 'FAC', 'serie' => InvoiceSerieType::INVOICE->value,
        'series_number'            => $doc === InvoiceDocumentStatus::ISSUED ? $number : null,
        'fiscal_year'              => $doc === InvoiceDocumentStatus::ISSUED ? 2026 : null,
        'invoice_date'             => $doc === InvoiceDocumentStatus::ISSUED ? sprintf('2026-09-%02d', $number) : null,
        'issued_at'                => $doc === InvoiceDocumentStatus::ISSUED ? now() : null,
        'status'                   => $status->value,
        'invoice_document_status'  => $doc->value,
        'fiscal_submission_status' => $doc === InvoiceDocumentStatus::ISSUED ? FiscalSubmissionStatus::NOT_REQUIRED->value : null,
        'user_id'                  => LARABILL_AID1825_BILLED,
        'billable_user_id'         => LARABILL_AID1825_BILLED,
        'taxable_amount'           => $total, 'total_tax_amount' => 0, 'total_amount' => $total,
        'created_at'               => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function aid1825uRow(string $table, string $column, string $id): array
{
    return (array) DB::table($table)->where($column, $id)->first();
}

beforeEach(function () {
    // ── Base schema: every manifest migration ordered BEFORE 055 (the 9.1 schema) ──
    $migrationsDir        = dirname(__DIR__, 3).'/database/migrations';
    $this->aid1825BaseDir = sys_get_temp_dir().'/larabill-aid1825-base-'.Str::random(8);
    File::ensureDirectoryExists($this->aid1825BaseDir);

    foreach (ManifestBase::migrationsBefore('add_voucher_sales') as $name) {
        copy("{$migrationsDir}/{$name}", "{$this->aid1825BaseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->aid1825BaseDir, '--realpath' => true])
        ->assertExitCode(0);

    // 9.1 sanity: nothing of 055 exists yet.
    expect(Schema::hasTable('voucher_sale_claims'))->toBeFalse()
        ->and(Schema::hasTable('credit_operations'))->toBeFalse()
        ->and(Schema::hasColumn('credit_movements', 'sale_invoice_id'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'sale_claim_id'))->toBeFalse();

    DB::table('users')->insert([
        ['id' => LARABILL_AID1825_BILLED, 'name' => 'Billed', 'email' => 'aid1825-billed@example.test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // ── 9.1 data: a payment allocated to an issued invoice with a TOP_UP remainder,
    //    and an advance-backed SURPLUS entry linked to a DRAFT advance. ──────────────
    aid1825uSeedInvoice(LARABILL_AID1825_INVOICE, 1, 6000, InvoiceStatus::PAID, InvoiceDocumentStatus::ISSUED);
    aid1825uSeedInvoice(LARABILL_AID1825_ADVANCE, 2, 4000, InvoiceStatus::DRAFT, InvoiceDocumentStatus::DRAFT);

    DB::table('grouped_payments')->insert([
        'id'               => LARABILL_AID1825_PAYMENT,
        'billable_user_id' => LARABILL_AID1825_BILLED,
        'amount'           => 10000,
        'currency'         => 'EUR',
        'paid_at'          => '2026-09-01 08:00:00',
        'idempotency_key'  => 'aid1825-payment',
        'status'           => GroupedPaymentStatus::POSTED->value,
        'method'           => 1,
        'created_at'       => now(), 'updated_at' => now(),
    ]);

    DB::table('grouped_payment_invoice')->insert([
        'grouped_payment_id' => LARABILL_AID1825_PAYMENT,
        'invoice_id'         => LARABILL_AID1825_INVOICE,
        'applied_amount'     => 6000,
        'status'             => AllocationStatus::ACTIVE->value,
        'applied_at'         => '2026-09-01 08:00:00',
        'active_invoice_id'  => LARABILL_AID1825_INVOICE,
        'previous_status'    => InvoiceStatus::SENT->value,
        'created_at'         => now(), 'updated_at' => now(),
    ]);

    foreach ([
        ['id' => LARABILL_AID1825_ENTRY, 'kind' => 1, 'amount' => 2500, 'advance_invoice_id' => null],
        ['id' => LARABILL_AID1825_ADVANCE_ENTRY, 'kind' => 0, 'amount' => 1500, 'advance_invoice_id' => LARABILL_AID1825_ADVANCE],
    ] as $entry) {
        DB::table('credit_movements')->insert($entry + [
            'billable_user_id'     => LARABILL_AID1825_BILLED,
            'currency'             => 'EUR',
            'grouped_payment_id'   => LARABILL_AID1825_PAYMENT,
            'allocation_id'        => null,
            'consumes_movement_id' => null,
            'band_start'           => null,
            'occurred_at'          => '2026-09-01 08:00:00',
            'created_at'           => now(), 'updated_at' => now(),
        ]);
    }

    $this->aid1825Before = [
        'payment'    => aid1825uRow('grouped_payments', 'id', LARABILL_AID1825_PAYMENT),
        'allocation' => aid1825uRow('grouped_payment_invoice', 'invoice_id', LARABILL_AID1825_INVOICE),
        'entry'      => aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ENTRY),
        'advance'    => aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ADVANCE_ENTRY),
        'invoice'    => aid1825uRow('invoices', 'id', LARABILL_AID1825_INVOICE),
    ];
});

afterEach(function () {
    if (isset($this->aid1825BaseDir) && is_dir($this->aid1825BaseDir)) {
        File::deleteDirectory($this->aid1825BaseDir);
    }
});

it('adds the voucher pieces and leaves every 9.1 payment, allocation and credit entry untouched', function () {
    aid1825uRunMigration();

    expect(Schema::hasTable('voucher_sale_claims'))->toBeTrue()
        ->and(Schema::hasTable('credit_operations'))->toBeTrue()
        ->and(Schema::hasColumns('credit_movements', ['sale_invoice_id', 'credit_operation_id', 'service_ref', 'service_description']))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'sale_claim_id'))->toBeTrue();

    foreach ($this->aid1825Before as $name => $before) {
        $after = match ($name) {
            'payment'    => aid1825uRow('grouped_payments', 'id', LARABILL_AID1825_PAYMENT),
            'allocation' => aid1825uRow('grouped_payment_invoice', 'invoice_id', LARABILL_AID1825_INVOICE),
            'entry'      => aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ENTRY),
            'advance'    => aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ADVANCE_ENTRY),
            'invoice'    => aid1825uRow('invoices', 'id', LARABILL_AID1825_INVOICE),
        };

        // Every 9.1 column byte for byte; every new column NULL.
        expect(array_intersect_key($after, $before))->toBe($before, "{$name} changed on upgrade");
        foreach (array_diff_key($after, $before) as $column => $value) {
            expect($value)->toBeNull("{$name}.{$column} should be NULL after an additive upgrade");
        }
    }

    expect(DB::table('voucher_sale_claims')->count())->toBe(0)
        ->and(DB::table('credit_operations')->count())->toBe(0)
        ->and((string) aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ADVANCE_ENTRY)['advance_invoice_id'])->toBe(LARABILL_AID1825_ADVANCE);
});

it('is idempotent: a second run changes nothing', function () {
    aid1825uRunMigration();
    $first = [
        'columns' => Schema::getColumnListing('credit_movements'),
        'entry'   => aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ENTRY),
    ];

    // The migration is guarded step by step; invoking its up() again must be a no-op.
    $migration = require dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1825_MIGRATION;
    $migration->up();

    expect(Schema::getColumnListing('credit_movements'))->toBe($first['columns'])
        ->and(aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ENTRY))->toBe($first['entry']);
});

it('rolls back cleanly on down() and leaves the 9.1 rows as they were', function () {
    aid1825uRunMigration();

    $migration = require dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1825_MIGRATION;
    $migration->down();

    expect(Schema::hasTable('voucher_sale_claims'))->toBeFalse()
        ->and(Schema::hasTable('credit_operations'))->toBeFalse()
        ->and(Schema::hasColumn('credit_movements', 'sale_invoice_id'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'sale_claim_id'))->toBeFalse()
        ->and(aid1825uRow('credit_movements', 'id', LARABILL_AID1825_ENTRY))->toBe($this->aid1825Before['entry'])
        ->and(aid1825uRow('grouped_payment_invoice', 'invoice_id', LARABILL_AID1825_INVOICE))->toBe($this->aid1825Before['allocation']);
});

it('resumes after an interruption between statements: a table or column left without its unique, index or foreign key gets it on rerun', function () {
    aid1825uRunMigration();

    // Simulate a run that died after the columns/tables existed but before
    // the later constraint statements (the schema builder emits them one by one).
    Schema::table('credit_movements', function ($table): void {
        $table->dropForeign(['sale_invoice_id']);
        $table->dropIndex(['sale_invoice_id']);
    });
    Schema::table('invoices', function ($table): void {
        $table->dropForeign(['sale_claim_id']);
        $table->dropUnique(['sale_claim_id']);
    });
    Schema::table('voucher_sale_claims', function ($table): void {
        $table->dropIndex(['stage']);
        $table->dropForeign(['grouped_payment_id']);
    });

    $fkColumns = fn (string $t): array => array_map(fn (array $fk): array => $fk['columns'], Schema::getForeignKeys($t));
    expect($fkColumns('credit_movements'))->not->toContain(['sale_invoice_id'])
        ->and($fkColumns('invoices'))->not->toContain(['sale_claim_id']);

    // Rerun the migration body itself (the migrator would skip an already-recorded run).
    $migration = require dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1825_MIGRATION;
    $migration->up();

    $indexes = fn (string $t): array => array_map(fn (array $i): array => [$i['columns'], (bool) $i['unique']], Schema::getIndexes($t));
    expect($fkColumns('credit_movements'))->toContain(['sale_invoice_id'])
        ->and($fkColumns('invoices'))->toContain(['sale_claim_id'])
        ->and($fkColumns('voucher_sale_claims'))->toContain(['grouped_payment_id'])
        ->and($indexes('invoices'))->toContain([['sale_claim_id'], true])
        ->and($indexes('voucher_sale_claims'))->toContain([['stage'], false]);

    // And a third run (nothing missing) changes nothing.
    $before = [Schema::getForeignKeys('credit_movements'), Schema::getIndexes('invoices'), Schema::getIndexes('voucher_sale_claims')];
    $migration->up();
    expect([Schema::getForeignKeys('credit_movements'), Schema::getIndexes('invoices'), Schema::getIndexes('voucher_sale_claims')])->toEqual($before);
});
