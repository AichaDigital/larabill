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
 * Legacy cancellation event — DEPRECATED compatibility shim (ADR-015 §5):
 * the `cancel()` shims still dispatch it (including deferred materialisation
 * through the shim path) so existing listeners keep working.
 *
 * @deprecated since 7.0.0, listen to `ServiceClosed` / `ServiceSuspended` — see UPGRADE-7.0.md
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
