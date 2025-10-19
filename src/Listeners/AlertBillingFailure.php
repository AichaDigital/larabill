<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Listeners;

use AichaDigital\Larabill\Events\RecurringBillingFailed;
use Illuminate\Support\Facades\Log;

/**
 * Listener for RecurringBillingFailed event
 *
 * Handles billing failures:
 * - Alerting administrators
 * - Creating support tickets
 * - Implementing retry strategies
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
            'error'               => $event->getErrorMessage(),
            'context'             => $event->context,
            'trace'               => $event->exception->getTraceAsString(),
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
