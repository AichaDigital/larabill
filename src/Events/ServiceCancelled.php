<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a service cancellation is requested
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ServiceCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ArticleServiceStatus $service,
        public readonly CancellationType $type,
        public readonly ?string $reason = null
    ) {}
}
