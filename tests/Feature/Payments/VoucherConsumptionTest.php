<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
use AichaDigital\Larabill\Exceptions\InvoiceNotCancellableException;
use AichaDigital\Larabill\Exceptions\InvoiceNotRectifiableException;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\CreditOperation;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\VoucherConsumptionRequest;
use AichaDigital\Larabill\ValueObjects\VoucherConsumptionResult;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use AichaDigital\Larabill\ValueObjects\VoucherSaleResult;

mutates(VoucherSaleService::class, SettlementService::class);

/*
 * AID-1825 minimal scope §1 items 5-6 and item 4 (annulment): the voucher is
 * consumed against services — all or nothing, never below zero, idempotent
 * by key — with the data of a receipt that is NOT an invoice; a top-up
 * proforma is cancelled under its lock and never reopened; the voucher-sale
 * invoice is annulled only when nothing stands on it.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

function aid1825cProforma(string $customerId, int $basePriceCents = 10000): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'Service voucher', 'quantity' => 100, 'base_price' => $basePriceCents]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

/** A sold voucher of the customer; returns the sale result. */
function aid1825cSold(string $customerId, int $basePriceCents = 10000): VoucherSaleResult
{
    $proforma = aid1825cProforma($customerId, $basePriceCents);
    $key      = 'sale-'.$proforma->id;

    return app(VoucherSaleService::class)->sellVoucher(new VoucherSaleRequest(
        (string) $proforma->id, $customerId, $proforma->total_amount, 'EUR', now()->subMinute()->startOfSecond(), PaymentMethod::Card, 'pi_'.$key, null, $key,
    ));
}

function aid1825cConsume(string $customerId, int $cents, string $key, string $serviceRef = 'domain:example.test'): VoucherConsumptionResult
{
    return app(VoucherSaleService::class)->consumeVoucher(new VoucherConsumptionRequest(
        billableUserId: $customerId,
        amount: cents($cents),
        currency: 'EUR',
        serviceRef: $serviceRef,
        serviceDescription: 'Domain registration',
        consumedAt: now(),
        recordedBy: null,
        operationKey: $key,
    ));
}

describe('consumeVoucher() (test 15)', function () {
    it('consumes against the voucher entry, all or nothing, and returns receipt data that is not an invoice', function () {
        $sold  = aid1825cSold($this->customer->id);
        $total = $sold->invoice->total_amount->unscaledValue();

        $result = aid1825cConsume($this->customer->id, 2500, 'c-1');

        $exit = $result->exits->sole();
        expect($exit->kind)->toBe(CreditMovementKind::CONSUMED)
            ->and($exit->amount->unscaledValue())->toBe(-2500)
            ->and((string) $exit->consumes_movement_id)->toBe((string) $sold->entry->id)
            ->and($exit->service_ref)->toBe('domain:example.test')
            ->and($exit->allocation_id)->toBeNull()
            ->and($exit->credit_operation_id)->not->toBeNull();

        // Receipt data: the sale invoice reference, what was consumed, what is left. Not a fiscal document.
        $receipt = $result->receipts[0];
        expect($result->receipts)->toHaveCount(1)
            ->and($receipt->saleInvoiceNumber)->toBe($sold->invoice->fresh()->fiscal_number)
            ->and($receipt->consumedAmount->unscaledValue())->toBe(2500)
            ->and($receipt->remainingOnEntry->unscaledValue())->toBe($total - 2500)
            ->and($receipt->remainingVoucherBalance->unscaledValue())->toBe($total - 2500)
            ->and(Invoice::query()->count())->toBe(2); // proforma + sale invoice: no document per consumption

        expect(CustomerCredit::for($this->customer->id, 'EUR')->breakdown()->voucherBacked->unscaledValue())->toBe($total - 2500);
    });

    it('never consumes a part: insufficient voucher balance refuses the whole consumption with zero writes', function () {
        $sold  = aid1825cSold($this->customer->id);
        $total = $sold->invoice->total_amount->unscaledValue();
        app(PaymentService::class)->receive($this->customer->id, cents(50000), 'EUR', now(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP); // consumable credit is NOT a voucher

        expect(fn () => aid1825cConsume($this->customer->id, $total + 1, 'c-2'))->toThrow(InsufficientCreditException::class);

        expect(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(0)
            ->and(CreditOperation::query()->count())->toBe(0)
            ->and(CustomerCredit::for($this->customer->id, 'EUR')->breakdown()->voucherBacked->unscaledValue())->toBe($total);
    });

    it('is idempotent by key: the same key replays the same exits, another payload under it conflicts', function () {
        aid1825cSold($this->customer->id);

        $first  = aid1825cConsume($this->customer->id, 1000, 'c-3');
        $second = aid1825cConsume($this->customer->id, 1000, 'c-3');

        expect($second->exits->sole()->id)->toBe($first->exits->sole()->id)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(1);

        expect(fn () => aid1825cConsume($this->customer->id, 2000, 'c-3'))->toThrow(IdempotencyConflictException::class);
        expect(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(1);
    });

    it('consumes FIFO across two vouchers and a consumed voucher\'s payment cannot be reversed', function () {
        $a      = aid1825cSold($this->customer->id, 3000);
        $b      = aid1825cSold($this->customer->id, 5000);
        $totalA = $a->invoice->total_amount->unscaledValue();

        $result = aid1825cConsume($this->customer->id, $totalA + 100, 'c-4');

        expect($result->exits)->toHaveCount(2)
            ->and((string) $result->exits->first()->consumes_movement_id)->toBe((string) $a->entry->id)
            ->and((string) $result->exits->last()->consumes_movement_id)->toBe((string) $b->entry->id)
            ->and($result->exits->last()->amount->unscaledValue())->toBe(-100);

        expect(fn () => app(PaymentService::class)->reverse($a->payment, 'late'))->toThrow(Exception::class);
        expect($a->payment->fresh()->isPosted())->toBeTrue();
    });
});

describe('cancelProforma() (test 10 of §5 — proforma cancellation under lock)', function () {
    it('cancels an unclaimed SENT top-up proforma and never reopens it', function () {
        $proforma = aid1825cProforma($this->customer->id);

        $cancelled = app(VoucherSaleService::class)->cancelProforma($proforma, 'staff');

        expect($cancelled->status)->toBe(InvoiceStatus::CANCELLED);

        $result = app(VoucherSaleService::class)->sellVoucher(new VoucherSaleRequest(
            (string) $proforma->id, $this->customer->id, $proforma->total_amount, 'EUR', now(), PaymentMethod::Card, 'pi_x', null, 'x',
        ));
        expect($result->outcome)->toBe(VoucherSaleOutcome::ProformaNotInForce)
            ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CANCELLED);
    });

    it('refuses a claimed, a converting and a converted proforma', function () {
        $sold      = aid1825cSold($this->customer->id);
        $converted = $sold->claim->proforma()->firstOrFail();

        expect(fn () => app(VoucherSaleService::class)->cancelProforma($converted, 'staff'))->toThrow(InvoiceNotCancellableException::class);
        expect($converted->fresh()->status)->toBe(InvoiceStatus::CONVERTED);

        $claimedProforma = aid1825cProforma($this->customer->id);
        $stop            = Invoice::saving(function (Invoice $invoice): void {
            if (! $invoice->exists && $invoice->proforma_id !== null) {
                throw new RuntimeException('crash after S1');
            }
        });
        try {
            app(VoucherSaleService::class)->sellVoucher(new VoucherSaleRequest(
                (string) $claimedProforma->id, $this->customer->id, $claimedProforma->total_amount, 'EUR', now(), PaymentMethod::Card, 'pi_y', null, 'y',
            ));
        } catch (RuntimeException) {
        } finally {
            Invoice::flushEventListeners();
            Invoice::clearBootedModels();
        }

        expect(fn () => app(VoucherSaleService::class)->cancelProforma($claimedProforma, 'staff'))->toThrow(SaleClaimedException::class);
        expect($claimedProforma->fresh()->status)->toBe(InvoiceStatus::SENT);
    });

    it('refuses a non-proforma', function () {
        $sold = aid1825cSold($this->customer->id);

        expect(fn () => app(VoucherSaleService::class)->cancelProforma($sold->invoice, 'staff'))->toThrow(InvoiceNotCancellableException::class);
    });
});

describe('annul() on a voucher-sale invoice (test 9 — item 4)', function () {
    it('refuses while the voucher has live balance (the sale is backed: active allocation)', function () {
        $sold = aid1825cSold($this->customer->id);

        expect(fn () => app(SettlementService::class)->annul($sold->invoice->fresh()))->toThrow(InvoiceNotAnnullableException::class);
        expect($sold->invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    });

    it('refuses once the voucher has consumptions, even if its remaining is zero', function () {
        $sold  = aid1825cSold($this->customer->id);
        $total = $sold->invoice->total_amount->unscaledValue();
        aid1825cConsume($this->customer->id, $total, 'all');

        expect(CustomerCredit::for($this->customer->id, 'EUR')->balance()->unscaledValue())->toBe(0);
        expect(fn () => app(SettlementService::class)->annul($sold->invoice->fresh()))->toThrow(InvoiceNotAnnullableException::class);
    });
});

describe('rectify() on a voucher-sale invoice', function () {
    it('refuses it: no rectificative is created and balance, outstanding and position are untouched', function (string $route) {
        $sold   = aid1825cSold($this->customer->id);
        $credit = CustomerCredit::for($this->customer->id, 'EUR');
        $before = [$credit->balance()->unscaledValue(), $credit->outstanding()->unscaledValue(), $credit->position()->unscaledValue()];
        $count  = Invoice::query()->count();

        $call = match ($route) {
            'full'    => fn () => app(SettlementService::class)->rectify($sold->invoice->fresh()),
            'partial' => fn () => app(SettlementService::class)->rectify($sold->invoice->fresh(), -1000),
            'keyed'   => fn () => app(SettlementService::class)->rectify($sold->invoice->fresh(), null, 'rectify-voucher-sale'),
        };

        expect($call)->toThrow(InvoiceNotRectifiableException::class, 'voucher-sale');

        expect(Invoice::query()->count())->toBe($count)
            ->and(Invoice::query()->where('rectifies_invoice_id', $sold->invoice->id)->exists())->toBeFalse()
            ->and([$credit->balance()->unscaledValue(), $credit->outstanding()->unscaledValue(), $credit->position()->unscaledValue()])->toBe($before);
    })->with(['full', 'partial', 'keyed']);

    it('reads the marker from the row, not from the instance handed in', function () {
        $sold  = aid1825cSold($this->customer->id);
        $stale = Invoice::query()->select(array_diff(array_keys($sold->invoice->fresh()->getAttributes()), ['sale_claim_id']))->findOrFail($sold->invoice->id);

        expect($stale->getAttribute('sale_claim_id'))->toBeNull()
            ->and(fn () => app(SettlementService::class)->rectify($stale))->toThrow(InvoiceNotRectifiableException::class, 'voucher-sale');
    });
});

describe('implementation gate round 1 — reproduced defects', function () {
    it('a vetoed CONSUMED exit save aborts the whole consumption: no operation row, no exit, balance intact', function () {
        $sold  = aid1825cSold($this->customer->id);
        $total = $sold->invoice->total_amount->unscaledValue();

        CreditMovement::saving(fn (CreditMovement $m) => $m->kind === CreditMovementKind::CONSUMED ? false : null);
        try {
            expect(fn () => aid1825cConsume($this->customer->id, 1000, 'veto-1'))->toThrow(LogicException::class);
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }

        expect(CreditOperation::query()->count())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(0)
            ->and(CustomerCredit::for($this->customer->id, 'EUR')->breakdown()->voucherBacked->unscaledValue())->toBe($total);
    });
});

describe('implementation gate round 2 — reproduced defects', function () {
    it('a vetoed operation-row save aborts the consumption: no exits without their operation, balance intact', function () {
        $sold  = aid1825cSold($this->customer->id);
        $total = $sold->invoice->total_amount->unscaledValue();

        CreditOperation::saving(fn () => false);
        try {
            expect(fn () => aid1825cConsume($this->customer->id, 1000, 'veto-op'))->toThrow(LogicException::class);
        } finally {
            CreditOperation::flushEventListeners();
            CreditOperation::clearBootedModels();
        }

        expect(CreditOperation::query()->count())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(0)
            ->and(CustomerCredit::for($this->customer->id, 'EUR')->breakdown()->voucherBacked->unscaledValue())->toBe($total);
    });
});

describe('implementation gate round 3 — reproduced defects', function () {
    it('the consumption fingerprint keeps field boundaries: a `|` inside a service reference cannot alias another payload', function () {
        $a = new VoucherConsumptionRequest($this->customer->id, cents(1000), 'EUR', 'svc:A|B', null, now(), null, 'k-fp');
        $b = new VoucherConsumptionRequest($this->customer->id, cents(1000), 'EUR|svc:A', 'B', null, now(), null, 'k-fp');

        expect($a->fingerprint())->not->toBe($b->fingerprint());

        aid1825cSold($this->customer->id);
        app(VoucherSaleService::class)->consumeVoucher($a);
        expect(fn () => app(VoucherSaleService::class)->consumeVoucher($b))->toThrow(IdempotencyConflictException::class);
    });
});

describe('implementation gate round 5 — consumption identity', function () {
    it('the description is part of the identity (another description under the same key conflicts); the instant and the actor are not', function () {
        aid1825cSold($this->customer->id);
        $a = new VoucherConsumptionRequest($this->customer->id, cents(1000), 'EUR', 'domain:x', 'Domain X, year 1', now()->subMinute(), 'alice', 'k-id');
        app(VoucherSaleService::class)->consumeVoucher($a);

        $otherDescription = new VoucherConsumptionRequest($this->customer->id, cents(1000), 'EUR', 'domain:x', 'Domain X, year 2', now()->subMinute(), 'alice', 'k-id');
        expect(fn () => app(VoucherSaleService::class)->consumeVoucher($otherDescription))->toThrow(IdempotencyConflictException::class);

        $retry = new VoucherConsumptionRequest($this->customer->id, cents(1000), 'EUR', 'domain:x', 'Domain X, year 1', now(), 'bob', 'k-id');
        expect(app(VoucherSaleService::class)->consumeVoucher($retry)->replayed)->toBeTrue()
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(1);
    });
});

describe('implementation gate round 8 — reproduced defect', function () {
    it('consumption verifies the PERSISTED exits: a listener that shrinks an exit amount makes the whole consumption roll back', function () {
        $sold  = aid1825cSold($this->customer->id);
        $total = $sold->invoice->total_amount->unscaledValue();

        CreditMovement::saving(function (CreditMovement $m): void {
            if (! $m->exists && $m->kind === CreditMovementKind::CONSUMED) {
                $m->amount = FixedDecimal::ofUnscaled(-1, 2);
            }
        });
        try {
            expect(fn () => aid1825cConsume($this->customer->id, 1000, 'shrunk-exit'))->toThrow(SaleIntegrityException::class);
        } finally {
            CreditMovement::flushEventListeners();
            CreditMovement::clearBootedModels();
        }

        expect(CreditOperation::query()->count())->toBe(0)
            ->and(CreditMovement::query()->where('kind', CreditMovementKind::CONSUMED->value)->count())->toBe(0)
            ->and(CustomerCredit::for($this->customer->id, 'EUR')->breakdown()->voucherBacked->unscaledValue())->toBe($total);
    });
});
