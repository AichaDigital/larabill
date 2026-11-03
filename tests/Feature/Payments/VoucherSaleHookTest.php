<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\VoucherSaleIssuanceHookContract;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\VoucherSaleClaim;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Support\Facades\DB;

mutates(VoucherSaleService::class, InvoiceService::class);

/*
 * AID-1825 minimal scope §3 — the optional issuance hook: when the consumer
 * binds VoucherSaleIssuanceHookContract, issue() calls afterIssue() INSIDE
 * its transaction, on the same connection, after the sale invoice is
 * numbered and ISSUED and the sale mark is written — so the local fiscal
 * record the hook creates commits with the number or not at all. Without a
 * binding, larabill makes no call. The hook may run up to three times in one
 * issue() call (its transaction retries); it must be retry-safe. What a
 * rollback does NOT undo (logs, listeners, other connections, anything
 * external) is the consumer's.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

function aid1825hSale(string $customerId): array
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $proforma = $proforma->fresh();
    $key      = 'sale-'.$proforma->id;

    return [$proforma, new VoucherSaleRequest((string) $proforma->id, $customerId, $proforma->total_amount, 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_'.$key, null, $key)];
}

/** A recording hook double: what it saw, and optionally what it throws. */
function aid1825hBindHook(?Closure $onCall = null): object
{
    $double = new class($onCall) implements VoucherSaleIssuanceHookContract
    {
        /** @var list<array{invoiceId: string, documentStatus: ?InvoiceDocumentStatus, number: ?string, transactionLevel: int, immutable: bool}> */
        public array $calls = [];

        public function __construct(private readonly ?Closure $onCall) {}

        public function afterIssue(Invoice $invoice): void
        {
            $this->calls[] = [
                'invoiceId'        => (string) $invoice->id,
                'documentStatus'   => $invoice->invoice_document_status,
                'number'           => $invoice->fiscal_number,
                'transactionLevel' => DB::transactionLevel(),
                'immutable'        => (bool) $invoice->is_immutable,
            ];

            if ($this->onCall !== null) {
                ($this->onCall)($invoice, count($this->calls));
            }
        }
    };

    app()->instance(VoucherSaleIssuanceHookContract::class, $double);

    return $double;
}

it('without a binding, the sale makes no fiscal call and issues as today', function () {
    [, $request] = aid1825hSale($this->customer->id);

    $result = app(VoucherSaleService::class)->sellVoucher($request);

    expect($result->outcome)->toBe(VoucherSaleOutcome::Sold)
        ->and($result->claim->fresh()->hook_ran)->toBeFalse()
        ->and(app()->bound(VoucherSaleIssuanceHookContract::class))->toBeFalse();
});

it('calls the hook once, inside the issue transaction, with the numbered ISSUED invoice, and records that it ran', function () {
    [, $request] = aid1825hSale($this->customer->id);
    $hook        = aid1825hBindHook();

    $result = app(VoucherSaleService::class)->sellVoucher($request);

    expect($result->outcome)->toBe(VoucherSaleOutcome::Sold)
        ->and($hook->calls)->toHaveCount(1)
        ->and($hook->calls[0]['invoiceId'])->toBe((string) $result->invoice->id)
        ->and($hook->calls[0]['documentStatus'])->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($hook->calls[0]['number'])->not->toBeNull()
        ->and($hook->calls[0]['transactionLevel'])->toBeGreaterThan((int) config('larabill.payments.base_transaction_level'))
        ->and($result->claim->fresh()->hook_ran)->toBeTrue()
        ->and($result->claim->fresh()->issued_by_sale)->toBeTrue();
});

it('a failing hook rolls the issue back: no number, PREPARED, no mark, no hook flag, nothing fiscal persisted by the hook', function () {
    [, $request] = aid1825hSale($this->customer->id);
    aid1825hBindHook(function (Invoice $invoice): void {
        // Same-connection write the hook "persisted" before failing: it must vanish with the rollback.
        DB::table('invoices')->where('id', $invoice->id)->update(['notes' => 'hook wrote this']);

        throw new RuntimeException('adapter refused');
    });

    expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(RuntimeException::class, 'adapter refused');

    $invoice = Invoice::query()->whereNotNull('sale_claim_id')->firstOrFail();
    $claim   = VoucherSaleClaim::query()->firstOrFail();
    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($invoice->fiscal_number)->toBeNull()
        ->and($invoice->notes)->not->toBe('hook wrote this')
        ->and($claim->issued_by_sale)->toBeFalse()
        ->and($claim->hook_ran)->toBeFalse()
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and(CreditMovement::query()->count())->toBe(0);
});

it('a resume after the hook committed does not call the hook a second time', function () {
    [, $request] = aid1825hSale($this->customer->id);
    $hook        = aid1825hBindHook();

    // Crash between S3a and S3b: veto the BACKED stage once.
    $vetoes = 0;
    VoucherSaleClaim::saving(function (VoucherSaleClaim $claim) use (&$vetoes) {
        if ($claim->stage === VoucherSaleStage::Backed && $vetoes === 0) {
            $vetoes++;

            return false;
        }

        return null;
    });

    try {
        expect(fn () => app(VoucherSaleService::class)->sellVoucher($request))->toThrow(LogicException::class); // the vetoed BACKED write is detected at the write
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }

    expect($hook->calls)->toHaveCount(1);

    $result = app(VoucherSaleService::class)->sellVoucher($request);

    expect($result->outcome)->toBe(VoucherSaleOutcome::Sold)
        ->and($hook->calls)->toHaveCount(1);
});

it('the hook sees is_immutable false on the package path, and true when a consumer sealed the PREPARED invoice before S3a', function () {
    [, $request] = aid1825hSale($this->customer->id);
    $hook        = aid1825hBindHook();

    app(VoucherSaleService::class)->sellVoucher($request);
    expect($hook->calls[0]['immutable'])->toBeFalse();

    // Second sale: stop it between S2 and S3a, seal the PREPARED child the way a
    // consumer's adapter would, then resume — the hook sees the flag as it finds it.
    [, $request2] = aid1825hSale($this->customer->id);
    $hook2        = aid1825hBindHook();
    VoucherSaleClaim::updating(function (VoucherSaleClaim $claim): void {
        if ($claim->stage === VoucherSaleStage::Issuing) {
            throw new RuntimeException('crash before S3a');
        }
    });
    try {
        expect(fn () => app(VoucherSaleService::class)->sellVoucher($request2))->toThrow(RuntimeException::class);
    } finally {
        VoucherSaleClaim::flushEventListeners();
        VoucherSaleClaim::clearBootedModels();
    }

    $prepared = Invoice::query()->where('invoice_document_status', InvoiceDocumentStatus::PREPARED->value)->whereNotNull('sale_claim_id')->firstOrFail();
    DB::table('invoices')->where('id', $prepared->id)->update(['is_immutable' => true]);

    expect(app(VoucherSaleService::class)->sellVoucher($request2)->outcome)->toBe(VoucherSaleOutcome::Sold)
        ->and($hook2->calls)->toHaveCount(1)
        ->and($hook2->calls[0]['immutable'])->toBeTrue()
        ->and($hook2->calls[0]['documentStatus'])->toBe(InvoiceDocumentStatus::ISSUED);
});
