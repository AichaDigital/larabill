# Upgrade guide — larabill 9.0 (AID-1778: advance invoices, partial card settlement, PHP 8.4 / Laravel 13)

> **⚠️ 9.0.0 is a BREAKING major.** It ships ONE migration (054, additive nullable column with a conservative 8.3.0 backfill) and retires the 7.0 shim layer. Read this guide before upgrading; every entry also lives in `CHANGELOG.md` under `[9.0.0]`.

## The upgrade ritual

```bash
composer update aichadigital/larabill
php artisan larabill:install   # idempotent: publishes only NEW migrations, never overwrites config
php artisan migrate
```

**`migrate:fresh` is never part of the path** (AID-398: stable releases respect persisted data).

### Migration 054 — `add_band_start_to_credit_movements`

- Adds the nullable base-100 column `credit_movements.band_start`: the cent offset at which an exit's band starts on its entry's tape. The end is derived arithmetic (`band_start + abs(amount)`), never stored.
- **The 8.3.0 backfill is a CONSERVATIVE RECONSTRUCTION CRITERION, not proof of the historical order.** An entry's exits are backfilled ONLY when the stored history is exact: (E1) no reversed exit on the entry, (E2) `created_at` strictly increasing along `id` order, and (E3) every existing advance artifact agrees with the computed bands. Every other entry keeps NULL on all its rows — that NULL on an ACTIVE exit IS the declaration «unresolvable», never a guess presented as history.
- **What an unresolvable entry means for the consumer:** the ledger keeps working untouched (`remaining()`, FIFO, funding checks never read the column), but every call to `AdvanceInvoiceBuilder::deductionLine()`/`rectificativeLines()` on its exits throws `InvalidAdvanceInvoiceException::unresolvedEntryPosition` with the entry id. Resolving a declared-unresolvable entry is an operator decision over real evidence and is out of 9.0 scope.
- The migration is idempotent and resumable: the `addColumn` DDL is `hasColumn`-guarded and the backfill runs per entry in its own transaction, so an interrupted run repeats cleanly and a re-run writes no-ops on already-banded entries.

## The platform jump

- **PHP `^8.4` and Laravel 13 only** (`illuminate/* ^13.0`). The CI matrix runs PHP 8.4 × Laravel 13.
- Consumers on PHP 8.3 or Laravel 12 stay on the **8.x line**, which receives security fixes only until its Laravel security EOL (published at https://laravel.com/docs/releases; the stack-wide version-line policy is tracked in AID-1793).
- The SQLite «table already exists» issue once documented for local PHP 8.4 runs did NOT reproduce on PHP 8.4.27 (verified 2026-10-26).

## Removed surface — symbol → replacement

Retired with ADR-015 Enmienda 2 (deprecated in 7.0, alive through the whole 8.x):

| Removed symbol (7.0 shim) | Replacement |
| -- | -- |
| `ServiceStatus` | `AgreementStatus` (string-backed five-state lifecycle) |
| `CancellationType` | the `agreement_causes` vocabulary + `ArticleServiceStatus::close()` / `::suspend()` |
| `ArticleServiceStatus::cancel()` (both) | `::close(?AgreementCause)` / `::suspend(AgreementCause)` |
| `ServiceLifecycleService::cancel()` | `::close()` / `::suspend()` via the lifecycle service |
| `ServiceLifecycleService::calculateCancellationEffectiveDate()` | the notice computation died with the shim; schedule with `::close(..., $effectiveAt)` |
| `ServiceLifecycleService::processPendingCancellations()` | `processScheduledClosures()` (closures + expiry in one pass) |
| `ServiceCancelled` event | `ServiceClosed` / `ServiceSuspended` — **the scheduled-closure materialiser no longer re-dispatches the legacy event**; move your listeners |
| `ArticleServiceStatus::calculateNextBillingDate()` / `::shouldBeBilled()` | `BillingInterval::addToDate()` via `::billingInterval()`; the emission gate (modality + interval) |
| `Article::isRecurring()` / `scopeRecurring()` | `modality = recurring` on the agreement |
| `Article::getPriceFor()` / `getEffectivePriceFor()` / `getBillingDaysInAdvanceFor()` | `getPriceForInterval()` / `getEffectivePriceForInterval()` / `getBillingDaysInAdvanceForInterval()` |
| `ArticleOverride::getDiscountAmount()` / `getDiscountPercentage()` | interval-keyed price reads + `custom_price` |
| `PricingService::getEffectivePrice()` / `createPricingDetails()` | `getEffectivePriceForInterval()` / `createPricingDetailsForInterval()` |
| `ArticlePriceService::setPrice()` | `setIntervalPrice(..., ?BillingInterval $interval, ...)` |

**Columns no longer written:** `refund_unused`, `cancellation_type`, `cancellation_requested_at` and `cancellation_effective_at` had no writer after the `cancel()` shims retired. Existing values are untouched data; `refund_unused` is still read by the indicative `ServiceLifecycleService::calculateRefund()`. Their columns' retirement remains the AID-971 rewrite.

**Exception — the `BillingFrequency` family (operator decision L17):** `BillingFrequency`, `BillingInterval::toLegacyFrequency()` and `Article::getAvailableFrequencies()` are NOT removed in 9.0. They are `@deprecated since 9.0.0, removal in 10.0`; migrate to `Article::getAvailableIntervals()` (a NULL element is the one-time cadence). `BillingInterval::fromLegacyFrequency()` stays alive: shipped migrations call it.

## The advance-invoice flow — build → link → issue

The art. 75.Dos LIVA advance is built from a collected credit entry and MUST be linked to its entry BEFORE it is issued:

```php
$builder = app(\AichaDigital\Larabill\Services\AdvanceInvoiceBuilder::class);

$draft = $builder->forCreditEntry($entry, $series, 'Advance', $taxGroupId, $isRoiTaxed);
$entry->linkAdvanceInvoice($draft);   // one-to-one, both directions, atomic
$issued = app(\AichaDigital\Larabill\Services\InvoiceService::class)->issue($draft);
```

- **Link before issue.** With the old order (issue → link) two callers could issue two advances for the same entry before either linked — the data-integrity defect (H4) that justifies the 9.0 narrowing of `linkAdvanceInvoice()`.
- **Retry issuing the SAME linked draft.** If issuance fails, the recovery retries `issue()` on that same linked draft — never build another one for the entry.
- **Orphan drafts are the consumer's responsibility.** larabill does NOT prevent issuing an unlinked draft: `InvoiceService::issue()` is a generic primitive with no hook into the credit ledger. «Only the linked one is issued» is the consumer's documented contract; a stray issuance of an orphan draft produces a valid, unlinked invoice whose cleanup is yours.

### The `linkAdvanceInvoice()` caller contract (K1)

Call it as the **OUTERMOST transaction**, or inside a wrapper that has made **no prior read**: under REPEATABLE READ a plain read made earlier fixes the snapshot, and the conflict check could read stale rows even after the invoice lock is acquired. It is deliberately NOT enforced with `PaymentOutsideTransactionRequiredException` (8.x callers keep working) — it is a documented contract, the same snapshot rule `PaymentService::receive()`/`allocate()` already document.

### New refusals of `linkAdvanceInvoice()`

- a proforma advance → `InvalidAdvanceInvoiceException::advanceNotAnInvoice`
- a non-draft advance (issued, converting, cancelled) → `::advanceNotDraft`
- an advance already linked to another entry → `CreditMovementAlreadyLinkedException::forAdvance` (the refused entry's row stays NULL)
- the entry already linked → `CreditMovementAlreadyLinkedException::forMovement` (unchanged)

## `refund()` cardinality change

`CustomerCredit::refund()` may now return **several REFUNDED exit rows per consumed entry**: when the take spans several free gaps on the entry's cent tape, the split rule inserts one row per gap. Iterate the whole collection instead of assuming one row per entry. The APPLIED path is unchanged — `apply()` returns allocations, and every row of a split shares the same `allocation_id`.

## Deprecated in 9.0 — removal in 10.0

- `BillingFrequency`, `BillingInterval::toLegacyFrequency()` and `Article::getAvailableFrequencies()` → `Article::getAvailableIntervals()` (operator L17: the family was left alive and undocumented by 7.0; this opens the one-full-major window).
- `RecurringBillingService`'s constructor parameters `$invoiceNumbering` and `$seriesResolver` (deprecated in prose since 6.10.0/AID-836, now formally `@deprecated`). Pass only the first parameter.
- The 8.3.0 deprecations were relabelled from «removal in 9.0» to **«removal in 10.0»** (operator L16): `Invoice::markAsPaidViaGroupedPayment()` / `::restoreStateViaGroupedPaymentReversal()` and the pivot columns `previous_status` / `previous_paid_at` / `active_invoice_id` — a deprecation from 8.3 does not complete a full stability window at 9.0.
