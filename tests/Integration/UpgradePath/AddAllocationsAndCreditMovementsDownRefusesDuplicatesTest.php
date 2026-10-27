<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * AID-962 — the loud down() of migration 053 (spec §3.5, second test).
 *
 * 053 relaxes two UNIQUEs of `grouped_payment_invoice`. Its down() re-creates
 * both and must FAIL LOUD (duplicate-key QueryException) when the database
 * accumulated valid 8.3 rows that the old constraints forbid — never a
 * silent dedup that invents which allocation "loses". One case per UNIQUE,
 * each seeding only its own duplicate, so a down() that omits either UNIQUE
 * turns exactly one case red. down() re-creates the UNIQUEs BEFORE any other
 * reversal step, so a refused rollback leaves the 8.3 schema untouched.
 */

const LARABILL_AID962_DOWN_MIGRATION = '2026_10_24_000001_add_allocations_and_credit_movements.php';

const LARABILL_AID962_DOWN_ISSUER = '01936df0-0000-7000-8000-000000962d01';
const LARABILL_AID962_DOWN_BILLED = '01936df0-0000-7000-8000-000000962d02';
const LARABILL_AID962_DOWN_I1     = '01936df0-0000-7000-8000-000000962d11';
const LARABILL_AID962_DOWN_P1     = '01936df0-0000-7000-8000-000000962d21';
const LARABILL_AID962_DOWN_P2     = '01936df0-0000-7000-8000-000000962d22';

function aid962DownMigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID962_DOWN_MIGRATION;
}

function aid962DownRollback(): int
{
    return Artisan::call('migrate:rollback', [
        '--database' => 'testing',
        '--path'     => aid962DownMigrationPath(),
        '--realpath' => true,
        '--step'     => 1,
    ]);
}

/**
 * The rollback must be refused by the duplicate key of THIS unique — not by
 * any other DDL error (a down() that skips the UNIQUE trips the FK-backed
 * index drop instead, which is also a QueryException).
 */
function aid962DownExpectDuplicateKey(string $uniqueName): void
{
    try {
        aid962DownRollback();
    } catch (QueryException $e) {
        expect((int) ($e->errorInfo[1] ?? 0))->toBe(1062)
            ->and($e->getMessage())->toContain($uniqueName);

        return;
    }

    test()->fail("migrate:rollback succeeded; expected a duplicate-key refusal on {$uniqueName}.");
}

function aid962DownSeedPayment(string $id): void
{
    $now = now();

    DB::table('grouped_payments')->insert([
        'id'               => $id,
        'billable_user_id' => LARABILL_AID962_DOWN_BILLED,
        'amount'           => 6050,
        'currency'         => 'EUR',
        'paid_at'          => '2026-10-01 08:00:00',
        'idempotency_key'  => 'aid962-down-'.$id,
        'status'           => 0,
        'created_at'       => $now, 'updated_at' => $now,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aid962DownSeedAllocation(string $paymentId, array $overrides = []): void
{
    $now = now();

    DB::table('grouped_payment_invoice')->insert(array_merge([
        'grouped_payment_id' => $paymentId,
        'invoice_id'         => LARABILL_AID962_DOWN_I1,
        'applied_amount'     => 6050,
        'previous_status'    => InvoiceStatus::SENT->value,
        'previous_paid_at'   => null,
        'active_invoice_id'  => LARABILL_AID962_DOWN_I1,
        'status'             => 0,
        'created_at'         => $now, 'updated_at' => $now,
    ], $overrides));
}

beforeEach(function () {
    // 8.2.0 base (everything ordered BEFORE 053 — AID-1619), then 053 in its
    // own batch — so --step=1 rolls back 053 only. Migrations ordered after
    // the slice (054 onward) must not be copied into the base dir.
    $migrationsDir           = dirname(__DIR__, 3).'/database/migrations';
    $this->aid962DownBaseDir = sys_get_temp_dir().'/larabill-aid962-down-base-'.Str::random(8);
    File::ensureDirectoryExists($this->aid962DownBaseDir);

    foreach (ManifestBase::migrationsBefore('add_allocations_and_credit_movements') as $name) {
        copy("{$migrationsDir}/{$name}", "{$this->aid962DownBaseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->aid962DownBaseDir, '--realpath' => true])
        ->assertExitCode(0);
    $this->artisan('migrate', ['--database' => 'testing', '--path' => aid962DownMigrationPath(), '--realpath' => true])
        ->assertExitCode(0);

    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_AID962_DOWN_ISSUER, 'name' => 'Issuer', 'email' => 'aid962-down-issuer@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['id' => LARABILL_AID962_DOWN_BILLED, 'name' => 'Billed', 'email' => 'aid962-down-billed@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('invoices')->insert([
        'id'                       => LARABILL_AID962_DOWN_I1,
        'fiscal_number'            => 'FAC-2026-000001',
        'prefix'                   => 'FAC', 'serie' => InvoiceSerieType::INVOICE->value, 'series_number' => 1, 'fiscal_year' => 2026,
        'invoice_date'             => '2026-10-01', 'issued_at' => $now,
        'status'                   => InvoiceStatus::PAID->value,
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'fiscal_submission_status' => FiscalSubmissionStatus::NOT_REQUIRED->value,
        'user_id'                  => LARABILL_AID962_DOWN_ISSUER,
        'billable_user_id'         => LARABILL_AID962_DOWN_BILLED,
        'taxable_amount'           => 12100, 'total_tax_amount' => 0, 'total_amount' => 12100,
        'created_at'               => $now, 'updated_at' => $now,
    ]);
});

afterEach(function () {
    if (isset($this->aid962DownBaseDir) && is_dir($this->aid962DownBaseDir)) {
        File::deleteDirectory($this->aid962DownBaseDir);
    }
});

it('refuses to roll back over two live allocations of one invoice (active_invoice_id UNIQUE)', function () {
    // Two partial payments, both live, covering the same invoice: distinct
    // pairs, same active_invoice_id — valid in 8.3, forbidden by the old key.
    aid962DownSeedPayment(LARABILL_AID962_DOWN_P1);
    aid962DownSeedPayment(LARABILL_AID962_DOWN_P2);
    aid962DownSeedAllocation(LARABILL_AID962_DOWN_P1);
    aid962DownSeedAllocation(LARABILL_AID962_DOWN_P2);

    aid962DownExpectDuplicateKey('grouped_payment_invoice_active_invoice_id_unique');

    expect(DB::table('grouped_payment_invoice')->count())->toBe(2)
        ->and(Schema::hasTable('credit_movements'))->toBeTrue()
        ->and(Schema::hasColumn('grouped_payment_invoice', 'status'))->toBeTrue();
});

it('refuses to roll back over a re-allocated payment/invoice pair (pair UNIQUE)', function () {
    // The same pair twice: a REVERSED allocation (NULL marker) and a live
    // re-allocation, as reverseAllocation() + allocate() leave it.
    aid962DownSeedPayment(LARABILL_AID962_DOWN_P1);
    aid962DownSeedAllocation(LARABILL_AID962_DOWN_P1, [
        'active_invoice_id' => null,
        'status'            => 1,
        'reversed_at'       => '2026-10-02 10:00:00',
        'reverse_reason'    => 'wrong invoice',
    ]);
    aid962DownSeedAllocation(LARABILL_AID962_DOWN_P1);

    aid962DownExpectDuplicateKey('grouped_payment_invoice_grouped_payment_id_invoice_id_unique');

    expect(DB::table('grouped_payment_invoice')->count())->toBe(2)
        ->and(Schema::hasTable('credit_movements'))->toBeTrue()
        ->and(Schema::hasColumn('grouped_payment_invoice', 'status'))->toBeTrue();
});
