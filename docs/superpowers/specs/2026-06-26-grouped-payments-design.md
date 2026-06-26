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
2. All invoices belong to the same `billable_user_id`.
3. All invoices share the same `currency`.
4. No invoice is a **proforma** (`InvoiceSerieType::PROFORMA`).
5. Every invoice status ∈ {`SENT`, `OVERDUE`, `PENDING`} — not draft/cancelled/converted/paid.
6. No invoice is already covered by an **active** (`posted`) grouped payment.
7. `amount == Σ invoice totals` **exactly** (FixedDecimal comparison).

### Idempotency
- `register()` accepts an optional `idempotencyKey`. Recommended: caller provides it.
- If absent, derive a deterministic key from `billable_user_id + sorted(invoice_ids) +
  currency + amount` (**not** `paid_at` — avoids false "splits" of the same logical
  collection across timestamps).
- `idempotency_key` is **unique**. A repeated `register()` with an existing key whose
  payload (`billable_user_id` + invoice set + `amount` + `currency`) **matches** returns
  the existing payment (no duplicate, no error). If the key exists but the payload
  **differs**, that is a caller bug (same token, different request) → raise an
  `IdempotencyConflictException`, never silently settle a different set.
- `reverse()` is idempotent too: reversing an already-`reversed` payment is a **no-op that
  returns the payment**, never a double-restore and never an error.

### Concurrency / double-pay (defense in depth)
- **Primary:** `register()` runs in a transaction and takes `lockForUpdate` on the target
  invoices before validating + writing.
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
| `currency` | char(3) | |
| `paid_at` | datetime | date the collection happened |
| `reference` | string nullable | bank / accounting reference |
| `idempotency_key` | string **unique** | provided or derived |
| `status` | tinyInteger | enum `GroupedPaymentStatus` {POSTED=0, REVERSED=1} (int-backed, mirrors `InvoiceStatus`) |
| `reversed_at` | datetime nullable | |
| `reversed_by` | char(36) nullable | actor id (no hard FK — may be a system actor) |
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

Indexes: `unique(active_invoice_id)` (the DB backstop); `grouped_payment_id`; `invoice_id`.

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
Flow: resolve/derive idempotency key → if a payment with that key exists, return it →
else open transaction, `lockForUpdate` the invoices, run all eligibility validations,
create the `posted` `GroupedPayment` + pivot rows (with `previous_status`/`previous_paid_at`
captured and `active_invoice_id = invoice_id`), set each invoice `status = PAID` +
`paid_at = $paidAt`, commit.

```
reverse(
    GroupedPayment $payment,
    string $reason,
    ?string $reversedBy = null,
): GroupedPayment
```
Flow: if already `reversed`, no-op return → else open transaction, set `status = reversed`
+ `reversed_at`/`reversed_by`/`reverse_reason`, for each pivot row restore the invoice to
`previous_status` + `previous_paid_at` and set the pivot `active_invoice_id = NULL`, commit.
Rows are kept for audit.

### Models
- `GroupedPayment` (`HasUuid`): `belongsToMany(Invoice)` through the pivot; `belongsTo` payer;
  casts `amount` → `FixedDecimal:2`, `status` → `GroupedPaymentStatus`. Issued invoices are
  immutable, but settling only flips `status`/`paid_at` (allowed transitions, like today).
- `Invoice`: add inverse `groupedPayments()` relation (trazabilidad).
- `GroupedPaymentStatus` enum (int-backed) + `GroupedPaymentFactory`.
- Domain exceptions under `src/Exceptions/` (e.g. `GroupedPaymentValidationException` with
  named constructors per failure: empty list, mixed users, mixed currencies, proforma,
  not-payable status, already-paid, amount mismatch).

## Migrations (repo contract)

Two new tables → **two `.php` + two byte-identical `.php.stub`** (`bin/sync-migration-stubs`)
+ **two new entries in `$migrationOrder`** of `LarabillInstallCommand`, validated by
`MigrationOrderConsistencyTest`. Order: `grouped_payments` then `grouped_payment_invoice`
(FK), both after `invoices` and the users table. FKs via `MigrationHelper::userIdColumn()`
for `billable_user_id`.

## Testing (TDD, Pest)

- **Happy path**: register settles N invoices → all PAID, payment posted, pivot captured.
- **Idempotency**: same key (provided and derived) → returns same payment, no duplicate.
- **Each validation rejection**: empty, mixed user, mixed currency, proforma, bad status,
  already-paid, amount mismatch.
- **Reverse**: restores exact `previous_status`/`previous_paid_at`, nulls `active_invoice_id`,
  payment `reversed`; reversing twice is a no-op.
- **Re-pay after reverse**: an invoice freed by reversal can join a new payment.
- **Double-pay backstop** (MySQL integration): the `unique(active_invoice_id)` constraint +
  the `lockForUpdate` path prevent a second active payment on the same invoice under
  concurrency. SQLite covers the service logic; the constraint is asserted on MySQL.

## Out of scope (restated)
Partial payments, payment methods/gateways, bank reconciliation, running balances,
settling proformas, multi-currency/multi-customer payments, partial unmount, and the
separate **fiscal** "factura agrupada" (proforma consolidation) feature.
