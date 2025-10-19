<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use AichaDigital\Larabill\Models\{ArticleServiceStatus, Invoice};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a recurring invoice is generated
 *
 * This event is dispatched after successfully creating an invoice
 * from a recurring service. Use it to:
 * - Send invoice notifications to customers
 * - Trigger external integrations (accounting systems)
 * - Log invoice creation for monitoring
 * - Update external CRM systems
 */
final class RecurringInvoiceGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly ArticleServiceStatus $service
    ) {}
}
