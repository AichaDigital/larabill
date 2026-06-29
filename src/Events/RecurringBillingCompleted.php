<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use Carbon\Carbon;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when recurring billing process completes
 *
 * This event is dispatched after processing all services due for billing.
 * Use it to:
 * - Send summary reports to administrators
 * - Monitor billing process health
 * - Trigger post-processing workflows
 * - Update billing dashboards
 */
final class RecurringBillingCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{processed: int, skipped: int, failed: int, invoices: list<int|string>, errors: list<array<string, mixed>>}  $results
     */
    public function __construct(
        public readonly Carbon $billingDate,
        public readonly array $results,
        public readonly bool $dryRun = false
    ) {}

    /**
     * Check if billing process had any failures
     */
    public function hasFailures(): bool
    {
        return $this->results['failed'] > 0;
    }

    /**
     * Check if billing process was successful
     */
    public function isSuccessful(): bool
    {
        return $this->results['processed'] > 0 && $this->results['failed'] === 0;
    }

    /**
     * Get total services processed (including failures)
     */
    public function getTotalProcessed(): int
    {
        return $this->results['processed'] + $this->results['failed'];
    }
}
