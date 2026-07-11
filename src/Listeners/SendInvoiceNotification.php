<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Listeners;

use AichaDigital\Larabill\Events\RecurringInvoiceGenerated;
use Illuminate\Support\Facades\Log;

/**
 * Listener for RecurringInvoiceGenerated event
 *
 * Handles post-invoice generation tasks like:
 * - Sending customer notifications
 * - Logging invoice creation
 * - Triggering external integrations
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
final class SendInvoiceNotification
{
    /**
     * Handle the event
     */
    public function handle(RecurringInvoiceGenerated $event): void
    {
        // Check if notifications are enabled
        if (! config('larabill.recurring_billing.send_notifications', true)) {
            return;
        }

        Log::info('Recurring invoice generated', [
            'invoice_id'  => $event->invoice->id,
            'service_id'  => $event->service->id,
            'customer_id' => $event->service->customer_id,
            'amount'      => $event->invoice->total,
        ]);

        // TODO: Implement actual notification dispatch
        // This could be:
        // - Email notification to customer
        // - Push notification
        // - Webhook to external systems
        // - SMS notification
        //
        // Example:
        // Mail::to($event->service->customer)->send(
        //     new InvoiceGeneratedMail($event->invoice)
        // );
    }
}
