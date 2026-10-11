<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a SUSPENDED agreement is reinstated back to active through
 * the §3.2 API (ADR-015 §3.2: the API dispatches `ServiceClosed` /
 * `ServiceSuspended` / `ServiceReinstated`). The provisioning edge
 * `pending → active` dispatches only the pre-existing `ServiceActivated`.
 *
 * The reinstatement clears the suspension cause — the narrative stays in the
 * suspension metadata. Existing `ServiceActivated` listeners keep working:
 * this event is dispatched IN ADDITION, not instead.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ServiceReinstated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ArticleServiceStatus $service
    ) {}
}
