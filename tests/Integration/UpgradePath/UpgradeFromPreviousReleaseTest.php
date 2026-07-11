<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceNumberingService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * Upgrade-path contract test (AID-412 / AID-398 "stable versions = respect
 * for data") — the consumer experience of a release upgrade, end to end:
 *
 *   1. Migrate ONLY the migrations shipped in the upgrade base (previous
 *      release), taken from tests/Contract/release-migration-manifest.json.
 *   2. Seed a representative consumer database with RAW inserts (base-shaped
 *      rows — models target HEAD schema and would lie about the past).
 *   3. Migrate to HEAD (`artisan migrate` skips the already-run names).
 *   4. Assert the invariants: no data loss, new migrations transform legacy
 *      data correctly, HEAD models read base-written rows, numbering stays
 *      continuous, and a brand-new invoice can be issued.
 *
 * The initial manifest (base 4.0.2) makes the AID-390 backfill the migration
 * under test: the seeded duplicate NULL-scope series controls MUST collapse
 * to the highest counter and the sentinel scope — a real data migration
 * exercised on day one, not a trivially-green harness.
 *
 * MySQL-gated like tests/Integration/Mysql (skips without LARABILL_TEST_MYSQL_*).
 */

const LARABILL_UPGRADE_USER_ISSUER = '01936df0-0000-7000-8000-0000000000a1';
const LARABILL_UPGRADE_USER_BILLED = '01936df0-0000-7000-8000-0000000000b2';

it('upgrades a populated previous-release database to HEAD preserving consumer data', function () {
    // ── 1. Base schema: migrate ONLY the upgrade-base migration set ─────────
    /** @var array{release: string, upgrade_base: string, migrations: array<int, array{name: string, sha256: string, in_base: bool}>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Contract/release-migration-manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $baseDir       = sys_get_temp_dir().'/larabill-upgrade-base-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);

    $expectedNewLedgerNames = [];

    foreach ($manifest['migrations'] as $entry) {
        if ($entry['in_base']) {
            copy("{$migrationsDir}/{$entry['name']}", "{$baseDir}/{$entry['name']}");
        } else {
            $expectedNewLedgerNames[] = basename($entry['name'], '.php');
        }
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // ── 2. Seed base-shaped consumer data (raw inserts, sentinel values) ────
    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_UPGRADE_USER_ISSUER, 'name' => 'Issuer', 'email' => 'issuer@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['id' => LARABILL_UPGRADE_USER_BILLED, 'name' => 'Billed', 'email' => 'billed@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $configId = DB::table('company_fiscal_configs')->insertGetId([
        'business_name' => 'Acme SL', 'tax_id' => 'ESB12345678', 'legal_entity_type' => 'SL',
        'address'       => 'Calle Mayor 1', 'city' => 'Malaga', 'zip_code' => '29001', 'country_code' => 'ES',
        'valid_from'    => '2026-01-01', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $profileId = DB::table('user_tax_profiles')->insertGetId([
        'owner_user_id' => LARABILL_UPGRADE_USER_BILLED, 'fiscal_name' => 'Cliente Uno', 'tax_id' => 'ESX1234567L',
        'country_code'  => 'ES', 'valid_from' => '2026-01-01', 'is_active' => true,
        'created_at'    => $now, 'updated_at' => $now,
    ]);

    $articleId = DB::table('articles')->insertGetId([
        'code'       => 'HOST-PRO', 'name' => json_encode(['en' => 'Pro hosting']), 'item_type' => ItemType::SERVICE->value,
        'cost_price' => 500, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $secondArticleId = DB::table('articles')->insertGetId([
        'code'      => 'DOM-COM', 'name' => json_encode(['en' => '.com domain']), 'item_type' => ItemType::GOOD->value,
        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('article_prices')->insert([
        ['article_id' => $articleId, 'billing_frequency' => 0, 'price' => 2500, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['article_id' => $articleId, 'billing_frequency' => 3, 'price' => 900, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['article_id' => $secondArticleId, 'billing_frequency' => 0, 'price' => 1500, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $issuerSnapshotPlaintext = json_encode(['business_name' => 'Acme SL', 'tax_id' => 'ESB12345678']);
    $legacyInvoiceIds        = [];

    foreach ([1, 2, 3] as $number) {
        $legacyInvoiceIds[$number] = (string) Str::orderedUuid();

        DB::table('invoices')->insert([
            'id'                       => $legacyInvoiceIds[$number],
            'fiscal_number'            => sprintf('FAC-2026-%06d', $number),
            'prefix'                   => 'FAC', 'serie' => InvoiceSerieType::INVOICE->value, 'series_number' => $number, 'fiscal_year' => 2026,
            'invoice_date'             => sprintf('2026-03-%02d', $number), 'issued_at' => $now,
            'status'                   => $number === 3 ? InvoiceStatus::SENT->value : InvoiceStatus::PAID->value,
            'user_id'                  => LARABILL_UPGRADE_USER_ISSUER, 'billable_user_id' => LARABILL_UPGRADE_USER_BILLED,
            'company_fiscal_config_id' => $configId, 'user_tax_profile_id' => $profileId,
            'issuer_snapshot'          => Crypt::encryptString((string) $issuerSnapshotPlaintext),
            'is_immutable'             => $number !== 3, 'immutable_at' => $number !== 3 ? $now : null,
            'taxable_amount'           => 10000, 'total_tax_amount' => 2100, 'total_amount' => 12100,
            'created_at'               => $now, 'updated_at' => $now,
        ]);
    }

    foreach ([1 => 2, 2 => 1, 3 => 1] as $number => $itemCount) {
        for ($i = 0; $i < $itemCount; $i++) {
            DB::table('invoice_items')->insert([
                'invoice_id'    => $legacyInvoiceIds[$number], 'item_type' => ItemType::SERVICE->value,
                'description'   => "Line {$i} of invoice {$number}", 'quantity' => 100,
                'unit_price'    => 5000, 'taxable_amount' => 5000, 'total_tax_amount' => 1050, 'total_amount' => 6050,
                'taxes_applied' => json_encode([['name' => 'IVA', 'rate' => 2100, 'amount' => 1050]]),
                'created_at'    => $now, 'updated_at' => $now,
            ]);
        }
    }

    // The AID-390 scenario: duplicate NULL-scope controls for the SAME logical
    // series (the pre-4.1.0 unique index never fired for NULL scopes).
    foreach ([7, 3] as $lastNumber) {
        DB::table('invoice_series_control')->insert([
            'prefix'            => 'FAC', 'serie' => InvoiceSerieType::INVOICE->value, 'fiscal_year' => 2026,
            'fiscal_year_start' => '2026-01-01', 'fiscal_year_end' => '2026-12-31',
            'last_number'       => $lastNumber, 'user_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    DB::table('eu_sales_thresholds')->insert([
        'user_id'              => LARABILL_UPGRADE_USER_ISSUER, 'fiscal_year' => 2026, 'total_amount' => 500000,
        'breakdown_by_country' => json_encode(['DE' => 200000, 'FR' => 300000]),
        'created_at'           => $now, 'updated_at' => $now,
    ]);

    $ledgerBefore = DB::table('migrations')->pluck('migration')->all();

    // ── 3. Upgrade: migrate to HEAD (skips already-run names) ───────────────
    $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

    File::deleteDirectory($baseDir);

    // Invariant 1 — the ledger grew by EXACTLY the post-base migrations.
    $ledgerAfter = DB::table('migrations')->pluck('migration')->all();
    $newlyRan    = array_values(array_diff($ledgerAfter, $ledgerBefore));
    sort($newlyRan);
    sort($expectedNewLedgerNames);
    expect($newlyRan)->toBe($expectedNewLedgerNames);

    // Invariant 2 — zero data loss (only the EXPECTED series-control collapse).
    expect(DB::table('invoices')->count())->toBe(3)
        ->and(DB::table('invoice_items')->count())->toBe(4)
        ->and(DB::table('articles')->count())->toBe(2)
        ->and(DB::table('article_prices')->count())->toBe(3)
        ->and(DB::table('company_fiscal_configs')->count())->toBe(1)
        ->and(DB::table('user_tax_profiles')->count())->toBe(1)
        ->and(DB::table('eu_sales_thresholds')->count())->toBe(1);

    // Invariant 3 — AID-390 backfill did its real work: duplicates collapsed
    // to the HIGHEST counter (an issued number is never reused) and the NULL
    // scope became the sentinel.
    $controls = DB::table('invoice_series_control')->get();
    expect($controls)->toHaveCount(1)
        ->and((int) $controls[0]->last_number)->toBe(7)
        ->and($controls[0]->user_id)->toBe(InvoiceSeriesControl::GLOBAL_SCOPE)
        ->and(DB::table('invoice_series_control')->whereNull('user_id')->count())->toBe(0);

    // Invariant 5 — HEAD models read base-written rows with materialized casts.
    $invoice = Invoice::query()->findOrFail($legacyInvoiceIds[1]);
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE)
        ->and($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->total_amount->unscaledValue())->toBe(12100)
        ->and($invoice->is_immutable)->toBeTrue();

    $article = Article::query()->findOrFail($articleId);
    expect($article->item_type)->toBe(ItemType::SERVICE)
        ->and($article->cost_price?->unscaledValue())->toBe(500);

    expect(CompanyFiscalConfig::query()->findOrFail($configId)->isCurrentlyActive())->toBeTrue()
        ->and(UserTaxProfile::query()->findOrFail($profileId)->isCurrentlyActive())->toBeTrue();

    $threshold = EuSalesThreshold::query()->firstOrFail();
    expect($threshold->getBreakdownByCountryAttribute($threshold->getRawOriginal('breakdown_by_country')))
        ->toBe(['DE' => 200000, 'FR' => 300000]);

    // Invariant 6 — encrypted snapshots survive the upgrade byte-exact.
    expect(Crypt::decryptString((string) DB::table('invoices')->where('id', $legacyInvoiceIds[1])->value('issuer_snapshot')))
        ->toBe((string) $issuerSnapshotPlaintext);

    // Invariant 7 — relations and the @api amber operation over legacy data.
    expect($invoice->items()->count())->toBe(2)
        ->and($article->prices()->count())->toBe(2)
        ->and($article->getDefaultPrice())->toBe(2500.0);

    // Invariant 4 — numbering continuity: the collapsed control keeps counting.
    $control = InvoiceSeriesControl::query()->firstOrFail();
    expect($control->getNextNumber())->toBe(8);

    $number = app(InvoiceNumberingService::class)->generateNumber('FAC', InvoiceSerieType::INVOICE->value);
    expect($number->seriesNumber)->toBe(8)
        ->and($number->prefix)->toBe('FAC')
        ->and($number->fiscalYear)->toBe(2026);

    // Invariant 8 — the consumer can still ISSUE after upgrading: a real
    // Invoice::create() runs the full creating hook (FiscalIntegrityChecker +
    // automatic fiscal snapshots) against the legacy configs.
    $newInvoice = Invoice::query()->create([
        'fiscal_number'    => $number->formatted,
        'prefix'           => $number->prefix,
        'serie'            => InvoiceSerieType::INVOICE,
        'series_number'    => $number->seriesNumber,
        'fiscal_year'      => $number->fiscalYear,
        'invoice_date'     => now()->toDateString(),
        'user_id'          => LARABILL_UPGRADE_USER_ISSUER,
        'billable_user_id' => LARABILL_UPGRADE_USER_BILLED,
        'taxable_amount'   => cents(10000), 'total_tax_amount' => cents(2100), 'total_amount' => cents(12100),
    ]);

    expect($newInvoice->exists)->toBeTrue()
        ->and($newInvoice->company_fiscal_config_id)->toBe($configId)
        ->and($newInvoice->user_tax_profile_id)->toBe($profileId)
        ->and(DB::table('invoices')->count())->toBe(4);

    // Invariant 9 — the upgrade is idempotent (AID-398 re-run contract).
    $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
    expect(DB::table('migrations')->pluck('migration')->all())->toBe($ledgerAfter);
});
