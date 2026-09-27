<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Listeners;

use AichaDigital\Larabill\Events\RecurringBillingFailed;
use AichaDigital\Larabill\Support\DatabaseFailureSanitiser;
use Illuminate\Support\Facades\Log;

/**
 * Listener for RecurringBillingFailed event
 *
 * Handles billing failures:
 * - Alerting administrators
 * - Creating support tickets
 * - Implementing retry strategies
 *
 * Sanitisation contract (AID-1442 follow-up): the log line reports the same
 * sanitised payload as the rest of the failure path — a QueryException
 * anywhere in the chain yields its class, the SQLSTATE and the driver code
 * only, never the raw message (which interpolates binding values into the
 * SQL), never the driver text, and no stack trace. The full Throwable still
 * rides the event for consumer triage; it must not be logged here.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
final class AlertBillingFailure
{
    /**
     * Handle the event
     */
    public function handle(RecurringBillingFailed $event): void
    {
        Log::error('Recurring billing failed for service', [
            'service_id'          => $event->service->id,
            'customer_id'         => $event->service->customer_id,
            'article_id'          => $event->service->article_id,
            'instance_identifier' => $event->service->instance_identifier,
            'error'               => DatabaseFailureSanitiser::message($event->exception),
            'context'             => $event->context,
        ]);

        // TODO: Implement alerting mechanisms
        // Examples:
        // - Send email to administrators
        // - Create support ticket
        // - Send Slack/Discord notification
        // - Trigger PagerDuty/OpsGenie alert
        //
        // Example:
        // Mail::to(config('larabill.admin_email'))
        //     ->send(new BillingFailureAlert($event));
        //
        // Slack::send([
        //     'text' => sprintf(
        //         '🚨 Billing failed for %s: %s',
        //         $event->getServiceIdentifier(),
        //         $event->getErrorMessage()
        //     ),
        // ]);
    }
}
