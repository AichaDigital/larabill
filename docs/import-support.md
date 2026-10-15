# Import support — the larabill-side contract for the WHMCS importer (ADR-015 §2.5)

> Scope: this document is the **package-side** contract of the WHMCS import
> (AID-895). The importer itself lives in the consumer repo (`clientes`);
> larabill provides the columns, the flag, the idempotency lookup and the
> existing emission routes — nothing more. Source of truth:
> `docs/specs/adr-015-agreement-model.md` §2.5.

## The two new columns

### `invoices.is_historical` (bool, default false)

- Set **by the importer** to `true` on **EVERY document migrated from the
  legacy system** — invoices, proformas, everything. Plain, visible,
  queryable provenance marker; it is not fiscal content and carries no
  fiscal semantics.
- Default `false` on ordinary creation. Existing (native) invoices are NOT
  historical, so the migration ships **no backfill**.
- Passed additively through `InvoiceService::createInvoice()` as the input
  `is_historical` (absent → `false`), following the same additive-input
  pattern as `rectifies_invoice_id` and `settlement_operation_key`.

### `article_service_status.origin_key` (varchar(64), nullable, UNIQUE)

- The **idempotency key** of the import (spec gate round 11). Format:
  `whmcs:<source_table>:<source_id>` — e.g. `whmcs:tblhosting:4711`,
  `whmcs:tbldomains:99`.
- The format is the **importer's convention and is NOT enforced in
  larabill**. What larabill enforces is the **UNIQUE index**: a re-import of
  an already-imported record matches by `origin_key` and **UPDATES** the
  matched row instead of duplicating it.
- The find-or-UPDATE flow belongs to the importer. Larabill provides the
  lookup only:
  - `ArticleServiceStatus::findByOriginKey(?string $key): ?static` — returns
    the previously imported agreement, or `null` for a `NULL` key (no
    accidental global match over the never-imported population) and for an
    unmatched key.
  - `ArticleServiceStatus::byOriginKey(?string $key)` — query scope with the
    same NULL discipline.
- Existing agreements have no origin, so the column ships nullable with **no
  backfill**; a UNIQUE nullable index admits many NULLs on every engine.
- **`external_reference` stays untouched**: nullable, unindexed, and
  explicitly NOT an idempotency key (§2.5). Do not use it for re-import
  matching.

## Zero-invoice guidance (gate round 6, P1)

Legacy zero invoices of sponsored services import as **0-amount invoices
with a traceability line** (description such as "legacy error, originating
from WHMCS"), created through the **existing non-fiscal route**:

```php
$invoiceService->createInvoice([
    'billable_user_id' => $userId,
    'type'             => 'proforma',   // InvoiceSerieType::PROFORMA — the fiscal quality IS the serie type
    'is_historical'    => true,
    'items'            => [
        ['description' => 'legacy error, originating from WHMCS', 'unit_price' => 0],
    ],
], ['make_immutable' => true]);
```

- **A series prefix cannot make a document non-fiscal; fiscal quality is the
  serie type** (`InvoiceSerieType`). Larabill adds NO new serie type and NO
  new route for this — the PROFORMA type via `InvoiceService` already carries
  the shape.
- The consumer configures the **proforma `prefix` for the legacy series**
  (`larabill.invoice_numbering.series.proforma`, or the per-call `series`
  option). Since AID-589 larabill ships no default series.
- Every document created this way carries `is_historical = true`.

## Terminal-state contract (gate round 7, P2 #6)

WHMCS agreement states map deterministically:

| WHMCS state | larabill target |
| -- | -- |
| `Active` | `active` |
| `Pending` | `pending` |
| `Suspended` | `suspended`, cause NULL |
| `Cancelled`, `Terminated`, `Completed`, `Fraud` | `closed`, cause NULL |

The **operator's stance governs**: the import records **that the service
ended**; the WHMCS state and its narrative live in consumer-side metadata
and order notes — **never** as an invented `closure_cause` (the cause
vocabulary of ADR-015 §2.2 records what the data proves, and legacy data
does not prove any cause). Both `closed` + NULL cause (via
`ArticleServiceStatus::close(null)`) and a directly created `suspended` row
with `closure_cause_id = null` are legal, pinned import shapes.

Every imported agreement carries its original identifiers, dates and state
in namespaced metadata; unsupported states appear as `unsupported` in the
importer report without approximation.

## Cadence contract (gate round 10, P2)

Source WHMCS billing cycles map deterministically:

| WHMCS cycle | larabill cadence |
| -- | -- |
| `Monthly` | 1 month |
| `Quarterly` | 3 months |
| `Semiannually` | 6 months |
| `Annually` | 1 year |
| `Biennially` | 2 years |
| `Triennially` | 3 years |
| `One Time` | NULL cadence + `one_time` modality |
| `Free Account` | `non_billable` modality |

**Long-period cycles are representable and are imported EXACT**: the 7.0
cadence domain is months 1-12 / years 1-10 (`BillingInterval` validates it),
so a 4-, 5- or 9-year period constructs exactly (`BillingInterval('year', 9)`).
Larabill imposes NO import-time block on them and nothing in `src/` rejects a
representable cadence: the reference consumer (`clientes`, MR !336) imports
the 15 long-period domain records at their exact cadence plus `year/1..year/10`
price rows — there is NO `unsupported` landing for representable cycles.
Truly unknown cycle strings keep their original value (consumer side), appear
as `unsupported` in the importer report, and are **never approximated** to a
schedulable interval.

> **AID-1613 re-adjudication (2026-10-12, operator decision — option B
> without the mapping change; supersedes the 2026-10-11 option-A note):** the
> original wording called long cycles «unrepresentable» (a fossil of the
> legacy bridge, which tops at 3 years) and then «BLOCKED» (an import-time
> decision). Both are retired — the block existed only in this document, never
> in code. The one live hazard is the transitional legacy bridge
> `BillingInterval::toLegacyFrequency()`, reachable only through
> `Article::getAvailableFrequencies()` (no production caller in larabill or
> in the reference consumer as of 2026-10-12): it fails loud for cadences
> without a legacy equivalent (year/4-10). Per the operator's recommendation
> (2026-10-12) it will be deprecated with the ADR-015 shim pattern
> (`@deprecated` + `E_USER_*` notice; **retirement deferred to the next major (9.0.0)** — ADR-015 Enmienda 2, 2026-10-13: the 8.0 coupling message stays scoped to the numbering lifecycle, and a minor cannot retire surface). Scheduling at
> year/4-10 is pinned by invariance tests (`addToDate`/`subtractFromDate`,
> next-due computation, `processScheduledClosures`, `RecurringBillingService`)
> — after the cutover these are live contracts.

## The metadata promise — a declared reading

§2.5 says the original identifiers, dates and state survive "in metadata +
order notes". **Larabill declares NO metadata column for this** — the §2.1
change list adds none to `article_service_status` beyond the lifecycle
columns, and AID-971 established the `invoices` table has none either.
Namespaced metadata is the **importer's consumer-side responsibility** (the
WHMCS side owns the namespace, the order notes, and the report of
`unsupported` records). This is a declared reading of §2.5, not an invented
column: the package deliberately does not grow a metadata surface for the
import.
