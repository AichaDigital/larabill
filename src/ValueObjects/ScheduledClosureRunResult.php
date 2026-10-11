<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

/**
 * Result of one run of the scheduled-closure materialiser
 * (`ServiceLifecycleService::processScheduledClosures()`).
 *
 * - `materialised` — agreements actually materialised (closed) BY THIS RUN.
 *   Rows already closed by another process, or deleted, are skipped and NOT
 *   counted.
 * - `failedServiceIds` — agreements whose materialisation THREW this run.
 *   They remain open for the next run; the caller can distinguish "nothing
 *   was due" (`materialised === 0`, no failures) from "everything failed"
 *   (`materialised === 0`, failures present).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class ScheduledClosureRunResult
{
    /**
     * @param  array<int, int|string>  $failedServiceIds  The ids of the
     *                                                    agreements whose materialisation threw this run (key type per
     *                                                    the `ArticleServiceStatus` id).
     */
    public function __construct(
        public readonly int $materialised,
        public readonly array $failedServiceIds,
    ) {}
}
