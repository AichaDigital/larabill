<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\FiscalIntegrityException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Notifications\FiscalIntegrityAlert;
use AichaDigital\Larabill\Services\FiscalIntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

uses(RefreshDatabase::class);

/**
 * Create multiple active user tax profiles bypassing model events, simulating
 * direct DB manipulation or data corruption (same pattern as the checker suite).
 *
 * @return Collection<int, UserTaxProfile>
 */
function aid1464DuplicateProfiles(string $ownerId, int $count): Collection
{
    return UserTaxProfile::withoutEvents(function () use ($ownerId, $count) {
        return collect(range(1, $count))->map(
            fn () => UserTaxProfile::factory()->active()->forOwner($ownerId)->create()
        );
    });
}

/**
 * Unsaved duplicate profiles carrying identifiable fixture data, for payload
 * privacy assertions that must not depend on the database (AID-1464).
 *
 * @return Collection<int, UserTaxProfile>
 */
function aid1464UnsavedDuplicateProfiles(): Collection
{
    return collect([0, 1])->map(function (int $i) {
        return UserTaxProfile::factory()->make([
            'fiscal_name' => 'AID1464 Fiscal Name '.$i,
            'tax_id'      => '1234567'.$i.'Z',
        ]);
    });
}

// =============================================================================
// Scalar mapping (AID-1464)
// =============================================================================

it('maps exception context to scalar properties', function () {
    $profiles = aid1464DuplicateProfiles('user-123', 2);
    $ids      = $profiles->pluck('id')->values()->all();

    $exception = FiscalIntegrityException::duplicateUserTaxProfile('user-123', $profiles);

    $alert = FiscalIntegrityAlert::fromException($exception);

    $payload = $alert->toArray(new stdClass);

    expect($payload['severity'])->toBe(FiscalIntegrityException::SEVERITY_ATOMIC)
        ->and($payload['affected_user_id'])->toBe('user-123')
        ->and($payload['duplicate_config_ids'])->toBe($ids)
        ->and($payload['message'])->toBe($exception->getMessage());
});

it('maps global exception context with null affected user', function () {
    $configs = CompanyFiscalConfig::withoutEvents(function () {
        return collect([
            CompanyFiscalConfig::factory()->active()->create(),
            CompanyFiscalConfig::factory()->active()->create(),
        ]);
    });
    $ids = $configs->pluck('id')->values()->all();

    $exception = FiscalIntegrityException::duplicateCompanyConfig($configs);

    $alert   = FiscalIntegrityAlert::fromException($exception);
    $payload = $alert->toArray(new stdClass);

    expect($payload['severity'])->toBe(FiscalIntegrityException::SEVERITY_GLOBAL)
        ->and($payload['affected_user_id'])->toBeNull()
        ->and($payload['duplicate_config_ids'])->toBe($ids)
        ->and($payload['is_global'])->toBeTrue();
});

// =============================================================================
// Queue payload privacy (AID-1464)
// =============================================================================

it('serializes without duplicate profile PII', function () {
    $profiles  = aid1464UnsavedDuplicateProfiles();
    $exception = FiscalIntegrityException::duplicateUserTaxProfile('user-123', $profiles);

    $alert = FiscalIntegrityAlert::fromException($exception);

    // The queue serializes the notification object verbatim: whatever appears
    // in this string lands in the `jobs` (and `failed_jobs`) payload.
    $serialized = serialize($alert);

    expect($serialized)
        ->not->toContain('12345670Z')
        ->not->toContain('12345671Z')
        ->not->toContain('AID1464 Fiscal Name 0')
        ->not->toContain('AID1464 Fiscal Name 1')
        ->and($serialized)->toContain(FiscalIntegrityException::SEVERITY_ATOMIC);
});

it('serializes without a Closure trace failure when duplicate models carry unsaved state', function () {
    // Regression guard for the second defect of AID-1464: serializing the whole
    // exception carried its trace, and a Closure anywhere in it aborted the
    // queued notification with "Serialization of 'Closure' is not allowed".
    $profiles  = aid1464UnsavedDuplicateProfiles();
    $exception = FiscalIntegrityException::duplicateUserTaxProfile('user-123', $profiles);

    $alert = FiscalIntegrityAlert::fromException($exception);

    expect(serialize($alert))->toBeString();
});

// =============================================================================
// Checker wiring: the queued notification is the real leak surface
// =============================================================================

it('does not enqueue duplicate profile PII through the checker notification path', function () {
    Queue::fake();

    config(['larabill.admin.email' => 'admin@test.com']);

    $profiles = aid1464DuplicateProfiles('user-123', 2);

    $leakedTaxId      = $profiles->first()->tax_id;
    $leakedFiscalName = $profiles->first()->fiscal_name;

    $checker = new FiscalIntegrityChecker;

    try {
        $checker->assertCanInvoiceUser('user-123');
    } catch (FiscalIntegrityException $e) {
        // Expected: the violation aborts invoicing after notifying.
    }

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($leakedTaxId, $leakedFiscalName) {
        // The notification object is the surface AID-1464 measured: it is what
        // the queue serializes into the `jobs`/`failed_jobs` payload. (The
        // on-demand notifiable is a named serializable class; the previous
        // anonymous one could never reach a real queue connection.)
        $serialized = serialize($job->notification);

        expect($serialized)
            ->not->toContain($leakedTaxId)
            ->not->toContain($leakedFiscalName);

        return true;
    });
});

// =============================================================================
// Real delivery paths (AID-1464) — no notification/mail fakes
// =============================================================================

/**
 * Install a real mail driver whose Symfony transport records every envelope
 * (or throws), executing the full mail-channel path end to end (AID-1464).
 *
 * @param  array<int, SentMessage>  $captured
 */
function aid1464CaptureMailTransport(array &$captured, ?Throwable $throw = null): void
{
    $transport = new class implements TransportInterface
    {
        /** @var array<int, SentMessage> */
        public array $envelopes = [];

        public ?Throwable $throw = null;

        /**
         * @param  array<int, Envelope>  $envelopes
         */
        public function bind(array &$envelopes, ?Throwable $throw): static
        {
            $this->envelopes = &$envelopes;
            $this->throw     = $throw;

            return $this;
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            if ($this->throw !== null) {
                throw $this->throw;
            }

            $envelope ??= Envelope::create($message);
            $this->envelopes[] = new SentMessage($message, $envelope);

            return $this->envelopes[count($this->envelopes) - 1];
        }

        public function __toString(): string
        {
            return 'aid1464://capture';
        }
    };

    $transport->bind($captured, $throw);

    // Mail::extend registers a TRANSPORT creator: Laravel builds the Mailer
    // around the returned Symfony transport (MailManager::createSymfonyTransport).
    Mail::extend('aid1464_capture', fn (array $config): TransportInterface => $transport);

    config([
        'mail.default'                 => 'aid1464_capture',
        'mail.mailers.aid1464_capture' => ['transport' => 'aid1464_capture'],
    ]);
}

it('delivers synchronously through the real mail channel without masking the fiscal exception', function () {
    $captured = [];
    aid1464CaptureMailTransport($captured);

    // Execute the notification inline, like an unqueued installation does.
    config(['queue.default' => 'sync']);

    config(['larabill.admin.email' => 'ops@aid1464.test, sec@aid1464.test']);

    $profiles          = aid1464DuplicateProfiles('user-123', 2);
    $fixtureTaxId      = $profiles->first()->tax_id;
    $fixtureFiscalName = $profiles->first()->fiscal_name;

    $checker = new FiscalIntegrityChecker;

    try {
        $checker->assertCanInvoiceUser('user-123');
    } catch (FiscalIntegrityException $e) {
        // Expected: the violation still aborts invoicing.
    }

    expect($e ?? null)->toBeInstanceOf(FiscalIntegrityException::class);

    expect($captured)->toHaveCount(1);

    $recipients = array_map(fn ($address) => $address->getAddress(), $captured[0]->getOriginalMessage()->getTo());

    expect($recipients)->toBe(['ops@aid1464.test', 'sec@aid1464.test']);

    $message = $captured[0]->getOriginalMessage();
    $body    = $message->getHtmlBody() ?? (string) $message->getTextBody();

    expect($message->getSubject())
        ->toBe(__('larabill::notifications.fiscal_integrity.subject_atomic'))
        ->and($body)
        ->not->toContain($fixtureTaxId)
        ->not->toContain($fixtureFiscalName);
});

it('delivers after a real queue serialization roundtrip', function () {
    $captured = [];
    aid1464CaptureMailTransport($captured);

    Queue::fake();
    config(['larabill.admin.email' => 'ops@aid1464.test']);

    aid1464DuplicateProfiles('user-123', 2);

    $checker = new FiscalIntegrityChecker;

    try {
        $checker->assertCanInvoiceUser('user-123');
    } catch (FiscalIntegrityException $e) {
        // Expected.
    }

    // Take the exact job the sender queued, push it through a real
    // serialization roundtrip like a queue worker would receive it, then
    // execute the channels for real.
    Queue::assertPushed(SendQueuedNotifications::class);

    $job = Queue::pushedJobs()[SendQueuedNotifications::class][0]['job'];
    $job = unserialize(serialize($job));

    $job->handle(app(ChannelManager::class));

    expect($captured)->toHaveCount(1)
        ->and(array_map(fn ($address) => $address->getAddress(), $captured[0]->getOriginalMessage()->getTo()))
        ->toBe(['ops@aid1464.test'])
        ->and($job->notification)->toBeInstanceOf(FiscalIntegrityAlert::class)
        ->and($job->notifiables->first())->toBeInstanceOf(AnonymousNotifiable::class);
});

it('logs a mail transport failure and still throws the fiscal exception', function () {
    $captured = [];
    aid1464CaptureMailTransport($captured, new RuntimeException('mail transport down'));

    // Synchronous queue so the transport failure surfaces inside notifyAdmins
    // (a real queue worker would fail the job later — outside this frontier).
    config(['queue.default' => 'sync']);

    Log::spy();
    config(['larabill.admin.email' => 'ops@aid1464.test']);

    aid1464DuplicateProfiles('user-123', 2);

    $checker = new FiscalIntegrityChecker;

    try {
        $checker->assertCanInvoiceUser('user-123');

        $this->fail('FiscalIntegrityException was expected');
    } catch (FiscalIntegrityException $e) {
        // The delivery failure must not mask the fiscal exception.
    }

    expect($captured)->toBeEmpty();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Failed to deliver fiscal integrity alert.'
            && isset($context['reason'])
            && isset($context['fiscal_context']['duplicate_config_ids']);
    });
});
