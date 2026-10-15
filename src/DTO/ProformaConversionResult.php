<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DTO;

use AichaDigital\Larabill\Models\Invoice;

/**
 * Typed result of `convertProformaToInvoice()` / `convertAndIssue()` (ADR-016 §9).
 *
 * The old `Invoice|array{invoice: Invoice, warnings: array<string>}` union die
 * with PR-2: a command in, a TYPED result out. The `warnings` channel (the
 * `on_changes: warn` fiscal-change summaries) moves here — the caller reads
 * `$result->warnings` instead of type-testing the return value.
 *
 * `invoice` is the PREPARED conversion product (or the idempotent re-read of
 * it, or the ISSUED document when reached through `convertAndIssue()`).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class ProformaConversionResult
{
    /**
     * @param  list<string>  $warnings  non-critical fiscal-change summaries
     *                                  (ADR-001, `on_changes: warn`); empty
     *                                  on a clean conversion
     */
    public function __construct(
        public Invoice $invoice,
        public array $warnings = [],
    ) {}
}
