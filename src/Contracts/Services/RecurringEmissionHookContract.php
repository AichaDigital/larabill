<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Contracts\Services;

use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;

/**
 * Recurring Emission Hook Contract
 *
 * Consumer integration point INSIDE the recurring emission boundary
 * (AID-836). Bind an implementation in the consumer app to run fiscal
 * registration (e.g. InvoiceVerifactuService::registerInvoice()), OSS
 * accumulation, or any other bookkeeping that must succeed-or-reject
 * together with the emission itself. A post-commit listener on
 * RecurringInvoiceGenerated arrives too late for that: the fiscal number
 * is already consumed and the service already advanced.
 *
 * Contract clauses — the hook runs inside a retried DB transaction:
 *
 * - Database writes ONLY, on the SAME connection that opened the boundary
 *   (larabill's default connection). The total-rollback guarantee covers
 *   the ambient transaction alone: a model with its own $connection, or any
 *   independent transaction/commit opened inside the hook, survives the
 *   rollback. Do not open independent transactions or commits here.
 * - No externally observable side effects: no network calls, no mail, no
 *   queued jobs unless dispatched with afterCommit(). Logging is tolerated,
 *   with the caveat that under a retry or rollback it may duplicate lines
 *   or describe work that was ultimately reverted.
 * - The whole boundary may re-run: DB::transaction(..., 3) retries on
 *   deadlock, re-executing the closure (and this hook) in full.
 * - The invoice arrives SEALED (is_immutable = true). On the invoice
 *   itself only the fiscal_verification_* field set is writable (the
 *   Invoice::update() guard window); everything else belongs in the
 *   consumer's own tables.
 * - Throwing rejects the emission completely: no invoice, no consumed
 *   fiscal number, no next_billing_date advance. The service is counted
 *   as failed for that run.
 *
 * The hook is invoked only when an invoice was actually CREATED in this
 * run — never on the idempotent path (invoice already existed) and never
 * on skipped services.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
interface RecurringEmissionHookContract
{
    /**
     * Called inside the emission boundary, after the invoice is sealed and
     * the service's next_billing_date has advanced, before commit.
     */
    public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void;
}
