<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a service is suspended (ADR-015 §3.2 close/suspend API).
 * The cause is the suspension-vocabulary row recorded on the agreement; it
 * is optional for listeners written before ADR-015 and always set on
 * suspensions raised through the API.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ServiceSuspended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ArticleServiceStatus $service,
        public readonly ?string $reason = null,
        public readonly ?AgreementCause $cause = null
    ) {}
}
