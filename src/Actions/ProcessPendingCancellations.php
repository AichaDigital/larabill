<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Actions;

use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Process pending service cancellations
 *
 * Runs as: Command, Job, or direct call
 */
final class ProcessPendingCancellations
{
    use AsAction;

    public string $commandSignature = 'larabill:process-cancellations';

    public string $commandDescription = 'Process all pending service cancellations';

    /**
     * Handle the action
     *
     * @return array{success: true, cancelled: int}|array{success: false, cancelled: int, error: string}
     */
    public function handle(): array
    {
        $service = new ServiceLifecycleService;

        try {
            $cancelledCount = $service->processPendingCancellations();

            Log::info('Processed pending cancellations', [
                'cancelled_count' => $cancelledCount,
            ]);

            return [
                'success'   => true,
                'cancelled' => $cancelledCount,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process pending cancellations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success'   => false,
                'cancelled' => 0,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute as a command
     */
    public function asCommand(Command $command): void
    {
        $result = $this->handle();

        if ($result['success']) {
            $command->info("✓ Processed {$result['cancelled']} pending cancellation(s)");
        } else {
            $command->error("✗ Failed to process pending cancellations: {$result['error']}");
        }
    }
}
