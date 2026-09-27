<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\RecurringEmissionHookContract;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Events\RecurringBillingFailed;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
 * AID-1442 follow-up: the recurring billing flow must not leak query data
 * through its failure reporting. A QueryException's message carries the SQL
 * with binding values interpolated; processRecurringBilling() puts the raw
 * message into the consumer-visible `errors` array, into its Log::error
 * context (together with a full stack trace), and dispatchBestEffort() logs
 * throwing listeners' messages the same way. The package's own
 * AlertBillingFailure listener — registered by default for every
 * installation — logs the same raw message plus a full stack trace from the
 * RecurringBillingFailed event. Database failures report the caught class,
 * the SQLSTATE and the driver code only — the same contract PDFService
 * already applies at its frontier (AID-1442). The RecurringBillingFailed
 * event keeps carrying the Throwable itself: its listeners are consumer
 * code, and the raw object is the triage payload — but the package's own
 * listener must not log it raw.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    // The recurring flow emits through the canonical path, which requires an
    // active CompanyFiscalConfig and a fiscally identified receiver — exactly
    // like any real consumer installation (mirrors RecurringBillingServiceTest).
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->service   = new RecurringBillingService(new PricingService);
    $this->userModel = config('larabill.user_model', 'App\\Models\\User');

    $this->makeCustomer = function () {
        $customer = $this->userModel::factory()->create();

        UserTaxProfile::factory()->create([
            'owner_user_id' => $customer->id,
            'tax_id'        => 'ESB12345678',
            'valid_from'    => now()->subYear(),
            'valid_until'   => null,
        ]);

        return $customer;
    };
});

describe('RecurringBillingService database-failure sanitiser (AID-1442 follow-up)', function () {
    it('sanitises a QueryException in the errors array and in every log site', function () {
        Log::spy();
        $bindingSentinel = 'zqx.recurring-binding@example.test';
        $infoSentinel    = 'zqx.recurring-errorinfo2.driver msg';

        // Realistic shape: the message Laravel builds interpolates the
        // bindings into the SQL text; errorInfo carries the driver metadata.
        // Deterministic sentinels, no faker (package rule for guarded fields).
        $queryException = new QueryException(
            'conn',
            'insert into billing_invoices (email) values (?)',
            [$bindingSentinel],
            new PDOException('driver msg with '.$bindingSentinel, 23000),
        );
        $queryException->errorInfo = ['23000', 1062, $infoSentinel];

        // Deterministic injection point: a consumer emission hook that
        // rejects the emission with a database failure. The throw happens
        // inside the atomic boundary, so the catch of
        // processRecurringBilling() is exercised through its real path.
        app()->instance(RecurringEmissionHookContract::class, new class($queryException) implements RecurringEmissionHookContract
        {
            public function __construct(private readonly Throwable $failure) {}

            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
            {
                throw $this->failure;
            }
        });

        // A throwing listener on RecurringBillingFailed exercises the
        // dispatchBestEffort() log site (site 3) through its real path. The
        // event keeps carrying the raw Throwable — that is by design.
        Event::listen(RecurringBillingFailed::class, fn () => throw $queryException);

        $customer = ($this->makeCustomer)();
        $article  = Article::factory()->monthly(2900)->create();

        ArticleServiceStatus::factory()->create([
            'customer_id'       => $customer->id,
            'article_id'        => $article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::ACTIVE,
            'next_billing_date' => now()->addDays(7),
            'effective_price'   => cents(2900),
        ]);

        $results = $this->service->processRecurringBilling(now());

        $expected = 'Illuminate\Database\QueryException: query failed (SQLSTATE 23000, driver code 1062)';

        expect($results['failed'])->toBe(1)
            ->and($results['errors'])->toHaveCount(1)
            ->and($results['errors'][0]['error'])->toBe($expected)
            // Neither the interpolated binding, the raw SQL nor errorInfo[2]
            // may reach the consumer-visible results array.
            ->and($results['errors'][0]['error'])->not->toContain($bindingSentinel)
            ->and($results['errors'][0]['error'])->not->toContain('insert into')
            ->and($results['errors'][0]['error'])->not->toContain($infoSentinel);

        $failedContexts   = [];
        $listenerContexts = [];
        $summaryContexts  = [];
        $alertContexts    = [];

        // Four Log::error calls occur on this path: the two in-scope sites of
        // RecurringBillingService ('Recurring billing failed' and the
        // dispatchBestEffort 'event listener failed' one), LogBillingSummary
        // re-logging the sanitised errors array ('Recurring billing error'),
        // and the package's own AlertBillingFailure listener ('Recurring
        // billing failed for service'), registered by default for every
        // installation. It reads the raw Throwable the event intentionally
        // carries, so its log line is asserted for the sanitised payload too:
        // the full Throwable is consumer-triage surface, never log content.
        // Mockery re-executes withArgs closures on every verification pass,
        // so buckets are keyed by the context fingerprint to stay idempotent.
        // Counts are pinned PER BUCKET below instead of one global
        // unconditional ->times(N): each site is asserted on its own, so the
        // test stays green precisely because every site is sanitised.
        $store = function (array &$bucket, array $context): void {
            $bucket[md5(json_encode($context))] = $context;
        };

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use (&$failedContexts, &$listenerContexts, &$summaryContexts, &$alertContexts, $store) {
            match ($message) {
                'Recurring billing failed'                => $store($failedContexts, $context),
                'Recurring billing event listener failed' => $store($listenerContexts, $context),
                'Recurring billing error'                 => $store($summaryContexts, $context),
                'Recurring billing failed for service'    => $store($alertContexts, $context),
                default                                   => null,
            };

            return true;
        });

        $failedContexts   = array_values($failedContexts);
        $listenerContexts = array_values($listenerContexts);
        $summaryContexts  = array_values($summaryContexts);
        $alertContexts    = array_values($alertContexts);

        expect($failedContexts)->toHaveCount(1)
            ->and($listenerContexts)->toHaveCount(1)
            ->and($summaryContexts)->toHaveCount(1)
            ->and($alertContexts)->toHaveCount(1)
            // The monitoring log line carries the sanitised payload, and the
            // raw stack trace no longer rides along (the full Throwable is
            // still on RecurringBillingFailed for consumer triage).
            ->and($failedContexts[0]['error'])->toBe($expected)
            ->and($failedContexts[0]['error'])->not->toContain($bindingSentinel)
            ->and($failedContexts[0])->not->toHaveKey('trace')
            ->and($listenerContexts[0]['error'])->toBe($expected)
            ->and($listenerContexts[0]['error'])->not->toContain($bindingSentinel)
            // The summary listener re-logs the consumer-visible errors array:
            // it must carry the sanitised value, not the raw one.
            ->and($summaryContexts[0]['error'])->toBe($expected)
            ->and($summaryContexts[0]['error'])->not->toContain($bindingSentinel)
            // The package's own listener reports the SAME sanitised payload:
            // no raw message (which interpolates the bindings into the SQL),
            // no raw SQL fragment, no driver text, and no 'trace' key.
            ->and($alertContexts[0]['error'])->toBe($expected)
            ->and($alertContexts[0]['error'])->not->toContain($bindingSentinel)
            ->and($alertContexts[0]['error'])->not->toContain('insert into')
            ->and($alertContexts[0]['error'])->not->toContain($infoSentinel)
            ->and($alertContexts[0])->not->toHaveKey('trace');
    });
});
