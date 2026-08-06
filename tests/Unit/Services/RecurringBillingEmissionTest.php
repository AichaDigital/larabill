<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\RecurringEmissionHookContract;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Events\RecurringBillingCompleted;
use AichaDigital\Larabill\Events\RecurringBillingFailed;
use AichaDigital\Larabill\Events\RecurringInvoiceGenerated;
use AichaDigital\Larabill\Exceptions\MissingRecurringEmissionHookException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PricingService;
use AichaDigital\Larabill\Services\RecurringBillingService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * AID-836 — the recurring flow emits through the canonical emission
 * contract: receiver + snapshots at birth, taxes, seal, atomic per-service
 * boundary with the consumer hook inside, and best-effort events.
 *
 * Design spec: docs/superpowers/specs/2026-08-06-aid-836-recurring-emission-contract.md
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

    $this->article = Article::factory()->monthly(2900)->create(['tax_group_id' => null]);

    $this->service = ArticleServiceStatus::factory()->create([
        'customer_id'       => $this->customer->id,
        'article_id'        => $this->article->id,
        'billing_frequency' => BillingFrequency::MONTHLY,
        'status'            => ServiceStatus::ACTIVE,
        'next_billing_date' => now()->addDays(7),
        'effective_price'   => cents(2900),
    ]);

    $this->billingService = fn () => new RecurringBillingService(new PricingService);
});

describe('receiver and snapshots (contract 1-2)', function () {
    it('emits with the receiver fixed at birth and ADR-003 semantics', function () {
        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['processed'])->toBe(1)->and($results['failed'])->toBe(0);

        $invoice = Invoice::findOrFail($results['invoices'][0]);

        // billable_user_id = receiver; user_id = issuer (parent ?? self —
        // TestUser has no parent, so it resolves to the customer itself).
        expect($invoice->billable_user_id)->toBe((string) $this->customer->id)
            ->and($invoice->user_id)->toBe((string) $this->customer->id)
            ->and($invoice->company_fiscal_config_id)->not->toBeNull()
            ->and($invoice->user_tax_profile_id)->not->toBeNull()
            ->and($invoice->issuer_snapshot)->not->toBeNull()
            ->and($invoice->customer_snapshot)->not->toBeNull()
            ->and($invoice->fiscal_snapshot)->not->toBeNull();
    });

    it('fails loudly and persists nothing when no CompanyFiscalConfig exists', function () {
        CompanyFiscalConfig::query()->delete();

        $originalDate = $this->service->next_billing_date->toDateString();

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['failed'])->toBe(1)
            ->and($results['processed'])->toBe(0)
            ->and(Invoice::count())->toBe(0)
            ->and(InvoiceItem::count())->toBe(0)
            ->and($this->service->fresh()->next_billing_date->toDateString())->toBe($originalDate);
    });
});

describe('fiscally identified receiver (D7)', function () {
    it('rejects a receiver without a valid tax profile and rolls back everything', function () {
        UserTaxProfile::query()->delete();

        $hook = new class implements RecurringEmissionHookContract
        {
            public int $calls = 0;

            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
            {
                $this->calls++;
            }
        };
        app()->instance(RecurringEmissionHookContract::class, $hook);

        $originalDate = $this->service->next_billing_date->toDateString();

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['failed'])->toBe(1)
            ->and($results['errors'][0]['error'])->toContain('UserTaxProfile')
            ->and(Invoice::count())->toBe(0)
            ->and(InvoiceItem::count())->toBe(0)
            ->and($hook->calls)->toBe(0)
            ->and($this->service->fresh()->next_billing_date->toDateString())->toBe($originalDate);
    });

    it('rejects a valid tax profile whose tax_id is empty', function () {
        UserTaxProfile::query()->update(['tax_id' => null]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['failed'])->toBe(1)
            ->and($results['errors'][0]['error'])->toContain('tax_id')
            ->and(Invoice::count())->toBe(0);
    });
});

describe('taxes and seal (contract 2-4)', function () {
    it('calculates taxes for the recurring line via the canonical path', function () {
        $vatGroup = TaxGroup::factory()->create(['name' => 'IVA General']);
        $rate     = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
        $vatGroup->taxRates()->attach($rate->id);
        $this->article->update(['tax_group_id' => $vatGroup->id]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        $invoice = Invoice::findOrFail($results['invoices'][0]);
        $item    = $invoice->items->first();

        expect($item->taxable_amount->unscaledValue())->toBe(2900)
            ->and($item->total_tax_amount->unscaledValue())->toBe(609)
            ->and($item->taxes_applied)->toHaveCount(1)
            ->and($invoice->total_amount->unscaledValue())->toBe(3509)
            ->and($invoice->total_tax_amount->unscaledValue())->toBe(609);
    });

    it('seals the invoice and anchors its dates to the real emission instant', function () {
        // A backdated processing run: $date decides eligibility only; the
        // invoice carries the REAL emission instant (spec D8).
        $pastDate = now()->subDays(20);
        $this->service->update(['next_billing_date' => $pastDate->copy()]);

        $results = ($this->billingService)()->processRecurringBilling($pastDate);

        $invoice = Invoice::findOrFail($results['invoices'][0]);

        expect($invoice->is_immutable)->toBeTrue()
            ->and($invoice->immutable_at)->not->toBeNull()
            ->and($invoice->issued_at)->not->toBeNull()
            ->and($invoice->status)->toBe(InvoiceStatus::SENT)
            ->and($invoice->invoice_date->toDateString())->toBe(now()->toDateString())
            ->and($invoice->due_date->toDateString())->toBe(now()->addDays(15)->toDateString());

        // The billed period, in contrast, stays anchored to the service.
        $item = $invoice->items->first();
        expect($item->service_date_from->toDateString())->toBe($pastDate->toDateString());
    });
});

describe('atomic boundary (contract 3, 5, 6)', function () {
    it('rolls back everything when the emission hook throws', function () {
        Event::fake([RecurringInvoiceGenerated::class]);

        $hook = new class implements RecurringEmissionHookContract
        {
            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
            {
                throw new RuntimeException('Consumer registration rejected the invoice');
            }
        };
        app()->instance(RecurringEmissionHookContract::class, $hook);

        $originalDate = $this->service->next_billing_date->toDateString();

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['failed'])->toBe(1)
            ->and($results['processed'])->toBe(0)
            ->and(Invoice::count())->toBe(0)
            ->and(InvoiceItem::count())->toBe(0)
            ->and($this->service->fresh()->next_billing_date->toDateString())->toBe($originalDate);

        Event::assertNotDispatched(RecurringInvoiceGenerated::class);

        // The fiscal number was NOT consumed: a healthy emission afterwards
        // takes the very first correlative of the series.
        $healthy = new class implements RecurringEmissionHookContract
        {
            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void {}
        };
        app()->instance(RecurringEmissionHookContract::class, $healthy);

        $results = ($this->billingService)()->processRecurringBilling(now());

        $invoice = Invoice::findOrFail($results['invoices'][0]);
        expect($invoice->series_number)->toBe(1);
    });

    it('passes a sealed invoice and the advanced service to the hook', function () {
        $hook = new class implements RecurringEmissionHookContract
        {
            public ?bool $sealed = null;

            public ?string $fiscalNumber = null;

            public int|string|null $serviceId = null;

            public ?string $nextBillingDate = null;

            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
            {
                $this->sealed          = $invoice->is_immutable;
                $this->fiscalNumber    = $invoice->fiscal_number;
                $this->serviceId       = $service->id;
                $this->nextBillingDate = $service->next_billing_date?->toDateString();
            }
        };
        app()->instance(RecurringEmissionHookContract::class, $hook);

        ($this->billingService)()->processRecurringBilling(now());

        expect($hook->sealed)->toBeTrue()
            ->and($hook->fiscalNumber)->not->toBeEmpty()
            ->and($hook->serviceId)->toBe($this->service->id)
            // updateNextBillingDate runs BEFORE the hook: the hook sees the
            // advanced service state (spec D5 ordering).
            ->and($hook->nextBillingDate)->toBe(now()->addDays(7)->addMonth()->toDateString());
    });

    it('does not re-invoke the hook nor redispatch the event on the idempotent path', function () {
        Event::fake([RecurringInvoiceGenerated::class]);

        $hook = new class implements RecurringEmissionHookContract
        {
            public int $calls = 0;

            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
            {
                $this->calls++;
            }
        };
        app()->instance(RecurringEmissionHookContract::class, $hook);

        $service = ($this->billingService)();

        $first = $service->processRecurringBilling(now());

        // Simulate a crash between the invoice commit and the advance: the
        // date rolls back while the invoice stays. The next run must repair
        // (re-advance) WITHOUT re-running the hook or the event.
        $originalDate = now()->addDays(7)->toDateString();
        DB::table('article_service_status')
            ->where('id', $this->service->id)
            ->update(['next_billing_date' => $originalDate.' 00:00:00']);

        $second = $service->processRecurringBilling(now());

        expect($second['invoices'][0])->toBe($first['invoices'][0])
            ->and($second['processed'])->toBe(1)
            ->and($hook->calls)->toBe(1)
            ->and($this->service->fresh()->next_billing_date->toDateString())
            ->toBe(now()->addDays(7)->addMonth()->toDateString());

        Event::assertDispatchedTimes(RecurringInvoiceGenerated::class, 1);
    });

    it('stands down when the selected period was already processed by an overlapping run', function () {
        // Stale in-memory instance (what the selection saw) vs a row another
        // run already advanced: the boundary must skip, not emit the next
        // period early (spec D5.b).
        $stale = ArticleServiceStatus::findOrFail($this->service->id);

        DB::table('article_service_status')
            ->where('id', $this->service->id)
            ->update(['next_billing_date' => now()->addDays(7)->addMonth()->toDateString().' 00:00:00']);

        $method = new ReflectionMethod(RecurringBillingService::class, 'emitWithinBoundary');
        $result = $method->invoke(($this->billingService)(), $stale, now(), null);

        expect($result['invoice'])->toBeNull()
            ->and($result['created'])->toBeFalse()
            ->and(Invoice::count())->toBe(0);
    });

    it('stands down when the service stopped being billable while waiting for the lock', function () {
        // Same period, but a concurrent suspension: the period check alone
        // would not catch it (spec D5.b').
        $stale = ArticleServiceStatus::findOrFail($this->service->id);

        DB::table('article_service_status')
            ->where('id', $this->service->id)
            ->update(['status' => ServiceStatus::SUSPENDED->value]);

        $method = new ReflectionMethod(RecurringBillingService::class, 'emitWithinBoundary');
        $result = $method->invoke(($this->billingService)(), $stale, now(), null);

        expect($result['invoice'])->toBeNull()
            ->and(Invoice::count())->toBe(0);
    });

    it('does not treat a proforma line with recurring metadata as an existing emission', function () {
        $periodStart = $this->service->next_billing_date;

        app(InvoiceService::class)->createProforma([
            'billable_user_id' => (string) $this->customer->id,
            'items'            => [
                [
                    'article_id'        => $this->article->id,
                    'base_price'        => 2900,
                    'description'       => 'Quoted recurring service',
                    'service_date_from' => $periodStart->toDateString(),
                    'service_date_to'   => $periodStart->copy()->addMonth()->subDay()->toDateString(),
                    'metadata'          => [
                        'source_reference' => [
                            'type'              => 'article_service',
                            'service_status_id' => $this->service->id,
                        ],
                    ],
                ],
            ],
        ]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['processed'])->toBe(1);

        $invoice = Invoice::findOrFail($results['invoices'][0]);
        expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    });

    it('ignores lines whose source type is not article_service', function () {
        $periodStart = $this->service->next_billing_date;

        app(InvoiceService::class)->createInvoice([
            'billable_user_id' => (string) $this->customer->id,
            'items'            => [
                [
                    'article_id'        => $this->article->id,
                    'base_price'        => 2900,
                    'description'       => 'Manually billed line for the same period',
                    'service_date_from' => $periodStart->toDateString(),
                    'metadata'          => [
                        'source_reference' => [
                            'type'              => 'manual',
                            'service_status_id' => $this->service->id,
                        ],
                    ],
                ],
            ],
        ]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        // The manual line does not satisfy the idempotency check: the
        // recurring emission still happens (two invoices total).
        expect($results['processed'])->toBe(1)
            ->and(Invoice::count())->toBe(2);
    });
});

describe('emission hook gate (D4)', function () {
    it('throws before issuing anything when the hook is required and none is bound', function () {
        config()->set('larabill.recurring_billing.require_emission_hook', true);

        expect(fn () => ($this->billingService)()->processRecurringBilling(now()))
            ->toThrow(MissingRecurringEmissionHookException::class);

        expect(Invoice::count())->toBe(0);
    });

    it('lets a dry run proceed without a hook even when the gate is on', function () {
        config()->set('larabill.recurring_billing.require_emission_hook', true);

        $results = ($this->billingService)()->processRecurringBilling(now(), dryRun: true);

        expect($results['processed'])->toBe(1)
            ->and(Invoice::count())->toBe(0);
    });
});

describe('best-effort events (D9)', function () {
    it('keeps an emitted invoice as processed when a Generated listener throws', function () {
        Event::listen(RecurringInvoiceGenerated::class, function (): void {
            throw new RuntimeException('Consumer listener exploded');
        });

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['processed'])->toBe(1)
            ->and($results['failed'])->toBe(0)
            ->and(Invoice::count())->toBe(1);
    });

    it('continues the batch and completes when a Failed listener throws', function () {
        Event::fake([RecurringBillingCompleted::class]);
        Event::listen(RecurringBillingFailed::class, function (): void {
            throw new RuntimeException('Failure listener exploded');
        });

        // Second service whose customer has no tax profile → its emission
        // fails, the Failed listener throws, and the batch must still
        // finish the healthy service and dispatch Completed.
        $orphanCustomer = TestUser::factory()->create();
        ArticleServiceStatus::factory()->create([
            'customer_id'       => $orphanCustomer->id,
            'article_id'        => $this->article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::ACTIVE,
            'next_billing_date' => now()->addDays(7),
            'effective_price'   => cents(2900),
        ]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['processed'])->toBe(1)
            ->and($results['failed'])->toBe(1);

        Event::assertDispatched(RecurringBillingCompleted::class);
    });

    it('counts a service as failed and continues when the hook throws an Error', function () {
        Event::fake([RecurringBillingCompleted::class]);

        $failingServiceId = $this->service->id;

        $hook = new class implements RecurringEmissionHookContract
        {
            public int|string|null $failFor = null;

            public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void
            {
                if ($service->id === $this->failFor) {
                    throw new Error('TypeError-like failure inside the consumer hook');
                }
            }
        };
        $hook->failFor = $failingServiceId;
        app()->instance(RecurringEmissionHookContract::class, $hook);

        $secondCustomer = TestUser::factory()->create();
        UserTaxProfile::factory()->create([
            'owner_user_id' => $secondCustomer->id,
            'tax_id'        => 'ES87654321X',
            'valid_from'    => now()->subYear(),
            'valid_until'   => null,
        ]);
        ArticleServiceStatus::factory()->create([
            'customer_id'       => $secondCustomer->id,
            'article_id'        => $this->article->id,
            'billing_frequency' => BillingFrequency::MONTHLY,
            'status'            => ServiceStatus::ACTIVE,
            'next_billing_date' => now()->addDays(7),
            'effective_price'   => cents(2900),
        ]);

        $results = ($this->billingService)()->processRecurringBilling(now());

        expect($results['processed'])->toBe(1)
            ->and($results['failed'])->toBe(1)
            ->and(Invoice::count())->toBe(1);

        Event::assertDispatched(RecurringBillingCompleted::class);
    });
});
