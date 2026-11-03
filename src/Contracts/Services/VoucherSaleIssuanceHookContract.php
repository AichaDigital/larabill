<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Contracts\Services;

use AichaDigital\Larabill\Models\Invoice;

/**
 * Optional issuance hook of the voucher sale (AID-1825 minimal scope §3).
 *
 * When the consumer binds an implementation in the container, `issue()`
 * calls `afterIssue()` for a voucher-sale invoice INSIDE its own transaction,
 * on the same connection, after the invoice is numbered and ISSUED and the
 * sale mark is written, and records `hook_ran` on the claim — so the local
 * fiscal record the hook creates commits with the number or not at all.
 *
 * Contract:
 * - The invoice is ISSUED and numbered. `is_immutable` is NOT promised
 *   either way: the consumer calls `makeImmutable()` first when its adapter
 *   requires it (the lara-verifactu bridge does).
 * - `issue()` retries its transaction up to 3 times on a deadlock: the hook
 *   may run more than once in one call and must be retry-safe (its
 *   same-connection writes roll back with each failed attempt).
 * - Throwing rolls the whole issue back: no number consumed, no mark, no
 *   hook flag. Nothing is half-written.
 * - Not undone by a rollback: logs, events and synchronous listeners except
 *   their same-connection writes, writes on another connection, anything
 *   external. `afterCommit` callbacks run after the root commit; a failure
 *   there leaves the external outcome unknown — the consumer reconciles.
 * - Never called without a binding, never called on a resume after it
 *   committed, never called for an invoice that is not a voucher sale.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
interface VoucherSaleIssuanceHookContract
{
    public function afterIssue(Invoice $invoice): void;
}
