<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Services\RecurringBillingService;
use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
 * ADR-015 §3.2 — the single locked materialiser for deferred closures and
 * expiry, and the emission-gate exclusion: an agreement with a scheduled
 * close is never billed for a period reaching beyond `close_scheduled_at`.
 *
 * Precedence (gate rounds 7-8): when both `close_scheduled_at` and
 * `expires_at` exist, whichever comes FIRST wins; on EQUAL dates the
 * scheduled close wins — an explicit close decision outranks the automatic
 * expiry. One materialiser handles both fields in the same pass, so
 * scheduler order never determines the resulting history.
 */

beforeEach(function () {
    $this->lifecycle       = new ServiceLifecycleService;
    $this->fulfilmentCause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'fulfilment');
    $this->legacyCause     = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation');
});

function schedulerService(array $attributes = []): ArticleServiceStatus
{
    $article = Article::factory()->service()->monthly(2900)->create();

    return ArticleServiceStatus::factory()->create(array_merge([
        'article_id'        => $article->id,
        'next_billing_date' => now()->addMonth(),
    ], $attributes));
}

it('closes a past-due scheduled closure with its stored cause and dispatches ServiceClosed', function () {
    Event::fake([ServiceClosed::class]);

    $service = schedulerService(['close_scheduled_at' => now()->subDay()]);

    $result = $this->lifecycle->processScheduledClosures();

    expect($result->materialised)->toBe(1);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBeNull() // migrated rows carry cause NULL
        ->and($fresh->next_billing_date)->toBeNull();

    Event::assertDispatched(ServiceClosed::class);
});

it('leaves future scheduled closures and active agreements alone', function () {
    schedulerService(['close_scheduled_at' => now()->addWeek()]);
    schedulerService();

    $result = $this->lifecycle->processScheduledClosures();

    expect($result->materialised)->toBe(0)
        ->and(ArticleServiceStatus::query()->where('status', AgreementStatus::ACTIVE)->count())->toBe(2);
});

it('closes an expired agreement with the fulfilment cause (expiry rewire, §4.2)', function () {
    Event::fake([ServiceClosed::class]);

    $service = schedulerService(['expires_at' => now()->subDay()]);

    $result = $this->lifecycle->processScheduledClosures();

    expect($result->materialised)->toBe(1);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($this->fulfilmentCause->id);

    Event::assertDispatched(ServiceClosed::class);
});

it('gives the earlier date precedence when both fields exist and differ', function () {
    // Expiry FIRST → fulfilment wins, the scheduled close is ignored.
    $expiryFirst = schedulerService([
        'expires_at'         => now()->subDay(),
        'close_scheduled_at' => now()->addWeek(),
    ]);
    $this->lifecycle->processScheduledClosures();
    expect($expiryFirst->fresh()->closure_cause_id)->toBe($this->fulfilmentCause->id);

    // Scheduled close FIRST → its stored cause wins, expiry ignored.
    $scheduledFirst = schedulerService([
        'close_scheduled_at' => now()->subDay(),
        'expires_at'         => now()->addWeek(),
    ]);
    $this->lifecycle->processScheduledClosures();
    expect($scheduledFirst->fresh()->closure_cause_id)->toBeNull()
        ->and($scheduledFirst->fresh()->status)->toBe(AgreementStatus::CLOSED);
});

it('gives the scheduled close the tie-break on equal dates (explicit decision outranks expiry)', function () {
    Event::fake([ServiceClosed::class]);

    $sameDate = now()->subDay()->toDateString();

    $service = schedulerService([
        'close_scheduled_at' => $sameDate,
        'expires_at'         => $sameDate,
    ]);

    $this->lifecycle->processScheduledClosures();

    // Equal dates: scheduled close wins with its stored cause (NULL here) —
    // NOT the fulfilment cause of the expiry path.
    expect($service->fresh()->closure_cause_id)->toBeNull()
        ->and($service->fresh()->status)->toBe(AgreementStatus::CLOSED);
});

it('processExpiredServices routes through the same single materialiser (delegation)', function () {
    $service = schedulerService(['expires_at' => now()->subDay()]);

    $count = $this->lifecycle->processExpiredServices();

    expect($count)->toBe(1)
        ->and($service->fresh()->status)->toBe(AgreementStatus::CLOSED)
        ->and($service->fresh()->closure_cause_id)->toBe($this->fulfilmentCause->id);
});

it('is idempotent: an already-closed row is skipped and not counted', function () {
    $service = schedulerService(['close_scheduled_at' => now()->subDay()]);

    expect($this->lifecycle->processScheduledClosures()->materialised)->toBe(1)
        ->and($this->lifecycle->processScheduledClosures()->materialised)->toBe(0)
        ->and($service->fresh()->status)->toBe(AgreementStatus::CLOSED);
});

it('never bills a period reaching beyond close_scheduled_at (emission gate, §3.2)', function () {
    // Monthly cadence, next_billing_date tomorrow (due within the advance
    // window): the billed period ends one month later — far beyond a
    // scheduled close next week.
    $scheduled = schedulerService([
        'next_billing_date'  => now()->addDay(),
        'close_scheduled_at' => now()->addWeek(),
    ]);
    $free = schedulerService(['next_billing_date' => now()->addDay()]); // control

    $results = $this->lifecycle->processScheduledClosures(); // nothing due yet
    expect($results->materialised)->toBe(0);

    // Both are in the billing window; only the control may bill.
    expect(scheduledCloseGate($scheduled))->toBeFalse()
        ->and(scheduledCloseGate($free))->toBeTrue();
});

it('still bills a period that ends before the scheduled close', function () {
    // Due tomorrow; the period (one month) ends BEFORE the close date two
    // months out, so the gate still bills it.
    $service = schedulerService([
        'next_billing_date'  => now()->addDay(),
        'close_scheduled_at' => now()->addMonths(2),
    ]);

    expect(scheduledCloseGate($service))->toBeTrue();
});

it('does not count a due row another process closed mid-run (AID-1590)', function () {
    // Two rows due. When the materialiser closes the FIRST one, the
    // dispatched `ServiceClosed` triggers a listener that closes the OTHER
    // due row — the realistic interleaving of a concurrent writer (no forks
    // needed: the row is no longer ACTIVE under `lockForUpdate()` when the
    // materialiser reaches it). That skipped row must NOT be counted:
    // `materialised` reflects only rows THIS run actually closed.
    $first  = schedulerService(['close_scheduled_at' => now()->subDay()]);
    $second = schedulerService(['close_scheduled_at' => now()->subDay()]);

    Event::listen(ServiceClosed::class, function (ServiceClosed $event) use ($first, $second): void {
        // Concurrent-writer stand-in: react to the first row's closure by
        // closing the other due row (row B's own closure event is a no-op).
        if ($event->service->id === $first->id) {
            $second->close($this->fulfilmentCause);
        }
    });

    $result = $this->lifecycle->processScheduledClosures();

    expect($result->materialised)->toBe(1)
        ->and($result->failedServiceIds)->toBe([])
        ->and($first->fresh()->status)->toBe(AgreementStatus::CLOSED)
        ->and($second->fresh()->status)->toBe(AgreementStatus::CLOSED); // closed by the listener, NOT counted
});

it('sanitises database failures in the log and reports failed ids (AID-1590)', function () {
    $first  = schedulerService(['close_scheduled_at' => now()->subDay()]);
    $second = schedulerService(['close_scheduled_at' => now()->subDay()]);

    // A QueryException carrying raw SQL and a binding value in its message —
    // exactly what `getMessage()` of a real driver failure interpolates.
    $previous            = new PDOException('SQLSTATE[42S01]: Base table or view already exists', 1050);
    $previous->errorInfo = ['42S01', 1050, 'table already exists'];
    $queryException      = new QueryException(
        'sqlite',
        'insert into secret_table (token) values (?)',
        ['BINDING-SECRET-424242'],
        $previous,
    );

    Event::listen(ServiceClosed::class, function (ServiceClosed $event) use ($first, $queryException): void {
        // The first row's materialisation blows up; the second must succeed.
        if ($event->service->id === $first->id) {
            throw $queryException;
        }
    });

    $loggedContext = null;
    Log::shouldReceive('error')->once()->andReturnUsing(function (string $message, array $context) use (&$loggedContext): void {
        $loggedContext = $context;
    });

    $result = $this->lifecycle->processScheduledClosures();

    expect($result->materialised)->toBe(1) // the second row materialised
        ->and($result->failedServiceIds)->toBe([$first->id])
        ->and($first->fresh()->status)->toBe(AgreementStatus::ACTIVE) // left open for the next run
        ->and($second->fresh()->status)->toBe(AgreementStatus::CLOSED)
        ->and($loggedContext['service_id'])->toBe($first->id)
        ->and($loggedContext['error'])->toBeString()
        // No raw SQL, no bindings, no driver message leaves the package.
        ->and($loggedContext['error'])->not->toContain('secret_table')
        ->and($loggedContext['error'])->not->toContain('BINDING-SECRET-424242')
        ->and($loggedContext['error'])->not->toContain('insert into')
        ->and($loggedContext['error'])->not->toContain('42S01]: Base table')
        // The sanitised shape is present instead (AID-1442 pattern).
        ->and($loggedContext['error'])->toContain('SQLSTATE 42S01')
        ->and($loggedContext['error'])->toContain('driver code 1050');
});

it('closes with NULL cause when the stored cause was retired between scheduling and materialisation (AID-1594)', function () {
    Event::fake([ServiceClosed::class]);

    $service = schedulerService();
    $service->close($this->legacyCause, 'End of term', now()->addDays(5));
    // Due now: pull the scheduled date into the past without touching state.
    $service->update(['close_scheduled_at' => now()->subDay()->toDateString()]);

    // Retire the cause AFTER scheduling — the realistic window.
    AgreementCause::query()->whereKey($this->legacyCause->getKey())->update(['is_active' => false]);

    $result = $this->lifecycle->processScheduledClosures();

    // The cause-optional principle (§2.2): closed + NULL cause is legal. The
    // agreement must NOT stay open forever re-failing every run.
    expect($result->materialised)->toBe(1)
        ->and($result->failedServiceIds)->toBe([]);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBeNull()
        // The original cause key and the situation travel in the closure metadata.
        ->and($fresh->metadata['closure']['materialised_cause_key'])->toBe($this->legacyCause->key)
        ->and($fresh->metadata['closure']['materialised_cause_situation'])->toBe('retired')
        ->and($fresh->metadata['closure']['note'])->toBe('End of term');

    Event::assertDispatched(ServiceClosed::class);
});

it('keeps closing with the stored cause while it stays active (AID-1594 control)', function () {
    $service = schedulerService();
    $service->close($this->legacyCause, 'End of term', now()->addDays(5));
    $service->update(['close_scheduled_at' => now()->subDay()->toDateString()]);

    $result = $this->lifecycle->processScheduledClosures();

    $fresh = $service->fresh();
    expect($result->materialised)->toBe(1)
        ->and($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($this->legacyCause->id)
        ->and($fresh->metadata['closure']['materialised_cause_key'] ?? null)->toBeNull();
});

it('distinguishes nothing-to-close from everything-failed (AID-1590)', function () {
    $first  = schedulerService(['close_scheduled_at' => now()->subDay()]);
    $second = schedulerService(['close_scheduled_at' => now()->subDay()]);

    $previous            = new PDOException('SQLSTATE[40001]: Serialization failure', 1213);
    $previous->errorInfo = ['40001', 1213, 'deadlock'];
    $queryException      = new QueryException(
        'sqlite',
        'update secret_table set status = ? where id = ?',
        ['BINDING-SECRET-999', $first->id],
        $previous,
    );

    Event::listen(ServiceClosed::class, function () use ($queryException): void {
        throw $queryException; // every materialisation fails
    });

    Log::shouldReceive('error')->twice();

    $result = $this->lifecycle->processScheduledClosures();

    // "nothing to close" would be materialised 0 AND no failures; here the
    // run had work, failed it all, and says so.
    expect($result->materialised)->toBe(0)
        ->and($result->failedServiceIds)->toBe([$first->id, $second->id])
        ->and($first->fresh()->status)->toBe(AgreementStatus::ACTIVE)
        ->and($second->fresh()->status)->toBe(AgreementStatus::ACTIVE);
});

/**
 * Read-only probe exposing the engine's private emission gate without
 * exercising the full emission boundary — the gate decision is the contract
 * under test here, not the invoicing itself.
 */
function scheduledCloseGate(ArticleServiceStatus $service): bool
{
    $engine = app(RecurringBillingService::class);

    // The engine is `final`; the protected gate is exposed read-only via
    // reflection — the decision is the contract under test, not the
    // invoicing.
    $gate = new ReflectionMethod($engine, 'shouldGenerateInvoice');

    return (bool) $gate->invoke($engine, $service, now());
}
