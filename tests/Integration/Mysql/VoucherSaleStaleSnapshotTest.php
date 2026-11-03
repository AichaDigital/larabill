<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Support\Facades\DB;

/*
 * AID-1825 §1a rule 1 under a STALE REPEATABLE-READ SNAPSHOT (implementation
 * gate round 3, suspected «stale parent state after BACKED»): a writer that
 * read the proforma and its line inside an open transaction BEFORE the sale,
 * and writes AFTER the sale completed. Its plain reads still answer from the
 * old snapshot (an in-force, unprotected proforma); the guards must judge
 * the row as committed NOW — converted and frozen — and refuse the write.
 *
 * Two real connections to the same engine: `testing` (the sale) and `stale`
 * (the old transaction). Needs a MySQL/MariaDB engine, like every test here.
 */

const LARABILL_AID1825_STALE = 'stale';

beforeEach(function () {
    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->markTestSkipped('LARABILL_TEST_MYSQL_* env not configured');
    }

    $this->bootstrap();

    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = $this->seedUser();

    config(['database.connections.'.LARABILL_AID1825_STALE => config('database.connections.testing')]);
    DB::purge(LARABILL_AID1825_STALE);
});

afterEach(function () {
    try {
        DB::connection(LARABILL_AID1825_STALE)->rollBack();
    } catch (Throwable) {
    }
    DB::purge(LARABILL_AID1825_STALE);
});

function aid1825sProforma(string $customerId): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

/** @return array{0: Invoice, 1: InvoiceItem} the proforma and its line as read inside the stale transaction */
function aid1825sStaleRead(Invoice $proforma): array
{
    $stale = DB::connection(LARABILL_AID1825_STALE);
    $stale->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $stale->beginTransaction();

    $header = Invoice::on(LARABILL_AID1825_STALE)->whereKey($proforma->id)->firstOrFail();
    $line   = InvoiceItem::on(LARABILL_AID1825_STALE)->where('invoice_id', $proforma->id)->firstOrFail();

    return [$header, $line];
}

it('control: the same stale writer, with no sale in between, writes normally', function () {
    $proforma        = aid1825sProforma($this->customer);
    [$header, $line] = aid1825sStaleRead($proforma);

    expect($header->update(['total_amount' => cents(99900)]))->toBeTrue()
        ->and($line->update(['description' => 'edited under the snapshot']))->toBeTrue();

    DB::connection(LARABILL_AID1825_STALE)->commit();
    expect($proforma->fresh()->total_amount->unscaledValue())->toBe(99900);
});

it('a header edit from a snapshot older than the sale is refused: the guard judges the row as committed now (converted, frozen)', function () {
    $proforma  = aid1825sProforma($this->customer);
    [$header]  = aid1825sStaleRead($proforma);
    $total     = $proforma->total_amount->unscaledValue();

    $request = new VoucherSaleRequest((string) $proforma->id, $this->customer, $proforma->total_amount, 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_stale_h', null, 'stale-h');
    expect(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold);
    expect($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED);

    // The stale copy still believes the proforma is SENT and editable.
    expect($header->status)->toBe(InvoiceStatus::SENT)
        ->and((bool) $header->getRawOriginal('is_immutable'))->toBeFalse();

    expect(fn () => $header->update(['total_amount' => cents(99900)]))->toThrow(Exception::class);

    DB::connection(LARABILL_AID1825_STALE)->rollBack();
    expect($proforma->fresh()->total_amount->unscaledValue())->toBe($total)
        ->and(Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail()->total_amount->unscaledValue())->toBe($total);
});

it('a line edit from a snapshot older than the sale is refused: the parent is read with a locking read', function () {
    $proforma    = aid1825sProforma($this->customer);
    [, $line]    = aid1825sStaleRead($proforma);
    $description = $line->description;

    $request = new VoucherSaleRequest((string) $proforma->id, $this->customer, $proforma->total_amount, 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_stale_l', null, 'stale-l');
    expect(app(VoucherSaleService::class)->sellVoucher($request)->outcome)->toBe(VoucherSaleOutcome::Sold);

    expect(fn () => $line->update(['description' => 'edited after the sale']))->toThrow(Exception::class);

    DB::connection(LARABILL_AID1825_STALE)->rollBack();
    expect(InvoiceItem::query()->whereKey($line->id)->firstOrFail()->description)->toBe($description);
});
