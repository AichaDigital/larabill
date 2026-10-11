<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticleServiceStatus;
use RuntimeException;

/**
 * An agreement already has a PENDING guarantee operation (ADR-015 §2.6).
 *
 * The registry holds at most ONE pending operation per agreement — enforced
 * by the UNIQUE index on `(agreement_id, pending_slot)` with the
 * nullable-sentinel pattern (`'PENDING'` while pending, NULL once refunded).
 * `GuaranteeService::openOperation()` converts the unique collision into
 * this typed, loud rejection: a duplicate guarantee claim for the same
 * agreement is refused, never absorbed silently.
 *
 * Raised only on a CONCURRENT duplicate request path; the release of the
 * sentinel (`pending_slot` → NULL on refund confirmation) lets a later
 * operation open again without colliding on the index.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class GuaranteeOperationPendingException extends RuntimeException
{
    public static function alreadyPending(ArticleServiceStatus $agreement): self
    {
        return new self(sprintf(
            'Agreement #%s already has a PENDING guarantee operation (ADR-015 §2.6 registry: at most one '
            .'pending operation per agreement). Remediation: settle and confirm (or reject) the existing '
            .'operation before opening a new one for this agreement — the refund confirmation releases the '
            .'pending sentinel.',
            (string) $agreement->getKey(),
        ));
    }
}
