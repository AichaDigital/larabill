<?php

declare(strict_types=1);

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/Mysql/ — no per-file uses() needed.

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticlePrice;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Support\MoneyDomain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AID-1527 §2.3/§4 — the engine-enforced side of the AEAT money domain.
 *
 * Assignment casts are NOT database invariants (gate finding 2: zero cast
 * invocations through where()->update(), upsert(), setRawAttributes(), raw
 * writes and pivot inserts). The SAME migration that widens the columns adds
 * named CHECK constraints per AEAT-class column. These probes exercise the
 * BYPASS paths directly: in-range accepted, out-of-range rejected by the
 * CHECK (Laravel's QueryException — NOT the domain exception, §2.1).
 *
 * Skip semantics: the widening migration and its CHECKs land with a sibling
 * work stream. Every test skips (information_schema guard) while the schema
 * is pre-migration, so this file stays green NOW and starts asserting the
 * moment the migration lands — on both engines of the frenada (MySQL 8.4,
 * MariaDB 11.4).
 */

/**
 * Skip the test unless the AID-1527 money schema is live on this database:
 * the widened bigint column AND at least one CHECK constraint on the probe
 * table (information_schema guard — no constraint name is assumed).
 */
function aid1527RequireMoneySchema(): void
{
    $database = env('LARABILL_TEST_MYSQL_DATABASE');

    $column = DB::selectOne(
        'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, 'article_prices', 'price']
    );

    if ($column === null || strtolower((string) $column->t) !== 'bigint') {
        test()->markTestSkipped(
            'AID-1527 widening migration not applied yet — article_prices.price is not bigint.'
        );
    }

    $checks = DB::selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
        [$database, 'article_prices', 'CHECK']
    );

    if ((int) ($checks->n ?? 0) === 0) {
        test()->markTestSkipped(
            'AID-1527 CHECK constraints not present yet — enforcement migration pending.'
        );
    }
}

/**
 * Build ONE row per AEAT-class table and map every AEAT-class column (the 15
 * widened columns + the CHECK-only pivot `applied_amount`) to that row id, so
 * the §4 boundary matrix probes the ENGINE clause per column through raw
 * `DB::table` writes — zero casts involved.
 *
 * Raw inserts carry each table's NOT NULL-without-default shape verbatim
 * (commissions/article_service_status/article_overrides keep the raw shapes
 * used across this harness — their factories would need MysqlUser::factory(),
 * which the consumer-shaped user model does not provide).
 *
 * @param  string  $userId  a seeded users.id (UUID v7, harness-shaped)
 * @return array<string, array{table: string, column: string, id: int|string}>
 */
function aid1527AeatBoundaryFixture(string $userId): array
{
    $article = Article::factory()->withoutPrices()->create();

    // unissuedDraft(): a coherent ADR-016 DRAFT row — lines stay writable and
    // the §8.2 coherence clauses accept the NULL number/issuance fields.
    $invoice = Invoice::factory()->unissuedDraft()->create([
        'user_id'          => $userId,
        'billable_user_id' => $userId,
    ]);
    $item    = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);
    $payment = GroupedPayment::factory()->create();

    $priceId = DB::table('article_prices')->insertGetId([
        'article_id' => $article->id, 'price' => 10000, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $overrideId = DB::table('article_overrides')->insertGetId([
        'customer_id' => $userId, 'article_id' => $article->id, 'custom_price' => 5000,
        'created_at'  => now(), 'updated_at' => now(),
    ]);

    $agreementId = DB::table('article_service_status')->insertGetId([
        'customer_id'         => $userId, 'article_id' => $article->id,
        'instance_identifier' => 'aid1527-boundary', 'started_at' => '2026-01-01',
        'status'              => 'active', 'effective_price' => 2900,
        'created_at'          => now(), 'updated_at' => now(),
    ]);

    $commissionId = DB::table('commissions')->insertGetId([
        'level' => 0, 'rate' => 2050, 'valid_from' => '2026-01-01',
        'name'  => 'aid1527-boundary', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // UUID PK: insertGetId() reads lastInsertId() and would return 0 — the
    // id is built explicitly and inserted verbatim.
    $guaranteeId = (string) Str::uuid();
    DB::table('article_guarantee_operations')->insert([
        'id'           => $guaranteeId,
        'agreement_id' => $agreementId, 'original_invoice_id' => $invoice->id,
        'amount'       => 5000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $pivotId = DB::table('grouped_payment_invoice')->insertGetId([
        'grouped_payment_id' => $payment->id, 'invoice_id' => $invoice->id,
        'applied_amount'     => 1000, 'previous_status' => 1, 'active_invoice_id' => null,
        'created_at'         => now(), 'updated_at' => now(),
    ]);

    return [
        'invoices.taxable_amount'                => ['table' => 'invoices', 'column' => 'taxable_amount', 'id' => $invoice->id],
        'invoices.total_tax_amount'              => ['table' => 'invoices', 'column' => 'total_tax_amount', 'id' => $invoice->id],
        'invoices.total_amount'                  => ['table' => 'invoices', 'column' => 'total_amount', 'id' => $invoice->id],
        'invoice_items.unit_price'               => ['table' => 'invoice_items', 'column' => 'unit_price', 'id' => $item->id],
        'invoice_items.taxable_amount'           => ['table' => 'invoice_items', 'column' => 'taxable_amount', 'id' => $item->id],
        'invoice_items.total_tax_amount'         => ['table' => 'invoice_items', 'column' => 'total_tax_amount', 'id' => $item->id],
        'invoice_items.total_amount'             => ['table' => 'invoice_items', 'column' => 'total_amount', 'id' => $item->id],
        'article_prices.price'                   => ['table' => 'article_prices', 'column' => 'price', 'id' => $priceId],
        'article_overrides.custom_price'         => ['table' => 'article_overrides', 'column' => 'custom_price', 'id' => $overrideId],
        'article_service_status.effective_price' => ['table' => 'article_service_status', 'column' => 'effective_price', 'id' => $agreementId],
        'articles.cost_price'                    => ['table' => 'articles', 'column' => 'cost_price', 'id' => $article->id],
        'commissions.min_amount'                 => ['table' => 'commissions', 'column' => 'min_amount', 'id' => $commissionId],
        'commissions.max_amount'                 => ['table' => 'commissions', 'column' => 'max_amount', 'id' => $commissionId],
        'commissions.rate'                       => ['table' => 'commissions', 'column' => 'rate', 'id' => $commissionId],
        'article_guarantee_operations.amount'    => ['table' => 'article_guarantee_operations', 'column' => 'amount', 'id' => $guaranteeId],
        'grouped_payment_invoice.applied_amount' => ['table' => 'grouped_payment_invoice', 'column' => 'applied_amount', 'id' => $pivotId],
    ];
}

describe('AID-1527 §4 — per-column signed boundary matrix (raw writes, engine clause)', function () {

    beforeEach(function () {
        $this->bootstrap();
        aid1527RequireMoneySchema();

        $this->boundary = aid1527AeatBoundaryFixture($this->seedUser());
    });

    it('persists exactly ±99_999_999_999_999 on EVERY AEAT-class column via raw writes and reads it back integral (upper-only-CHECK mutant)', function () {
        foreach ([MoneyDomain::MAX_MONEY_CENTS, -MoneyDomain::MAX_MONEY_CENTS] as $value) {
            foreach ($this->boundary as $pointer => $probe) {
                DB::table($probe['table'])->where('id', $probe['id'])->update([$probe['column'] => $value]);

                $raw = DB::table($probe['table'])->where('id', $probe['id'])->value($probe['column']);

                expect((int) $raw)->toBe($value, "{$pointer} did not persist {$value} through a raw write");
            }
        }
    });

    it('rejects ±(MAX+1) on EVERY AEAT-class column with the engine QueryException (upper-only-CHECK mutant)', function () {
        $over     = MoneyDomain::MAX_MONEY_CENTS + 1;
        $accepted = [];

        foreach ([1, -1] as $sign) {
            foreach ($this->boundary as $pointer => $probe) {
                try {
                    DB::table($probe['table'])->where('id', $probe['id'])->update([$probe['column'] => $sign * $over]);
                    $accepted[] = sprintf('%s accepted %s%d', $pointer, $sign > 0 ? '+' : '', -$over);
                } catch (QueryException) {
                    continue; // the engine clause fired — the rejection is proven
                }
            }
        }

        expect($accepted)->toBe(
            [],
            'AEAT-class columns that accepted an over-domain raw write: '.implode(', ', $accepted)
        );
    });

    it('accepts a REAL relation attach() on the payment pivot at ±MAX applied_amount (pivot-tautology mutant)', function () {
        $userId   = $this->seedUser();
        $payment  = GroupedPayment::factory()->create();
        $posted   = Invoice::factory()->unissuedDraft()->create(['user_id' => $userId, 'billable_user_id' => $userId]);
        $reversed = Invoice::factory()->unissuedDraft()->create(['user_id' => $userId, 'billable_user_id' => $userId]);

        // attach() writes the pivot WITHOUT casts: the engine CHECK is the
        // only guard (round-2 finding 3: raw attach stored 1e14 unguarded).
        $payment->invoices()->attach($posted->id, [
            'applied_amount' => MoneyDomain::MAX_MONEY_CENTS, 'previous_status' => 1,
        ]);
        $payment->invoices()->attach($reversed->id, [
            'applied_amount' => -MoneyDomain::MAX_MONEY_CENTS, 'previous_status' => 1,
        ]);

        expect((int) DB::table('grouped_payment_invoice')
            ->where('grouped_payment_id', $payment->id)->where('invoice_id', $posted->id)
            ->value('applied_amount'))->toBe(MoneyDomain::MAX_MONEY_CENTS)
            ->and((int) DB::table('grouped_payment_invoice')
                ->where('grouped_payment_id', $payment->id)->where('invoice_id', $reversed->id)
                ->value('applied_amount'))->toBe(-MoneyDomain::MAX_MONEY_CENTS);
    });

    it('rejects a REAL relation attach() at 100000000000000 with the engine QueryException (pivot-tautology mutant)', function () {
        $userId  = $this->seedUser();
        $payment = GroupedPayment::factory()->create();
        $invoice = Invoice::factory()->unissuedDraft()->create(['user_id' => $userId, 'billable_user_id' => $userId]);

        $payment->invoices()->attach($invoice->id, [
            'applied_amount' => 100_000_000_000_000, 'previous_status' => 1,
        ]);
    })->throws(QueryException::class);

});

describe('AID-1527 §2.3/§4 — engine-enforced AEAT money domain (bypass paths)', function () {

    beforeEach(function () {
        $this->bootstrap();
        aid1527RequireMoneySchema();

        $article = Article::factory()->withoutPrices()->create();

        $this->priceId = DB::table('article_prices')->insertGetId([
            'article_id' => $article->id,
            'price'      => 10000,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    it('accepts an in-range value through a where()->update() bypass (zero cast invocations)', function () {
        ArticlePrice::whereKey($this->priceId)->update(['price' => MoneyDomain::MAX_MONEY_CENTS]);

        $raw = (int) DB::table('article_prices')->where('id', $this->priceId)->value('price');

        expect($raw)->toBe(MoneyDomain::MAX_MONEY_CENTS);
    });

    it('rejects one cent beyond the AEAT bound through a where()->update() bypass with the engine QueryException (§2.1: not the domain exception)', function () {
        ArticlePrice::whereKey($this->priceId)->update(['price' => MoneyDomain::MAX_MONEY_CENTS + 1]);
    })->throws(QueryException::class);

    it('accepts an in-range value through upsert()', function () {
        $row          = (array) DB::table('article_prices')->where('id', $this->priceId)->first();
        $row['price'] = MoneyDomain::MAX_MONEY_CENTS;

        DB::table('article_prices')->upsert([$row], ['id'], ['price']);

        $raw = (int) DB::table('article_prices')->where('id', $this->priceId)->value('price');

        expect($raw)->toBe(MoneyDomain::MAX_MONEY_CENTS);
    });

    it('rejects an out-of-range value through upsert() with the engine QueryException', function () {
        $row          = (array) DB::table('article_prices')->where('id', $this->priceId)->first();
        $row['price'] = MoneyDomain::MAX_MONEY_CENTS + 1;

        DB::table('article_prices')->upsert([$row], ['id'], ['price']);
    })->throws(QueryException::class);

    it('persists above-int-max money integral: 3 × 2_147_483_648 (the widening, not just the domain)', function () {
        ArticlePrice::whereKey($this->priceId)->update(['price' => 3 * 2_147_483_648]);

        $raw = (int) DB::table('article_prices')->where('id', $this->priceId)->value('price');

        expect($raw)->toBe(6_442_450_944);
    });

    it('persists exactly ±MAX and zero through the bypass (symmetric, zero valid)', function () {
        foreach ([MoneyDomain::MAX_MONEY_CENTS, -MoneyDomain::MAX_MONEY_CENTS, 0] as $value) {
            ArticlePrice::whereKey($this->priceId)->update(['price' => $value]);

            $raw = (int) DB::table('article_prices')->where('id', $this->priceId)->value('price');

            expect($raw)->toBe($value);
        }
    });

    it('reads a bypass-written ±MAX value back through the model cast, integral', function () {
        ArticlePrice::whereKey($this->priceId)->update(['price' => -MoneyDomain::MAX_MONEY_CENTS]);

        $price = ArticlePrice::query()->findOrFail($this->priceId);

        expect($price->price)->toBeInstanceOf(FixedDecimal::class)
            ->and($price->price->unscaledValue())->toBe(-MoneyDomain::MAX_MONEY_CENTS);
    });

});
