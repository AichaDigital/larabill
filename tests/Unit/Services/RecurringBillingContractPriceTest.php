<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\InvoiceItemMetadata;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Exceptions\MissingContractPriceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-956 — effective_price is contractual state, not a cache.
 *
 * The recurring engine bills the price stored on the agreement. It does not
 * re-derive it from the catalogue or from overrides at emission time, which
 * is what ADR-004 has promised since 2025-12-09.
 *
 * Design spec: docs/superpowers/specs/2026-08-17-aid-956-effective-price-contractual-state.md
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();

    UserTaxProfile::factory()->create([
        'owner_user_id' => $this->customer->id,
        'tax_id'        => 'ES12345678Z',
        'valid_from'    => now()->subYear(),
        'valid_until'   => null,
    ]);

    $this->billingService = fn () => new RecurringBillingService(new PricingService);

    // Closure, not a file-level helper: those live in the suite's global
    // namespace and collide across files.
    $this->contractFor = fn (Article $article, int $contractPrice, ?int $overrideId = null) => ArticleServiceStatus::factory()->create([
        'customer_id'         => $this->customer->id,
        'article_id'          => $article->id,
        'billing_frequency'   => BillingFrequency::MONTHLY,
        'status'              => ServiceStatus::ACTIVE,
        'next_billing_date'   => now()->addDays(7),
        'effective_price'     => cents($contractPrice),
        'current_override_id' => $overrideId,
    ]);
});

describe('the engine bills the contract (D1)', function () {
    it('bills the contract price when the catalogue holds no price for that frequency', function () {
        // Article deliberately created WITHOUT any ArticlePrice. The state is
        // required: ArticleFactory::configure() auto-seeds a random price for
        // any article that ends up with none. No override either, so the
        // contract price is the single source of the amount.
        $article = Article::factory()->withoutPrices()->create(['tax_group_id' => null]);

        expect($article->getPriceFor(BillingFrequency::MONTHLY))->toBeNull();

        ArticleServiceStatus::factory()->create([
            'customer_id'         => $this->customer->id,
            'article_id'          => $article->id,
            'billing_frequency'   => BillingFrequency::MONTHLY,
            'status'              => ServiceStatus::ACTIVE,
            'next_billing_date'   => now()->addDays(7),
            'effective_price'     => cents(4500),
            'current_override_id' => null,
        ]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['invoices'])->toHaveCount(1);

        $item = Invoice::findOrFail($results['invoices'][0])->items->first();

        // Before the fix this is 0: PricingService re-derives from the
        // catalogue and falls back to `?? 0.0` when it finds nothing.
        expect($item->unit_price->unscaledValue())->toBe(4500)
            ->and($item->taxable_amount->unscaledValue())->toBe(4500);
    });

    it('bills the contract price when it differs from the catalogue', function () {
        // No override: catalogue and contract disagree, and the contract wins.
        $article = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        ($this->contractFor)($article, 4500);

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        expect($item->unit_price->unscaledValue())->toBe(4500);
    });

    it('does not reprice a live agreement when the catalogue price changes', function () {
        // No override on purpose: an active one would mask the catalogue
        // change and the test would pass against the broken engine too.
        $article = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        ($this->contractFor)($article, 2900);

        // Through the model, not the relation's query builder: the FixedDecimal
        // cast is an Eloquent concern and the builder would bind the object raw.
        $article->prices()->firstOrFail()->update(['price' => cents(9900)]);

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        expect($item->unit_price->unscaledValue())->toBe(2900);
    });

    it('does not reprice a live agreement when its override changes', function () {
        // The mutation is custom_price, NOT valid_to: the old engine never
        // looked at the override validity window, so expiring it would pass
        // against the broken code and prove nothing.
        $article  = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        $override = ArticleOverride::factory()->create([
            'article_id'   => $article->id,
            'customer_id'  => $this->customer->id,
            'custom_price' => cents(2400),
            'is_active'    => true,
        ]);

        ($this->contractFor)($article, 2400, $override->id);

        $override->update(['custom_price' => cents(1000)]);

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        expect($item->unit_price->unscaledValue())->toBe(2400);
    });

    it('keeps a distinct price on each agreement for the same customer, article and frequency', function () {
        // ArticleOverride belongs to (customer, article) and carries no
        // frequency or service reference, so it cannot express two different
        // agreements for the same pair. The contract can.
        $article = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);

        ($this->contractFor)($article, 2400);
        ($this->contractFor)($article, 3600);

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['invoices'])->toHaveCount(2);

        $billed = InvoiceItem::query()
            ->whereIn('invoice_id', $results['invoices'])
            ->get()
            ->map(fn (InvoiceItem $item): int => $item->unit_price->unscaledValue())
            ->sort()
            ->values()
            ->all();

        expect($billed)->toBe([2400, 3600]);
    });

    it('bills zero when the contract price is zero', function () {
        // The catalogue deliberately holds a NON-zero price: if it held none,
        // the old `?? 0.0` would also emit zero and this would be a false
        // green. Zero is a valid agreement, never "absent".
        $article = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        ($this->contractFor)($article, 0);

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        expect($item->unit_price->unscaledValue())->toBe(0)
            ->and($results['failed'])->toBe(0);
    });
});

describe('the contract price guard (D7)', function () {
    it('refuses to price a contract that carries no price', function () {
        // Transient model on purpose: the column is NOT NULL, so a correctly
        // persisted agreement can never reach the engine null, and neither the
        // schema is weakened nor a null row is fabricated to prove this. The
        // guard defends the public API, which can be handed a non-persisted
        // model or one from a divergent consumer schema.
        $service = new ArticleServiceStatus;

        expect(fn () => (new PricingService)->createPricingDetailsForContract($service))
            ->toThrow(MissingContractPriceException::class);
    });
});

describe('the engine never prices from the catalogue (D1)', function () {
    it('decides the amount without consulting the catalogue or overrides', function () {
        // Scope, as the spec requires: this asserts nothing about the engine
        // reading ArticlePrice at all — shouldGenerateInvoice() still does, for
        // billing_days_in_advance, and the selection still eager-loads
        // currentOverride. What must never happen is a catalogue or override
        // lookup DECIDING the amount.
        $article = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        ($this->contractFor)($article, 4500);

        $pricing = Mockery::mock(PricingService::class)->makePartial();
        $pricing->shouldNotReceive('createPricingDetailsForService');
        $pricing->shouldNotReceive('getActiveOverride');
        $pricing->shouldNotReceive('getEffectivePriceForService');

        $results = (new RecurringBillingService($pricing))->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        expect($item->unit_price->unscaledValue())->toBe(4500);
    });
});

/*
 * Characterisation, not regression: these two are green before AND after the
 * fix, and they do NOT prove D1. Test 8 only coincides today because the old
 * dynamic engine and updateEffectivePrice() happen to resolve the same
 * candidate; test 9 holds because emitted lines are already immutable. They
 * stay because they pin the contract, but they must not be counted in the
 * sensitivity check.
 */
describe('the revision belongs to the consumer (D3/D4)', function () {
    it('changes future invoices once the consumer calls the revision explicitly', function () {
        $article  = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        $contract = ($this->contractFor)($article, 2900);

        $article->prices()->firstOrFail()->update(['price' => cents(3300)]);
        $contract->updateEffectivePrice();

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        expect($contract->fresh()->effective_price->unscaledValue())->toBe(3300)
            ->and($item->unit_price->unscaledValue())->toBe(3300);
    });

    it('leaves already emitted invoices untouched when the contract is revised', function () {
        $article  = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        $contract = ($this->contractFor)($article, 2900);

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        $article->prices()->firstOrFail()->update(['price' => cents(9900)]);
        $contract->updateEffectivePrice();

        expect($item->fresh()->unit_price->unscaledValue())->toBe(2900);
    });

    it('refuses to revise a contract when neither an override nor a catalogue price exists', function () {
        // This one runs against a PERSISTED row on purpose: the guarantee that
        // matters here is that the previous price survives a failed revision.
        $article  = Article::factory()->withoutPrices()->create(['tax_group_id' => null]);
        $contract = ($this->contractFor)($article, 4500);

        expect(fn () => $contract->updateEffectivePrice())
            ->toThrow(MissingContractPriceException::class);

        expect($contract->fresh()->effective_price->unscaledValue())->toBe(4500);
    });
});

describe('what the emitted line actually persists (D6)', function () {
    it('freezes the exact pricing payload on the line, and survives the round trip', function () {
        // Existing coverage only checked top-level keys. This pins the values,
        // because the shape of pricing_details IS the contract a consumer reads
        // months later when auditing an odd price.
        $article  = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);
        $override = ArticleOverride::factory()->create([
            'article_id'   => $article->id,
            'customer_id'  => $this->customer->id,
            'custom_price' => cents(2400),
            'is_active'    => true,
        ]);

        ($this->contractFor)($article, 2400, $override->id);

        $results = ($this->billingService)()->processRecurringBilling(now());
        $item    = Invoice::findOrFail($results['invoices'][0])->items->first();

        $pricing = $item->metadata['pricing_details'];

        expect($pricing['base_price'])->toEqual(2400)
            ->and($pricing['applied_price'])->toEqual(2400)
            ->and($pricing['pricing_rule'])->toBe('contract_price')
            // Null on purpose: there is no stored historical base to compute a
            // discount against, and the catalogue is not consulted to invent one.
            ->and($pricing['discount_amount'])->toBeNull()
            ->and($pricing['discount_percentage'])->toBeNull()
            // Observation of the discount the contract pointed at, never
            // provenance of the amount.
            ->and($pricing['override_id'])->toBe($override->id);

        $roundTripped = InvoiceItemMetadata::fromArray($item->metadata)->pricingDetails;

        expect($roundTripped->appliedPrice)->toEqual(2400)
            ->and($roundTripped->pricingRule)->toBe('contract_price')
            ->and($roundTripped->overrideId)->toBe($override->id);
    });
});
