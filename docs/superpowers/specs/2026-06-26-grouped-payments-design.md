# AID-30 — Grouped Payments (pago agrupado) — Design Spec

> Status: Approved (brainstorm 2026-06-26) · Scope: larabill v1 of grouped payments
> Issue: AID-30 "Pagos agrupados"

## Context

larabill has **no payment entity** today. "Paid" is binary: `Invoice.status = PAID`
+ `paid_at` timestamp, set in passing by `BillingService`/`InvoiceService`. There is
no record of *a payment*, no reference, no partials, no reversal, no `markAsPaid()`.

AID-30 introduces a **grouped payment**: an accounting record of a single external
collection (one bank transfer, one cash receipt) that settles **several already-issued
invoices** at once, with strong idempotency and a clean audit trail, so accounting can
tell which invoices each payment covered.

This is the **accounting** half. The **fiscal** half — consolidating N proformas into a
single issued invoice ("factura agrupada") — is a separate, larger concern (it touches
numbering + VeriFACTU) and is tracked as its own issue, **out of scope here**.

## Scope

**In:**
- A `GroupedPayment` entity + `grouped_payment_invoice` pivot.
- `register()` a payment that settles a set of issued invoices (atomic, idempotent,
  strongly validated).
- `reverse()` a payment in full (restores the invoices to their exact prior state).
- Strong concurrency control against double-paying an invoice.

**Out (deferred / explicit non-goals):**
- Partial payments (one invoice settled by several payments, or a payment split across
  invoices). The pivot keeps an `applied_amount` column so this is *possible* later, but
  v1 always settles each invoice in full.
- Payment methods / gateways / bank reconciliation.
- Per-invoice or per-customer running balance.
- Settling **proformas** (a proforma is not fiscal debt — rejected in v1).
- Multi-currency or multi-customer within one payment (rejected in v1).
- Partial "unmount" (removing one invoice from a live payment). Changing the set =
  reverse + create a new one.

## Domain rules

A grouped payment is **immutable** once `posted`. Its lifecycle is **`posted → reversed`**
(no pending/failed — larabill records collections, it does not process them). To change
which invoices a payment covers: reverse it and create a new one.

### Eligibility (every failure → a clear domain exception)
1. Invoice list is non-empty.
2. **No duplicate invoice IDs** in the list (a repeated id is a caller bug →
   `GroupedPaymentValidationException::duplicateInvoices()`; never silently dedupe).
3. All invoices belong to the same `billable_user_id`.
4. The payment `currency` matches each invoice's **effective fiscal currency**
   (`invoice.companyFiscalConfig.currency`); any mismatch →
   `GroupedPaymentValidationException::currencyMismatch()` (D3 — validated against the
   issuer's fiscal config, not assumed from the payer). Invoices with no resolvable
   `companyFiscalConfig` currency are not currency-checked (documented gap).
5. No invoice is a **proforma** (`InvoiceSerieType::PROFORMA`).
6. Every invoice status ∈ {`SENT`, `OVERDUE`, `PENDING`} — not draft/cancelled/converted/paid.
7. No invoice is already covered by an **active** (`posted`) grouped payment.
8. `amount == Σ invoice totals` **exactly** (FixedDecimal comparison).

### Idempotency
- `register()` accepts an optional `idempotencyKey`. Recommended: caller provides it.
- If absent, derive a deterministic key from `billable_user_id + sorted(invoice_ids) +
  currency + amount` (**not** `paid_at` — avoids false "splits" of the same logical
  collection across timestamps).
- The lookup short-circuits **only an active (`posted`) payment**. If a key maps to a
  `posted` payment whose payload (`billable_user_id` + invoice set + `amount` + `currency`)
  **matches**, return it (no duplicate, no error). If it maps to a `posted` payment whose
  payload **differs** → `IdempotencyConflictException` (same token, different request),
  never silently settle a different set.
- **Re-pay after reverse requires a fresh key (D2).** A key that maps to a **`reversed`**
  payment is **spent**: re-collecting the same invoice set is a *new* operational event (a
  real re-payment is a different bank transfer with its own reference), so the caller MUST
  pass a new `idempotencyKey`. Reusing the spent/derived key → `IdempotencyConflictException`
  with an actionable message. The derived key (which excludes `paid_at`/`reference`) is
  therefore only safe for retrying the *same live* collection, never for a post-reversal redo.
- **`reference` is NOT part of payment identity** nor the equivalence check — descriptive
  metadata. A retry of a live payment with the same key/payload but a different `reference`
  returns the existing payment **unchanged** (first-write-wins), never a conflict.
- **Concurrency backstop:** the unique `idempotency_key` closes the register race. Two
  identical concurrent registers: the loser's INSERT raises a unique-violation
  `QueryException`; catch it, re-read the now-committed row, and return it (payload matches)
  or raise `IdempotencyConflictException` (payload differs) — never surface a raw
  `QueryException`.
- `reverse()` is idempotent: reversing an already-`reversed` payment is a **no-op that
  returns the payment**, never a double-restore and never an error. The no-op check runs
  **inside the transaction with `lockForUpdate` on the payment** (re-read status) so two
  concurrent reversals can't both write; the first reversal is the immutable audit truth —
  `reversed_at`/`reversed_by`/`reverse_reason` are never overwritten.

### Concurrency / double-pay (defense in depth)
- **Primary:** `register()` runs in a transaction and takes `lockForUpdate` on the target
  invoices **in a deterministic order (sorted by `invoice_id`)** before validating + writing.
  The ordered lock prevents deadlocks between concurrent transactions whose invoice lists
  overlap (T1 `[A,B]` vs T2 `[B,A]`); both acquire in the same order.
- **DB backstop (MySQL-safe):** the pivot carries `active_invoice_id` = `invoice_id` while
  the payment is `posted`, set to **NULL** when the payment is reversed. A `unique` index on
  `active_invoice_id` allows many NULLs (reversed history kept intact) but only **one active
  payment per invoice**. This also permits re-paying an invoice after a reversal.

## Data model

### Table `grouped_payments` (UUID v7 via `HasUuid`)
| Column | Type | Notes |
|---|---|---|
| `id` | char(36) | UUID v7 |
| `billable_user_id` | char(36) | FK users via `MigrationHelper::userIdColumn()` — the payer |
| `amount` | integer | cast `FixedDecimalCast:2` (base-100), coherent with AID-237/246 |
| `currency` | char(3) | validated against each invoice's `companyFiscalConfig.currency` (D3) |
| `paid_at` | datetime | date the collection happened |
| `reference` | string nullable | bank / accounting reference |
| `idempotency_key` | string **unique** | provided or derived |
| `status` | tinyInteger | enum `GroupedPaymentStatus` {POSTED=0, REVERSED=1} (int-backed, mirrors `InvoiceStatus`) |
| `reversed_at` | datetime nullable | |
| `reversed_by` | char(36) nullable | actor id via `MigrationHelper::userIdColumn($table, 'reversed_by', nullable: true)` — emits char(36) + index, **no hard FK** (may be a system actor) |
| `reverse_reason` | string nullable | |
| `notes` | text nullable | |
| timestamps | | |

Indexes: `idempotency_key` unique; `billable_user_id`; `status`; `paid_at`.

### Table `grouped_payment_invoice` (pivot)
| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | internal pivot PK (not domain-exposed) |
| `grouped_payment_id` | char(36) | FK `grouped_payments` (cascade on delete) |
| `invoice_id` | char(36) | FK `invoices` |
| `applied_amount` | integer | cast `FixedDecimalCast:2`; v1 = invoice total (field ready for future partials) |
| `previous_status` | tinyInteger | `InvoiceStatus` before marking PAID (for exact restore on reverse) |
| `previous_paid_at` | datetime nullable | invoice `paid_at` before (exact restore) |
| `active_invoice_id` | char(36) nullable | = `invoice_id` while posted, NULL when reversed |
| timestamps | | |

Indexes: `unique(grouped_payment_id, invoice_id)` (exactly one pivot row per invoice per
payment — the relation-uniqueness guard against a duplicated id slipping through);
`unique(active_invoice_id)` (the one-active-payment-per-invoice backstop); `grouped_payment_id`;
`invoice_id`.

## Surface — `GroupedPaymentService`

larabill is framework-agnostic (no HTTP layer). The surface is a service (PHP methods).

```
register(
    string $billableUserId,
    array $invoiceIds,           // UUIDs
    DateTimeInterface $paidAt,
    FixedDecimal $amount,
    string $currency,
    ?string $reference = null,
    ?string $idempotencyKey = null,
): GroupedPayment
```
Flow: resolve/derive idempotency key → if a **posted** payment with that key exists, return it
(a **reversed** one → spent key → `IdempotencyConflictException`, D2) → else open transaction,
`lockForUpdate` the invoices (ordered), run all eligibility validations, create the `posted`
`GroupedPayment` + pivot rows (with `previous_status`/`previous_paid_at` captured and
`active_invoice_id = invoice_id`), set each invoice PAID via
`Invoice::markAsPaidViaGroupedPayment($paidAt)` (D1 — a dedicated collection-state method that
works on immutable invoices), commit. The create wraps a unique-violation `QueryException`
catch (D2 concurrency backstop).

```
reverse(
    GroupedPayment $payment,
    string $reason,
    ?string $reversedBy = null,
): GroupedPayment
```
Flow: open transaction, re-read + `lockForUpdate` the payment; if already `reversed`, no-op
return (audit fields untouched) → else set `status = reversed` + `reversed_at`/`reversed_by`/
`reverse_reason`, for each pivot row restore the invoice via
`Invoice::restoreStateViaGroupedPaymentReversal($previousStatus, $previousPaidAt)` and set the
pivot `active_invoice_id = NULL`, commit. Rows are kept for audit.

### Models
- `GroupedPayment` (`HasUuid`): `belongsToMany(Invoice)` through the pivot; `belongsTo` payer;
  casts `amount` → `FixedDecimal:2`, `status` → `GroupedPaymentStatus`.
- `Invoice`: add inverse `groupedPayments()` relation (traceability) **and two dedicated
  collection-state methods (D1)**: `markAsPaidViaGroupedPayment(DateTimeInterface $paidAt)` and
  `restoreStateViaGroupedPaymentReversal(InvoiceStatus $status, ?DateTimeInterface $paidAt)`.
  Both set `status` + `paid_at` via `save()`, bypassing the `update()` immutability guard **on
  purpose**: immutability protects fiscal *content* (amounts, dates, snapshots), not the
  *collection state*. The existing `update()` guard and its test are untouched.
- `GroupedPaymentStatus` enum (int-backed) + `GroupedPaymentFactory` (default `billable_user_id`
  is a generated UUID — the column is not null).
- Domain exceptions under `src/Exceptions/`: `GroupedPaymentValidationException` (named
  constructors per failure: empty list, duplicate invoices, mixed users, **currency mismatch**,
  proforma, not-payable status, already-paid, amount mismatch, invoices not found) +
  `IdempotencyConflictException`.

## Migrations (repo contract)

Two new tables → **two `.php` + two byte-identical `.php.stub`** (`bin/sync-migration-stubs`)
+ **two new entries in `$migrationOrder`** of `LarabillInstallCommand`, validated by
`MigrationOrderConsistencyTest`. Order: `grouped_payments` then `grouped_payment_invoice`
(FK), both after `invoices` and the users table. User-keyed columns via
`MigrationHelper::userIdColumn()`: `billable_user_id` (not null) and `reversed_by`
(nullable) — both emit char(36) + an index, no hard FK.

## Testing (TDD, Pest)

- **Happy path**: register settles N invoices → all PAID, payment posted, pivot captured.
- **Idempotency**: same key (provided and derived) → returns same payment, no duplicate.
- **Idempotency + reference**: same key/payload, different `reference` → returns the existing
  payment unchanged (first-write-wins), no conflict.
- **Each validation rejection**: empty, **duplicate invoice id**, mixed user, mixed currency,
  proforma, bad status, already-paid, amount mismatch.
- **Reverse**: restores exact `previous_status`/`previous_paid_at`, nulls `active_invoice_id`,
  payment `reversed`; reversing twice is a no-op.
- **Reverse audit immutability**: a second reverse with a different `reason`/`reversedBy`
  leaves `reversed_at`/`reversed_by`/`reverse_reason` untouched.
- **Re-pay after reverse (D2)**: with a **fresh** key, an invoice freed by reversal joins a
  new posted payment; replaying the **spent** key (or the derived key) → `IdempotencyConflictException`.
- **Status guard**: `status` only ever holds `GroupedPaymentStatus` values (enum-cast on the
  model; no intermediate/implicit DB states) — assert no path writes an out-of-enum value.
- **Relation uniqueness** (MySQL integration): `unique(grouped_payment_id, invoice_id)` rejects
  a duplicate pivot row for the same invoice within one payment.
- **Double-pay backstop** (MySQL integration): the `unique(active_invoice_id)` constraint +
  the ordered `lockForUpdate` path prevent a second active payment on the same invoice under
  concurrency. SQLite covers the service logic; the constraints are asserted on MySQL.

## Design decisions (post-adversarial review, 2026-06-27)

A Codex adversarial teardown of the implementation plan surfaced three contract-level calls
(the rest were mechanical plan fixes):

- **D1 — Immutable-invoice settlement → dedicated model methods.** `Invoice::update()`
  hard-blocks `status`/`paid_at` on immutable invoices (`src/Models/Invoice.php`). Settling
  and reversing therefore go through `markAsPaidViaGroupedPayment()` /
  `restoreStateViaGroupedPaymentReversal()` (via `save()`), NOT `update()`. Collection state
  is not fiscal content, so this is a permitted transition, encapsulated and named in the
  model; the `update()` guard and its test stay as-is.
- **D2 — Re-pay after reverse requires a fresh idempotency key.** The auto-derived key is
  only valid for retrying a *live* collection. Once a payment is reversed its key is spent; a
  re-payment is a new event and must carry a new key. Avoids the UNIQUE-key collision and
  keeps every payment a distinct historical row.
- **D3 — Currency is validated, not assumed.** An earlier draft treated `currency` as
  unchecked metadata on the false premise that one `billable_user_id` implies one issuer/
  currency — but `billable_user_id` is the *payer*; the issuer is `user_id`. So `currency` is
  validated per-invoice against `invoice.companyFiscalConfig.currency`.

## Out of scope (restated)
Partial payments, payment methods/gateways, bank reconciliation, running balances,
settling proformas, multi-currency/multi-customer payments, partial unmount, and the
separate **fiscal** "factura agrupada" (proforma consolidation) feature.
