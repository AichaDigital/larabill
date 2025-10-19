<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Actions;

use AichaDigital\Larabill\Services\RecurringBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Process Recurring Billing Action
 *
 * Generates invoices for services due for billing.
 * Can be executed as: Command, Job, or Direct call.
 *
 * Command usage:
 *   php artisan larabill:process-recurring
 *   php artisan larabill:process-recurring --date="2024-01-15"
 *   php artisan larabill:process-recurring --dry-run
 *
 * Job usage:
 *   ProcessRecurringBilling::dispatch();
 *   ProcessRecurringBilling::dispatch(now()->addDay());
 *
 * Direct call:
 *   ProcessRecurringBilling::run();
 *   ProcessRecurringBilling::run(now(), true); // dry-run
 */
final class ProcessRecurringBilling
{
    use AsAction;

    // Command configuration
    public string $commandSignature = 'larabill:process-recurring {--date=} {--dry-run}';

    public string $commandDescription = 'Process recurring billing for services due';

    // Job configuration (from config)
    public string $jobConnection = '';

    public string $jobQueue = '';

    public int $jobTries = 3;

    public int $jobTimeout = 300;

    public function __construct(
        protected RecurringBillingService $billingService
    ) {
        // Configure job queue from config
        $this->jobConnection = config('larabill.queue.connection') ?? config('queue.default');
        $this->jobQueue      = config('larabill.queue.name', 'default');
        $this->jobTries      = config('larabill.queue.tries', 3);
        $this->jobTimeout    = config('larabill.queue.timeout', 300);
    }

    /**
     * Core logic - executed in any context
     *
     * @param  Carbon|null  $date  Processing date (defaults to now)
     * @param  bool  $dryRun  If true, simulates without creating invoices
     * @return array{processed: int, skipped: int, failed: int, invoices: array, errors: array}
     */
    public function handle(?Carbon $date = null, bool $dryRun = false): array
    {
        $date = $date ?? now();

        return $this->billingService->processRecurringBilling($date, $dryRun);
    }

    /**
     * As Artisan Command
     *
     * Usage:
     *   php artisan larabill:process-recurring
     *   php artisan larabill:process-recurring --date="2024-01-15"
     *   php artisan larabill:process-recurring --dry-run
     */
    public function asCommand(Command $command): int
    {
        $date = $command->option('date')
            ? Carbon::parse($command->option('date'))
            : null;

        $dryRun = (bool) $command->option('dry-run');

        $command->info('🔄 Processing recurring billing...');

        if ($dryRun) {
            $command->warn('⚠️  DRY RUN MODE - No invoices will be created');
        }

        $results = $this->handle($date, $dryRun);

        $command->newLine();
        $command->info("✅ Services processed: {$results['processed']}");
        $command->info('📄 Invoices created: '.count($results['invoices']));

        if ($results['failed'] > 0) {
            $command->error("❌ Failed: {$results['failed']}");
            $command->table(
                ['Service ID', 'Customer', 'Error'],
                collect($results['errors'])->map(fn ($e) => [
                    $e['service_id'],
                    $e['customer_id'] ?? 'N/A',
                    $e['error'],
                ])
            );
        }

        if ($results['skipped'] > 0) {
            $command->warn("⏭️  Skipped: {$results['skipped']} (not due yet)");
        }

        return $results['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * As Job (queued execution)
     *
     * Usage:
     *   ProcessRecurringBilling::dispatch();
     *   ProcessRecurringBilling::dispatch(now()->addDay());
     */
    public function asJob(?Carbon $date = null): void
    {
        $this->handle($date, false);
    }
}
