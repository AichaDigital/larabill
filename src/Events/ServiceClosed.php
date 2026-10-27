<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Events;

use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an agreement is closed (ADR-015 §3.2) — immediately or on
 * the materialisation of a scheduled close. Cause is nullable: `closed` +
 * NULL cause is a legal state (the import requirement, ADR-015 §2.2).
 *
 * New code listens to this event. The legacy `ServiceCancelled` shim event
 * was removed with the 7.0 shim layer in 9.0 (ADR-015 Enmienda 2).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ServiceClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ArticleServiceStatus $service,
        public readonly ?AgreementCause $cause = null,
        public readonly ?string $note = null
    ) {}
}
