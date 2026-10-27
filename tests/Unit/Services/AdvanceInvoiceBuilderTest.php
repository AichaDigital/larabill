<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Exceptions\InvalidCreditMovementException;
use AichaDigital\Larabill\Exceptions\MissingFiscalSeriesException;
use AichaDigital\Larabill\Exceptions\ReverseChargeWithTaxException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\AdvanceInvoiceBuilder;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-1778 spec §8.2 (S2/S3) — the REFUSAL half of AdvanceInvoiceBuilder::
 * forCreditEntry(): entry validation, tax-group validation and the
 * reverse-charge early refusal. Every refusal must be fail-loud with ZERO
 * writes (no invoice, no item — the draft transaction never opens).
 *
 * The money half (pinned rounding cases, draft shape, O5 duplicates) lives in
 * tests/Feature/Payments/AdvanceInvoiceBuilderTest.php.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->customer = TestUser::factory()->create();

    $this->entry = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(10000),
    ]);

    $this->group = TaxGroup::factory()->create(['name' => 'IVA General 21%']);
    $this->rate  = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $this->group->taxRates()->attach($this->rate->id);

    $this->assertNoDrafts = function (): void {
        expect(Invoice::query()->count())->toBe(0)
            ->and(InvoiceItem::query()->count())->toBe(0);
    };
});

it('refuses an exit movement with notAnEntry and writes nothing (R10)', function () {
    $exit = CreditMovement::factory()->create([
        'billable_user_id'     => (string) $this->customer->id,
        'currency'             => 'EUR',
        'kind'                 => CreditMovementKind::APPLIED->value,
        'amount'               => cents(-4000),
        'consumes_movement_id' => $this->entry->id,
    ]);

    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $exit, 'FAC', 'Advance', $this->group->id, false,
    ))->toThrow(InvalidCreditMovementException::class);

    ($this->assertNoDrafts)();
});

it('refuses a reversed entry with the additive ::reversed constructor and writes nothing (R10)', function () {
    $reversed = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(5000),
        'reversed_at'      => now(),
    ]);

    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $reversed, 'FAC', 'Advance', $this->group->id, false,
    ))->toThrow(InvalidCreditMovementException::class);

    ($this->assertNoDrafts)();
});

it('refuses an already-linked entry with forMovement and writes nothing (R10)', function () {
    $existingAdvance = PaymentFixtures::sentInvoice(10000, (string) $this->customer->id);

    $linked = CreditMovement::factory()->create([
        'billable_user_id'   => (string) $this->customer->id,
        'currency'           => 'EUR',
        'amount'             => cents(7000),
        'advance_invoice_id' => $existingAdvance->id,
    ]);

    $invoicesBefore = Invoice::query()->count();
    $itemsBefore    = InvoiceItem::query()->count();

    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $linked, 'FAC', 'Advance', $this->group->id, false,
    ))->toThrow(CreditMovementAlreadyLinkedException::class)
        // Zero writes BY THE BUILDER: the only invoice is the linked fixture itself.
        ->and(Invoice::query()->count())->toBe($invoicesBefore)
        ->and(InvoiceItem::query()->count())->toBe($itemsBefore);
});

it('refuses a currency mismatch against the active fiscal config with ::creditCurrencyMismatch (R10)', function () {
    $foreign = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'USD',
        'amount'           => cents(10000),
    ]);

    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $foreign, 'FAC', 'Advance', $this->group->id, false,
    ))->toThrow(GroupedPaymentValidationException::class);

    ($this->assertNoDrafts)();
});

it('refuses reverse charge with a real-VAT group through ::forTaxGroup BEFORE any write (R9)', function () {
    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Advance', $this->group->id, true,
    ))->toThrow(ReverseChargeWithTaxException::class);

    ($this->assertNoDrafts)();
});

it('refuses a multi-rate tax group with ::multiRateTaxGroup and writes nothing (R11)', function () {
    $multi = TaxGroup::factory()->create(['name' => 'Multi']);
    $multi->taxRates()->attach([
        $this->rate->id,
        TaxRate::factory()->create(['name' => 'Second rate', 'rate' => 1000])->id,
    ]);

    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Advance', $multi->id, false,
    ))->toThrow(InvalidAdvanceInvoiceException::class);

    ($this->assertNoDrafts)();
});

it('keeps the AID-589 rule: no series passed and none configured throws MissingFiscalSeriesException (R12)', function () {
    config()->set('larabill.invoice_numbering.series.invoice', null);
    config()->set('larabill.invoice_numbering.invoice_prefix', null);

    expect(fn () => app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, null, 'Advance', $this->group->id, false,
    ))->toThrow(MissingFiscalSeriesException::class);

    ($this->assertNoDrafts)();
});
