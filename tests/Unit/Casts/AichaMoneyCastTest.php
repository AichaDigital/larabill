<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\MoneyDomainOverflowException;
use AichaDigital\Larabill\Models\AgreementGuaranteeOperation;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleOverride;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Commission;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Support\MoneyDomain;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * AID-1527 §2.1 — AichaMoneyCast: composition over the final FixedDecimalCast,
 * validating the NORMALIZED scale-2 storage cents on set (finding 3), with
 * object caching disabled (finding 4: a cached VO re-invokes set() while
 * merging cached attributes during toArray()/getAttributes(), so an innocent
 * read of a legacy over-domain row threw on serialization).
 *
 * The §2 matrix is a MODEL-CAST mapping, not a single stand-in: every
 * AEAT-class model carries AichaMoneyCast on every listed attribute
 * (implementation-gate finding 2 — a one-model stand-in accepted the
 * Commission-cast-restore mutant).
 */

/**
 * The AEAT-class cast map: model => its AichaMoneyCast attributes.
 *
 * @return array<class-string, list<string>>
 */
function aid1527AeatCastMap(): array
{
    return [
        Invoice::class                     => ['taxable_amount', 'total_tax_amount', 'total_amount'],
        InvoiceItem::class                 => ['unit_price', 'taxable_amount', 'total_tax_amount', 'total_amount'],
        ArticlePrice::class                => ['price'],
        ArticleOverride::class             => ['custom_price'],
        ArticleServiceStatus::class        => ['effective_price'],
        Article::class                     => ['cost_price'],
        Commission::class                  => ['rate', 'min_amount', 'max_amount'],
        AgreementGuaranteeOperation::class => ['amount'],
    ];
}

describe('AID-1527 §2.1 — AichaMoneyCast on AEAT-class columns', function () {

    it('accepts assignment at exactly +MAX', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofUnscaled(MoneyDomain::MAX_MONEY_CENTS, 2);

        expect($price->price)->toBeInstanceOf(FixedDecimal::class)
            ->and($price->price->unscaledValue())->toBe(MoneyDomain::MAX_MONEY_CENTS);
    });

    it('accepts assignment at exactly -MAX (symmetric: rectificatives)', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofUnscaled(-MoneyDomain::MAX_MONEY_CENTS, 2);

        expect($price->price->unscaledValue())->toBe(-MoneyDomain::MAX_MONEY_CENTS);
    });

    it('accepts zero (AID-956: cero es un precio válido)', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofUnscaled(0, 2);

        expect($price->price->unscaledValue())->toBe(0);
    });

    it('accepts a null assignment (null passes the guard)', function () {
        $price        = new ArticlePrice;
        $price->price = null;

        expect($price->price)->toBeNull();
    });

    it('rejects MAX+1 with the typed MoneyDomainOverflowException', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofUnscaled(MoneyDomain::MAX_MONEY_CENTS + 1, 2);
    })->throws(MoneyDomainOverflowException::class);

    it('rejects -MAX-1 with the typed exception (round-1 mutation: a naive > MAX check accepts -MAX-1)', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofUnscaled(-MoneyDomain::MAX_MONEY_CENTS - 1, 2);
    })->throws(MoneyDomainOverflowException::class);

    it('validates the NORMALIZED scale-2 cents, not the input VO scale (finding 3: scale-0 1e12 normalizes to 1e14)', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofDecimalString('1000000000000');
    })->throws(MoneyDomainOverflowException::class);

    it('rejects a scale-3 input that rounds UP onto the bound (x.x5 boundary)', function () {
        $price = new ArticlePrice;
        // 999,999,999,999.995 rounds half-up at scale 2 to 1e14 → beyond the bound.
        $price->price = FixedDecimal::ofDecimalString('999999999999.995');
    })->throws(MoneyDomainOverflowException::class);

    it('rejects a negative scale-3 input that rounds DOWN onto -1e14', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofDecimalString('-999999999999.995');
    })->throws(MoneyDomainOverflowException::class);

    it('accepts a scale-3 input that rounds to just inside the bound (x.x4 boundary)', function () {
        $price        = new ArticlePrice;
        $price->price = FixedDecimal::ofDecimalString('999999999999.994');

        expect($price->price->unscaledValue())->toBe(MoneyDomain::MAX_MONEY_CENTS);
    });

    it('persists MAX through an unguarded factory creation and reads back integral (casts still apply)', function () {
        $price = ArticlePrice::factory()->create(['price' => cents(MoneyDomain::MAX_MONEY_CENTS)]);

        expect($price->refresh()->price)->toBeInstanceOf(FixedDecimal::class)
            ->and($price->refresh()->price->unscaledValue())->toBe(MoneyDomain::MAX_MONEY_CENTS);
    });

    it('legacy-read: a forced-raw over-domain row reads via property access AND survives toArray()/getAttributes() (finding 4, withoutObjectCaching regression)', function () {
        $price = ArticlePrice::factory()->create(['price' => cents(10000)]);

        // Bulk-written legacy row: over the AEAT domain, bypassing every cast
        // (the engine CHECK is the guard for this path on MySQL/MariaDB; on
        // SQLite this write succeeds — the documented §2.3 narrower parity).
        DB::table('article_prices')->where('id', $price->id)->update([
            'price' => 200_000_000_000_000,
        ]);

        $fresh = ArticlePrice::query()->findOrFail($price->id);

        // First read via property access: get() is unguarded.
        expect($fresh->price)->toBeInstanceOf(FixedDecimal::class)
            ->and($fresh->price->unscaledValue())->toBe(200_000_000_000_000);

        // The historical regression: serialization re-invoked set() on the
        // cached VO and threw. Without object caching this stays a plain read.
        $array = $fresh->toArray();
        expect($array['price'])->toBeInstanceOf(FixedDecimal::class)
            ->and($array['price']->unscaledValue())->toBe(200_000_000_000_000)
            ->and($fresh->getAttributes()['price'])->toBe(200_000_000_000_000);
    });

});

describe('AID-1527 §4 — model-cast mapping: every AEAT-class model enforces the money domain', function () {

    it('rejects 100000000000000 with the typed MoneyDomainOverflowException on EVERY AEAT-class model attribute (cast-mapping matrix)', function () {
        $accepted = [];

        foreach (aid1527AeatCastMap() as $model => $columns) {
            foreach ($columns as $column) {
                try {
                    $instance            = new $model;
                    $instance->{$column} = FixedDecimal::ofUnscaled(100_000_000_000_000, 2);
                    $accepted[]          = "{$model}::{$column} accepted 1e14";
                } catch (MoneyDomainOverflowException) {
                    continue; // enforced — the cast mapping reaches this attribute
                }
            }
        }

        expect($accepted)->toBe(
            [],
            'AEAT-class attributes whose cast mapping does NOT enforce the domain: '.implode(', ', $accepted)
        );
    });

    it('persists ±MAX through the casts on every AEAT-class model and reads back integral', function () {
        $max    = MoneyDomain::MAX_MONEY_CENTS;
        $negMax = -MoneyDomain::MAX_MONEY_CENTS;

        // Invoice — three AEAT columns, both signs. unissuedDraft(): the
        // factory default is ISSUED, which fiscally protects the lines
        // (ADR-016 §6.5) and would block the InvoiceItem save below.
        $invoice = Invoice::factory()->unissuedDraft()->create([
            'user_id'           => TestCase::USER_UUID_1,
            'taxable_amount'    => cents($max),
            'total_tax_amount'  => cents($negMax),
            'total_amount'      => cents($max),
        ]);
        expect($invoice->refresh()->taxable_amount->unscaledValue())->toBe($max)
            ->and($invoice->refresh()->total_tax_amount->unscaledValue())->toBe($negMax)
            ->and($invoice->refresh()->total_amount->unscaledValue())->toBe($max);

        // InvoiceItem — four AEAT columns (quantity keeps the vendor cast).
        $item = InvoiceItem::factory()->create([
            'invoice_id'        => $invoice->id,
            'unit_price'        => cents($max),
            'taxable_amount'    => cents($negMax),
            'total_tax_amount'  => cents($max),
            'total_amount'      => cents($negMax),
        ]);
        expect($item->refresh()->unit_price->unscaledValue())->toBe($max)
            ->and($item->refresh()->taxable_amount->unscaledValue())->toBe($negMax)
            ->and($item->refresh()->total_tax_amount->unscaledValue())->toBe($max)
            ->and($item->refresh()->total_amount->unscaledValue())->toBe($negMax);

        // ArticlePrice — the historical stand-in, covered for completeness.
        $price = ArticlePrice::factory()->create(['price' => cents($negMax)]);
        expect($price->refresh()->price->unscaledValue())->toBe($negMax);

        // ArticleOverride.
        $override = ArticleOverride::factory()->create(['custom_price' => cents($max)]);
        expect($override->refresh()->custom_price->unscaledValue())->toBe($max);

        // ArticleServiceStatus — the contractual price (ADR-013).
        $agreement = ArticleServiceStatus::factory()->create(['effective_price' => cents($negMax)]);
        expect($agreement->refresh()->effective_price->unscaledValue())->toBe($negMax);

        // Article — nullable cost price.
        $article = Article::factory()->withoutPrices()->create(['cost_price' => cents($max)]);
        expect($article->refresh()->cost_price->unscaledValue())->toBe($max);

        // Commission — rate (dual-purpose G1) AND the FIXED-row bounds.
        $commission = Commission::factory()->create([
            'rate'       => cents($max),
            'min_amount' => cents($negMax),
            'max_amount' => cents($max),
        ]);
        expect($commission->refresh()->rate->unscaledValue())->toBe($max)
            ->and($commission->refresh()->min_amount->unscaledValue())->toBe($negMax)
            ->and($commission->refresh()->max_amount->unscaledValue())->toBe($max);

        // AgreementGuaranteeOperation — no factory: constructed and saved
        // directly so the amount cast is exercised on the real save path.
        $operation                      = new AgreementGuaranteeOperation;
        $operation->agreement_id        = $agreement->id;
        $operation->original_invoice_id = $invoice->id;
        $operation->amount              = cents($max);
        $operation->save();

        expect($operation->refresh()->amount->unscaledValue())->toBe($max);
    });

});
