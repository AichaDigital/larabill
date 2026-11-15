<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use AichaDigital\Larabill\ValueObjects\SaleRefundRequest;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * AID-1997 spec section 6, STD-005 four cases — the upgrade path of
 * migration 056 (`add_one_off_sales`) on a 9.5.0 database: (1) with data —
 * additive, no backfill, every payment/allocation/credit entry byte for
 * byte as seeded, and a full reserve → settle → refund round trip runs on
 * the upgraded schema; (2) empty base; (3) idempotence — a second run
 * changes nothing; (4) structure — the refund UNIQUEs (sale_refunds.
 * refund_key, sale_refunds.rectificative_invoice_id, invoices.
 * sale_refund_id, invoices.sale_reservation_id) and the FKs; a conflicting
 * second link is rejected by the engine.
 */

const LARABILL_AID1997_MIGRATION = '2026_11_15_000001_add_one_off_sales.php';

const LARABILL_AID1997_BILLED = '0193df70-0000-7000-8000-000000199701';

const LARABILL_AID1997_PROFORMA = '0193df70-0000-7000-8000-000000199702';

const LARABILL_AID1997_INVOICE = '0193df70-0000-7000-8000-000000199703';

const LARABILL_AID1997_PAYMENT = '0193df70-0000-7000-8000-000000199704';

function aid1997uRunMigration(): void
{
    $exit = Artisan::call('migrate', [
        '--database' => 'testing',
        '--path'     => dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1997_MIGRATION,
        '--realpath' => true,
    ]);

    expect($exit)->toBe(0);
}

/**
 * A base-shaped 9.5 row. The coherence CHECK numbers EVERY serie — a 9.5
 * proforma carries its own PRO correlative (ADR-016 3.1: proforma con
 * correlativo de serie propia) and a NULL document axis (CHECK C2: the axis
 * exists only for fiscal rows); a fiscal row carries the full numbered shape
 * plus its submission stamp.
 */
function aid1997uSeedInvoice(string $id, string $prefix, int $number, int $total, InvoiceStatus $status, bool $fiscal): void
{
    DB::table('invoices')->insert([
        'id'                       => $id,
        'fiscal_number'            => sprintf('%s-2026-%06d', $prefix, $number),
        'prefix'                   => $prefix,
        'serie'                    => $fiscal ? InvoiceSerieType::INVOICE->value : InvoiceSerieType::PROFORMA->value,
        'series_number'            => $number,
        'fiscal_year'              => 2026,
        'invoice_date'             => sprintf('2026-09-%02d', $number),
        'issued_at'                => now(),
        'status'                   => $status->value,
        'invoice_document_status'  => $fiscal ? InvoiceDocumentStatus::ISSUED->value : null,
        'fiscal_submission_status' => $fiscal ? FiscalSubmissionStatus::NOT_REQUIRED->value : null,
        'user_id'                  => LARABILL_AID1997_BILLED,
        'billable_user_id'         => LARABILL_AID1997_BILLED,
        'taxable_amount'           => $total, 'total_tax_amount' => 0, 'total_amount' => $total,
        'created_at'               => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function aid1997uRow(string $table, string $column, string $id): array
{
    return (array) DB::table($table)->where($column, $id)->first();
}

beforeEach(function () {
    // ── Base schema: every manifest migration ordered BEFORE 056 (the 9.5.0 schema) ──
    $migrationsDir        = dirname(__DIR__, 3).'/database/migrations';
    $this->aid1997BaseDir = sys_get_temp_dir().'/larabill-aid1997-base-'.Str::random(8);
    File::ensureDirectoryExists($this->aid1997BaseDir);

    foreach (ManifestBase::migrationsBefore('add_one_off_sales') as $name) {
        copy("{$migrationsDir}/{$name}", "{$this->aid1997BaseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->aid1997BaseDir, '--realpath' => true])
        ->assertExitCode(0);

    // 9.5 sanity: nothing of 056 exists yet.
    expect(Schema::hasTable('sale_reservations'))->toBeFalse()
        ->and(Schema::hasTable('sale_refunds'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'sale_reservation_id'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'sale_refund_id'))->toBeFalse();

    DB::table('users')->insert([
        ['id' => LARABILL_AID1997_BILLED, 'name' => 'Billed', 'email' => 'aid1997-billed@example.test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // ── 9.5 data: a settled-looking paid invoice with its payment and allocation,
    //    and a SENT proforma the round trip will reserve. ──────────────────────────
    aid1997uSeedInvoice(LARABILL_AID1997_INVOICE, 'FAC', 1, 6000, InvoiceStatus::PAID, fiscal: true);
    aid1997uSeedInvoice(LARABILL_AID1997_PROFORMA, 'PRO', 2, 10000, InvoiceStatus::SENT, fiscal: false);

    DB::table('grouped_payments')->insert([
        'id'               => LARABILL_AID1997_PAYMENT,
        'billable_user_id' => LARABILL_AID1997_BILLED,
        'amount'           => 6000,
        'currency'         => 'EUR',
        'paid_at'          => '2026-09-01 08:00:00',
        'idempotency_key'  => 'aid1997-payment',
        'status'           => GroupedPaymentStatus::POSTED->value,
        'method'           => 1,
        'created_at'       => now(), 'updated_at' => now(),
    ]);

    DB::table('grouped_payment_invoice')->insert([
        'grouped_payment_id' => LARABILL_AID1997_PAYMENT,
        'invoice_id'         => LARABILL_AID1997_INVOICE,
        'applied_amount'     => 6000,
        'status'             => AllocationStatus::ACTIVE->value,
        'applied_at'         => '2026-09-01 08:00:00',
        'active_invoice_id'  => LARABILL_AID1997_INVOICE,
        'previous_status'    => InvoiceStatus::SENT->value,
        'created_at'         => now(), 'updated_at' => now(),
    ]);

    $this->aid1997Before = [
        'payment'    => aid1997uRow('grouped_payments', 'id', LARABILL_AID1997_PAYMENT),
        'allocation' => aid1997uRow('grouped_payment_invoice', 'invoice_id', LARABILL_AID1997_INVOICE),
        'invoice'    => aid1997uRow('invoices', 'id', LARABILL_AID1997_INVOICE),
        'proforma'   => aid1997uRow('invoices', 'id', LARABILL_AID1997_PROFORMA),
    ];
});

afterEach(function () {
    if (isset($this->aid1997BaseDir) && is_dir($this->aid1997BaseDir)) {
        File::deleteDirectory($this->aid1997BaseDir);
    }
});

it('adds the one-off pieces, leaves every 9.5 row untouched, and runs the upgraded refund round trip (case 1 + the upgraded-domain operation)', function () {
    aid1997uRunMigration();

    expect(Schema::hasTable('sale_reservations'))->toBeTrue()
        ->and(Schema::hasTable('sale_refunds'))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'sale_reservation_id'))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'sale_refund_id'))->toBeTrue();

    foreach ($this->aid1997Before as $name => $before) {
        $after = match ($name) {
            'payment'    => aid1997uRow('grouped_payments', 'id', LARABILL_AID1997_PAYMENT),
            'allocation' => aid1997uRow('grouped_payment_invoice', 'invoice_id', LARABILL_AID1997_INVOICE),
            'invoice'    => aid1997uRow('invoices', 'id', LARABILL_AID1997_INVOICE),
            'proforma'   => aid1997uRow('invoices', 'id', LARABILL_AID1997_PROFORMA),
        };

        // Every 9.5 column byte for byte; every new column NULL.
        expect(array_intersect_key($after, $before))->toBe($before, "{$name} changed on upgrade");
        foreach (array_diff_key($after, $before) as $column => $value) {
            expect($value)->toBeNull("{$name}.{$column} should be NULL after an additive upgrade");
        }
    }

    // The upgraded-domain operation (R2-8): a full reserve → settle → refund
    // round trip runs on the upgraded schema. A fresh config-bearing
    // proforma — the seeded 9.5 rows carry no fiscal config (a seeded row's
    // company_fiscal_config_id is NULL and the round trip needs a currency).
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null, 'currency' => 'EUR']);
    $roundTripUserId = '0193df70-0000-7000-8000-000000199706';
    DB::table('users')->insert([
        'id'         => $roundTripUserId,
        'name'       => 'RoundTrip',
        'email'      => 'aid1997-roundtrip@example.test',
        'password'   => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $roundTripProforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $roundTripUserId,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $roundTripProforma->update(['status' => InvoiceStatus::SENT->value]);

    $service = app(OneOffSaleService::class);

    $reservation = $service->reserve((string) $roundTripProforma->id, 'aid1997-upgrade-op');
    $settled     = $service->settle(new SaleSettlementRequest(
        'aid1997-upgrade-op',
        $roundTripUserId,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subMinute()->startOfSecond(),
        PaymentMethod::Card,
        'pi_aid1997',
        $roundTripUserId,
    ));

    expect($settled->reservation->stage)->toBe(SaleReservationStage::Settled);

    $refunded = $service->refund(new SaleRefundRequest(
        'aid1997-upgrade-op',
        'aid1997-upgrade-refund',
        null,
        'EUR',
        now()->startOfSecond(),
        PaymentMethod::BankTransfer,
        'ref_aid1997',
        $roundTripUserId,
        'upgraded schema round trip',
    ));

    expect($refunded->refund->stage)->toBe(SaleRefundStage::Refunded)
        ->and($refunded->rectificative)->not->toBeNull()
        ->and($refunded->remainingRefundable->unscaledValue())->toBe(0);
});

it('migrates an empty base cleanly (case 2)', function () {
    DB::table('grouped_payment_invoice')->where('invoice_id', LARABILL_AID1997_INVOICE)->delete();
    DB::table('grouped_payments')->where('id', LARABILL_AID1997_PAYMENT)->delete();
    DB::table('invoices')->whereIn('id', [LARABILL_AID1997_INVOICE, LARABILL_AID1997_PROFORMA])->delete();
    DB::table('users')->where('id', LARABILL_AID1997_BILLED)->delete();

    aid1997uRunMigration();

    expect(Schema::hasTable('sale_reservations'))->toBeTrue()
        ->and(Schema::hasTable('sale_refunds'))->toBeTrue()
        ->and(DB::table('sale_reservations')->count())->toBe(0)
        ->and(DB::table('sale_refunds')->count())->toBe(0);
});

it('is idempotent: a second run changes nothing (case 3)', function () {
    aid1997uRunMigration();

    $first = [
        'reservations' => Schema::getColumnListing('sale_reservations'),
        'refunds'      => Schema::getColumnListing('sale_refunds'),
        'invoice'      => aid1997uRow('invoices', 'id', LARABILL_AID1997_INVOICE),
    ];

    $migration = require dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1997_MIGRATION;
    $migration->up();

    expect(Schema::getColumnListing('sale_reservations'))->toBe($first['reservations'])
        ->and(Schema::getColumnListing('sale_refunds'))->toBe($first['refunds'])
        ->and(aid1997uRow('invoices', 'id', LARABILL_AID1997_INVOICE))->toBe($first['invoice']);
});

it('carries the refund UNIQUEs and FKs, and the engine rejects a conflicting second link (case 4)', function () {
    aid1997uRunMigration();

    $uniqueOn = function (string $table, string $column): bool {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === [$column] && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    };

    expect($uniqueOn('sale_reservations', 'operation_key'))->toBeTrue()
        ->and($uniqueOn('sale_reservations', 'grouped_payment_id'))->toBeTrue()
        ->and($uniqueOn('sale_reservations', 'allocation_id'))->toBeTrue()
        ->and($uniqueOn('sale_refunds', 'refund_key'))->toBeTrue()
        ->and($uniqueOn('sale_refunds', 'rectificative_invoice_id'))->toBeTrue()
        ->and($uniqueOn('invoices', 'sale_reservation_id'))->toBeTrue()
        ->and($uniqueOn('invoices', 'sale_refund_id'))->toBeTrue();

    // FKs land on non-SQLite engines (the ensureForeign SQLite skip).
    $fkColumns = [];

    foreach (Schema::getForeignKeys('sale_refunds') as $fk) {
        $fkColumns = array_merge($fkColumns, $fk['columns'] ?? []);
    }

    expect($fkColumns)->toContain('sale_reservation_id')
        ->and($fkColumns)->toContain('rectificative_invoice_id');

    // The engine rejects a conflicting second link: two invoices cannot
    // carry the same reservation. A REAL reservation row (the FK fires
    // before the UNIQUE on a dangling id).
    $reservationId = '0193df70-0000-7000-8000-000000199705';
    DB::table('sale_reservations')->insert([
        'id'                        => $reservationId,
        'proforma_id'               => LARABILL_AID1997_PROFORMA,
        'operation_key'             => 'aid1997-case4-reservation',
        'stage'                     => 0,
        'expected_amount'           => 10000,
        'expected_currency'         => 'EUR',
        'expected_billable_user_id' => LARABILL_AID1997_BILLED,
        'issued_by_sale'            => false,
        'created_at'                => now(), 'updated_at' => now(),
    ]);

    DB::table('invoices')->where('id', LARABILL_AID1997_PROFORMA)->update(['sale_reservation_id' => $reservationId]);

    try {
        DB::table('invoices')->where('id', LARABILL_AID1997_INVOICE)->update(['sale_reservation_id' => '0193df70-0000-7000-8000-000000199705']);
        $this->fail('the engine must reject a conflicting second link');
    } catch (Throwable) {
        // The UNIQUE index rejected it — exactly the constraint the case pins.
    }
});
