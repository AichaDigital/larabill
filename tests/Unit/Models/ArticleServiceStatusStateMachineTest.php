<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Events\ServiceActivated;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Events\ServiceReinstated;
use AichaDigital\Larabill\Events\ServiceSuspended;
use AichaDigital\Larabill\Exceptions\IllegalAgreementTransitionException;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * ADR-015 §3.1 — the agreement state machine, enforced through the
 * close/suspend API of §3.2. The transition map (incl. the illegal ones) is
 * pinned here: closed is final, pending cannot suspend, a suspension cause
 * cannot close and a closure cause cannot suspend, and closed + NULL cause
 * is a legal state (the import requirement).
 */

function stateMachineService(array $attributes = []): ArticleServiceStatus
{
    return ArticleServiceStatus::factory()->create(array_merge([
        'next_billing_date' => now()->addMonth(),
    ], $attributes));
}

function closedCause(): AgreementCause
{
    return AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'client_request');
}

function suspendedCause(): AgreementCause
{
    return AgreementCause::findActive(AgreementCause::SCOPE_SUSPENDED, 'unpaid');
}

it('transitions pending → active through the provisioning path', function () {
    Event::fake([ServiceActivated::class]);

    $service           = ArticleServiceStatus::factory()->pending()->create();
    $originalStartedAt = $service->started_at;

    $service->activate();

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
        ->and($fresh->started_at)->not->toBeNull()
        // Activation never moves an already-set started_at.
        ->and($fresh->started_at->equalTo($originalStartedAt))->toBeTrue();

    Event::assertDispatched(ServiceActivated::class);
});

it('transitions active → suspended with a suspension cause and records it', function () {
    Event::fake([ServiceSuspended::class]);

    $service = stateMachineService();
    $cause   = suspendedCause();

    $service->suspend($cause, 'Payment overdue');

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::SUSPENDED)
        ->and($fresh->closure_cause_id)->toBe($cause->id)
        ->and($fresh->metadata['suspension']['reason'])->toBe('Payment overdue')
        ->and($fresh->metadata['suspension']['cause_key'])->toBe('unpaid');

    Event::assertDispatched(ServiceSuspended::class, fn ($event) => $event->service->id === $service->id);
});

it('transitions suspended → active through reinstatement and clears the suspension cause', function () {
    Event::fake([ServiceReinstated::class, ServiceActivated::class]);

    $service = stateMachineService();
    $cause   = suspendedCause();
    $service->suspend($cause);
    expect($service->fresh()->closure_cause_id)->toBe($cause->id);

    $service->activate();

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
        ->and($fresh->closure_cause_id)->toBeNull();

    // ADR-015 §3.2: the reinstatement edge dispatches the dedicated
    // ServiceReinstated event — IN ADDITION to the pre-existing
    // ServiceActivated, whose existing listeners keep working.
    Event::assertDispatched(ServiceReinstated::class, fn ($event) => $event->service->is($service));
    Event::assertDispatched(ServiceActivated::class);
});

it('does NOT dispatch ServiceReinstated on the provisioning edge pending → active', function () {
    Event::fake([ServiceReinstated::class, ServiceActivated::class]);

    ArticleServiceStatus::factory()->pending()->create()->activate();

    Event::assertDispatched(ServiceActivated::class);
    Event::assertNotDispatched(ServiceReinstated::class);
});

it('transitions active → closed with a closure cause', function () {
    Event::fake([ServiceClosed::class]);

    $service = stateMachineService();
    $cause   = closedCause();

    $service->close($cause, 'Client asked out');

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBe($cause->id)
        ->and($fresh->next_billing_date)->toBeNull();

    Event::assertDispatched(ServiceClosed::class, function ($event) use ($service, $cause) {
        return $event->service->id === $service->id
            && $event->cause?->is($cause);
    });
});

it('transitions active → closed with NULL cause — closed + NULL cause is a legal state (import requirement)', function () {
    $service = stateMachineService();

    $service->close(null);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->closure_cause_id)->toBeNull();
});

it('transitions suspended → closed with a closure cause', function () {
    $service = stateMachineService();
    $service->suspend(suspendedCause());

    $service->close(closedCause());

    expect($service->fresh()->status)->toBe(AgreementStatus::CLOSED);
});

it('transitions pending → closed', function () {
    $service = ArticleServiceStatus::factory()->pending()->create();

    $service->close(closedCause());

    expect($service->fresh()->status)->toBe(AgreementStatus::CLOSED);
});

it('transitions guarantee → closed', function () {
    $service = ArticleServiceStatus::factory()->create(['status' => AgreementStatus::GUARANTEE]);

    $service->close(closedCause());

    expect($service->fresh()->status)->toBe(AgreementStatus::CLOSED);
});

it('schedules a close: future effectiveAt keeps the agreement active with close_scheduled_at', function () {
    $service  = stateMachineService();
    $cause    = closedCause();
    $effecive = now()->addDays(15);

    $service->close($cause, 'End of term', $effecive);

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
        ->and($fresh->close_scheduled_at->toDateString())->toBe($effecive->toDateString())
        ->and($fresh->closure_cause_id)->toBe($cause->id)
        ->and($fresh->next_billing_date)->not->toBeNull();
});

it('refuses to SCHEDULE a close from a non-active state — no zombie schedule the materialiser would never process (AID-1593)', function (string $state) {
    $service = $state === AgreementStatus::SUSPENDED->value
        ? tap(stateMachineService(), fn (ArticleServiceStatus $s) => $s->suspend(suspendedCause()))
        : stateMachineService(['status' => AgreementStatus::from($state)]);
    $service = $service->fresh();

    // Suspension assigns its own cause; capture it — the close attempt must
    // change nothing at all.
    $causeBefore = $service->fresh()->closure_cause_id;

    expect(fn () => $service->close(closedCause(), 'End of term', now()->addDays(15)))
        ->toThrow(IllegalAgreementTransitionException::class);

    // Untouched: no schedule planted, no state moved, no cause re-assigned.
    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::from($state))
        ->and($fresh->close_scheduled_at)->toBeNull()
        ->and($fresh->closure_cause_id)->toBe($causeBefore);
})->with([
    'suspended' => AgreementStatus::SUSPENDED->value,
    'pending'   => AgreementStatus::PENDING->value,
    'guarantee' => AgreementStatus::GUARANTEE->value,
]);

it('still closes IMMEDIATELY from suspended — the §3.1 edge is intact (AID-1593)', function () {
    $service = stateMachineService();
    $service->suspend(suspendedCause());
    $service = $service->fresh();

    $service->close(closedCause(), 'Out now');

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->close_scheduled_at)->toBeNull();
});

it('names the current state and the immediate-close alternative in the scheduled-close rejection', function () {
    $service = stateMachineService();
    $service->suspend(suspendedCause());
    $service = $service->fresh();

    try {
        $service->close(closedCause(), null, now()->addDays(15));
        $this->fail('Expected IllegalAgreementTransitionException::scheduledCloseFromNonActive.');
    } catch (IllegalAgreementTransitionException $e) {
        expect($e->getMessage())->toContain("'suspended'")
            ->and($e->getMessage())->toContain('close immediately');
    }
});

it('closes immediately when effectiveAt is today or in the past', function () {
    $service = stateMachineService();

    $service->close(closedCause(), null, now()->startOfDay());

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::CLOSED)
        ->and($fresh->close_scheduled_at)->toBeNull();
});

it('cancels a scheduled close: stays active, clears the schedule, records the cancellation in metadata', function () {
    $service = stateMachineService();
    $service->close(closedCause(), null, now()->addDays(15));
    expect($service->fresh()->close_scheduled_at)->not->toBeNull();

    $service->cancelScheduledClose('Plan change instead');

    $fresh = $service->fresh();
    expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
        ->and($fresh->close_scheduled_at)->toBeNull()
        ->and($fresh->closure_cause_id)->toBeNull()
        ->and($fresh->metadata['closure']['schedule_cancelled_note'])->toBe('Plan change instead');
});

it('throws the typed exception naming the transition on every illegal transition', function () {
    // closed is FINAL — nothing may leave it.
    $closed = ArticleServiceStatus::factory()->create(['status' => AgreementStatus::CLOSED]);

    try {
        $closed->activate();
        $this->fail('closed → active must be illegal');
    } catch (IllegalAgreementTransitionException $e) {
        expect($e->getMessage())->toContain('closed')->and($e->getMessage())->toContain('active');
    }

    try {
        $closed->close(closedCause());
        $this->fail('closed → closed must be illegal');
    } catch (IllegalAgreementTransitionException $e) {
        expect($e->getMessage())->toContain('closed');
    }

    // pending cannot suspend (never provisioned).
    $pending = ArticleServiceStatus::factory()->pending()->create();

    try {
        $pending->suspend(suspendedCause());
        $this->fail('pending → suspended must be illegal');
    } catch (IllegalAgreementTransitionException $e) {
        expect($e->getMessage())->toContain('pending')->and($e->getMessage())->toContain('suspended');
    }

    // guarantee cannot be reinstated — only the refund closes it.
    $guarantee = ArticleServiceStatus::factory()->create(['status' => AgreementStatus::GUARANTEE]);

    try {
        $guarantee->activate();
        $this->fail('guarantee → active must be illegal');
    } catch (IllegalAgreementTransitionException $e) {
        expect($e->getMessage())->toContain('guarantee')->and($e->getMessage())->toContain('active');
    }
});

it('enforces the cause scope: a suspension cause cannot close and a closure cause cannot suspend', function () {
    $service = stateMachineService();

    try {
        $service->close(suspendedCause());
        $this->fail('a suspension cause must not close an agreement');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('unpaid')->and($e->getMessage())->toContain('closed');
    }

    try {
        $service->suspend(closedCause());
        $this->fail('a closure cause must not suspend an agreement');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('client_request')->and($e->getMessage())->toContain('suspended');
    }

    // The failed transitions must not have mutated the agreement.
    expect($service->fresh()->status)->toBe(AgreementStatus::ACTIVE);
});

it('rejects an inactive cause for new assignments through the API', function () {
    $service = stateMachineService();
    $cause   = closedCause();

    AgreementCause::query()->whereKey($cause->id)->update(['is_active' => false]);

    try {
        $service->close(AgreementCause::query()->findOrFail($cause->id));
        $this->fail('an inactive cause must not be assignable');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('inactive');
    }

    expect($service->fresh()->status)->toBe(AgreementStatus::ACTIVE);
});

it('pins the full transition map including the illegal pairs', function () {
    expect(AgreementStatus::PENDING->canTransitionTo(AgreementStatus::ACTIVE))->toBeTrue()
        ->and(AgreementStatus::PENDING->canTransitionTo(AgreementStatus::CLOSED))->toBeTrue()
        ->and(AgreementStatus::PENDING->canTransitionTo(AgreementStatus::SUSPENDED))->toBeFalse()
        ->and(AgreementStatus::PENDING->canTransitionTo(AgreementStatus::GUARANTEE))->toBeFalse()
        ->and(AgreementStatus::ACTIVE->canTransitionTo(AgreementStatus::SUSPENDED))->toBeTrue()
        ->and(AgreementStatus::ACTIVE->canTransitionTo(AgreementStatus::CLOSED))->toBeTrue()
        ->and(AgreementStatus::ACTIVE->canTransitionTo(AgreementStatus::GUARANTEE))->toBeTrue()
        ->and(AgreementStatus::ACTIVE->canTransitionTo(AgreementStatus::PENDING))->toBeFalse()
        ->and(AgreementStatus::SUSPENDED->canTransitionTo(AgreementStatus::ACTIVE))->toBeTrue()
        ->and(AgreementStatus::SUSPENDED->canTransitionTo(AgreementStatus::CLOSED))->toBeTrue()
        ->and(AgreementStatus::SUSPENDED->canTransitionTo(AgreementStatus::GUARANTEE))->toBeFalse()
        ->and(AgreementStatus::GUARANTEE->canTransitionTo(AgreementStatus::CLOSED))->toBeTrue()
        ->and(AgreementStatus::GUARANTEE->canTransitionTo(AgreementStatus::ACTIVE))->toBeFalse()
        ->and(AgreementStatus::GUARANTEE->canTransitionTo(AgreementStatus::SUSPENDED))->toBeFalse()
        ->and(AgreementStatus::CLOSED->canTransitionTo(AgreementStatus::ACTIVE))->toBeFalse()
        ->and(AgreementStatus::CLOSED->canTransitionTo(AgreementStatus::PENDING))->toBeFalse()
        ->and(AgreementStatus::CLOSED->canTransitionTo(AgreementStatus::SUSPENDED))->toBeFalse()
        ->and(AgreementStatus::CLOSED->canTransitionTo(AgreementStatus::GUARANTEE))->toBeFalse()
        ->and(AgreementStatus::CLOSED->canTransitionTo(AgreementStatus::CLOSED))->toBeFalse();
});
