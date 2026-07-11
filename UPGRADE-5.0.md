# Upgrading larabill 4.x → 5.0

## TL;DR

larabill **5.0 separates the fiscal TYPE from the fiscal SERIES** (AID-307).
Until 4.x the `invoices.serie` column held the fiscal *type*
(`InvoiceSerieType`), and that type was wrongly used as the AEAT series and as
the uniqueness scope. As a result two real series of the same type collided
(`FAC-2026-000001` and `ARB-2026-000001` both reduced to `serie='1'`), and the
adapter sent the type — not the series — to Verifactu.

The real fiscal series lives in `invoices.prefix`. In 5.0 it scopes the unique
index, the numbering counter, and the AEAT `NumSerieFactura` series component.
The fiscal type still travels separately to AEAT as `TipoFactura` (F1/F2/R1).

**Are you affected?**

- **No, behaviourally** — if you issue invoices under a single series. The
  default series resolves to `FAC` (invoices) and `PRO` (proformas) exactly as
  before, with **no config or code change required**. You only need to run the
  schema migration.
- **Yes** — if you referenced the `invoices` unique index by name, or you want
  to run **multiple series** for the same fiscal type (now supported).

## Migration steps

### 1. Update the dependency

```bash
composer update aichadigital/larabill
```

### 2. Apply the schema change

Re-running the installer is idempotent and additive — it publishes only the new
migration (by name) and never overwrites your `config/larabill.php`:

```bash
php artisan larabill:install
php artisan migrate
```

The single new migration swaps the `invoices` unique index from
`(serie, series_number, fiscal_year)` to
`(prefix, serie, series_number, fiscal_year)`. The new key is a **superset** of
the old one, so the migration is **DDL-only, touches no rows, and cannot fail on
existing data** (any set that satisfied the old key satisfies the new one).

> **Large tables:** `ADD UNIQUE` builds an index — a real DDL cost on tables
> with millions of invoices. Prefer a maintenance window or
> `pt-online-schema-change`. On modern InnoDB the change is `ALGORITHM=INPLACE`.

### 3. (Optional) Configure multiple series

You can now run more than one series for the same fiscal type (RD 1619/2012
art. 6). The per-type default series is declared in config:

```php
// config/larabill.php
'invoice_numbering' => [
    'series' => [
        'invoice'       => env('LARABILL_SERIES_INVOICE', 'FAC'),
        'proforma'      => env('LARABILL_SERIES_PROFORMA', 'PRO'),
        'rectificative' => env('LARABILL_SERIES_RECTIFICATIVE', 'RECT'),
        'simplified'    => env('LARABILL_SERIES_SIMPLIFIED', 'TIK'),
    ],
    // ...
],
```

To issue an invoice under a specific series, pass it per invoice:

```php
$invoiceService->createInvoice([
    'billable_user_id' => $user->id,
    'series'           => 'ARB',   // opt into a second series
    'items'            => [...],
]);
```

Each series keeps its own correlative sequence. **Enabling multiple series is a
fiscal decision you are responsible for justifying** (RD 1619/2012 art. 6);
larabill only exposes the capability.

## Verifactu / AEAT notes

- The adapter now sends `invoices.prefix` as the `NumSerieFactura` series
  component (previously it sent the int-backed fiscal type). This affects
  **future emissions only** — invoices already registered in AEAT are immutable
  there and are not retro-altered.
- **Mid-year series changes are supported and transparent.** The Verifactu hash
  chain is global per obligated party (a single `SIF·OT` chain), never per
  series, so starting a new series mid-year continues the same chain. Only the
  correlative numbering is per series, which larabill already guarantees.

## Not in this release

To keep the fiscal fix focused, the following deprecations ride a **later**
major (they are unrelated to the series change): the removal of the
`@deprecated` `BillingService`, and the `user_id` compatibility shims on
`UserTaxProfile`. They still work in 5.0.
