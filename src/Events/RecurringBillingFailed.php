<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Event fired when a recurring billing attempt fails
 *
 * This event is dispatched when invoice generation fails for a specific service.
 * Use it to:
 * - Alert administrators about billing issues
 * - Create support tickets automatically
 * - Log errors for debugging
 * - Implement retry strategies
 */
final class RecurringBillingFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly ArticleServiceStatus $service,
        public readonly Throwable $exception,
        public readonly array $context = []
    ) {}

    /**
     * Get error message
     */
    public function getErrorMessage(): string
    {
        return $this->exception->getMessage();
    }

    /**
     * Get service identifier for logging
     */
    public function getServiceIdentifier(): string
    {
        return sprintf(
            'Service #%d (Customer: %d, Article: %d)',
            $this->service->id,
            $this->service->customer_id,
            $this->service->article_id
        );
    }
}
