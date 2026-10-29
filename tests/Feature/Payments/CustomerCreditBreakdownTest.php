<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use AichaDigital\Larabill\ValueObjects\CreditBreakdown;
use AichaDigital\Larabill\ValueObjects\CreditEntryRemainder;
use Illuminate\Support\Facades\DB;

mutates(CustomerCredit::class);

/*
 * CustomerCredit::breakdown() (AID-1817): the live balance of the bound
 * customer and currency split by the origin of each entry — CONSUMABLE
 * (no advance invoice; spendable with apply()) and ADVANCE-BACKED (linked to
 * an advance invoice; only consumable as a deduction line, AID-1815). The two
 * parts always add up to balance().
 */

/** @param  array<int, Allocation>  $allocations */
function aid1817TopUp(int $cents, DateTimeInterface $at, array $allocations = [], string $billable = TestCase::USER_UUID_2, string $currency = 'EUR'): GroupedPayment
{
    return app(PaymentService::class)->receive(
        $billable, cents($cents), $currency, $at, PaymentMethod::BankTransfer, null, null, $allocations, CreditMovementKind::TOP_UP,
    );
}

/** An unnumbered DRAFT fiscal invoice of the customer, linkable as an advance. */
function aid1817AdvanceDraft(string $billable = TestCase::USER_UUID_2): Invoice
{
    return Invoice::factory()->unissuedDraft()->create(['billable_user_id' => $billable, 'user_id' => TestCase::USER_UUID_1]);
}

function aid1817AssertAddsUp(CreditBreakdown $breakdown, CustomerCredit $credit): void
{
    expect($breakdown->consumable->plus($breakdown->advanceBacked)->unscaledValue())
        ->toBe($credit->balance()->unscaledValue())
        ->and($breakdown->total()->unscaledValue())->toBe($credit->balance()->unscaledValue());
}

it('splits mixed entries after applications and partial refunds, and adds up to balance()', function () {
    $p1 = aid1817TopUp(1500, now()->subDays(4)->startOfSecond());
    $z  = PaymentFixtures::sentInvoice(1000);
    $p2 = aid1817TopUp(2400, now()->subDays(3)->startOfSecond(), [new Allocation($z->id, cents(1000))]);
    app(PaymentService::class)->reverseAllocation($p2->allocations()->sole()->id, 'reassign');   // SURPLUS 1000 on P2

    $e1      = $p1->creditMovements()->sole();
    $e2      = $p2->creditMovements()->where('kind', CreditMovementKind::TOP_UP->value)->sole();
    $surplus = $p2->creditMovements()->where('kind', CreditMovementKind::SURPLUS->value)->sole();

    // Noise that must not count: same customer in USD, another customer in EUR.
    aid1817TopUp(9900, now()->subDays(5)->startOfSecond(), currency: 'USD');
    aid1817TopUp(8800, now()->subDays(5)->startOfSecond(), billable: TestCase::USER_UUID_3);

    // FIFO: 1500 from E1, 500 from E2 (E2 left with 900).
    app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, PaymentFixtures::sentInvoice(5000, overrides: ['company_fiscal_config_id' => CompanyFiscalConfig::factory()->create(['currency' => 'EUR'])->id]), cents(2000));

    // E2 is linked to an advance AFTER being partly consumed: only its rest counts, as advance-backed.
    $advance = aid1817AdvanceDraft();
    $e2->linkAdvanceInvoice($advance);

    // A partial refund consumes FIFO, exactly like refund() does today: 300 from E2 (600 left).
    app(CustomerCredit::class)->refund(TestCase::USER_UUID_2, cents(300), 'EUR', now()->subHour()->startOfSecond(), PaymentMethod::BankTransfer);

    $credit    = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR');
    $breakdown = $credit->breakdown();

    expect($breakdown)->toBeInstanceOf(CreditBreakdown::class)
        ->and($breakdown->consumable->unscaledValue())->toBe(1000)
        ->and($breakdown->advanceBacked->unscaledValue())->toBe(600)
        ->and($breakdown->entries)->toHaveCount(2)
        ->and(array_map(fn (CreditEntryRemainder $e) => $e->movementId, $breakdown->entries))->toBe([$e2->id, $surplus->id])
        ->and(array_map(fn (CreditEntryRemainder $e) => $e->remaining->unscaledValue(), $breakdown->entries))->toBe([600, 1000])
        ->and(array_map(fn (CreditEntryRemainder $e) => $e->advanceInvoiceId, $breakdown->entries))->toBe([$advance->id, null])
        ->and($breakdown->entries[0]->isAdvanceBacked())->toBeTrue()
        ->and($breakdown->entries[1]->isAdvanceBacked())->toBeFalse()
        ->and(collect($breakdown->entries)->pluck('movementId')->all())->not->toContain($e1->id);

    aid1817AssertAddsUp($breakdown, $credit);
    aid1817AssertAddsUp(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->breakdown(), CustomerCredit::for(TestCase::USER_UUID_2, 'USD'));
    expect(CustomerCredit::for(TestCase::USER_UUID_2, 'USD')->breakdown()->consumable->unscaledValue())->toBe(9900);
});

it('ignores reversed exits and reversed entries', function () {
    $entry = aid1817TopUp(2000, now()->subDays(2)->startOfSecond())->creditMovements()->sole();
    $x     = PaymentFixtures::sentInvoice(5000);

    // An application undone: its exit is reversed and the entry is whole again.
    $row = app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $x, cents(700))->sole();
    app(PaymentService::class)->reverseAllocation($row->id, 'undo');

    // A second payment reversed entirely: its entry no longer counts.
    $gone = aid1817TopUp(500, now()->subDay()->startOfSecond());
    app(PaymentService::class)->reverse($gone, 'bounced');

    $credit    = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR');
    $breakdown = $credit->breakdown();

    expect($breakdown->consumable->unscaledValue())->toBe(2000)
        ->and($breakdown->advanceBacked->unscaledValue())->toBe(0)
        ->and(array_map(fn (CreditEntryRemainder $e) => $e->movementId, $breakdown->entries))->toBe([$entry->id]);

    aid1817AssertAddsUp($breakdown, $credit);
});

it('counts an entry linked to an advance still in DRAFT as advance-backed', function () {
    $entry = aid1817TopUp(1200, now()->subDay()->startOfSecond())->creditMovements()->sole();
    $draft = aid1817AdvanceDraft();
    $entry->linkAdvanceInvoice($draft);

    expect($draft->refresh()->fiscal_number)->toBeNull();

    $breakdown = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown();

    expect($breakdown->consumable->unscaledValue())->toBe(0)
        ->and($breakdown->advanceBacked->unscaledValue())->toBe(1200);
});

it('returns zero parts and no entries for a customer without credit', function () {
    $breakdown = CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown();

    expect($breakdown->consumable->unscaledValue())->toBe(0)
        ->and($breakdown->advanceBacked->unscaledValue())->toBe(0)
        ->and($breakdown->entries)->toBe([]);
});

it('reads without writing or locking', function () {
    aid1817TopUp(1500, now()->subDay()->startOfSecond());
    $before = CreditMovement::query()->count();

    DB::flushQueryLog();
    DB::enableQueryLog();
    CustomerCredit::for(TestCase::USER_UUID_2, 'EUR')->breakdown();
    $queries = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect($sql)->toStartWith('select')
            ->and($sql)->not->toContain('for update')
            ->and($sql)->not->toContain('lock in share mode');
    }
    expect(CreditMovement::query()->count())->toBe($before);
});

it('throws LogicException on an unbound instance', function () {
    app(CustomerCredit::class)->breakdown();
})->throws(LogicException::class);
