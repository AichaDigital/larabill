<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Exceptions\InvalidBillingIntervalException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Capture E_USER_DEPRECATED notices raised by $callback through a custom
 * error handler (AID-1778 T5e: the 9.0-deprecated frequency bridge).
 *
 * @param  callable(): mixed  $callback
 * @return array<int, string>
 */
function articleLegacyDeprecations(callable $callback): array
{
    $notices = [];

    set_error_handler(function (int $errno, string $errstr) use (&$notices): bool {
        if ($errno === E_USER_DEPRECATED) {
            $notices[] = $errstr;

            return true;
        }

        return false;
    });

    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $notices;
}

describe('Article creation', function () {
    it('can create an article', function () {
        $article = Article::factory()->create([
            'code' => 'TEST-001',
            'name' => 'Test Article',
        ]);

        expect($article->code)->toBe('TEST-001')
            ->and($article->name)->toBe('Test Article')
            ->and($article->exists)->toBeTrue();
    });

    it('casts item_type to enum', function () {
        $article = Article::factory()->service()->create();

        expect($article->item_type)->toBeInstanceOf(ItemType::class)
            ->and($article->item_type)->toBe(ItemType::SERVICE);
    });

    it('casts cost_price to Base100Int (integer)', function () {
        $article = Article::factory()->create([
            'cost_price' => cents(1500), // €15.00 in base100
        ]);

        expect($article->cost_price->unscaledValue())->toBe(1500);
    });

    it('casts booleans correctly', function () {
        $article = Article::factory()->create();

        expect($article->is_active)->toBeTrue();
    });

    it('casts metadata to array', function () {
        $article = Article::factory()->create([
            'metadata' => ['key' => 'value'],
        ]);

        expect($article->metadata)->toBeArray()
            ->and($article->metadata)->toHaveKey('key')
            ->and($article->metadata['key'])->toBe('value');
    });

    it('soft deletes articles', function () {
        $article = Article::factory()->create();

        $article->delete();

        expect($article->trashed())->toBeTrue()
            ->and(Article::count())->toBe(0)
            ->and(Article::withTrashed()->count())->toBe(1);
    });

    it('can restore soft deleted articles', function () {
        $article = Article::factory()->create();
        $article->delete();

        $article->restore();

        expect($article->trashed())->toBeFalse()
            ->and(Article::count())->toBe(1);
    });
});

describe('Article relationships', function () {
    it('has tax group relationship', function () {
        $taxGroup = TaxGroup::factory()->create();
        $article  = Article::factory()->create(['tax_group_id' => $taxGroup->id]);

        expect($article->taxGroup)->toBeInstanceOf(TaxGroup::class)
            ->and($article->taxGroup->id)->toBe($taxGroup->id);
    });

    it('has prices relationship', function () {
        $article = Article::factory()->create();

        expect($article->prices)->toHaveCount(1)
            ->and($article->prices->first())->toBeInstanceOf(ArticlePrice::class);
    });

    it('has overrides relationship', function () {
        $article = Article::factory()->create();
        ArticleOverride::factory()->count(3)->create(['article_id' => $article->id]);

        expect($article->overrides)->toHaveCount(3)
            ->and($article->overrides->first())->toBeInstanceOf(ArticleOverride::class);
    });

    it('has service statuses relationship', function () {
        $article = Article::factory()->service()->create();
        ArticleServiceStatus::factory()->count(2)->create(['article_id' => $article->id]);

        expect($article->serviceStatuses)->toHaveCount(2)
            ->and($article->serviceStatuses->first())->toBeInstanceOf(ArticleServiceStatus::class);
    });

    it('has invoice items relationship', function () {
        $article = Article::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => TestCase::USER_UUID_1]);

        quietInvoiceItem([
            'invoice_id'  => $invoice->id,
            'article_id'  => $article->id,
            'description' => 'Article invoice line',
        ]);

        expect($article->invoiceItems()->exists())->toBeTrue();
    });
});

describe('Article scopes', function () {
    it('scopes active articles', function () {
        Article::factory()->create(['is_active' => true]);
        Article::factory()->create(['is_active' => true]);
        Article::factory()->inactive()->create();

        $active = Article::active()->get();

        expect($active)->toHaveCount(2);
    });

    it('scopes goods', function () {
        Article::factory()->good()->count(2)->create();
        Article::factory()->service()->count(3)->create();

        $goods = Article::goods()->get();

        expect($goods)->toHaveCount(2)
            ->and($goods->first()->item_type)->toBe(ItemType::GOOD);
    });

    it('scopes services', function () {
        Article::factory()->service()->count(3)->create();
        Article::factory()->good()->count(2)->create();

        $services = Article::services()->get();

        expect($services)->toHaveCount(3)
            ->and($services->first()->item_type)->toBe(ItemType::SERVICE);
    });

    it('scopes by category', function () {
        Article::factory()->category('hosting')->count(2)->create();
        Article::factory()->category('domains')->count(3)->create();

        $hosting = Article::byCategory('hosting')->get();

        expect($hosting)->toHaveCount(2)
            ->and($hosting->first()->category)->toBe('hosting');
    });
});

describe('Article type helpers', function () {
    it('identifies services correctly', function () {
        $service = Article::factory()->service()->create();
        $good    = Article::factory()->good()->create();

        expect($service->isService())->toBeTrue()
            ->and($good->isService())->toBeFalse();
    });

    it('identifies goods correctly', function () {
        $good    = Article::factory()->good()->create();
        $service = Article::factory()->service()->create();

        expect($good->isGood())->toBeTrue()
            ->and($service->isGood())->toBeFalse();
    });

    it('seeds recurring-capable articles through cadence-bearing prices', function () {
        $recurring = Article::factory()
            ->withPrice(BillingFrequency::MONTHLY, 2900)
            ->create();

        $oneTime = Article::factory()
            ->oneTime(5000)
            ->create();

        // Verify test setup: each article has exactly 1 price
        expect($recurring->prices)->toHaveCount(1, 'Setup: recurring should have 1 price')
            ->and($recurring->prices->first()->billing_unit)->toBe('month', 'Setup: should be 1 month')
            ->and($oneTime->prices)->toHaveCount(1, 'Setup: oneTime should have 1 price')
            ->and($oneTime->prices->first()->billing_unit)->toBeNull('Setup: should be one-time (NULL cadence)');

        expect($recurring->activePrices()->whereNotNull('billing_unit')->exists())->toBeTrue()
            ->and($oneTime->activePrices()->whereNotNull('billing_unit')->exists())->toBeFalse();
    });
});

describe('Article instance identifier', function () {
    it('requires instance identifier for services by default', function () {
        $service = Article::factory()->service()->create();
        $good    = Article::factory()->good()->create();

        expect($service->requiresInstanceIdentifier())->toBeTrue()
            ->and($good->requiresInstanceIdentifier())->toBeFalse();
    });

    it('can override instance identifier requirement via metadata', function () {
        $service = Article::factory()->service()->create([
            'metadata' => [
                'service' => [
                    'requires_instance' => false,
                ],
            ],
        ]);

        expect($service->requiresInstanceIdentifier())->toBeFalse();
    });

    it('validates instance identifier based on metadata rules', function () {
        $article = Article::factory()->create([
            'item_type' => ItemType::SERVICE,
            'metadata'  => [
                'service' => [
                    'requires_instance'   => true,
                    'instance_validation' => ['type' => 'domain'],
                ],
            ],
        ]);

        expect($article->validateInstanceIdentifier('example.com'))->toBeTrue()
            ->and($article->validateInstanceIdentifier('not-a-domain'))->toBeFalse()
            ->and($article->validateInstanceIdentifier(null))->toBeFalse();
    });
});

describe('Article pricing', function () {
    it('gets price for specific frequency', function () {
        $article = Article::factory()
            ->withPrices([
                BillingFrequency::MONTHLY->value   => 2900,
                BillingFrequency::QUARTERLY->value => 7900,
                BillingFrequency::YEARLY->value    => 29000,
            ])
            ->create();

        // Verify test setup: exactly 3 prices created
        expect($article->prices)->toHaveCount(3, 'Setup verification: should have exactly 3 prices');

        expect($article->getPriceForInterval(new BillingInterval('month', 1)))->toEqual(2900.0)
            ->and($article->getPriceForInterval(new BillingInterval('month', 3)))->toEqual(7900.0)
            ->and($article->getPriceForInterval(new BillingInterval('year', 1)))->toEqual(29000.0);
    });

    it('returns null for unavailable frequency', function () {
        $article = Article::factory()
            ->monthly(2900)
            ->create();

        // Verify test setup: only 1 price (1 month)
        expect($article->prices)->toHaveCount(1, 'Setup verification: should have exactly 1 price');

        // 'week' is outside the ADR-015 cadence domain: the lookup fails loud
        // (domain guard, InvalidBillingIntervalException) instead of returning null.
        expect(fn () => $article->getPriceForInterval(new BillingInterval('week', 1)))
            ->toThrow(InvalidBillingIntervalException::class);
    });

    it('gets available frequencies', function () {
        $article = Article::factory()
            ->withPrices([
                BillingFrequency::MONTHLY->value   => 2900,
                BillingFrequency::QUARTERLY->value => 7900,
            ])
            ->create();

        // Verify test setup: exactly 2 prices
        expect($article->prices)->toHaveCount(2, 'Setup verification: should have exactly 2 prices');

        $notices = articleLegacyDeprecations(fn () => $article->getAvailableFrequencies());

        expect($notices)->toHaveCount(1)
            ->and($notices[0])->toContain('Article::getAvailableFrequencies() is deprecated since larabill 9.0.0')
            ->and($notices[0])->toContain('Article::getAvailableIntervals()');

        $frequencies = $article->getAvailableFrequencies();

        expect($frequencies)->toHaveCount(2)
            ->and($frequencies)->toContain(BillingFrequency::MONTHLY)
            ->and($frequencies)->toContain(BillingFrequency::QUARTERLY);
    });

    it('gets available intervals — the interval-native read (NULL element is the one-time cadence)', function () {
        $article = Article::factory()
            ->withPrices([
                BillingFrequency::MONTHLY->value   => 2900,
                BillingFrequency::QUARTERLY->value => 7900,
            ])
            ->create();

        $oneTime = Article::factory()->oneTime(5000)->create();

        $intervals = $article->getAvailableIntervals();

        $pairs = fn (Collection $intervals) => $intervals->map(
            fn (?BillingInterval $interval) => $interval === null ? null : [$interval->unit, $interval->quantity],
        );

        expect($pairs($article->getAvailableIntervals()))->toHaveCount(2)
            ->toContain(['month', 1])
            ->toContain(['month', 3])
            ->and($pairs($oneTime->getAvailableIntervals()))->each->toBeNull();
    });

    it('gets default price (ONE_TIME or first available)', function () {
        $articleWithOneTime = Article::factory()
            ->oneTime(5000)
            ->create();

        $articleMonthly = Article::factory()
            ->monthly(2900)
            ->create();

        // Verify test setup
        expect($articleWithOneTime->prices)->toHaveCount(1, 'Setup: oneTime article should have 1 price')
            ->and($articleMonthly->prices)->toHaveCount(1, 'Setup: monthly article should have 1 price');

        expect($articleWithOneTime->getDefaultPrice())->toEqual(5000.0)
            ->and($articleMonthly->getDefaultPrice())->toEqual(2900.0);
    });

    it('gets billing days in advance for frequency', function () {
        $article = Article::factory()->withoutPrices()->create();
        ArticlePrice::factory()
            ->for($article)
            ->create([
                'price'                   => cents(2900),
                'billing_days_in_advance' => 7,
            ]);

        // Verify test setup: exactly 1 price with correct days_in_advance
        $article->refresh();
        expect($article->prices)->toHaveCount(1, 'Setup verification: should have exactly 1 price')
            ->and($article->prices->first()->billing_days_in_advance)->toBe(7, 'Setup verification: days_in_advance should be 7');

        expect($article->getBillingDaysInAdvanceForInterval(new BillingInterval('month', 1)))->toBe(7);
    });
});

describe('Article price overrides', function () {
    it('returns standard price when no customer provided', function () {
        $article = Article::factory()
            ->monthly(2900)
            ->create();

        expect(app(PricingService::class)->getEffectivePriceForInterval($article, new BillingInterval('month', 1), null))->toEqual(2900.0);
    });

    it('returns standard price when customer has no override', function () {
        $article = Article::factory()
            ->monthly(2900)
            ->create();

        expect(app(PricingService::class)->getEffectivePriceForInterval($article, new BillingInterval('month', 1), 999))->toEqual(2900.0);
    });

    it('returns override price when customer has active override', function () {
        $userModel = config('larabill.user_model', 'App\\Models\\User');
        $customer  = $userModel::factory()->create();

        $article = Article::factory()
            ->monthly(2900)
            ->create();

        ArticleOverride::factory()->create([
            'article_id'   => $article->id,
            'customer_id'  => $customer->id,
            'custom_price' => cents(2400),
            'is_active'    => true,
        ]);

        expect(app(PricingService::class)->getEffectivePriceForInterval($article, new BillingInterval('month', 1), $customer->id))->toEqual(2400.0);
    });

    it('returns override price for UUID customer IDs', function () {
        $customerId = (string) Str::uuid();

        $article = Article::factory()
            ->monthly(2900)
            ->create();

        ArticleOverride::factory()->create([
            'article_id'   => $article->id,
            'customer_id'  => $customerId,
            'custom_price' => cents(2400),
            'is_active'    => true,
        ]);

        expect(app(PricingService::class)->getEffectivePriceForInterval($article, new BillingInterval('month', 1), $customerId))->toEqual(2400.0)
            ->and($article->hasActiveOverrideFor($customerId))->toBeTrue();
    });

    it('gets active override for customer', function () {
        $userModel = config('larabill.user_model', 'App\\Models\\User');
        $customer  = $userModel::factory()->create();

        $article = Article::factory()->create();

        $override = ArticleOverride::factory()->create([
            'article_id'  => $article->id,
            'customer_id' => $customer->id,
        ]);

        $result = $article->getActiveOverrideFor($customer->id);

        expect($result)->toBeInstanceOf(ArticleOverride::class)
            ->and($result->id)->toBe($override->id);
    });

    it('returns null when customer has no active override', function () {
        $article = Article::factory()->create();

        expect($article->getActiveOverrideFor(999))->toBeNull();
    });

    it('checks if customer has active override', function () {
        $userModel = config('larabill.user_model', 'App\\Models\\User');
        $customer  = $userModel::factory()->create();

        $article = Article::factory()->create();

        ArticleOverride::factory()->create([
            'article_id'  => $article->id,
            'customer_id' => $customer->id,
        ]);

        expect($article->hasActiveOverrideFor($customer->id))->toBeTrue()
            ->and($article->hasActiveOverrideFor(999))->toBeFalse();
    });
});

describe('Article factory presets', function () {
    it('creates hosting service with typical prices', function () {
        $article = Article::factory()->hosting()->create();

        expect($article->isService())->toBeTrue()
            ->and($article->category)->toBe('hosting')
            ->and($article->prices)->toHaveCount(3); // monthly, quarterly, yearly
    });

    it('creates domain service with yearly price', function () {
        $article = Article::factory()->domain('es')->create();

        expect($article->isService())->toBeTrue()
            ->and($article->category)->toBe('domains')
            ->and($article->code)->toBe('DOMAIN-ES');
    });

    it('creates product with one-time price', function () {
        $article = Article::factory()->product()->create();

        expect($article->isGood())->toBeTrue()
            ->and($article->category)->toBe('hardware')
            ->and($article->getPriceForInterval(null))->not->toBeNull();
    });
});
