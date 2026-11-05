<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Support\DocumentCurrency;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

mutates(DocumentCurrency::class);

/*
 * 9.3.1 Step A (spec docs/superpowers/specs/2026-11-04-issued-invoice-currency-for-credit-apply.md):
 * an ISSUED invoice whose fiscal snapshot carries a currency keeps that frozen
 * currency on the collection side; a later in-place edit of the referenced
 * CompanyFiscalConfig does not re-denominate it. Legacy issued rows with no
 * snapshot currency keep the configuration fallback — not protected the same way.
 */

/** An issued (SENT) invoice of $cents for USER_UUID_2 whose snapshot froze $config's currency. */
function aid962cIssuedWithSnapshot(CompanyFiscalConfig $config, int $cents = 5000): Invoice
{
    $invoice = PaymentFixtures::sentInvoice($cents, overrides: ['company_fiscal_config_id' => $config->id]);
    $invoice->forceFill(['fiscal_snapshot' => $invoice->generateFiscalContextSnapshot($config, null)])->saveQuietly();

    return $invoice->refresh();
}

function aid962cTopUp(int $cents, string $currency, int $daysAgo): void
{
    app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents($cents), $currency, now()->subDays($daysAgo)->startOfSecond(),
        PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
}

it('the fixture freezes the configuration currency in the snapshot of an issued invoice', function () {
    $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
    $invoice = aid962cIssuedWithSnapshot($config);

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoice->getFiscalSnapshotData()['currency'])->toBe('EUR');
});

describe('DocumentCurrency::of()', function () {
    it('returns the frozen currency of an issued invoice over an edited configuration', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        $config->update(['currency' => 'USD']);

        expect(DocumentCurrency::of($invoice->fresh()))->toBe('EUR')
            ->and(DocumentCurrency::frozen($invoice->fresh()))->toBe('EUR');
    });

    it('falls back to the configuration for a legacy issued row with no snapshot (not protected)', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = PaymentFixtures::sentInvoice(5000, overrides: ['company_fiscal_config_id' => $config->id]);
        expect($invoice->fiscal_snapshot)->toBeNull();

        $config->update(['currency' => 'USD']);

        expect(DocumentCurrency::frozen($invoice->fresh()))->toBeNull()
            ->and(DocumentCurrency::of($invoice->fresh()))->toBe('USD');
    });

    it('reads the configuration for a document that is not issued, even when it carries a snapshot', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        $invoice->forceFill(['invoice_document_status' => InvoiceDocumentStatus::PREPARED->value])->saveQuietly();
        $config->update(['currency' => 'USD']);

        expect(DocumentCurrency::of($invoice->fresh()))->toBe('USD');
    });

    it('keeps the raw configuration value on the fallback, an empty currency included (v9.3.0 parity)', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => '']);
        $invoice = PaymentFixtures::sentInvoice(5000, overrides: ['company_fiscal_config_id' => $config->id]);

        expect(DocumentCurrency::of($invoice->fresh()))->toBe('');
        // v9.3.0 compared the raw value: '' is not "no currency", so a USD payment is a mismatch.
        expect(fn () => app(PaymentService::class)->receive(
            TestCase::USER_UUID_2, cents(1000), 'USD', now()->startOfSecond(), PaymentMethod::BankTransfer,
            null, null, [new Allocation($invoice->id, cents(1000))],
        ))->toThrow(GroupedPaymentValidationException::class, 'does not match the payment currency');
    });

    it('treats an empty snapshot currency as absent and falls back to the configuration', function () {
        $config               = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice              = aid962cIssuedWithSnapshot($config);
        $snapshot             = $invoice->getFiscalSnapshotData();
        $snapshot['currency'] = '';
        $invoice->forceFill(['fiscal_snapshot' => Crypt::encryptString((string) json_encode($snapshot))])->saveQuietly();
        $config->update(['currency' => 'USD']);

        expect($invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
            ->and(DocumentCurrency::frozen($invoice->fresh()))->toBeNull()
            ->and(DocumentCurrency::of($invoice->fresh()))->toBe('USD');
    });

    it('returns null for an invoice that references no configuration', function () {
        expect(DocumentCurrency::of(PaymentFixtures::sentInvoice(5000)))->toBeNull();
    });
});

describe('apply() after an in-place edit of the configuration currency', function () {
    it('consumes only credit in the frozen currency', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        aid962cTopUp(3000, 'USD', 3);
        aid962cTopUp(2000, 'EUR', 2);
        $config->update(['currency' => 'USD']);

        $rows = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1500));

        expect($rows)->toHaveCount(1)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->pluck('currency')->unique()->all())->toBe(['EUR'])
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(500)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->balance()->unscaledValue())->toBe(3000);
    });

    it('refuses when the customer holds credit only in the edited currency, writing nothing', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        aid962cTopUp(3000, 'USD', 3);
        $config->update(['currency' => 'USD']);
        $statusBefore = $invoice->fresh()->status;

        expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1500)))
            ->toThrow(InsufficientCreditException::class);

        expect(PaymentAllocation::count())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->count())->toBe(0)
            ->and($invoice->fresh()->status)->toBe($statusBefore);
    });

    it('serves the auto-apply call shape (no currency, min of balance and outstanding)', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config, 5000);
        aid962cTopUp(9000, 'USD', 3);
        aid962cTopUp(2000, 'EUR', 2);
        $config->update(['currency' => 'USD']);

        $credit = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance();
        $amount = $credit->compareTo($invoice->amountOutstanding()) < 0 ? $credit : $invoice->amountOutstanding();
        app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, $amount, TestCase::USER_UUID_1);

        expect($invoice->fresh()->amountOutstanding()->unscaledValue())->toBe(3000)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->balance()->unscaledValue())->toBe(9000);
    });

    it('plans in the frozen currency when the configuration moves between the read and the locks', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        aid962cTopUp(2000, 'EUR', 2);
        aid962cTopUp(3000, 'USD', 3);

        aid962cEditConfigOnInvoiceLock((string) $config->id, 'USD');
        app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1500));

        expect($config->fresh()->currency)->toBe('USD')
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(500);
    });

    it('refuses a legacy row whose configuration moves between the read and the locks', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = PaymentFixtures::sentInvoice(5000, overrides: ['company_fiscal_config_id' => $config->id]);
        aid962cTopUp(2000, 'EUR', 2);
        aid962cTopUp(3000, 'USD', 3);

        aid962cEditConfigOnInvoiceLock((string) $config->id, 'USD');

        expect(fn () => app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1500)))
            ->toThrow(GroupedPaymentValidationException::class, 'does not match the payment currency');
        expect(PaymentAllocation::count())->toBe(0);
    });
});

/**
 * One-shot: on the first read of `invoices` inside apply()'s own transaction
 * (its locking read; the read before the transaction is at the base level), move the configuration currency on the same connection —
 * the relation is loaded after that read, so it sees the moved value.
 */
function aid962cEditConfigOnInvoiceLock(string $configId, string $currency): void
{
    $fired = false;
    $base  = DB::transactionLevel(); // the test's own wrapping transaction, if any
    DB::listen(function (QueryExecuted $query) use (&$fired, $base, $configId, $currency): void {
        if ($fired || DB::transactionLevel() <= $base || ! preg_match('/from\s+["`]?invoices["`]?/i', $query->sql)) {
            return;
        }
        $fired = true;
        DB::table('company_fiscal_configs')->where('id', $configId)->update(['currency' => $currency]);
    });
}

describe('payments and outstanding after an in-place edit', function () {
    it('receive() refuses a payment in the edited currency and accepts one in the frozen currency', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        $config->update(['currency' => 'USD']);

        $receive = fn (string $currency) => app(PaymentService::class)->receive(
            TestCase::USER_UUID_2, cents(1000), $currency, now()->startOfSecond(), PaymentMethod::BankTransfer,
            null, null, [new Allocation($invoice->id, cents(1000))],
        );

        expect(fn () => $receive('USD'))->toThrow(GroupedPaymentValidationException::class, 'does not match the payment currency');
        expect($receive('EUR')->currency)->toBe('EUR');
    });

    it('register() refuses a payment in the edited currency and accepts one in the frozen currency', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        $invoice = aid962cIssuedWithSnapshot($config);
        $config->update(['currency' => 'USD']);

        $register = fn (string $currency) => app(GroupedPaymentService::class)->register(
            TestCase::USER_UUID_2, [(string) $invoice->id], now()->startOfSecond(), cents(5000), $currency,
        );

        expect(fn () => $register('USD'))->toThrow(GroupedPaymentValidationException::class, 'does not match the payment currency');
        expect($register('EUR')->currency)->toBe('EUR');
    });

    it('keeps a non-EUR frozen currency when the configuration is edited to EUR', function () {
        $config  = CompanyFiscalConfig::factory()->create(['currency' => 'USD']);
        $invoice = aid962cIssuedWithSnapshot($config);
        aid962cTopUp(2000, 'USD', 3);
        aid962cTopUp(3000, 'EUR', 2);
        $config->update(['currency' => 'EUR']);

        expect(DocumentCurrency::of($invoice->fresh()))->toBe('USD');

        app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(1500));

        expect(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->balance()->unscaledValue())->toBe(500)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->balance()->unscaledValue())->toBe(3000)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->outstanding()->unscaledValue())->toBe(3500)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->outstanding()->unscaledValue())->toBe(0);

        $receive = fn (string $currency) => app(PaymentService::class)->receive(
            TestCase::USER_UUID_2, cents(1000), $currency, now()->startOfSecond(), PaymentMethod::BankTransfer,
            null, null, [new Allocation($invoice->id, cents(1000))],
        );

        expect(fn () => $receive('EUR'))->toThrow(GroupedPaymentValidationException::class, 'does not match the payment currency');
        expect($receive('USD')->currency)->toBe('USD');
    });

    it('counts the invoice in the outstanding of its frozen currency only', function () {
        $config = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);
        aid962cIssuedWithSnapshot($config, 5000);
        $config->update(['currency' => 'USD']);

        expect(CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->outstanding()->unscaledValue())->toBe(5000)
            ->and(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->outstanding()->unscaledValue())->toBe(0);
    });
});
