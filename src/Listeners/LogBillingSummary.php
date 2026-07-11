<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Listeners;

use AichaDigital\Larabill\Events\RecurringBillingCompleted;
use Illuminate\Support\Facades\Log;

/**
 * Listener for RecurringBillingCompleted event
 *
 * Handles post-billing tasks like:
 * - Sending summary reports to administrators
 * - Monitoring billing process health
 * - Triggering post-processing workflows
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
final class LogBillingSummary
{
    /**
     * Handle the event
     */
    public function handle(RecurringBillingCompleted $event): void
    {
        $logLevel = $event->hasFailures() ? 'warning' : 'info';

        Log::log($logLevel, 'Recurring billing completed', [
            'date'      => $event->billingDate->toDateString(),
            'dry_run'   => $event->dryRun,
            'processed' => $event->results['processed'],
            'skipped'   => $event->results['skipped'],
            'failed'    => $event->results['failed'],
            'invoices'  => count($event->results['invoices']),
        ]);

        // If there were errors, log them separately
        if ($event->hasFailures()) {
            foreach ($event->results['errors'] as $error) {
                Log::error('Recurring billing error', [
                    'service_id'  => $error['service_id']  ?? null,
                    'customer_id' => $error['customer_id'] ?? null,
                    'article_id'  => $error['article_id']  ?? null,
                    'error'       => $error['error']       ?? 'Unknown error',
                ]);
            }
        }

        // TODO: Implement summary email to administrators
        // if ($event->isSuccessful() || $event->hasFailures()) {
        //     Mail::to(config('larabill.admin_email'))
        //         ->send(new BillingSummaryMail($event));
        // }
    }
}
