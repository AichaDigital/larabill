# Upgrade guide — larabill 8.0 (ADR-016, invoice numbering lifecycle)

> **PR-1 (schema foundations + transitional coherence) and PR-2 (the lifecycle,
> AID-1627) are both landed in this delivery; PR-3 (tag) follows.** PR-1 shipped
> the document axis, the enforcer and the transitional stamping; **PR-2 rewired
> the lifecycle — the transitional stamping is DEAD**: fiscal births are
> unnumbered DRAFTs and the correlative moves into `issue()`. Every behavioral
> note below is also in `CHANGELOG.md` under `[Unreleased]`.

## What PR-1 ships

- **New document axis** on `invoices`: `invoice_document_status`
  (`InvoiceDocumentStatus`: DRAFT / PREPARED / ISSUED / CANCELLED) and
  `fiscal_submission_status` (`FiscalSubmissionStatus`: NOT_REQUIRED / PENDING /
  REGISTERED / ACTION_REQUIRED / CONTENT_REJECTED / LEGACY_UNKNOWN), plus the
  cancellation audit pair `document_cancelled_at` / `document_cancelled_by`.
  All four columns are NULLABLE. The axis exists only for FISCAL rows;
  proforma rows stay NULL on every lifecycle column (spec §3.1, §8.2).
- **The five number/issuance fields become NULLABLE** (`fiscal_number`,
  `series_number`, `fiscal_year`, `invoice_date`, `issued_at`) and
  `issued_at` **loses its `DEFAULT CURRENT_TIMESTAMP`**: an unissued row can
  no longer falsify an issuance instant.
- **The §8.2 coherence clause set, enforced twice:** a named MySQL CHECK
  (`larabill_invoice_document_axis_coherence`, migration 051, added only after
  the backfill healed the data) plus an application enforcer (the `Invoice`
  `saving` guard) that evaluates the same NULL-safe clauses on EVERY engine —
  it is the only enforcement on SQLite. A malformed shape now throws
  `InvoiceDocumentCoherenceException` on save.
- **Backfill of the legacy population** (migration 051, one transaction).

## The upgrade ritual

1. **Stop the workers and put the site in maintenance.** The recurring-emission
   cron is explicitly named here: disable it before `php artisan migrate`
   (its next run would otherwise birth numbered rows under the transitional
   stamping while the chain is mid-flight — harmless for data, but the ritual
   keeps the upgrade window clean and the classification report honest).
2. `composer update aichadigital/larabill`
3. `php artisan larabill:install` — idempotent; it publishes only the THREE
   new migrations (049 preflight, 050 schema, 051 backfill) in order.
4. `php artisan migrate`

**`migrate:fresh` is never part of the path** (AID-398: stable releases respect
persisted data).

### What the preflight reports and when it aborts

Migration 049 is **read-only**. It prints, per population:

- numbered fiscal rows with legacy `status = CANCELLED` — classified ISSUED by
  the backfill (the number WAS consumed); the collection status is kept;
- numbered fiscal rows with legacy `status = DRAFT` — the "numbered drafts"
  population; classified ISSUED;
- numbered fiscal rows with legacy `status = PENDING` — classified ISSUED and
  their collection status remapped to `DRAFT` (PENDING encoded the collapsed
  conversion/issuance lifecycle, which dies with 8.0).

It **fails loud** (before any DDL) when it finds:

- any **unnumbered fiscal row** (spec §8.3 case 3 — a mutable unnumbered row
  has no legacy producer; investigate the rows before upgrading);
- any row whose `serie` is outside the `InvoiceSerieType` domain (0..3) —
  nothing the backfill can heal, and the CHECK could not be added over it
  (round 6, V11).

### The backfill (migration 051)

One transaction, **idempotent by construction** — it re-classifies from the
observable state (only rows still carrying a NULL document axis are
legacy-shaped), so a partially-applied failure re-runs cleanly:

- every numbered fiscal row → `invoice_document_status = ISSUED`,
  `fiscal_submission_status = LEGACY_UNKNOWN`;
- legacy `status = PENDING` fiscal rows → collection status `DRAFT`;
- proforma rows (`serie = PROFORMA`) are untouched on every axis;
- no cancellation metadata is written by the backfill, ever.

Every DDL operation of migration 050 is introspection-guarded
(`hasColumn`/information_schema), so the chain is resumable at any point.

## What changes for consumers (PR-2 — the lifecycle)

### The old→new call-site map

- **`createInvoice()` (fiscal types, direct emission)** — **shape kept, NOT
  behavior-compatible (§7):** one call still returns an issued document on the
  happy path, but it now runs TWO transactions: phase 1 commits a durable
  UNNUMBERED DRAFT (no correlative, five fields NULL, document axis DRAFT),
  phase 2 is `issue()`. Old: the correlative burned BEFORE the row existed, so
  a later failure left a burned number and no row. New: a phase-2 failure
  leaves a durable unnumbered DRAFT — call `issue($invoice)` to resume it, or
  cancel it through the consumer path.
- **`createInvoice(status: 'draft')`** — now means it: the call returns an
  UNNUMBERED DRAFT and phase 2 does not run (the AID-1628 seam). Old: a
  numbered draft (the defect this major exists to remove).
- **`createInvoice(status: SENT/PAID/OVERDUE)`** — still accepted: the
  document is issued first, then the collection axis is stamped post-issuance.
- **`createInvoice(status: PENDING/CONVERTED/CANCELLED/CONVERTING)`** — now
  REJECTED with `InvalidInvoiceStatusException` (§7.2, V14/V15): lifecycle
  outcomes are never birth inputs — in both string and integer form. A caller
  wanting a cancelled document creates the DRAFT and cancels it.
- **`createProforma()` and every `createInvoice(type: 'proforma')` call** —
  UNCHANGED verbatim (§3.1, round 11 V16): numbered at birth on the PRO serie,
  document axis NULL. The WHMCS zero-invoice import route depends on it.
- **`convertProformaToInvoice()`** — **the breaking change of this major
  (§9):** it NO LONGER issues and NO LONGER returns `Invoice|array`. It returns
  a `ProformaConversionResult` (`$result->invoice`, `$result->warnings`) whose
  document is PREPARED: verbatim clone retained, `prefix` stamped, NO
  correlative consumed. Old: numbered, dated, immutable, correlative consumed.
  The caller decides: `issue()`, `convertAndIssue()`, or leave it prepared.
  The proforma now reads `CONVERTING` + immutable at conversion; `CONVERTED`
  arrives at `issue()` (§6 step 8).
- **Consumers reading the conversion result** — `$result =
  $service->convertProformaToInvoice($proforma); $invoice = $result->invoice;`
  and the `on_changes: warn` summaries moved to `$result->warnings`.
- **One-shot converters** — replace
  `$invoice = $service->convertProformaToInvoice($p)->invoice` followed by
  manual numbering expectations with `$service->convertAndIssue($p, $options)`:
  two resumable transactions, same end state, never a second invoice (§11).
- **Recurring emissions and settlement artifacts** (`RecurringBillingService`,
  `SettlementService::rectify`/`settleUnconsumed`) — result-compatible: still
  born `SENT` + sealed on the happy path, now through the two-phase contract
  internally.
- **`InvoiceNumberingService::generateNumber()`** — direct callers keep their
  atomicity contract (AID-390). New optional trailing instant parameter
  (additive). `issue()` uses the new `@internal generateNumberInTransaction()`
  inside its own transaction instead.
- **Hand-written fiscal rows** — a consumer creating fiscal rows through
  `Invoice::create` must produce one of the coherent §8.2 shapes: an unnumbered
  fiscal row carries `invoice_document_status = DRAFT` with the five fields
  NULL (the PR-1 `ISSUED`-at-birth transitional shape is gone); an issued one
  carries all five populated. The enforcer still rejects incoherent shapes on
  save, and rows loaded from the database are re-checked against their
  PERSISTED shape.
- **Deleting documents** — `delete()` on a row whose document status is
  `ISSUED` or `PREPARED` now throws (§6.5): the §1.1.2 deletion hole is closed.
  `DRAFT` and `CANCELLED` rows stay deletable. Bulk/query-builder and external
  SQL remain documented outside the guarantee (v7 §10 limit, verbatim).
- **Editing issued documents** — fiscal content on a protected row (persisted
  `is_immutable` OR `ISSUED`) cannot be changed through ANY public operation
  any more, including the attribute-assignment + `save()` bypass (D8).
  Collection state (`status`, `paid_at`) remains writable through the named
  grouped-payment paths; fiscal-verification fields and conversion mirror
  fields keep their deliberate paths. Corollary: `regenerateEncryptedSnapshots()`
  on an ISSUED row now throws.
- **Lines of issued documents** — `InvoiceItem` creation/update/deletion on a
  protected parent throws (§6.5, v7 §7.2). Test/fixture writes that materialize
  the legacy shape go through the documented outside-the-guarantee surface
  (`withoutEvents`/`saveQuietly`).

### New @api surface (§11.1)

- `InvoiceService::issue(Invoice $invoice): Invoice`
- `InvoiceService::cancelPreparedInvoice(Invoice $invoice, string $actor): Invoice`
  — direct DRAFT cancellation keeps the consumer path (legacy `CANCELLED` +
  document `CANCELLED` with `document_cancelled_at` stamped, actor NULL).
- `InvoiceService::convertAndIssue(Invoice $proforma, array $options = []): ProformaConversionResult`
- `Invoice::scopeStalledPreparations()` + `larabill:stalled-preparations`
  (`--json` available)
- `ProformaConversionResult` (DTO), `InvoiceNotIssuableException`,
  `InvoiceNotCancellableException`, `InvoiceStatus::CONVERTING` (value 7)
- `SettlementService::settleUnconsumed(..., ?string $settlementOperationKey = null)`
  — additive; keyed routes gain the adoption-resume promise (a durable
  unnumbered DRAFT carrying the key is resumed by `issue()`, never minted
  twice). **`settleUnconsumed()` without a key carries NO resume promise
  (round 2 B3, verbatim):** a phase-2 failure there leaves a draft no retry can
  deterministically find, and a retry mints a second artifact.

## Retry / durability scoping (§7.1/§6, rounds 1-2 V2/B2)

The durable-phase promise and the whole-unit deadlock retry hold ONLY when the
entry point runs as the OUTERMOST transaction. Laravel nests transactions as
savepoints: inside a caller-owned transaction, the inner commit does not COMMIT
and Laravel raises `DeadlockException` instead of retrying — the caller owns
its unit. Per route:

- **Direct/consumer calls of `createInvoice()`** — outermost: phase-1 durable,
  whole-unit retry owned here.
- **`SettlementService::rectify()` / `settleUnconsumed()`** — outermost: same
  scoping.
- **`RecurringBillingService` emissions** — calls `createInvoice()` INSIDE its
  own outer transaction on purpose: all-or-nothing (service status + invoice in
  one unit, the v6.10 contract). A phase-2 failure rolls the draft back with
  the emission — result-compatible, NEVER durable.
- **`issue()` / `cancelPreparedInvoice()` / `convertAndIssue()`** — outermost:
  they own the whole-unit retry. Nested: the caller owns it.
- **`convertAndIssue()` failure modes** — conversion failure leaves nothing;
  issuance failure leaves `CONVERTING` + `PREPARED` (legal, resumable — the
  stalled-preparations report lists them); failure after the issuance commit
  leaves `ISSUED` with the submission axis pending.

## What changed for consumers (PR-1 — schema foundations)

- **The document axis and the coherence clause set** ship in PR-1's migrations
  (049 preflight, 050 schema, 051 backfill) and are ENFORCED twice: the MySQL
  CHECK and the application enforcer on every engine.

## Issuance identity re-check and the `force` option (implementation-gate adjudication, 2026-10-13)

`issue()` re-runs the fiscal-change identity check (ADR-001) and blocks on CRITICAL changes — by design, per ADR-016 §6 step 3 and the v7 §5.6 matrix (identity is validated AGAIN at issuance, the moment the correlative is consumed). This is independent of the conversion-side `force`/`on_changes` options: `force` authorizes the CONVERSION despite critical changes, but does NOT authorize the ISSUANCE. A forced conversion whose critical change remains unresolved yields a PREPARED document that cannot issue — the remedies are `cancelPreparedInvoice()` (after resolving or superseding the change) or resolving the identity and issuing. Attempting `issue()` on it throws `FiscalConfigChangedException` naming the changes.

## Direct durable DRAFTs — visibility limit (implementation-gate adjudication, 2026-10-13)

The `larabill:stalled-preparations` report covers conversion-born PREPARED aggregates (spec §11.3 / v7 Codex 58). A DIRECT invoice whose phase-2 issuance failed leaves a durable unnumbered DRAFT that is the CALLER's responsibility: it is resumable with `issue()` (or cancellable), but it is not distinguished in the report from an intentionally-kept draft (both are plain DRAFT rows). If your integration creates direct invoices, keep your own handle on the phase-2 outcome; the submission-axis work in R2 (AID-1629) is the natural place for a persisted attempt marker if operators need one.

## Nested-issuance retry ownership (implementation-gate round 2)

`issue()` owns the whole-unit deadlock retry only when it runs as the outermost transaction. On the keyed resume paths (`SettlementService::adoptOrResumeByKey()` inside `rectify()`, and `GuaranteeService::completeRegistry()`), `issue()` runs INSIDE the caller's ambient transaction: Laravel throws `DeadlockException` at transaction level > 1 instead of retrying, so the CALLER owns the retry. The keyed DRAFT survives any such transient failure — a later `settle()`/`rectify()` retry resumes it; no artifact is ever lost, only the completion attempt.
