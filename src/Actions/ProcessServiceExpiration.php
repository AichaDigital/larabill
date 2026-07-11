<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Actions;

use AichaDigital\Larabill\Services\ServiceLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Process expired services
 *
 * Runs as: Command, Job, or direct call
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ProcessServiceExpiration
{
    use AsAction;

    public string $commandSignature = 'larabill:process-expired';

    public string $commandDescription = 'Process all services that have expired';

    /**
     * Handle the action
     *
     * @return array{success: true, expired: int}|array{success: false, expired: int, error: string}
     */
    public function handle(): array
    {
        $service = new ServiceLifecycleService;

        try {
            $expiredCount = $service->processExpiredServices();

            Log::info('Processed expired services', [
                'expired_count' => $expiredCount,
            ]);

            return [
                'success' => true,
                'expired' => $expiredCount,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process expired services', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'expired' => 0,
                'error'   => $e->getMessage(),
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
            $command->info("✓ Processed {$result['expired']} expired service(s)");
        } else {
            $command->error("✗ Failed to process expired services: {$result['error']}");
        }
    }
}
