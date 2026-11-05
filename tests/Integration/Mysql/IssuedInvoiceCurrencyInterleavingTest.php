<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * 9.3.1 Step A, TA4 (spec 2026-11-04 §5): a deterministic interleaving of two
 * REAL connections. A second connection (`editor`) commits
 * `UPDATE company_fiscal_configs SET currency = 'USD'` between apply()'s read
 * before the transaction and its read under the locks — injected on the
 * invoice locking read of the `testing` connection. The configuration row is
 * not locked by apply(), so the commit goes through and the relation loaded
 * afterwards sees USD.
 *
 * With a snapshot currency the plan stays EUR (the source is the locked,
 * immutable row). On a legacy row with no snapshot apply() refuses with
 * currencyMismatch instead of planning over payments chosen for EUR. Not a
 * fork test: Step A introduces no lock and the invariant does not depend on
 * serialisation.
 */

const LARABILL_STEP_A_EDITOR = 'editor';

beforeEach(function () {
    if (! MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        test()->markTestSkipped('LARABILL_TEST_MYSQL_* env not configured');
    }

    $this->bootstrap();
    $this->customer = $this->seedUser();
    $this->issuer   = $this->seedUser();

    config(['database.connections.'.LARABILL_STEP_A_EDITOR => config('database.connections.testing')]);
    DB::purge(LARABILL_STEP_A_EDITOR);
});

afterEach(function () {
    // Runs even when beforeEach() skipped (no MySQL env): no app, no facade root then.
    if (MysqlIntegrationTestCase::mysqlEnvAvailable()) {
        DB::purge(LARABILL_STEP_A_EDITOR);
    }
});

function stepAInvoice(MysqlIntegrationTestCase $case, CompanyFiscalConfig $config, bool $withSnapshot): Invoice
{
    $invoice = Invoice::factory()->sent()->create([
        'user_id'                  => $case->issuer,
        'billable_user_id'         => $case->customer,
        'company_fiscal_config_id' => $config->id,
        'total_amount'             => FixedDecimal::ofUnscaled(5000, 2),
        'is_immutable'             => false,
        'paid_at'                  => null,
        'due_date'                 => null,
    ]);

    if ($withSnapshot) {
        $invoice->forceFill(['fiscal_snapshot' => $invoice->generateFiscalContextSnapshot($config, null)])->saveQuietly();
    }

    return $invoice->refresh();
}

function stepATopUp(string $customer, int $cents, string $currency, int $daysAgo): void
{
    app(PaymentService::class)->receive(
        $customer, FixedDecimal::ofUnscaled($cents, 2), $currency, now()->subDays($daysAgo)->startOfSecond(),
        PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
}

/** One-shot: on apply()'s invoice locking read, the `editor` connection commits the currency edit. */
function stepAEditOnInvoiceLock(string $configId): void
{
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $configId): void {
        if ($fired || $query->connectionName !== 'testing' || DB::connection('testing')->transactionLevel() === 0
                   || ! preg_match('/from\s+`invoices`.*for update/is', $query->sql)) {
            return;
        }
        $fired = true;
        DB::connection(LARABILL_STEP_A_EDITOR)->table('company_fiscal_configs')->where('id', $configId)->update(['currency' => 'USD']);
    });
}

it('plans in the frozen currency when another connection edits the configuration mid-apply', function () {
    $config  = CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null, 'currency' => 'EUR']);
    $invoice = stepAInvoice($this, $config, withSnapshot: true);
    stepATopUp($this->customer, 2000, 'EUR', 2);
    stepATopUp($this->customer, 3000, 'USD', 3);

    stepAEditOnInvoiceLock((string) $config->id);
    app(CustomerCredit::class)->apply($this->customer, $invoice, FixedDecimal::ofUnscaled(1500, 2));

    expect($config->fresh()->currency)->toBe('USD') // the edit did commit, mid-operation
        ->and(CreditMovement::query()->where('kind', CreditMovementKind::APPLIED->value)->pluck('currency')->unique()->all())->toBe(['EUR'])
        ->and(CustomerCredit::for($this->customer, 'EUR')->balance()->unscaledValue())->toBe(500)
        ->and(CustomerCredit::for($this->customer, 'USD')->balance()->unscaledValue())->toBe(3000);
});

it('refuses a legacy row with no snapshot when another connection edits the configuration mid-apply', function () {
    $config  = CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null, 'currency' => 'EUR']);
    $invoice = stepAInvoice($this, $config, withSnapshot: false);
    stepATopUp($this->customer, 2000, 'EUR', 2);
    stepATopUp($this->customer, 3000, 'USD', 3);

    stepAEditOnInvoiceLock((string) $config->id);

    expect(fn () => app(CustomerCredit::class)->apply($this->customer, $invoice, FixedDecimal::ofUnscaled(1500, 2)))
        ->toThrow(GroupedPaymentValidationException::class, 'does not match the payment currency');
    expect($config->fresh()->currency)->toBe('USD')
        ->and(PaymentAllocation::count())->toBe(0);
});
