# larabill v7 — PR-1: Schema Foundations + Transitional Coherence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land every schema foundation the v7 proforma→invoice lifecycle needs — new state axes, contractual freezing columns, tax-truth tables, obligations, epochs, outbox, backfills and immutability guards — while leaving the v6 runtime coherent with the new schema and closing conversion defects D1 (no lock) and D2 (`proforma_id` never written).

**Architecture:** Additive DDL under a read-only global preflight plus a package-side write gate (advisory lock). No behavior is rewritten in this PR: the old `convertProformaToInvoice()` keeps riding `TaxCalculationService` and the live catalog (a **named transitional exception**, §9 of the spec — it ends in PR-3). What this PR guarantees is that after `migrate`, every row in the database carries a coherent v7 state, and every write path in v6 stamps the new columns.

**Tech Stack:** PHP 8.3 (`php83` — see Global Constraints), Laravel 12/13, Pest, MySQL 8 (production contract) + SQLite (unit suite), `lara100` `FixedDecimal`, Spatie Laravel Package Tools.

**Spec:** `docs/superpowers/specs/2026-07-13-proforma-invoice-lifecycle-v7-design.md` (the constitution is §3; this PR implements §7 plus the transitional wiring of §9.1).

**Linear:** epic AID-459.

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP binary:** this package's local flow is pinned to **PHP 8.3**. Run tests as `php83 vendor/bin/pest`. Run Composer as `php83 "$(which composer)" <cmd>` — a bare `composer update` resolves against PHP 8.4 and bricks every `php83 vendor/bin/*` (umbrella lesson 2026-06-21/2026-07-12).
- **Migration contract (ADR-007/ADR-010, CONTRIBUTING.md):** every package table migration ships **five** pieces — the timestamped `.php`, a byte-identical `.php.stub`, an entry in `$migrationOrder` (`src/Console/LarabillInstallCommand.php`), an entry in `tests/Contract/release-migration-manifest.json` with `"in_base": false`, and a bump of the pinned count in `tests/Integration/InstallMysql/InstallCommandSchemaTest.php`. **All five land in the SAME commit as the migration.** No commit in this PR may leave `MigrationOrderConsistencyTest` or `InstallCommandSchemaTest` red — "quality gates before every commit" has no migration exemption. Each migration task below therefore closes its own contract pieces before committing; Task 15 only writes the upgrade document and runs the final full verification.
- **`$migrationOrder` keys:** the array currently holds **35 entries and ends at `'038'`** (the numbering has historical gaps at 020, 024, 025 — the key is an order token, not a count). The 13 v7 migrations take **`'039'` … `'051'`**. Never reuse an existing key: overwriting `'033'`–`'038'` silently deletes six shipped migrations from the installer.
- **Stub bootstrap is a manual copy.** `bin/sync-migration-stubs` only *regenerates* stubs that already exist (it globs `*.php.stub`). For a new migration: `cp database/migrations/<name>.php database/migrations/<name>.php.stub`, then run `php83 bin/sync-migration-stubs` and confirm it reports the stub unchanged. **Never hand-edit a `.stub`.**
- **User FKs:** always `MigrationHelper::userIdColumn($table, 'col')` — UUID v7 `char(36)` (ADR-006). Never `foreignId()`/`unsignedBigInteger()` for a `users` FK.
- **Money:** Base-100 signed integers only. Never `decimal`/`float` columns. Eloquent attributes use `FixedDecimalCast:2` from `lara100`.
- **Currency:** every new money-bearing table carries `char(3)` ISO-4217 (constitution 15). Backfill `EUR`.
- **CHECK constraints: MySQL enforces, guards enforce everywhere (constitutional — the spec was amended to match).** Laravel's `Blueprint` has no `check()` API, and SQLite accepts a CHECK only inside `CREATE TABLE`; reproducing these tables as hand-written raw DDL per engine would fork the schema definition — the exact drift ADR-007 exists because of. So the rule for **all** v7 CHECKs (`invoices` axes, facts/obligations single-owner XOR, obligation origin) is:
  - **MySQL: real CHECK constraints**, all of them, added in link 13. This is the production engine and the engine the schema contract is proven against.
  - **Every engine: an application-level enforcer.** For `invoices` that is `Invoice::assertAxisCoherence()` (Task 13) — there is a live writer, so PR-1 must ship it. For facts/obligations there is **no writer at all in PR-1** (no models, no services), so their guard ships with the models in **PR-2**, as a recorded obligation of that PR, not a footnote.
  - Spec §7.3 said "CHECK-enforced single owner" without qualifying the engine; it now says MySQL CHECK + model guard. **A promise of both engines with an implementation in neither is the one outcome that is not acceptable.**
- Every DDL operation (columns, indexes, generated columns, CHECKs) is **introspection-guarded** so a chain killed mid-DDL re-runs cleanly (spec §7, Codex 65). Guarding the index but not the generated column it indexes is the classic hole — guard each statement separately.
- **The write gate is held for the whole chain, in BOTH directions.** Every `up()` and every `down()` begins with `UpgradeGate::ensureHeld()`. Only link 1 releases: on the way up it is the first to run, on the way down it is the **last** — so a rollback is never performed on an unprotected database, and a failed rollback can be resumed.
- **Quality gates before every commit:** `php83 vendor/bin/pint`, `php83 vendor/bin/phpstan analyse --memory-limit=1G` (level 8, zero new baseline entries), `php83 vendor/bin/pest`.
- **Enum integer values are contractual** once written (they land in `tinyint` columns). The values fixed in Task 1 are final for v7.
- **No behavior rewrite in PR-1.** If a task tempts you to reimplement conversion, issuance or tax resolution: stop. That is PR-2/PR-3.

## File Structure

**New enums** (`src/Enums/`) — one responsibility each, all backed by `int` except the derived projection:

- `ProformaStatus.php`, `InvoiceDocumentStatus.php`, `FiscalSubmissionStatus.php` — the three state axes (§6.1, §4.1).
- `ObligationState.php` — obligation lifecycle (§5.8).
- `OperationNature.php` — closed contractual nature (constitution 14).
- `PriceTaxMode.php` — `tax_exclusive` | `tax_inclusive` (§4.3).
- `EconomicFactType.php` — payment / delivery / completion (§5.1).
- `EpochIntegrity.php` — `intact` | `compromised` (§5.2).
- `SubmissionOperationType.php` — registration | annulment (outbox `unique(invoice_id, operation_type)`, §6.7).

**New support** (`src/Support/`):

- `UpgradeGate.php` — the package-side write gate (advisory lock held by the migration chain; write ops refuse while held).
- `V7Preflight.php` — the read-only global preflight: detections + report (§7.1). Pure query logic, returns a report object; the migration and the artisan command both call it.

**New console** (`src/Console/`):

- `LarabillV7PreflightCommand.php` — `larabill:v7-preflight`, runs `V7Preflight` and prints the report without mutating anything (operators run it *before* the maintenance window).

**Migrations** (`database/migrations/`, each with its `.php.stub`) — order matters, FKs depend on it:

1. `2026_07_20_000001_v7_preflight_gate.php` — read-only preflight; acquires the write gate for the chain.
2. `2026_07_20_000002_create_tax_catalog_epochs_table.php`
3. `2026_07_20_000003_create_tax_determinations_table.php`
4. `2026_07_20_000004_create_tax_determination_components_table.php`
5. `2026_07_20_000005_add_v7_lifecycle_columns_to_invoices.php`
6. `2026_07_20_000006_add_v7_contract_columns_to_invoice_items.php`
7. `2026_07_20_000007_create_billing_economic_facts_table.php`
8. `2026_07_20_000008_create_billing_chargeability_schedules_table.php`
9. `2026_07_20_000009_create_billing_fiscal_obligations_table.php`
10. `2026_07_20_000010_create_fiscal_submission_outbox_table.php`
11. `2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php`
12. `2026_07_20_000012_backfill_v7_invoice_item_contract_terms.php`
13. `2026_07_20_000013_add_v7_coherence_constraints.php` — CHECKs + active-link unique. **Last**: constraints only after the data is coherent.

**Modified:**

- `src/Models/Invoice.php` — new casts; `saving`/`deleting` immutability guards (closes D8's `save()` bypass); transitional stamping of `invoice_document_status`/`proforma_status` on create.
- `src/Models/InvoiceItem.php` — new casts; `saving`/`deleting` guard driven by the header state (closes D8's line hole).
- `src/Services/InvoiceService.php` — transitional: `lockForUpdate()` in `convertProformaToInvoice()` (closes D1) + write `proforma_id` (closes D2) + dual-write the mirror.
- `src/Console/LarabillInstallCommand.php` — `$migrationOrder` + 13 entries.
- `tests/Contract/release-migration-manifest.json`, `tests/Integration/InstallMysql/InstallCommandSchemaTest.php`, `tests/Contract/snapshots/` — contract gates.
- `UPGRADE-7.0.md` (new, **in the dist**, not under `docs/` — `docs/` is export-ignored), `CHANGELOG.md`.

---

### Task 1: The v7 enums

The nine persisted enums. Pure PHP, no database. Every later task casts against these, so their integer values are frozen here.

`ObligationHealth` (the derived proforma projection, §5.8) is **not** in this PR — nothing persists it and PR-2 owns the projection.

**Files:**
- Create: `src/Enums/ProformaStatus.php`, `src/Enums/InvoiceDocumentStatus.php`, `src/Enums/FiscalSubmissionStatus.php`, `src/Enums/ObligationState.php`, `src/Enums/OperationNature.php`, `src/Enums/PriceTaxMode.php`, `src/Enums/EconomicFactType.php`, `src/Enums/EpochIntegrity.php`, `src/Enums/SubmissionOperationType.php`
- Test: `tests/Unit/Enums/V7EnumsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the nine enums above. Integer values (contractual):
  - `ProformaStatus`: `DRAFT=0`, `FROZEN=1`, `CONVERTING=2`, `CONVERTED=3`, `SUPERSEDED=4`, `CANCELLED=5`, `LEGACY=6`. Methods: `isTerminal(): bool`, `canTransitionTo(self $to): bool`.
  - `InvoiceDocumentStatus`: `DRAFT=0`, `PREPARED=1`, `ISSUED=2`, `CANCELLED=3`. Methods: `isTerminal(): bool`, `canTransitionTo(self $to): bool`.
  - `FiscalSubmissionStatus`: `NOT_REQUIRED=0`, `PENDING=1`, `REGISTERED=2`, `ACTION_REQUIRED=3`, `CONTENT_REJECTED=4`, `LEGACY_UNKNOWN=5`.
  - `ObligationState`: `PENDING=0`, `DETERMINED=1`, `BLOCKED_PARTIAL_ADVANCE=2`, `BLOCKED_RESOLUTION=3`, `FULFILLED=4`, `VOIDED=5`. Method: `isBlocked(): bool`.
  - `OperationNature`: `GOODS_DELIVERY=0`, `ONE_OFF_SERVICE=1`, `SUCCESSIVE_TRACT=2`.
  - `PriceTaxMode`: `TAX_EXCLUSIVE=0`, `TAX_INCLUSIVE=1`.
  - `EconomicFactType`: `PAYMENT_RECEIVED=0`, `GOODS_DELIVERED=1`, `SERVICE_COMPLETED=2`.
  - `EpochIntegrity`: `INTACT=0`, `COMPROMISED=1`.
  - `SubmissionOperationType`: `REGISTRATION=0`, `ANNULMENT=1`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Enums/V7EnumsTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\EconomicFactType;
use AichaDigital\Larabill\Enums\EpochIntegrity;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\ObligationState;
use AichaDigital\Larabill\Enums\OperationNature;
use AichaDigital\Larabill\Enums\PriceTaxMode;
use AichaDigital\Larabill\Enums\ProformaStatus;
use AichaDigital\Larabill\Enums\SubmissionOperationType;

it('freezes the persisted integer values of every v7 enum', function (): void {
    // These land in tinyint columns. Changing them silently rewrites history.
    expect(ProformaStatus::DRAFT->value)->toBe(0)
        ->and(ProformaStatus::FROZEN->value)->toBe(1)
        ->and(ProformaStatus::CONVERTING->value)->toBe(2)
        ->and(ProformaStatus::CONVERTED->value)->toBe(3)
        ->and(ProformaStatus::SUPERSEDED->value)->toBe(4)
        ->and(ProformaStatus::CANCELLED->value)->toBe(5)
        ->and(ProformaStatus::LEGACY->value)->toBe(6)
        ->and(InvoiceDocumentStatus::DRAFT->value)->toBe(0)
        ->and(InvoiceDocumentStatus::PREPARED->value)->toBe(1)
        ->and(InvoiceDocumentStatus::ISSUED->value)->toBe(2)
        ->and(InvoiceDocumentStatus::CANCELLED->value)->toBe(3)
        ->and(FiscalSubmissionStatus::NOT_REQUIRED->value)->toBe(0)
        ->and(FiscalSubmissionStatus::PENDING->value)->toBe(1)
        ->and(FiscalSubmissionStatus::REGISTERED->value)->toBe(2)
        ->and(FiscalSubmissionStatus::ACTION_REQUIRED->value)->toBe(3)
        ->and(FiscalSubmissionStatus::CONTENT_REJECTED->value)->toBe(4)
        ->and(FiscalSubmissionStatus::LEGACY_UNKNOWN->value)->toBe(5)
        ->and(ObligationState::PENDING->value)->toBe(0)
        ->and(ObligationState::DETERMINED->value)->toBe(1)
        ->and(ObligationState::BLOCKED_PARTIAL_ADVANCE->value)->toBe(2)
        ->and(ObligationState::BLOCKED_RESOLUTION->value)->toBe(3)
        ->and(ObligationState::FULFILLED->value)->toBe(4)
        ->and(ObligationState::VOIDED->value)->toBe(5)
        ->and(OperationNature::GOODS_DELIVERY->value)->toBe(0)
        ->and(OperationNature::ONE_OFF_SERVICE->value)->toBe(1)
        ->and(OperationNature::SUCCESSIVE_TRACT->value)->toBe(2)
        ->and(PriceTaxMode::TAX_EXCLUSIVE->value)->toBe(0)
        ->and(PriceTaxMode::TAX_INCLUSIVE->value)->toBe(1)
        ->and(EconomicFactType::PAYMENT_RECEIVED->value)->toBe(0)
        ->and(EconomicFactType::GOODS_DELIVERED->value)->toBe(1)
        ->and(EconomicFactType::SERVICE_COMPLETED->value)->toBe(2)
        ->and(EpochIntegrity::INTACT->value)->toBe(0)
        ->and(EpochIntegrity::COMPROMISED->value)->toBe(1)
        ->and(SubmissionOperationType::REGISTRATION->value)->toBe(0)
        ->and(SubmissionOperationType::ANNULMENT->value)->toBe(1);
});

it('allows only the proforma transitions the spec draws', function (): void {
    expect(ProformaStatus::DRAFT->canTransitionTo(ProformaStatus::FROZEN))->toBeTrue()
        ->and(ProformaStatus::DRAFT->canTransitionTo(ProformaStatus::CANCELLED))->toBeTrue()
        ->and(ProformaStatus::FROZEN->canTransitionTo(ProformaStatus::CONVERTING))->toBeTrue()
        ->and(ProformaStatus::FROZEN->canTransitionTo(ProformaStatus::SUPERSEDED))->toBeTrue()
        ->and(ProformaStatus::FROZEN->canTransitionTo(ProformaStatus::CANCELLED))->toBeTrue()
        ->and(ProformaStatus::CONVERTING->canTransitionTo(ProformaStatus::CONVERTED))->toBeTrue()
        ->and(ProformaStatus::CONVERTING->canTransitionTo(ProformaStatus::FROZEN))->toBeTrue()
        ->and(ProformaStatus::LEGACY->canTransitionTo(ProformaStatus::FROZEN))->toBeTrue();
});

it('forbids the proforma transitions the spec excludes', function (): void {
    // D7: a DRAFT proforma is not convertible.
    expect(ProformaStatus::DRAFT->canTransitionTo(ProformaStatus::CONVERTING))->toBeFalse()
        // CONVERTING has no direct supersede/cancel: exits are issuance or cancelPreparedInvoice().
        ->and(ProformaStatus::CONVERTING->canTransitionTo(ProformaStatus::CANCELLED))->toBeFalse()
        ->and(ProformaStatus::CONVERTING->canTransitionTo(ProformaStatus::SUPERSEDED))->toBeFalse()
        // A LEGACY proforma cannot determine or convert before adoption.
        ->and(ProformaStatus::LEGACY->canTransitionTo(ProformaStatus::CONVERTING))->toBeFalse()
        ->and(ProformaStatus::LEGACY->canTransitionTo(ProformaStatus::CANCELLED))->toBeFalse();
});

it('marks the terminal proforma states', function (): void {
    expect(ProformaStatus::CONVERTED->isTerminal())->toBeTrue()
        ->and(ProformaStatus::SUPERSEDED->isTerminal())->toBeTrue()
        ->and(ProformaStatus::CANCELLED->isTerminal())->toBeTrue()
        ->and(ProformaStatus::CONVERTING->isTerminal())->toBeFalse()
        ->and(ProformaStatus::FROZEN->isTerminal())->toBeFalse();
});

it('allows only the invoice document transitions the spec draws', function (): void {
    expect(InvoiceDocumentStatus::DRAFT->canTransitionTo(InvoiceDocumentStatus::PREPARED))->toBeTrue()
        ->and(InvoiceDocumentStatus::DRAFT->canTransitionTo(InvoiceDocumentStatus::CANCELLED))->toBeTrue()
        ->and(InvoiceDocumentStatus::PREPARED->canTransitionTo(InvoiceDocumentStatus::ISSUED))->toBeTrue()
        ->and(InvoiceDocumentStatus::PREPARED->canTransitionTo(InvoiceDocumentStatus::CANCELLED))->toBeTrue()
        // Cancellation is legal only BEFORE issuance; ISSUED is terminal.
        ->and(InvoiceDocumentStatus::ISSUED->canTransitionTo(InvoiceDocumentStatus::CANCELLED))->toBeFalse()
        ->and(InvoiceDocumentStatus::ISSUED->isTerminal())->toBeTrue()
        ->and(InvoiceDocumentStatus::DRAFT->canTransitionTo(InvoiceDocumentStatus::ISSUED))->toBeFalse();
});

it('classifies the blocked obligation states', function (): void {
    expect(ObligationState::BLOCKED_PARTIAL_ADVANCE->isBlocked())->toBeTrue()
        ->and(ObligationState::BLOCKED_RESOLUTION->isBlocked())->toBeTrue()
        ->and(ObligationState::DETERMINED->isBlocked())->toBeFalse()
        ->and(ObligationState::FULFILLED->isBlocked())->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php83 vendor/bin/pest tests/Unit/Enums/V7EnumsTest.php`
Expected: FAIL — `Class "AichaDigital\Larabill\Enums\ProformaStatus" not found`.

- [ ] **Step 3: Write the enums**

`src/Enums/ProformaStatus.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The proforma documental state axis (spec §4.1).
 *
 * Distinct from InvoiceStatus (a legacy delivery/collection axis, authority
 * reduced in v7) and from InvoiceDocumentStatus (the fiscal document axis,
 * NULL on proformas). A row is a proforma iff serie = PROFORMA, iff
 * proforma_status IS NOT NULL — CHECK-backed on MySQL, guard-backed everywhere.
 *
 * Integer values are persisted in invoices.proforma_status and are contractual.
 *
 * @api
 */
enum ProformaStatus: int
{
    /** Editable; NOT convertible (closes defect D7); identity snapshots provisional. */
    case DRAFT = 0;

    /** Commercial content immutable; the ONLY state conversion may start from. */
    case FROZEN = 1;

    /** A PREPARED invoice exists and is linked. Non-terminal; no direct supersede/cancel. */
    case CONVERTING = 2;

    /** The linked invoice was issued. Terminal; stamped by issue() in the same transaction. */
    case CONVERTED = 3;

    case SUPERSEDED = 4;

    case CANCELLED = 5;

    /**
     * Migration-only entry state: a pre-v7 immutable proforma lacking freeze-time
     * identity, audited nature, frozen classification and known fact history.
     * Cannot determine or convert; projects UNKNOWN. adopt() (audited) promotes it.
     */
    case LEGACY = 6;

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CONVERTED, self::SUPERSEDED, self::CANCELLED => true,
            default => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::DRAFT => in_array($to, [self::FROZEN, self::CANCELLED], true),
            self::FROZEN => in_array($to, [self::CONVERTING, self::SUPERSEDED, self::CANCELLED], true),
            // Exits are issuance (CONVERTED) or cancelPreparedInvoice() (back to FROZEN).
            self::CONVERTING => in_array($to, [self::CONVERTED, self::FROZEN], true),
            self::LEGACY => $to === self::FROZEN,
            self::CONVERTED, self::SUPERSEDED, self::CANCELLED => false,
        };
    }
}
```

`src/Enums/InvoiceDocumentStatus.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The fiscal document state axis (spec §6.1). NULL on proforma rows.
 *
 * Conversion, issuance and fiscal submission are distinct transitions on
 * distinct axes (constitution 8): this one owns the document. Cancellation is
 * legal only BEFORE issuance — an ISSUED invoice is terminal and immutable.
 *
 * @api
 */
enum InvoiceDocumentStatus: int
{
    case DRAFT = 0;

    /** Born from conversion or from createInvoice()'s durable phase; not yet numbered. */
    case PREPARED = 1;

    case ISSUED = 2;

    case CANCELLED = 3;

    public function isTerminal(): bool
    {
        return match ($this) {
            self::ISSUED, self::CANCELLED => true,
            default => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::DRAFT => in_array($to, [self::PREPARED, self::CANCELLED], true),
            self::PREPARED => in_array($to, [self::ISSUED, self::CANCELLED], true),
            self::ISSUED, self::CANCELLED => false,
        };
    }
}
```

`src/Enums/FiscalSubmissionStatus.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The fiscal registration state axis (spec §6.1, operator arbitration D5).
 *
 * Set at issuance ONLY: ISSUED <=> fiscal_submission_status NOT NULL, NULL on
 * DRAFT/PREPARED/CANCELLED rows (before issuance there is neither a submission
 * intent nor a positive decision to record).
 *
 * Failure classes are SEPARATE on purpose, and the classification arrives as a
 * TYPED result from lara-verifactu (§6.7) — larabill never parses messages.
 *
 * @api
 */
enum FiscalSubmissionStatus: int
{
    /** A positive, audited compliance decision: this invoice needs no submission. */
    case NOT_REQUIRED = 0;

    /** Intent recorded in the outbox. Transient failures (timeout, network, outage) stay here. */
    case PENDING = 1;

    /** Reconciled confirmation from the fiscal authority. */
    case REGISTERED = 2;

    /** Credentials/endpoint/operational cause. Fix the CAUSE (never the invoice) -> back to PENDING, SAME payload. */
    case ACTION_REQUIRED = 3;

    /** Fiscal content rejected. Terminal for this submission: the exit is annulment/rectification, never a resubmission with altered data. */
    case CONTENT_REJECTED = 4;

    /** Pre-v7 rows. Exits only through an explicit operator reconciliation operation. */
    case LEGACY_UNKNOWN = 5;
}
```

`src/Enums/ObligationState.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Lifecycle of a fiscal obligation row (spec §5.8).
 *
 * An obligation is one concrete accrual — never a per-proforma scalar. Every
 * obligation gets its issuance deadline AT BIRTH, blocked ones included: the
 * VAT accrued, and a technical block does not remove the legal clock.
 *
 * @api
 */
enum ObligationState: int
{
    /** Born; determination outstanding. */
    case PENDING = 0;

    /** Determined at the accrual date; convertible. */
    case DETERMINED = 1;

    /** A partial payment accrued VAT that v7 cannot document 1:1. Deadline still runs. Exit: the 1:N ticket. */
    case BLOCKED_PARTIAL_ADVANCE = 2;

    /** The resolver could not prove the rule (out-of-epoch / compromised epoch). Exit: re-resolution or audited override. */
    case BLOCKED_RESOLUTION = 3;

    /** An issued invoice documents exactly this obligation. Stamped by issue(). */
    case FULFILLED = 4;

    /** Its originating facts were superseded. Voided with audit, never deleted. */
    case VOIDED = 5;

    public function isBlocked(): bool
    {
        return match ($this) {
            self::BLOCKED_PARTIAL_ADVANCE, self::BLOCKED_RESOLUTION => true,
            default => false,
        };
    }
}
```

`src/Enums/OperationNature.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The contractual nature of a line — a CLOSED enum owned by larabill
 * (constitution 14). An advance is a payment FACT, never a nature.
 *
 * Frozen per line at freeze(); it selects the accrual rule (art. 75 LIVA):
 * goods -> delivery date, one-off service -> completion date, successive tract
 * -> the frozen chargeability schedule. Extending this enum is a MINOR release;
 * consumers never register nature classes.
 *
 * @api
 */
enum OperationNature: int
{
    case GOODS_DELIVERY = 0;

    case ONE_OFF_SERVICE = 1;

    case SUCCESSIVE_TRACT = 2;
}
```

`src/Enums/PriceTaxMode.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * How the frozen commercial contract expresses its price (spec §4.3).
 *
 * TAX_EXCLUSIVE: contract_line_total is the NET the customer agreed; taxes are
 * added on top at the determined rates.
 * TAX_INCLUSIVE: contract_line_total is the GROSS the customer agreed; the
 * determination redistributes it over the component set under the closed
 * additive algebra, with the base reconciled by unit_price_base_adjustment.
 *
 * @api
 */
enum PriceTaxMode: int
{
    case TAX_EXCLUSIVE = 0;

    case TAX_INCLUSIVE = 1;
}
```

`src/Enums/EconomicFactType.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The economic facts a consumer may register (spec §5.1). Append-only,
 * historical truth: never rejected for being inconvenient, never deleted.
 *
 * There is NO "chargeability reached" fact: chargeability has ONE authority,
 * the frozen schedule (operator arbitration D4).
 *
 * @api
 */
enum EconomicFactType: int
{
    case PAYMENT_RECEIVED = 0;

    case GOODS_DELIVERED = 1;

    case SERVICE_COMPLETED = 2;
}
```

`src/Enums/EpochIntegrity.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Integrity of a catalog epoch (spec §5.2).
 *
 * COMPROMISED means the rule-set hash no longer matches and the change did NOT
 * come through a governed mutation — an external write. Determinations under a
 * compromised epoch are reported; resolving over it fails loud (override
 * available). An epoch is never silently re-opened.
 *
 * @api
 */
enum EpochIntegrity: int
{
    case INTACT = 0;

    case COMPROMISED = 1;
}
```

`src/Enums/SubmissionOperationType.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The operation an outbox row carries towards the fiscal authority (spec §6.7).
 *
 * The outbox enforces unique(invoice_id, operation_type): two intents for the
 * same invoice and the same operation are impossible.
 *
 * @api
 */
enum SubmissionOperationType: int
{
    case REGISTRATION = 0;

    case ANNULMENT = 1;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Unit/Enums/V7EnumsTest.php`
Expected: PASS — 6 passed.

- [ ] **Step 5: Verify the surface taxonomy gate still passes**

Every class in `src/` must carry `@api` or `@internal` (AID-413). The nine enums are `@api`.

Run: `php83 vendor/bin/pest tests/Contract --filter='SurfaceTaxonomy'`
Expected: PASS.

- [ ] **Step 6: Quality gates**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: Pint clean; PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Enums tests/Unit/Enums/V7EnumsTest.php
git commit -m "feat(v7): add the persisted lifecycle, tax and submission enums

The three state axes (proforma document, invoice document, fiscal
submission), plus obligation state, contractual nature, price tax mode,
economic fact type, epoch integrity and submission operation type.

Integer values land in tinyint columns and are contractual from here on.

Refs AID-459 (PR-1)"
```

---

### Task 2: The package-side write gate

A read-only preflight cannot stop concurrent writes, and `php artisan down` only stops HTTP — not workers, cron or CLI (spec §7, Codex 64). So the v7 migration chain holds an **advisory lock**, and larabill's own write operations refuse to run while it is held.

The lock is engine-portable: MySQL uses `GET_LOCK()`/`RELEASE_LOCK()`; SQLite (single-process test engine) uses an in-process flag. The gate is checked, not acquired, by write operations.

**Files:**
- Create: `src/Support/UpgradeGate.php`, `src/Exceptions/UpgradeInProgressException.php`
- Test: `tests/Unit/Support/UpgradeGateTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `UpgradeGate::ensureHeld(): void` — **idempotent**. Acquires the lock if nobody holds it; returns silently if THIS connection already holds it; throws if ANOTHER connection holds it (a concurrent migration run). Every link of the chain calls it — see below for why.
  - `UpgradeGate::release(): void` — releases it (last link, and every `down()`). Idempotent.
  - `UpgradeGate::isHeld(): bool` — **queries the database**, not a process-local flag.
  - `UpgradeGate::assertNotUpgrading(): void` — throws `UpgradeInProgressException` when held by any connection. Called at the top of every package write operation.
  - `UpgradeGate::lockName() = 'larabill_v7_upgrade'`

**Three properties the naive version got wrong (review findings 1 and 7):**

1. **Cross-process.** A worker in another PHP process must SEE the lock. A static boolean is invisible to it. On MySQL, `isHeld()` asks `IS_USED_LOCK()`, which returns the connection id of the holder — from any connection. (SQLite is a single-process test engine; there the in-process flag IS the whole world, and that is honest, not a shortcut.)
2. **Acquire BEFORE the preflight**, not after. Checking first and locking second leaves a window where a writer mutates the very rows the preflight just declared clean.
3. **Resumable.** If link 7 fails, Laravel has already recorded link 1 as applied. The next `migrate` starts at link 7 — link 1 never re-runs, and the crashed process's connection released the MySQL lock when it died. That is why every link calls `ensureHeld()` instead of trusting link 1: the gate re-establishes itself on resume. `GET_LOCK` is re-entrant per connection with a counter, so `ensureHeld()` must NOT blindly re-acquire (that would need N releases) — it checks first.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/UpgradeGateTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\UpgradeInProgressException;
use AichaDigital\Larabill\Support\UpgradeGate;

afterEach(function (): void {
    UpgradeGate::release();
});

it('reports no upgrade in progress by default', function (): void {
    expect(UpgradeGate::isHeld())->toBeFalse();

    UpgradeGate::assertNotUpgrading(); // must not throw
});

it('holds and releases the gate', function (): void {
    UpgradeGate::ensureHeld();

    expect(UpgradeGate::isHeld())->toBeTrue();

    UpgradeGate::release();

    expect(UpgradeGate::isHeld())->toBeFalse();
});

it('is idempotent: ensureHeld twice still releases with one release', function (): void {
    // GET_LOCK is re-entrant WITH A COUNTER: a blind re-acquire would need two
    // releases to unlock, and the gate would stay stuck after the chain finished.
    UpgradeGate::ensureHeld();
    UpgradeGate::ensureHeld();

    UpgradeGate::release();

    expect(UpgradeGate::isHeld())->toBeFalse();
});

it('refuses package write operations while the upgrade lock is held', function (): void {
    UpgradeGate::ensureHeld();

    UpgradeGate::assertNotUpgrading();
})->throws(
    UpgradeInProgressException::class,
    'larabill is upgrading its schema (v7)'
);

it('is idempotent on release', function (): void {
    UpgradeGate::release();
    UpgradeGate::release();

    expect(UpgradeGate::isHeld())->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Unit/Support/UpgradeGateTest.php`
Expected: FAIL — `Class "AichaDigital\Larabill\Support\UpgradeGate" not found`.

- [ ] **Step 3: Write the exception**

`src/Exceptions/UpgradeInProgressException.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * Thrown when a larabill write operation is attempted while the v7 upgrade
 * chain holds the write gate (spec §7).
 *
 * `php artisan down` stops HTTP, not workers, cron or CLI. The operator ritual
 * is: stop workers + maintenance mode + this gate enforces the rest.
 *
 * @api
 */
class UpgradeInProgressException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'larabill is upgrading its schema (v7) and refuses write operations '
            .'until the migration chain completes. Stop workers and cron, let '
            .'`php artisan migrate` finish, then retry.'
        );
    }
}
```

- [ ] **Step 4: Write the gate**

`src/Support/UpgradeGate.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use AichaDigital\Larabill\Exceptions\UpgradeInProgressException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The package-side write gate for the v7 upgrade chain (spec §7).
 *
 * A read-only preflight cannot stop concurrent writes, and `php artisan down`
 * stops HTTP only — not workers, not cron, not CLI. So the migration chain takes
 * an advisory lock and larabill's own write operations check it: they refuse
 * while the upgrade is running.
 *
 * THE LOCK LIVES IN THE DATABASE, NOT IN THIS PROCESS. A worker in another PHP
 * process must see it, so isHeld() asks MySQL (IS_USED_LOCK returns the holder's
 * connection id, visible from ANY connection). A process-local boolean would be
 * invisible to exactly the writers this gate exists to stop.
 *
 * ensureHeld() is idempotent and RESUMABLE. If a link of the chain fails, Laravel
 * has already recorded the earlier links as applied: the next `migrate` starts in
 * the middle, link 1 never re-runs, and the dead process's connection released
 * the lock. Every link therefore calls ensureHeld() rather than trusting link 1.
 * GET_LOCK is re-entrant per connection with a reference count, so ensureHeld()
 * checks before acquiring — blindly re-acquiring would need N releases to unlock.
 *
 * SQLite is a single-process test engine: there the in-process flag IS the whole
 * world. That is honest, not a shortcut.
 *
 * @internal
 */
final class UpgradeGate
{
    /**
     * MySQL advisory locks are GLOBAL TO THE SERVER, not scoped to a schema. Two
     * larabill databases on one MySQL instance (staging + production, or two
     * tenants) would block each other's upgrades — and, worse, one database's
     * migration would make the OTHER database's application refuse writes.
     *
     * The lock name is therefore namespaced by the database it belongs to.
     */
    public static function lockName(): string
    {
        return 'larabill_v7_upgrade:'.substr(sha1((string) DB::getDatabaseName()), 0, 12);
    }

    /** SQLite-only state. On MySQL the database is the source of truth. */
    private static bool $heldInProcess = false;

    /**
     * Acquire the gate, or confirm this connection already holds it.
     *
     * Called by EVERY link of the chain (resume-safe), never only by the first.
     *
     * @throws RuntimeException when another connection holds the lock.
     */
    public static function ensureHeld(): void
    {
        if (! self::usesAdvisoryLock()) {
            self::$heldInProcess = true;

            return;
        }

        $holder = self::lockHolderConnectionId();

        if ($holder !== null) {
            if ($holder === self::currentConnectionId()) {
                return; // Already ours. Do NOT re-acquire: GET_LOCK counts.
            }

            throw new RuntimeException(
                'Another connection (id '.$holder.') holds the larabill v7 upgrade lock. '
                .'A concurrent migration run is in progress; refusing to proceed.'
            );
        }

        /** @var int|null $acquired */
        $acquired = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::lockName()])?->acquired;

        if ((int) $acquired !== 1) {
            throw new RuntimeException(
                'Could not acquire the larabill v7 upgrade lock ('.self::lockName().').'
            );
        }
    }

    public static function release(): void
    {
        if (self::usesAdvisoryLock()) {
            // RELEASE_LOCK is a no-op when we do not hold it: safe and idempotent.
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::lockName()]);
        }

        self::$heldInProcess = false;
    }

    /** Visible from ANY connection — that is the whole point. */
    public static function isHeld(): bool
    {
        if (! self::usesAdvisoryLock()) {
            return self::$heldInProcess;
        }

        return self::lockHolderConnectionId() !== null;
    }

    /**
     * Called at the top of every larabill write operation.
     *
     * @throws UpgradeInProgressException
     */
    public static function assertNotUpgrading(): void
    {
        if (self::isHeld()) {
            throw UpgradeInProgressException::make();
        }
    }

    private static function lockHolderConnectionId(): ?int
    {
        /** @var int|null $holder */
        $holder = DB::selectOne('SELECT IS_USED_LOCK(?) AS holder', [self::lockName()])?->holder;

        return $holder === null ? null : (int) $holder;
    }

    private static function currentConnectionId(): int
    {
        /** @var int $id */
        $id = DB::selectOne('SELECT CONNECTION_ID() AS id')?->id;

        return (int) $id;
    }

    private static function usesAdvisoryLock(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Unit/Support/UpgradeGateTest.php`
Expected: PASS — 4 passed.

- [ ] **Step 6: Wire the gate where the writes actually happen — the models**

A hand-kept list of service methods is a list that will be wrong the first time someone adds a service. The models are the choke point every Eloquent write passes through, so the gate goes there (in the same `booted()` the guards of Task 13 use, registered FIRST):

```php
// src/Models/Invoice.php and src/Models/InvoiceItem.php, in booted():
static::saving(fn () => UpgradeGate::assertNotUpgrading());
static::deleting(fn () => UpgradeGate::assertNotUpgrading());
```

Plus **one** service-level call, because it writes through the query builder and never touches a model:

- `src/Services/InvoiceNumberingService.php` — `generateNumber()`: `UpgradeGate::assertNotUpgrading();` as the first statement.

**Declared limit, and say it in `UPGRADE-7.0.md`:** raw query-builder and raw SQL writes from the consumer's own code bypass the gate entirely. That is why the operator instruction is *stop the workers* — the gate is a backstop for larabill's own paths, not a substitute for the maintenance window.

> The chain's own migrations write through the query builder, so they are unaffected by the model guard — which is exactly what we need: the gate must stop everyone EXCEPT the migration holding it.

- [ ] **Step 7: Test the wiring**

Append to `tests/Unit/Support/UpgradeGateTest.php`:

```php
it('refuses invoice numbering while the upgrade lock is held', function (): void {
    UpgradeGate::ensureHeld();

    app(\AichaDigital\Larabill\Services\InvoiceNumberingService::class)
        ->generateNumber('FAC', \AichaDigital\Larabill\Enums\InvoiceSerieType::INVOICE, null);
})->throws(UpgradeInProgressException::class);
```

Adjust the `generateNumber()` argument list to the real signature (read it first — do not guess).

Run: `php83 vendor/bin/pest tests/Unit/Support/UpgradeGateTest.php`
Expected: PASS — 6 passed.

- [ ] **Step 7b: Prove the gate is visible ACROSS connections (MySQL)**

This is the test that would have caught the process-local boolean. Create `tests/Integration/Mysql/UpgradeGateCrossConnectionTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\UpgradeGate;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

uses(TestCase::class);

afterEach(function (): void {
    UpgradeGate::release();
});

it('is visible from a SECOND connection — the worker this gate exists to stop', function (): void {
    UpgradeGate::ensureHeld();

    // A different connection to the same database: exactly what a queue worker,
    // a cron job or an artisan CLI is. A process-local flag is invisible here.
    DB::purge('second');
    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

    $holder = DB::connection('second')
        ->selectOne('SELECT IS_USED_LOCK(?) AS holder', [UpgradeGate::lockName()])
        ->holder;

    expect($holder)->not->toBeNull();
});

it('refuses a second concurrent migration run', function (): void {
    UpgradeGate::ensureHeld();

    // Simulate another migrate process by taking the lock's view from another
    // connection id: ensureHeld() must NOT silently proceed.
    // (Drive this by calling ensureHeld() on a connection that does not hold it.)
    DB::purge('second');
    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);

    $original = config('database.default');

    try {
        config(['database.default' => 'second']);

        expect(fn () => UpgradeGate::ensureHeld())
            ->toThrow(RuntimeException::class, 'holds the larabill v7 upgrade lock');
    } finally {
        // MUST be restored, or afterEach() releases from the WRONG connection and
        // the lock stays held for the rest of the suite.
        config(['database.default' => $original]);
    }
});
```

> Confirm the second-connection wiring against the package's testbench config before running — the shape above is the intent, not a guarantee that `config()` juggling is the cleanest route in this suite. What must be TRUE is: the lock is observable from a connection that did not take it, and a second `ensureHeld()` from a different connection throws.

Run: `php83 vendor/bin/pest tests/Integration/Mysql/UpgradeGateCrossConnectionTest.php`
Expected: PASS — 2 passed.

- [ ] **Step 8: Full suite + quality gates**

```bash
php83 vendor/bin/pest
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: all green. The gate is never held during normal tests, so no existing test changes behavior.

- [ ] **Step 9: Commit**

```bash
git add src/Support/UpgradeGate.php src/Exceptions/UpgradeInProgressException.php src/Services tests/Unit/Support/UpgradeGateTest.php
git commit -m "feat(v7): add the package-side upgrade write gate

A read-only preflight cannot stop concurrent writes and `artisan down`
stops HTTP only, not workers or cron. The v7 migration chain takes an
advisory lock; larabill's own write operations refuse while it is held.

Refs AID-459 (PR-1)"
```

---

### Task 3: The global preflight + its artisan command

The first migration of the chain is **read-only**: it detects every inconsistency that would make a v7 backfill lie, and aborts loudly before any mutation (spec §7.1). Operators run the same detector as an artisan command *before* the maintenance window.

**Files:**
- Create: `src/Support/V7Preflight.php`, `src/Support/V7PreflightReport.php`, `src/Console/LarabillV7PreflightCommand.php`
- Modify: `src/LarabillServiceProvider.php` (register the command)
- Test: `tests/Integration/UpgradePath/V7PreflightTest.php`

**Interfaces:**
- Consumes: nothing (raw `DB` queries against the pre-v7 schema).
- Produces:
  - `V7PreflightReport` — `readonly` value object: `blockers: array<int, string>`, `warnings: array<int, string>`, `attestations: array<int, string>`; `hasBlockers(): bool`; `toLines(): array<int, string>`.
  - `V7Preflight::run(): V7PreflightReport`
  - Command signature `larabill:v7-preflight`, exit code 1 when blockers exist.

**The detections (spec §7.1) — blockers unless stated:**

1. A proforma marked converted (`converted_invoice_id` set) whose target row does not exist.
2. `converted_invoice_id` pointing at a row that is itself a proforma (`serie = 0`).
3. Two proformas pointing at the same invoice.
4. Contradictory inverse links: `A.converted_invoice_id = B` while `B.proforma_id` is set and ≠ A.
5. Multiple invoices sharing one `proforma_id`.
6. **`proforma_id` pointing at a row that is NOT a proforma** (`serie ≠ 0`) — the canonical column is not validated by anything today.
7. **Self-link:** a row whose `proforma_id` or `converted_invoice_id` is its own id.
8. **Warning:** historical line incoherence — `taxable_amount ≠ round(quantity × unit_price / 100)` (quantity is Base-100). Reported with the declared precedence: **the line total wins** (§4.3).
9. **Warning:** numbered rows that are not fiscally coherent (a numbered row with `status = cancelled`).
10. **Currency:** the EUR premise is not a printed platitude — see below.

**All four link shapes must be handled, not just the one the backfill happens to read (review finding 8).** The pre-v7 database can hold:

| Shape | Meaning | Treatment |
|---|---|---|
| mirror only (`p.converted_invoice_id` set, `i.proforma_id` NULL) | the common legacy case (defect D2) | backfill repairs the canonical column |
| **canonical only** (`i.proforma_id` set, `p.converted_invoice_id` NULL) | someone wrote the canonical link directly | backfill **must repair the mirror** and classify the proforma `CONVERTED` — otherwise it lands as DRAFT/LEGACY with a live invoice hanging off it |
| both, agreeing | already coherent | untouched |
| both, contradicting | corrupt | **BLOCKER** (detection 4) |

The canonical-only shape is the one the first draft of this plan silently mishandled. Test all four, plus the non-proforma target and the self-link.

**The EUR premise is a BLOCKING gate, not a printed note (review finding 9).** A message nobody must acknowledge is not an attestation: the migration would print it and cheerfully write `EUR` over a database that may hold something else. The preflight therefore **fails** unless the operator has explicitly accepted it:

```php
// config/larabill.php
'upgrade' => [
    // v7 persists an explicit ISO-4217 currency and backfills EUR on every
    // existing row (constitution 15). larabill cannot verify this for you.
    // Set to true ONLY after confirming every historical amount is in EUR.
    'attest_currency_eur' => env('LARABILL_V7_ATTEST_CURRENCY_EUR', false),
],
```

`V7Preflight` emits a BLOCKER while that flag is false, naming the config key and the env var. The operator's acceptance is then a line in their repository — auditable, not a scrollback they may or may not have read.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/UpgradePath/V7PreflightTest.php`. This runs on the MySQL integration connection (the schema contract is only real there) and seeds legacy rows with raw inserts, since the v7 columns do not exist yet at preflight time.

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\V7Preflight;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestCase::class);

/**
 * Insert a minimal legacy invoice row directly, bypassing the model:
 * the preflight runs against the PRE-v7 schema, where no v7 column exists.
 *
 * @param  array<string, mixed>  $overrides
 */
function legacyInvoiceRow(array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('invoices')->insert(array_merge([
        'id'            => $id,
        'fiscal_number' => 'FAC-2025-'.random_int(100000, 999999),
        'prefix'        => 'FAC',
        'serie'         => 1, // InvoiceSerieType::INVOICE
        'series_number' => random_int(1, 999999),
        'fiscal_year'   => 2025,
        'invoice_date'  => '2025-06-01',
        'issued_at'     => '2025-06-01 10:00:00',
        'status'        => 0,
        'user_id'       => TestCase::USER_UUID_1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $overrides));

    return $id;
}

beforeEach(function (): void {
    // The EUR attestation is a BLOCKER when absent (by design). Every test that
    // expects the preflight to pass must therefore accept it explicitly — which is
    // itself a small proof that the gate works.
    config(['larabill.upgrade.attest_currency_eur' => true]);
});

it('blocks when the operator has not attested the EUR premise', function (): void {
    config(['larabill.upgrade.attest_currency_eur' => false]);

    $report = (new V7Preflight)->run();

    expect($report->hasBlockers())->toBeTrue()
        ->and(implode("\n", $report->blockers))->toContain('attest_currency_eur');
});

it('passes on a coherent legacy database', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO']);
    $invoice  = legacyInvoiceRow(['proforma_id' => $proforma]);

    DB::table('invoices')->where('id', $proforma)->update(['converted_invoice_id' => $invoice]);

    $report = (new V7Preflight)->run();

    expect($report->hasBlockers())->toBeFalse();
});

it('blocks when a converted proforma points at a row that does not exist', function (): void {
    legacyInvoiceRow([
        'serie'                => 0,
        'prefix'               => 'PRO',
        'converted_invoice_id' => (string) Str::uuid7(), // dangling
    ]);

    $report = (new V7Preflight)->run();

    expect($report->hasBlockers())->toBeTrue()
        ->and(implode("\n", $report->blockers))->toContain('converted_invoice_id');
});

it('blocks when two invoices share one proforma_id', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO']);
    legacyInvoiceRow(['proforma_id' => $proforma]);
    legacyInvoiceRow(['proforma_id' => $proforma]);

    $report = (new V7Preflight)->run();

    expect($report->hasBlockers())->toBeTrue()
        ->and(implode("\n", $report->blockers))->toContain('share one proforma_id');
});

it('blocks when two proformas point at the same invoice', function (): void {
    $invoice = legacyInvoiceRow();
    legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO', 'converted_invoice_id' => $invoice]);
    legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO', 'converted_invoice_id' => $invoice]);

    $report = (new V7Preflight)->run();

    expect($report->hasBlockers())->toBeTrue();
});

it('warns (never blocks) on historical line incoherence and declares line-total precedence', function (): void {
    $invoice = legacyInvoiceRow();

    DB::table('invoice_items')->insert([
        'invoice_id'       => $invoice,
        'item_type'        => 1,
        'description'      => 'Legacy line whose total does not match qty x unit price',
        'quantity'         => 300,   // Base-100: 3 units
        'unit_price'       => 1000,  // 10.00
        'taxable_amount'   => 2999,  // 29.99 — NOT 3 x 10.00
        'total_tax_amount' => 0,
        'total_amount'     => 2999,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $report = (new V7Preflight)->run();

    expect($report->hasBlockers())->toBeFalse()
        ->and(implode("\n", $report->warnings))->toContain('line total wins');
});
```

- [ ] **Step 2: Run it to verify it fails**

The MySQL integration suite needs the env described in `tests/Integration/Mysql/` (`LARABILL_TEST_MYSQL_*`).

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7PreflightTest.php`
Expected: FAIL — `Class "AichaDigital\Larabill\Support\V7Preflight" not found`.

- [ ] **Step 3: Write the report value object**

`src/Support/V7PreflightReport.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

/**
 * The outcome of the v7 global preflight (spec §7.1).
 *
 * Blockers abort the migration chain before any mutation. Warnings are recorded
 * and printed — they describe data the backfill will treat under a DECLARED
 * precedence rule, not data it will silently reinterpret. Attestations are
 * human decisions the software cannot make (constitution 15).
 *
 * @internal
 */
final readonly class V7PreflightReport
{
    /**
     * @param  array<int, string>  $blockers
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $attestations
     */
    public function __construct(
        public array $blockers = [],
        public array $warnings = [],
        public array $attestations = [],
    ) {}

    public function hasBlockers(): bool
    {
        return $this->blockers !== [];
    }

    /** @return array<int, string> */
    public function toLines(): array
    {
        $lines = [];

        foreach ($this->blockers as $blocker) {
            $lines[] = 'BLOCKER: '.$blocker;
        }

        foreach ($this->warnings as $warning) {
            $lines[] = 'WARNING: '.$warning;
        }

        foreach ($this->attestations as $attestation) {
            $lines[] = 'ATTESTATION REQUIRED: '.$attestation;
        }

        return $lines;
    }
}
```

- [ ] **Step 4: Write the preflight**

`src/Support/V7Preflight.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Illuminate\Support\Facades\DB;

/**
 * The read-only global preflight of the v7 upgrade chain (spec §7.1).
 *
 * It mutates NOTHING. It detects the inconsistencies that would make a v7
 * backfill write a lie — a "converted" proforma with no invoice, one invoice
 * claimed by two proformas, contradictory inverse links — and it surfaces the
 * historical line incoherence whose resolution rule (§4.3: THE LINE TOTAL WINS)
 * the operator must see before it is applied.
 *
 * Serie values are read as raw integers because the preflight runs against the
 * pre-v7 schema: 0 = PROFORMA, 1 = INVOICE, 2 = RECTIFICATIVE.
 *
 * @internal
 */
final class V7Preflight
{
    private const SERIE_PROFORMA = 0;

    public function run(): V7PreflightReport
    {
        $blockers = [];

        $blockers = array_merge(
            $blockers,
            $this->danglingConversionTargets(),
            $this->conversionTargetsThatAreProformas(),
            $this->invoicesClaimedByTwoProformas(),
            $this->contradictoryInverseLinks(),
            $this->proformasWithMultipleInvoices(),
            $this->canonicalLinksToNonProforma(),
            $this->selfLinks(),
            $this->missingCurrencyAttestation(),
        );

        return new V7PreflightReport(
            blockers: $blockers,
            warnings: array_merge($this->incoherentLines(), $this->numberedCancelledRows()),
            attestations: [
                'Currency: v7 backfills EUR on every existing row. Accepted via '
                .'`larabill.upgrade.attest_currency_eur` (env LARABILL_V7_ATTEST_CURRENCY_EUR).',
            ],
        );
    }

    /** The canonical column is validated by nothing today: it can point at a fiscal row. */
    private function canonicalLinksToNonProforma(): array
    {
        $rows = DB::table('invoices as i')
            ->join('invoices as p', 'p.id', '=', 'i.proforma_id')
            ->where('p.serie', '!=', self::SERIE_PROFORMA)
            ->pluck('i.id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' invoice(s) carry a proforma_id pointing at a row that is NOT a proforma: '
            .$rows->implode(', '),
        ];
    }

    /** @return array<int, string> */
    private function selfLinks(): array
    {
        $rows = DB::table('invoices')
            ->where(function ($query): void {
                $query->whereColumn('proforma_id', 'id')
                    ->orWhereColumn('converted_invoice_id', 'id');
            })
            ->pluck('id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' row(s) link to THEMSELVES via proforma_id or converted_invoice_id: '
            .$rows->implode(', '),
        ];
    }

    /**
     * The EUR premise is a human decision larabill cannot verify. An unacknowledged
     * warning is not an attestation: without an explicit acceptance, the migration
     * would print a note and then write EUR over data that may not be EUR.
     *
     * @return array<int, string>
     */
    private function missingCurrencyAttestation(): array
    {
        if (config('larabill.upgrade.attest_currency_eur', false) === true) {
            return [];
        }

        return [
            'Currency attestation missing. v7 persists an explicit ISO-4217 currency and backfills '
            .'EUR on EVERY existing row in invoices and invoice_items (constitution 15). larabill '
            .'cannot verify this for you. Confirm that every historical amount is denominated in '
            .'EUR, then set larabill.upgrade.attest_currency_eur = true '
            .'(env LARABILL_V7_ATTEST_CURRENCY_EUR=true) and re-run. If ANY historical row is not '
            .'EUR, STOP and correct it first.',
        ];
    }

    /** @return array<int, string> */
    private function danglingConversionTargets(): array
    {
        $rows = DB::table('invoices as p')
            ->leftJoin('invoices as i', 'i.id', '=', 'p.converted_invoice_id')
            ->whereNotNull('p.converted_invoice_id')
            ->whereNull('i.id')
            ->pluck('p.id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' proforma(s) carry a converted_invoice_id pointing at a row that does '
            .'not exist: '.$rows->implode(', '),
        ];
    }

    /** @return array<int, string> */
    private function conversionTargetsThatAreProformas(): array
    {
        $rows = DB::table('invoices as p')
            ->join('invoices as i', 'i.id', '=', 'p.converted_invoice_id')
            ->where('i.serie', self::SERIE_PROFORMA)
            ->pluck('p.id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' proforma(s) carry a converted_invoice_id pointing at another PROFORMA, '
            .'not at a fiscal invoice: '.$rows->implode(', '),
        ];
    }

    /** @return array<int, string> */
    private function invoicesClaimedByTwoProformas(): array
    {
        $rows = DB::table('invoices')
            ->select('converted_invoice_id')
            ->whereNotNull('converted_invoice_id')
            ->groupBy('converted_invoice_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('converted_invoice_id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' invoice(s) are claimed as the conversion target of MORE THAN ONE '
            .'proforma: '.$rows->implode(', '),
        ];
    }

    /** @return array<int, string> */
    private function contradictoryInverseLinks(): array
    {
        $rows = DB::table('invoices as p')
            ->join('invoices as i', 'i.id', '=', 'p.converted_invoice_id')
            ->whereNotNull('i.proforma_id')
            ->whereColumn('i.proforma_id', '!=', 'p.id')
            ->pluck('p.id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' proforma(s) have contradictory inverse links: the proforma points at an '
            .'invoice whose proforma_id points at a DIFFERENT proforma: '.$rows->implode(', '),
        ];
    }

    /** @return array<int, string> */
    private function proformasWithMultipleInvoices(): array
    {
        $rows = DB::table('invoices')
            ->select('proforma_id')
            ->whereNotNull('proforma_id')
            ->groupBy('proforma_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('proforma_id');

        return $rows->isEmpty() ? [] : [
            $rows->count().' proforma(s) share one proforma_id across MULTIPLE invoices. v7 conversion '
            .'is strictly 1:1 (the 1:N advance-invoice ticket is a separate release): '.$rows->implode(', '),
        ];
    }

    /**
     * Historical lines whose stored base does not equal round(quantity x unit_price).
     * quantity is Base-100, so the product must be divided by 100.
     *
     * NOT a blocker: §4.3 declares the resolution rule up front — the LINE TOTAL WINS,
     * because it is the amount the customer was billed. The operator must see the list.
     *
     * @return array<int, string>
     */
    private function incoherentLines(): array
    {
        $count = DB::table('invoice_items')
            ->whereRaw('taxable_amount <> ROUND(quantity * unit_price / 100)')
            ->count();

        return $count === 0 ? [] : [
            $count.' historical invoice line(s) store a taxable_amount that does not equal '
            .'round(quantity x unit_price). The v7 backfill applies the declared precedence: '
            .'the line total wins (contract_line_total := taxable_amount), and the residue is '
            .'recorded in unit_price_base_adjustment. No amount is rewritten.',
        ];
    }

    /** @return array<int, string> */
    private function numberedCancelledRows(): array
    {
        $count = DB::table('invoices')
            ->where('serie', '!=', self::SERIE_PROFORMA)
            ->where('status', 4) // InvoiceStatus::CANCELLED
            ->count();

        return $count === 0 ? [] : [
            $count.' numbered fiscal row(s) carry status = cancelled. v7 classifies every numbered '
            .'row as ISSUED on the document axis (a fiscal number was consumed); the legacy '
            .'cancellation stays in the collection axis. Review them before migrating.',
        ];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7PreflightTest.php`
Expected: PASS — 5 passed.

- [ ] **Step 6: Write the artisan command**

`src/Console/LarabillV7PreflightCommand.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use AichaDigital\Larabill\Support\V7Preflight;
use Illuminate\Console\Command;

/**
 * Runs the v7 upgrade preflight WITHOUT migrating anything (spec §7.1).
 *
 * Operators run this INSIDE the maintenance window, right after `composer update`
 * and before `migrate`: the command ships WITH v7, so it does not exist on the old
 * code, and updating while traffic is live would put the v7 runtime on the v6 schema.
 * It answers "will the chain abort on my data?" without touching a row.
 *
 * @api
 */
class LarabillV7PreflightCommand extends Command
{
    protected $signature = 'larabill:v7-preflight';

    protected $description = 'Check whether this database can be migrated to larabill v7 (read-only)';

    public function handle(V7Preflight $preflight): int
    {
        $report = $preflight->run();

        foreach ($report->toLines() as $line) {
            $this->line($line);
        }

        if ($report->hasBlockers()) {
            $this->error('Preflight FAILED. Fix the blockers above before running `php artisan migrate`.');

            return self::FAILURE;
        }

        $this->info('Preflight passed. Review the warnings and the attestation before migrating.');

        return self::SUCCESS;
    }
}
```

Register it in `src/LarabillServiceProvider.php` alongside the existing commands (find the `->hasCommands([...])` call and add `LarabillV7PreflightCommand::class`).

- [ ] **Step 7: Test the command's exit code**

Append to `tests/Integration/UpgradePath/V7PreflightTest.php`:

```php
it('exits non-zero from the artisan command when a blocker exists', function (): void {
    legacyInvoiceRow([
        'serie'                => 0,
        'prefix'               => 'PRO',
        'converted_invoice_id' => (string) Str::uuid7(),
    ]);

    $this->artisan('larabill:v7-preflight')->assertExitCode(1);
});
```

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7PreflightTest.php`
Expected: PASS — 6 passed.

- [ ] **Step 8: Quality gates + commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
php83 vendor/bin/pest
git add src/Support/V7Preflight.php src/Support/V7PreflightReport.php src/Console/LarabillV7PreflightCommand.php src/LarabillServiceProvider.php tests/Integration/UpgradePath/V7PreflightTest.php
git commit -m "feat(v7): add the read-only upgrade preflight and its artisan command

Detects the link inconsistencies that would make a v7 backfill write a
lie, surfaces the historical line incoherence under its declared
precedence rule (the line total wins), and demands the EUR attestation.

Mutates nothing. Operators run larabill:v7-preflight before the window.

Refs AID-459 (PR-1)"
```

---

### Task 4: Migration 1 — the preflight gate

The first link of the chain: run the preflight, abort loudly on blockers, and take the write gate for the rest of the chain. It creates no table and mutates no row.

**Files:**
- Create: `database/migrations/2026_07_20_000001_v7_preflight_gate.php` (+ its `.php.stub`)
- Test: `tests/Integration/UpgradePath/V7ChainTest.php`

**Interfaces:**
- Consumes: `V7Preflight::run()`, `UpgradeGate::ensureHeld()` (Tasks 2–3).
- Produces: the write gate, held for the whole chain. **Every** link calls `ensureHeld()` in both `up()` and `down()`; **only this link releases**, and only in its `down()` — on the way down it is the last to run, so releasing anywhere else would leave the remaining rollbacks unprotected.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/UpgradePath/V7ChainTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\UpgradeGate;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestCase::class);

it('aborts the migration chain when the preflight finds a blocker', function (): void {
    // Seed a dangling conversion target, then run ONLY the preflight migration.
    DB::table('invoices')->insert([
        'id'                   => (string) Str::uuid7(),
        'fiscal_number'        => 'PRO-2025-000001',
        'prefix'               => 'PRO',
        'serie'                => 0,
        'series_number'        => 1,
        'fiscal_year'          => 2025,
        'invoice_date'         => '2025-06-01',
        'issued_at'            => '2025-06-01 10:00:00',
        'status'               => 0,
        'user_id'              => TestCase::USER_UUID_1,
        'converted_invoice_id' => (string) Str::uuid7(), // dangling
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    $migration = require __DIR__.'/../../../database/migrations/2026_07_20_000001_v7_preflight_gate.php';

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'larabill v7 preflight FAILED');

    // The gate must NOT stay held after an aborted preflight.
    expect(UpgradeGate::isHeld())->toBeFalse();
});

it('holds the write gate once the preflight passes', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/2026_07_20_000001_v7_preflight_gate.php';

    $migration->up();

    expect(UpgradeGate::isHeld())->toBeTrue();

    UpgradeGate::release();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7ChainTest.php`
Expected: FAIL — `Failed to open stream: ... 2026_07_20_000001_v7_preflight_gate.php`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000001_v7_preflight_gate.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\UpgradeGate;
use AichaDigital\Larabill\Support\V7Preflight;
use Illuminate\Database\Migrations\Migration;

/**
 * v7 upgrade chain — link 1 of 13: preflight + write gate (spec §7).
 *
 * This migration creates nothing and mutates nothing. It does two things:
 *
 * 1. Runs the READ-ONLY global preflight. If it finds a blocker — a "converted"
 *    proforma with no invoice, one invoice claimed by two proformas,
 *    contradictory inverse links — the chain aborts HERE, before any v7 column
 *    exists. A backfill over incoherent links would write a lie that no later
 *    migration could detect.
 *
 * 2. Takes the package-side write gate. A read-only check cannot stop concurrent
 *    writes, and `php artisan down` stops HTTP but not workers, cron or CLI. From
 *    here until the last link of the chain, larabill's own write operations refuse
 *    to run (UpgradeInProgressException). The operator instruction stays: stop
 *    workers, enable maintenance, then migrate.
 *
 * The gate is RELEASED by 2026_07_20_000013_add_v7_coherence_constraints.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GATE FIRST, then check. Checking first and locking second leaves a window
        // in which a worker mutates the very rows the preflight just declared clean.
        UpgradeGate::ensureHeld();

        $report = (new V7Preflight)->run();

        foreach ($report->toLines() as $line) {
            // Migrations have no $this->line(); the report belongs in the operator's output.
            fwrite(STDOUT, $line.PHP_EOL);
        }

        if ($report->hasBlockers()) {
            // Do not leave the gate held on a database we refused to migrate.
            UpgradeGate::release();

            throw new RuntimeException(
                'larabill v7 preflight FAILED. The blockers listed above must be fixed before '
                .'migrating. No v7 schema change has been applied. Re-run `php artisan '
                .'larabill:v7-preflight` after fixing them.'
            );
        }
    }

    /**
     * On the way down, link 1 is the LAST to run — so this is where the gate is
     * released, and only here. Every other link's down() calls ensureHeld() first:
     * a rollback executed on an unprotected database is exactly as dangerous as an
     * upgrade executed on one, and a failed rollback must be resumable.
     *
     * A held gate on a database with no v7 schema would refuse every write for
     * nothing, so the release is unconditional.
     */
    public function down(): void
    {
        UpgradeGate::release();
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7ChainTest.php`
Expected: PASS — 2 passed.

- [ ] **Step 5: Bootstrap the stub (manual copy — the sync script does not create it)**

```bash
cp database/migrations/2026_07_20_000001_v7_preflight_gate.php \
   database/migrations/2026_07_20_000001_v7_preflight_gate.php.stub
php83 bin/sync-migration-stubs
```
Expected: the script reports the stub **unchanged** (byte-identical, ADR-007).

- [ ] **Step 5b: Close the contract pieces for THIS migration (same commit)**

Every migration lands with its five pieces. No commit in this PR leaves the contract tests red.

1. `src/Console/LarabillInstallCommand.php` — append `'039' => 'v7_preflight_gate',` (the array ends at `'038'`; **never reuse an existing key**).
2. `php83 bin/sync-upgrade-manifest` — adds the entry with `"in_base": false`. Verify with `git diff` that **no existing `sha256` changed**.
3. `tests/Integration/InstallMysql/InstallCommandSchemaTest.php` — bump the pinned count by one (35 → 36; it reaches 48 when all 13 have landed).

```bash
php83 vendor/bin/pest tests/Unit/Console/MigrationOrderConsistencyTest.php
php83 vendor/bin/pest tests/Contract
```
Expected: both green **before** you commit.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_20_000001_v7_preflight_gate.php* \
        src/Console/LarabillInstallCommand.php tests/Contract/release-migration-manifest.json \
        tests/Integration/InstallMysql/InstallCommandSchemaTest.php \
        tests/Integration/UpgradePath/V7ChainTest.php
git commit -m "feat(v7): migration 1 — preflight gate

Takes the write gate BEFORE checking (checking first would leave a window
for a writer to dirty the rows just declared clean), then aborts the chain
before any v7 mutation when the data cannot support a truthful backfill.

Refs AID-459 (PR-1)"
```

> **Every subsequent migration repeats Step 5b with its own key (`'040'`, `'041'`, …) and its own count bump.** The plan does not restate it each time; it is a Global Constraint. A commit that leaves `MigrationOrderConsistencyTest` red is a broken commit, not a work-in-progress.

> **Every subsequent migration also starts its `up()` with `UpgradeGate::ensureHeld();`** — the chain must re-establish the gate when a resumed `migrate` skips link 1. This too is not restated per task.

---

### Task 5: The tax-truth tables — epochs, determinations, components

Three tables, one deliverable: the provable interval (`tax_catalog_epochs`), the immutable determination and its component breakdown. They must land together — a determination without its epoch has no provenance, and a determination without its components has no breakdown.

PR-1 creates the **schema only**. The resolver, the epoch closure and the hash computation are PR-2.

**Files:**
- Create: `database/migrations/2026_07_20_000002_create_tax_catalog_epochs_table.php`, `..._000003_create_tax_determinations_table.php`, `..._000004_create_tax_determination_components_table.php` (+ their `.php.stub`)
- Test: `tests/Integration/Mysql/V7TaxTruthSchemaTest.php`

**Interfaces:**
- Consumes: the enums of Task 1 (`EpochIntegrity`, `PriceTaxMode`, `OperationNature`).
- Produces:
  - `tax_catalog_epochs` — PK `uuid id`; `revision` (unsigned big int, unique); `observed_from` (timestamp); `closed_at` (timestamp, nullable); `rule_set_hash` char(64); `integrity` tinyint (`EpochIntegrity`); `declared_effective_at` (timestamp, nullable); timestamps. **Single-active enforced** in Task 12 / link 13 (same generated-column technique as the active link; both are applied after the data settles).
  - `tax_determinations` — PK `uuid id`; `invoice_item_id` (FK `invoice_items`, restrict); `fiscal_obligation_id` (uuid, nullable — FK added in Task 7, after the obligations table exists); `epoch_id` (FK epochs, restrict); `epoch_revision`, `epoch_hash`; `resolver_version` (string); `source` enum-ish tinyint (`0=epoch`, `1=override`); `accrual_date` (date); `requested_effective_date` (date); `resolved_at` (timestamp); `currency` char(3); `taxable_amount`, `total_tax_amount`, `total_amount` (Base-100 ints); `rounding_adjustment` (signed int, default 0 — the ≤1-cent explicit adjustment of §4.3); `rounding_rule` (string); `price_tax_mode` tinyint; `operation_nature` tinyint; `supersedes_determination_id` (uuid, nullable, unique self-FK); `override_actor`, `override_reason`, `override_source` (nullable); `is_active` (bool, default true); timestamps.
  - `tax_determination_components` — PK `id`; `tax_determination_id` (FK, cascade); `tax_rate_id` (FK `tax_rates`, restrict); `tax_type` tinyint; `rate` (int, Base-100 of the percentage — same convention as `tax_rates.rate`); `base_amount`, `quota_amount` (Base-100 ints); `is_rounding_adjusted` (bool, default false); timestamps; `unique(tax_determination_id, tax_rate_id)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mysql/V7TaxTruthSchemaTest.php`. This asserts against MySQL, where column types and indexes are real (SQLite collapses them — see the umbrella note on `tests/Integration/Mysql/`).

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

it('creates the tax catalog epochs table with a provable interval', function (): void {
    expect(Schema::hasTable('tax_catalog_epochs'))->toBeTrue()
        ->and(Schema::hasColumns('tax_catalog_epochs', [
            'id', 'revision', 'observed_from', 'closed_at', 'rule_set_hash',
            'integrity', 'declared_effective_at',
        ]))->toBeTrue()
        ->and(Schema::getColumnType('tax_catalog_epochs', 'rule_set_hash'))->toBe('char');
});

it('creates the determinations table carrying its own provenance', function (): void {
    expect(Schema::hasColumns('tax_determinations', [
        'id', 'invoice_item_id', 'fiscal_obligation_id', 'epoch_id', 'epoch_revision',
        'epoch_hash', 'resolver_version', 'source', 'accrual_date', 'requested_effective_date',
        'resolved_at', 'currency', 'taxable_amount', 'total_tax_amount', 'total_amount',
        'rounding_adjustment', 'rounding_rule', 'price_tax_mode', 'operation_nature',
        'supersedes_determination_id', 'override_actor', 'override_reason', 'override_source',
        'is_active',
    ]))->toBeTrue();
});

it('stores every determination amount as a Base-100 integer, never a decimal', function (): void {
    foreach (['taxable_amount', 'total_tax_amount', 'total_amount', 'rounding_adjustment'] as $column) {
        expect(Schema::getColumnType('tax_determinations', $column))->toBeIn(['integer', 'int']);
    }
});

it('creates the component breakdown with one row per rate', function (): void {
    expect(Schema::hasColumns('tax_determination_components', [
        'id', 'tax_determination_id', 'tax_rate_id', 'tax_type', 'rate',
        'base_amount', 'quota_amount', 'is_rounding_adjusted',
    ]))->toBeTrue();
});

it('forbids two components for the same rate inside one determination', function (): void {
    $indexes = collect(Schema::getIndexes('tax_determination_components'));

    expect($indexes->firstWhere('name', 'tax_det_components_determination_rate_unique'))->not->toBeNull()
        ->and($indexes->firstWhere('name', 'tax_det_components_determination_rate_unique')['unique'])->toBeTrue();
});

it('forbids two determinations superseding the same determination', function (): void {
    $indexes = collect(Schema::getIndexes('tax_determinations'));

    expect($indexes->firstWhere('name', 'tax_determinations_supersedes_unique')['unique'])->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7TaxTruthSchemaTest.php`
Expected: FAIL — `Schema::hasTable('tax_catalog_epochs')` is false.

- [ ] **Step 3: Write the epochs migration**

`database/migrations/2026_07_20_000002_create_tax_catalog_epochs_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 2 of 13: catalog epochs (spec §5.2).
 *
 * Timestamp inference is REJECTED as proof of what the catalog held on a past
 * date: deletes destroy evidence, pivots have no history, imports bypass
 * timestamps, and code changes never appear in tables at all.
 *
 * An epoch is the PROVABLE INTERVAL: from `observed_from` until `closed_at`, the
 * rule set hashed as `rule_set_hash` was what the system observed. Governed
 * catalog mutations close the active epoch and open the next one in the SAME
 * transaction. A hash mismatch with no governing mutation marks the epoch
 * COMPROMISED — never silently re-opened.
 *
 * Honest limit, stated in the spec: epochs prove system-observed stability, not
 * juridical correctness. The first epoch starts at install.
 *
 * The single-active-epoch constraint (exactly one row with closed_at IS NULL) is
 * applied in link 13, with the same generated-column technique as the active
 * conversion link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_catalog_epochs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            $table->unsignedBigInteger('revision')->unique()->comment('Monotonic epoch revision; pinned on every determination');
            $table->timestamp('observed_from')->comment('Start of the provable interval');
            $table->timestamp('closed_at')->nullable()->comment('End of the interval. NULL = the active epoch (exactly one, enforced in link 13)');

            $table->char('rule_set_hash', 64)->comment('SHA-256 over the canonical JSON serialization of the rule set (spec §5.2)');
            $table->unsignedTinyInteger('integrity')->default(0)->comment('EpochIntegrity enum: 0=intact, 1=compromised');

            $table->timestamp('declared_effective_at')->nullable()->comment('Operator annotation on the closing mutation: when the rule change was MEANT to take effect. Audit only — never authority.');

            $table->timestamps();

            $table->index(['observed_from', 'closed_at'], 'tax_catalog_epochs_interval_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_catalog_epochs');
    }
};
```

- [ ] **Step 4: Write the determinations migration**

`database/migrations/2026_07_20_000003_create_tax_determinations_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 3 of 13: tax determinations (spec §5.3/§5.4).
 *
 * A determination is the IMMUTABLE fiscal truth of one line at one accrual date.
 * It carries its own provenance — which epoch, which hash, which resolver
 * version, resolved from the epoch or from an audited override — so that years
 * later the row itself answers "why this rate?" without consulting a catalog
 * that has since changed.
 *
 * Conversion COPIES a determination onto the invoice line. It never re-resolves
 * (the defect D4 this release exists to kill).
 *
 * `fiscal_obligation_id` is nullable here and gets its FK in link 9: the
 * obligations table does not exist yet at this point in the chain.
 *
 * Amounts are Base-100 integers (never decimal). `rounding_adjustment` is the
 * explicit, persisted, at-most-one-cent adjustment of §4.3 — the reason
 * invariant (b) is formally RELAXED rather than silently violated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_determinations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            $table->foreignId('invoice_item_id')->constrained('invoice_items')->restrictOnDelete()->comment('The line this determination fixes');
            $table->uuid('fiscal_obligation_id')->nullable()->comment('The accrual this determination resolves. FK added in link 9.');

            // Provenance — the whole point of the table.
            $table->foreignUuid('epoch_id')->constrained('tax_catalog_epochs')->restrictOnDelete()->comment('The provable interval this resolution was made under');
            $table->unsignedBigInteger('epoch_revision')->comment('Denormalized epoch revision (survives even a corrupted epochs table)');
            $table->char('epoch_hash', 64)->comment('Denormalized rule-set hash at resolution time');
            $table->string('resolver_version', 32)->comment('Resolver algorithm version, pinned PER DETERMINATION (rolling deploys make per-epoch versions non-atomic)');
            $table->unsignedTinyInteger('source')->default(0)->comment('0=epoch (proved), 1=override (a human decided what larabill could not prove)');

            // Dates.
            $table->date('accrual_date')->comment('The date the tax accrued (art. 75 LIVA). The rule is resolved AT this date.');
            $table->date('requested_effective_date')->comment('The date the caller asked the resolver about');
            $table->timestamp('resolved_at')->comment('When the resolution physically happened');

            // Amounts (Base-100 integers).
            $table->char('currency', 3)->default('EUR')->comment('ISO-4217. The v7 resolver admits EUR only and fails loud otherwise (constitution 15)');
            $table->integer('taxable_amount')->default(0)->comment('Base-100: the CANONICAL net base of the line');
            $table->integer('total_tax_amount')->default(0)->comment('Base-100: sum of the component quotas');
            $table->integer('total_amount')->default(0)->comment('Base-100: taxable_amount + total_tax_amount');
            $table->integer('rounding_adjustment')->default(0)->comment('Base-100, signed, |value| <= 1: the explicit at-most-one-cent adjustment on ONE component (spec §4.3). Part of the displayed breakdown, never hidden.');
            $table->string('rounding_rule', 32)->default('half_up')->comment('The rounding rule applied, recorded so the breakdown is reproducible');

            // Frozen inputs, copied so the determination is self-contained.
            $table->unsignedTinyInteger('price_tax_mode')->comment('PriceTaxMode enum: 0=tax_exclusive, 1=tax_inclusive');
            $table->unsignedTinyInteger('operation_nature')->comment('OperationNature enum: 0=goods, 1=one-off service, 2=successive tract');

            // Correction (pre-issuance only).
            $table->uuid('supersedes_determination_id')->nullable()->comment('Audited re-determination: the determination this one replaces. Legal only while no ISSUED invoice consumed it.');
            $table->string('override_actor')->nullable()->comment('Who decided, when source = override');
            $table->text('override_reason')->nullable();
            $table->string('override_source')->nullable()->comment('The documentary source of the human decision');

            $table->boolean('is_active')->default(true)->comment('Exactly one ACTIVE determination per line per obligation (enforced in link 13)');

            $table->timestamps();

            $table->foreign('supersedes_determination_id')->references('id')->on('tax_determinations')->restrictOnDelete();
            $table->unique('supersedes_determination_id', 'tax_determinations_supersedes_unique');
            $table->index(['invoice_item_id', 'is_active'], 'tax_determinations_item_active_index');
            $table->index('accrual_date', 'tax_determinations_accrual_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_determinations');
    }
};
```

- [ ] **Step 5: Write the components migration**

`database/migrations/2026_07_20_000004_create_tax_determination_components_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 4 of 13: the determination breakdown (spec §4.3/§5.3).
 *
 * One row per tax component of one determination. v7 ships a CLOSED composition
 * algebra: additive components over the SAME base (VAT + recargo de
 * equivalencia). Withholdings, cascading and different-base compositions fail
 * loud and belong to their own ticket — this table is deliberately not shaped to
 * silently accommodate them.
 *
 * Binding invariants (proved by property test in PR-2):
 *   net base + SUM(quota_amount) == the bound gross, exactly, per line;
 *   each quota derives from base x rate under the documented rounding, EXCEPT
 *   for at most one component flagged is_rounding_adjusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_determination_components', function (Blueprint $table): void {
            $table->id();

            $table->foreignUuid('tax_determination_id')->constrained('tax_determinations')->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->restrictOnDelete()->comment('The rate identity resolved from the epoch');

            $table->unsignedTinyInteger('tax_type')->comment('TaxType enum: which tax this component is');
            $table->integer('rate')->comment('Base-100 of the percentage, same convention as tax_rates.rate (21%% = 2100)');
            $table->integer('base_amount')->default(0)->comment('Base-100: the base this component was applied to');
            $table->integer('quota_amount')->default(0)->comment('Base-100: the resulting quota');
            $table->boolean('is_rounding_adjusted')->default(false)->comment('True on the AT MOST ONE component carrying the determination rounding_adjustment');

            $table->timestamps();

            $table->unique(['tax_determination_id', 'tax_rate_id'], 'tax_det_components_determination_rate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_determination_components');
    }
};
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7TaxTruthSchemaTest.php`
Expected: PASS — 6 passed.

- [ ] **Step 7: Run the SQLite suite too**

The unit suite auto-loads every timestamped migration (`tests/TestCase::defineDatabaseMigrations`), so a broken migration breaks everything.

Run: `php83 vendor/bin/pest`
Expected: green. The contract gates were closed in this migration's own commit (Global Constraints) — a red `MigrationOrderConsistencyTest` here means a previous task committed a broken state.

- [ ] **Step 8: Bootstrap the three stubs + commit**

```bash
for m in 2026_07_20_000002_create_tax_catalog_epochs_table \
         2026_07_20_000003_create_tax_determinations_table \
         2026_07_20_000004_create_tax_determination_components_table; do
  cp "database/migrations/$m.php" "database/migrations/$m.php.stub"
done
php83 bin/sync-migration-stubs
php83 vendor/bin/pint
git add database/migrations/2026_07_20_00000[234]* tests/Integration/Mysql/V7TaxTruthSchemaTest.php
git commit -m "feat(v7): migrations 2-4 — catalog epochs, determinations, components

The provable interval and the immutable determination that carries its own
provenance (epoch, hash, resolver version, epoch-or-override source), so a
determination answers 'why this rate' years later without the live catalog.

Schema only; the resolver and epoch closure are PR-2.

Refs AID-459 (PR-1)"
```

---

### Task 6: Migration 5 — the v7 lifecycle columns on `invoices`

The three state axes, the currency, the supersession self-FK, and the **nullable fiscal fields** a PREPARED invoice needs (it is not numbered yet). Also hardens `proforma_id`'s FK from `nullOnDelete` to `restrict`: a proforma whose deletion silently NULLs the fiscal link of an issued invoice is exactly the kind of quiet corruption v7 exists to end.

Columns only. The CHECK constraints and the active-link unique index come in Task 12 (link 13), **after** the backfill has made every row coherent — a constraint applied before the data settles would abort the chain on legacy rows.

**Files:**
- Create: `database/migrations/2026_07_20_000005_add_v7_lifecycle_columns_to_invoices.php` (+ `.php.stub`)
- Test: `tests/Integration/Mysql/V7InvoiceSchemaTest.php`

**Interfaces:**
- Consumes: enums from Task 1.
- Produces on `invoices`: `proforma_status` (nullable tinyint), `invoice_document_status` (nullable tinyint), `fiscal_submission_status` (nullable tinyint), `currency` (char(3), default `EUR`), `supersedes_proforma_id` (nullable uuid, unique self-FK, restrict), `converted_at` (already exists — untouched). Made nullable: `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`. `prefix` stays NOT NULL.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mysql/V7InvoiceSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestCase::class);

it('adds the three v7 state axes to invoices', function (): void {
    expect(Schema::hasColumns('invoices', [
        'proforma_status', 'invoice_document_status', 'fiscal_submission_status',
        'currency', 'supersedes_proforma_id',
    ]))->toBeTrue();
});

it('lets a PREPARED invoice exist without a fiscal number', function (): void {
    // The whole point of splitting conversion from issuance (defect D6): a PREPARED
    // invoice is a real row that has NOT consumed a correlative.
    $id = (string) Str::uuid7();

    DB::table('invoices')->insert([
        'id'                      => $id,
        'fiscal_number'           => null,
        'prefix'                  => 'FAC',
        'serie'                   => 1,
        'series_number'           => null,
        'fiscal_year'             => null,
        'invoice_date'            => null,
        'issued_at'               => null,
        'status'                  => 0,
        'invoice_document_status' => 1, // PREPARED
        'currency'                => 'EUR',
        'user_id'                 => TestCase::USER_UUID_1,
        'created_at'              => now(),
        'updated_at'              => now(),
    ]);

    expect(DB::table('invoices')->where('id', $id)->exists())->toBeTrue();
});

it('allows many unnumbered rows to coexist (a nullable unique tolerates NULLs)', function (): void {
    foreach (range(1, 3) as $i) {
        DB::table('invoices')->insert([
            'id'                      => (string) Str::uuid7(),
            'fiscal_number'           => null,
            'prefix'                  => 'FAC',
            'serie'                   => 1,
            'series_number'           => null,
            'fiscal_year'             => null,
            'invoice_date'            => null,
            'issued_at'               => null,
            'status'                  => 0,
            'invoice_document_status' => 1,
            'currency'                => 'EUR',
            'user_id'                 => TestCase::USER_UUID_1,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }

    expect(DB::table('invoices')->whereNull('fiscal_number')->count())->toBe(3);
});

it('forbids two proformas superseding the same proforma', function (): void {
    $indexes = collect(Schema::getIndexes('invoices'));

    expect($indexes->firstWhere('name', 'invoices_supersedes_proforma_unique')['unique'])->toBeTrue();
});

it('defaults the currency of every row to EUR', function (): void {
    expect(Schema::getColumnType('invoices', 'currency'))->toBe('char');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7InvoiceSchemaTest.php`
Expected: FAIL — `Schema::hasColumns` false.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000005_add_v7_lifecycle_columns_to_invoices.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 5 of 13: the lifecycle columns on `invoices` (spec §7.1).
 *
 * Three axes, deliberately separate (constitution 8):
 *
 *   proforma_status          the proforma document      (NULL on fiscal rows)
 *   invoice_document_status  the fiscal document        (NULL on proformas)
 *   fiscal_submission_status the AEAT registration      (NULL until issuance)
 *
 * The legacy `status` column (InvoiceStatus) keeps the delivery/collection axis
 * with reduced authority; separating THAT is out of scope (its own ticket).
 *
 * The fiscal fields become NULLABLE because a PREPARED invoice is a real row
 * that has not consumed a correlative — the split of conversion from issuance
 * is the whole point of defect D6. The bidirectional CHECK that makes a numbered
 * non-ISSUED row impossible lands in link 13, after the backfill.
 *
 * `proforma_id`'s FK is hardened from nullOnDelete to RESTRICT: silently NULLing
 * the fiscal origin of an issued invoice because someone deleted a proforma is
 * exactly the quiet corruption this release exists to end.
 *
 * Every DDL operation is introspection-guarded so a partially-applied failed run
 * re-runs cleanly (spec §7, Codex 65).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'proforma_status')) {
                $table->unsignedTinyInteger('proforma_status')->nullable()->after('status')
                    ->comment('ProformaStatus enum (spec §4.1). NOT NULL iff serie = PROFORMA. 0=draft 1=frozen 2=converting 3=converted 4=superseded 5=cancelled 6=legacy');
            }

            if (! Schema::hasColumn('invoices', 'invoice_document_status')) {
                $table->unsignedTinyInteger('invoice_document_status')->nullable()->after('proforma_status')
                    ->comment('InvoiceDocumentStatus enum (spec §6.1). NULL on proformas. 0=draft 1=prepared 2=issued 3=cancelled');
            }

            if (! Schema::hasColumn('invoices', 'fiscal_submission_status')) {
                $table->unsignedTinyInteger('fiscal_submission_status')->nullable()->after('invoice_document_status')
                    ->comment('FiscalSubmissionStatus enum (spec §6.1). Set at issuance ONLY. 0=not_required 1=pending 2=registered 3=action_required 4=content_rejected 5=legacy_unknown');
            }

            if (! Schema::hasColumn('invoices', 'currency')) {
                $table->char('currency', 3)->default('EUR')->after('total_amount')
                    ->comment('ISO-4217. Validated equal across the whole billing aggregate (constitution 15)');
            }

            if (! Schema::hasColumn('invoices', 'supersedes_proforma_id')) {
                $table->uuid('supersedes_proforma_id')->nullable()->after('proforma_id')
                    ->comment('Supersession: the proforma this one replaces (spec §4.2). Single FK; the inverse is a query.');
            }
        });

        // Nullable fiscal fields: a PREPARED invoice is not numbered.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('fiscal_number')->nullable()->change();
            $table->unsignedBigInteger('series_number')->nullable()->change();
            $table->year('fiscal_year')->nullable()->change();
            $table->date('invoice_date')->nullable()->change();
            $table->timestamp('issued_at')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('invoices'))->pluck('name');

            if (! $indexes->contains('invoices_supersedes_proforma_unique')) {
                $table->foreign('supersedes_proforma_id')->references('id')->on('invoices')->restrictOnDelete();
                $table->unique('supersedes_proforma_id', 'invoices_supersedes_proforma_unique');
            }
        });

        // Harden the canonical conversion link: nullOnDelete -> restrict.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['proforma_id']);
            $table->foreign('proforma_id')->references('id')->on('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['proforma_id']);
            $table->foreign('proforma_id')->references('id')->on('invoices')->nullOnDelete();

            $table->dropUnique('invoices_supersedes_proforma_unique');
            $table->dropForeign(['supersedes_proforma_id']);

            $table->dropColumn([
                'proforma_status',
                'invoice_document_status',
                'fiscal_submission_status',
                'currency',
                'supersedes_proforma_id',
            ]);
        });

        // Restoring NOT NULL is only safe when no unnumbered row survives.
        // Documented in UPGRADE-7.0.md: roll back BEFORE preparing any invoice.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('fiscal_number')->nullable(false)->change();
            $table->unsignedBigInteger('series_number')->nullable(false)->change();
            $table->year('fiscal_year')->nullable(false)->change();
            $table->date('invoice_date')->nullable(false)->change();
            $table->timestamp('issued_at')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7InvoiceSchemaTest.php`
Expected: PASS — 5 passed.

- [ ] **Step 5: Bootstrap the stub + commit**

```bash
cp database/migrations/2026_07_20_000005_add_v7_lifecycle_columns_to_invoices.php \
   database/migrations/2026_07_20_000005_add_v7_lifecycle_columns_to_invoices.php.stub
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_000005* tests/Integration/Mysql/V7InvoiceSchemaTest.php
git commit -m "feat(v7): migration 5 — the three state axes on invoices

Proforma document, fiscal document and fiscal submission become separate,
persisted axes; the fiscal fields turn nullable so a PREPARED invoice can
exist without consuming a correlative; proforma_id's FK is hardened to
restrict.

Refs AID-459 (PR-1)"
```

---

### Task 7: Migration 6 — the frozen contract columns on `invoice_items`

What FROZEN actually freezes (§4.3): the **commercial contract** (`contract_unit_price`, `contract_line_total`, `price_tax_mode`) — distinct from the fiscal net `unit_price`, whose semantics never change — plus the explicit arithmetic reconciliation (`unit_price_base_adjustment`), the frozen fiscal classification the resolver reads, and the single-consumption link to a determination.

**Files:**
- Create: `database/migrations/2026_07_20_000006_add_v7_contract_columns_to_invoice_items.php` (+ `.php.stub`)
- Test: `tests/Integration/Mysql/V7InvoiceItemSchemaTest.php`

**Interfaces:**
- Consumes: `tax_determinations` (Task 5), `PriceTaxMode` / `OperationNature` (Task 1), `tax_groups`.
- Produces on `invoice_items`: `contract_unit_price` (int), `contract_line_total` (int), `price_tax_mode` (tinyint), `unit_price_base_adjustment` (signed int, default 0), `operation_nature` (nullable tinyint), `frozen_tax_group_id` (nullable FK `tax_groups`), `frozen_jurisdiction` (nullable char(2)), `frozen_is_exempt` (bool), `frozen_is_reverse_charge` (bool), `frozen_user_tax_profile_id` (nullable), `tax_determination_id` (nullable uuid, **unique** — single consumption), `currency` (char(3), default EUR).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mysql/V7InvoiceItemSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

it('adds the frozen commercial contract to every line', function (): void {
    expect(Schema::hasColumns('invoice_items', [
        'contract_unit_price', 'contract_line_total', 'price_tax_mode',
        'unit_price_base_adjustment', 'currency',
    ]))->toBeTrue();
});

it('adds the frozen fiscal classification the resolver reads', function (): void {
    // §5.3: the resolver reads ONLY these columns. Not the live catalog, not the
    // customer's current profile — the facts as they were frozen.
    expect(Schema::hasColumns('invoice_items', [
        'operation_nature', 'frozen_tax_group_id', 'frozen_jurisdiction',
        'frozen_is_exempt', 'frozen_is_reverse_charge', 'frozen_user_tax_profile_id',
    ]))->toBeTrue();
});

it('stores the base adjustment as a signed integer', function (): void {
    // It may exceed one cent for large quantities and is then NOT called rounding
    // (spec §4.3) — but it is always Base-100 and always signed.
    expect(Schema::getColumnType('invoice_items', 'unit_price_base_adjustment'))->toBeIn(['integer', 'int']);
});

it('lets a determination be consumed by at most one invoice line', function (): void {
    $indexes = collect(Schema::getIndexes('invoice_items'));

    expect($indexes->firstWhere('name', 'invoice_items_tax_determination_unique')['unique'])->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7InvoiceItemSchemaTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000006_add_v7_contract_columns_to_invoice_items.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 6 of 13: the frozen contract on `invoice_items` (spec §4.3/§7.2).
 *
 * FROZEN freezes the COMMERCIAL CONTRACT, not a fiscal estimate:
 *
 *   contract_unit_price   what the customer agreed to pay per unit
 *   contract_line_total   THE BINDING AMOUNT (line level — the unit x quantity
 *                         residue never decides the contract, Codex 48)
 *   price_tax_mode        whether that amount is net (exclusive) or gross (inclusive)
 *
 * `unit_price` keeps ONE semantics throughout the package: the fiscal NET unit
 * price, scale 2. For exclusive lines it is contractual input; for inclusive
 * lines it is DERIVED from the determination.
 *
 * The two are reconciled EXPLICITLY, never by a false equality:
 *
 *   taxable_amount = round(quantity x unit_price) + unit_price_base_adjustment
 *
 * The determination's taxable_amount is canonical. The adjustment is exactly the
 * reconciliation a scale-2 derived unit price cannot express. For large
 * quantities it can exceed one cent — and then it is NOT called "rounding"; the
 * PDF and the API present it as a base adjustment, because that is what it is.
 *
 * The frozen fiscal classification (tax group, jurisdiction, exemption,
 * reverse-charge, profile reference) is what the dated resolver reads — and the
 * ONLY thing it reads (§5.3). Freezing it is what makes a determination
 * reproducible after the customer's profile has moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_items', 'contract_unit_price')) {
                $table->integer('contract_unit_price')->default(0)->after('unit_price')
                    ->comment('Base-100: the frozen commercial unit price. Distinct from unit_price (the fiscal NET).');
            }

            if (! Schema::hasColumn('invoice_items', 'contract_line_total')) {
                $table->integer('contract_line_total')->default(0)->after('contract_unit_price')
                    ->comment('Base-100: THE BINDING contract amount for this line. Net if tax_exclusive, gross if tax_inclusive.');
            }

            if (! Schema::hasColumn('invoice_items', 'price_tax_mode')) {
                $table->unsignedTinyInteger('price_tax_mode')->default(0)->after('contract_line_total')
                    ->comment('PriceTaxMode enum: 0=tax_exclusive (contract_line_total is net), 1=tax_inclusive (it is gross)');
            }

            if (! Schema::hasColumn('invoice_items', 'unit_price_base_adjustment')) {
                $table->integer('unit_price_base_adjustment')->default(0)->after('taxable_amount')
                    ->comment('Base-100, signed: taxable_amount - round(quantity x unit_price). The explicit reconciliation of spec §4.3 — a base adjustment, not a rounding error.');
            }

            if (! Schema::hasColumn('invoice_items', 'currency')) {
                $table->char('currency', 3)->default('EUR')->after('total_amount')
                    ->comment('ISO-4217; must equal the invoice currency (validated across the aggregate)');
            }

            if (! Schema::hasColumn('invoice_items', 'operation_nature')) {
                $table->unsignedTinyInteger('operation_nature')->nullable()->after('item_type')
                    ->comment('OperationNature enum, frozen at freeze(). Nullable on LEGACY lines (adopt() supplies it). Selects the accrual rule (art. 75 LIVA).');
            }

            if (! Schema::hasColumn('invoice_items', 'frozen_tax_group_id')) {
                $table->foreignId('frozen_tax_group_id')->nullable()->after('operation_nature')
                    ->constrained('tax_groups')->restrictOnDelete()
                    ->comment('Frozen fiscal classification: the resolver reads THIS, never the live catalog');
            }

            if (! Schema::hasColumn('invoice_items', 'frozen_jurisdiction')) {
                $table->char('frozen_jurisdiction', 2)->nullable()->after('frozen_tax_group_id')
                    ->comment('ISO-3166-1 alpha-2 of the jurisdiction frozen at freeze()');
            }

            if (! Schema::hasColumn('invoice_items', 'frozen_is_exempt')) {
                $table->boolean('frozen_is_exempt')->default(false)->after('frozen_jurisdiction');
            }

            if (! Schema::hasColumn('invoice_items', 'frozen_is_reverse_charge')) {
                $table->boolean('frozen_is_reverse_charge')->default(false)->after('frozen_is_exempt');
            }

            if (! Schema::hasColumn('invoice_items', 'frozen_user_tax_profile_id')) {
                $table->unsignedBigInteger('frozen_user_tax_profile_id')->nullable()->after('frozen_is_reverse_charge')
                    ->comment('FK to user_tax_profiles: the customer fiscal profile as frozen (no constraint — profiles are versioned, not deleted)');
            }

            if (! Schema::hasColumn('invoice_items', 'tax_determination_id')) {
                $table->uuid('tax_determination_id')->nullable()->after('taxes_applied')
                    ->comment('The determination this INVOICE line consumed. Unique: a determination is consumable by at most one line (spec §5.4).');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('invoice_items'))->pluck('name');

            if (! $indexes->contains('invoice_items_tax_determination_unique')) {
                $table->foreign('tax_determination_id')->references('id')->on('tax_determinations')->restrictOnDelete();
                $table->unique('tax_determination_id', 'invoice_items_tax_determination_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropUnique('invoice_items_tax_determination_unique');
            $table->dropForeign(['tax_determination_id']);
            $table->dropForeign(['frozen_tax_group_id']);

            $table->dropColumn([
                'contract_unit_price',
                'contract_line_total',
                'price_tax_mode',
                'unit_price_base_adjustment',
                'currency',
                'operation_nature',
                'frozen_tax_group_id',
                'frozen_jurisdiction',
                'frozen_is_exempt',
                'frozen_is_reverse_charge',
                'frozen_user_tax_profile_id',
                'tax_determination_id',
            ]);
        });
    }
};
```

- [ ] **Step 4: Add the missing FK on determinations**

`tax_determinations.fiscal_obligation_id` was left FK-less in link 3 (the table did not exist). It gets its FK in link 9 (Task 8) — note it there, do not add it here.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7InvoiceItemSchemaTest.php`
Expected: PASS — 4 passed.

- [ ] **Step 6: Bootstrap the stub + commit**

```bash
cp database/migrations/2026_07_20_000006_add_v7_contract_columns_to_invoice_items.php \
   database/migrations/2026_07_20_000006_add_v7_contract_columns_to_invoice_items.php.stub
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_000006* tests/Integration/Mysql/V7InvoiceItemSchemaTest.php
git commit -m "feat(v7): migration 6 — the frozen commercial contract on invoice lines

Separates the frozen commercial contract (contract_unit_price,
contract_line_total, price_tax_mode) from the fiscal net, reconciles them
explicitly via unit_price_base_adjustment, and freezes the fiscal
classification the dated resolver is the only reader of.

Refs AID-459 (PR-1)"
```

---

### Task 8: Migrations 7–9 — economic facts, chargeability schedules, fiscal obligations

The three tables that carry tax truth from "something happened commercially" to "this VAT accrued on this date and must be invoiced by that date". They land together: an obligation with no fact and no schedule entry has no origin.

**Where the single-owner XOR and obligation-origin invariants live:** the **MySQL CHECK constraints are added in link 13** (Task 12), alongside the `invoices` axis CHECKs — one place, one guarded routine, one `down()`. They are not declared here because `Blueprint` has no `check()` API and SQLite would need hand-written per-engine `CREATE TABLE`, forking the schema definition (the drift ADR-007 exists because of).

On SQLite the invariant is enforced by the model guard **PR-2 ships with the `EconomicFact` and `FiscalObligation` models**. That is safe in PR-1 for a checkable reason: **PR-1 creates no model and no service for these tables, so no application code can write them** — the only writers are migrations and tests. It stops being safe the moment PR-2 adds a writer, which is exactly when PR-2 adds the guard. This is a **recorded obligation of PR-2**, and the spec (§7.3) was amended to say so.

**Files:**
- Create: `database/migrations/2026_07_20_000007_create_billing_economic_facts_table.php`, `..._000008_create_billing_chargeability_schedules_table.php`, `..._000009_create_billing_fiscal_obligations_table.php` (+ `.php.stub` each)
- Test: `tests/Integration/Mysql/V7ObligationSchemaTest.php`

**Interfaces:**
- Consumes: `invoices`, `invoice_items`, `tax_determinations` (Tasks 5–7); enums `EconomicFactType`, `ObligationState`.
- Produces:
  - `billing_economic_facts` — uuid PK; `proforma_id` (nullable uuid FK invoices) **XOR** `invoice_id` (nullable uuid FK invoices) — CHECK-enforced single owner in link 13; `invoice_item_id` (nullable — line scope, schema-ready, unused in v7); `type` tinyint; `occurred_on` (date); `amount` (int, nullable — only payments carry one); `currency` char(3); `source_event_key` (string, **unique**); `actor`, `source`; `supersedes_fact_id` (nullable uuid, unique self-FK); `metadata` json; timestamps.
  - `billing_chargeability_schedules` — id; `invoice_item_id` (FK, cascade); `chargeable_on` (date); `amount` (int); `currency` char(3); `period_from`/`period_to` (dates, nullable); `materialized_at` (timestamp, nullable); `unique(invoice_item_id, chargeable_on)`.
  - `billing_fiscal_obligations` — uuid PK; `proforma_id`/`invoice_id` (nullable uuid FKs, same XOR ownership); `economic_fact_id` (nullable FK facts); `chargeability_schedule_id` (nullable FK schedules); `idempotency_key` (string, **unique**); `amount` (int); `currency` char(3); `accrual_date` (date); `issuance_due_at` (timestamp); `tax_determination_id` (nullable FK); `state` tinyint; `fulfilled_by_invoice_id` (nullable uuid FK invoices); `voided_at`, `void_reason`, `void_actor`; timestamps.
  - **Adds the deferred FK** `tax_determinations.fiscal_obligation_id → billing_fiscal_obligations.id` (restrict).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mysql/V7ObligationSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestCase::class);

it('creates the three tax-truth carrier tables', function (): void {
    expect(Schema::hasTable('billing_economic_facts'))->toBeTrue()
        ->and(Schema::hasTable('billing_chargeability_schedules'))->toBeTrue()
        ->and(Schema::hasTable('billing_fiscal_obligations'))->toBeTrue();
});

it('makes a retried payment webhook impossible to register twice', function (): void {
    // §5.1 Codex 73: source_event_key is unique, so a retried webhook returns the
    // EXISTING fact instead of minting a duplicate fact-and-obligation.
    $row = [
        'id'               => (string) Str::uuid7(),
        'type'             => 0, // PAYMENT_RECEIVED
        'occurred_on'      => '2026-06-30',
        'amount'           => 12100,
        'currency'         => 'EUR',
        'source_event_key' => 'stripe:pi_3ABC123',
        'actor'            => 'system',
        'source'           => 'stripe-webhook',
        'created_at'       => now(),
        'updated_at'       => now(),
    ];

    DB::table('billing_economic_facts')->insert($row);

    $duplicate = array_merge($row, ['id' => (string) Str::uuid7()]);

    expect(fn () => DB::table('billing_economic_facts')->insert($duplicate))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('makes one chargeability date produce at most one schedule entry per line', function (): void {
    $indexes = collect(Schema::getIndexes('billing_chargeability_schedules'));

    expect($indexes->firstWhere('name', 'billing_schedules_item_date_unique')['unique'])->toBeTrue();
});

it('gives every obligation a deadline column, blocked ones included', function (): void {
    // §5.8: the VAT accrued. A technical block does not remove the legal clock.
    $column = collect(Schema::getColumns('billing_fiscal_obligations'))
        ->firstWhere('name', 'issuance_due_at');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse();
});

it('makes one source incapable of creating two obligations', function (): void {
    $indexes = collect(Schema::getIndexes('billing_fiscal_obligations'));

    expect($indexes->firstWhere('name', 'billing_obligations_idempotency_unique')['unique'])->toBeTrue();
});

it('links a determination back to the obligation it resolves', function (): void {
    $foreignKeys = collect(Schema::getForeignKeys('tax_determinations'))
        ->pluck('foreign_table');

    expect($foreignKeys)->toContain('billing_fiscal_obligations');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7ObligationSchemaTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the economic facts migration**

`database/migrations/2026_07_20_000007_create_billing_economic_facts_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 7 of 13: economic facts (spec §5.1).
 *
 * Facts are HISTORICAL TRUTH: append-only, never rejected for being
 * inconvenient, never deleted. A payment that arrived is a payment that arrived,
 * whether or not it fits the invoice the issuer wanted to send.
 *
 * Correction is by REPLACEMENT (supersedes_fact_id, audited), never by mutation.
 *
 * `source_event_key` is UNIQUE and required for machine-registered facts: a
 * retried payment webhook returns the EXISTING fact instead of minting a
 * duplicate fact — and a duplicate obligation behind it (Codex 73).
 *
 * Each fact belongs to exactly ONE billing aggregate — a proforma XOR a direct
 * invoice (Codex 71). The XOR is CHECK-enforced in link 13.
 *
 * `invoice_item_id` is schema-ready line scope. v7 is DOCUMENT-scoped: scenarios
 * that genuinely need per-line mapping fail typed, naming the per-line accrual
 * ticket (Codex 72). The column exists so that ticket is an expansion, not a
 * migration of live fiscal data.
 *
 * There is deliberately NO "chargeability reached" fact type: chargeability has
 * ONE authority, the frozen schedule (operator arbitration D4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_economic_facts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            // Single owner: proforma XOR direct invoice (CHECK in link 13).
            $table->foreignUuid('proforma_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete()
                ->comment('Set when the aggregate is a DIRECT invoice (createInvoice), not a proforma');

            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete()
                ->comment('Line scope: schema-ready, UNUSED in v7 (document-scoped). The per-line accrual ticket fills it.');

            $table->unsignedTinyInteger('type')->comment('EconomicFactType enum: 0=payment_received, 1=goods_delivered, 2=service_completed');
            $table->date('occurred_on')->comment('When the fact happened in the world — NOT when it was registered');

            $table->integer('amount')->nullable()->comment('Base-100. Payments carry an amount; deliveries and completions do not.');
            $table->char('currency', 3)->default('EUR')->comment('ISO-4217; must equal the aggregate currency (constitution 15)');

            $table->string('source_event_key')->unique()->comment('Idempotency of REGISTRATION: a retried webhook returns the existing fact (Codex 73)');
            $table->string('actor')->comment('Who registered it (user id, system, integration name)');
            $table->string('source')->comment('Where it came from (stripe-webhook, manual, import)');

            $table->uuid('supersedes_fact_id')->nullable()->comment('Audited correction: the fact this one replaces. Facts are never mutated or deleted.');

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('supersedes_fact_id')->references('id')->on('billing_economic_facts')->restrictOnDelete();
            $table->unique('supersedes_fact_id', 'billing_facts_supersedes_unique');
            $table->index(['proforma_id', 'occurred_on'], 'billing_facts_proforma_date_index');
            $table->index(['invoice_id', 'occurred_on'], 'billing_facts_invoice_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_economic_facts');
    }
};
```

- [ ] **Step 4: Write the chargeability schedules migration**

`database/migrations/2026_07_20_000008_create_billing_chargeability_schedules_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 8 of 13: the frozen chargeability schedule (spec §5.1).
 *
 * For SUCCESSIVE_TRACT lines, this schedule is the SOLE authority on
 * chargeability (operator arbitration D4). Larabill evaluates it with its own
 * fiscal clock and materializes an obligation when each date is reached —
 * idempotently, catch-up capable.
 *
 * A FUTURE chargeability date is a CALENDAR ENTRY, not an obligation. The two
 * concepts stay separate: obligations exist only for reached accruals.
 * `materialized_at` records when a row crossed that line — a consequence, never
 * a second authority.
 *
 * The schedule is FROZEN CONTENT: changing it after freeze requires superseding
 * the proforma. After a date is reached, no rewrite removes the born obligation.
 *
 * art. 75.Uno.7.º fallback (no agreed exigibility, or exigibility beyond 12
 * months) is computed by the regional strategy, which writes the resulting
 * 31-December proportional rows here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_chargeability_schedules', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();

            $table->date('chargeable_on')->comment('The date this tranche becomes chargeable. Frozen at freeze().');
            $table->integer('amount')->comment('Base-100: the amount chargeable on that date');
            $table->char('currency', 3)->default('EUR');

            $table->date('period_from')->nullable()->comment('The service period this tranche covers (kept in service_date_from/to on the invoice line)');
            $table->date('period_to')->nullable();

            $table->timestamp('materialized_at')->nullable()->comment('When the reached date produced its obligation. NULL = not yet reached (a calendar entry, not an obligation).');

            $table->timestamps();

            $table->unique(['invoice_item_id', 'chargeable_on'], 'billing_schedules_item_date_unique');
            $table->index(['chargeable_on', 'materialized_at'], 'billing_schedules_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_chargeability_schedules');
    }
};
```

- [ ] **Step 5: Write the fiscal obligations migration**

`database/migrations/2026_07_20_000009_create_billing_fiscal_obligations_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 9 of 13: fiscal obligations (spec §5.8, operator arbitration D1).
 *
 * ONE ROW PER CONCRETE ACCRUAL — never a per-proforma scalar. This is the table
 * that makes "we owe an invoice for this, by this date" a queryable fact instead
 * of an inference.
 *
 * EVERY obligation gets its deadline AT BIRTH, blocked ones included: the VAT
 * accrued, and a technical block (a partial advance v7 cannot document 1:1, a
 * rule v7 cannot prove) does NOT remove the legal clock (RD 1619/2012 art. 11;
 * Spain B2B: before the 16th of the month following accrual). That is why
 * issuance_due_at is NOT NULL.
 *
 * `idempotency_key` is derived from the SOURCE (fact id, or schedule entry +
 * period), so the same payment or the same chargeability date can never create
 * two obligations — however many times catch-up evaluation runs.
 *
 * Voiding is by audit (voided_at/void_reason/void_actor) when the originating
 * facts are superseded. Rows are never deleted.
 *
 * This migration also closes the FK left open in link 3:
 * tax_determinations.fiscal_obligation_id -> billing_fiscal_obligations.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_fiscal_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            // Same XOR ownership as facts (CHECK in link 13).
            $table->foreignUuid('proforma_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete()
                ->comment('Set when the aggregate is a DIRECT invoice');

            // Origin: a fact, or a reached schedule entry. Never neither.
            $table->foreignUuid('economic_fact_id')->nullable()->constrained('billing_economic_facts')->restrictOnDelete();
            $table->foreignId('chargeability_schedule_id')->nullable()->constrained('billing_chargeability_schedules')->restrictOnDelete();

            $table->string('idempotency_key')->unique('billing_obligations_idempotency_unique')
                ->comment('Derived from the source (fact id / schedule entry + period). Catch-up evaluation can never duplicate an obligation.');

            $table->integer('amount')->comment('Base-100: the amount that accrued');
            $table->char('currency', 3)->default('EUR');
            $table->date('accrual_date')->comment('When the tax accrued (art. 75 LIVA). The resolver is asked about THIS date.');
            $table->timestamp('issuance_due_at')->comment('The legal issuance deadline. NOT NULL — blocked obligations keep their clock (spec §5.8).');

            // A real FK, not a bare uuid: tax_determinations already exists (link 3).
            // The inverse (determinations -> obligations) is the one that had to wait,
            // and it is added at the bottom of this migration.
            $table->foreignUuid('tax_determination_id')->nullable()
                ->constrained('tax_determinations')->restrictOnDelete()
                ->comment('The active determination for this accrual');

            $table->unsignedTinyInteger('state')->default(0)
                ->comment('ObligationState enum: 0=pending 1=determined 2=blocked_partial_advance 3=blocked_resolution 4=fulfilled 5=voided');

            $table->foreignUuid('fulfilled_by_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete()
                ->comment('The ISSUED invoice that documents exactly this obligation. Stamped by issue().');

            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->string('void_actor')->nullable();

            $table->timestamps();

            $table->index(['proforma_id', 'state'], 'billing_obligations_proforma_state_index');
            $table->index(['state', 'issuance_due_at'], 'billing_obligations_overdue_index')
                ->comment('The overdue report reads this: blocked and pending obligations past their deadline');
            $table->index('accrual_date', 'billing_obligations_accrual_index');
        });

        // Close the FK left open in link 3 (the obligations table did not exist yet).
        Schema::table('tax_determinations', function (Blueprint $table): void {
            $table->foreign('fiscal_obligation_id')
                ->references('id')->on('billing_fiscal_obligations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tax_determinations', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_obligation_id']);
        });

        Schema::dropIfExists('billing_fiscal_obligations');
    }
};
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7ObligationSchemaTest.php`
Expected: PASS — 6 passed.

- [ ] **Step 7: Bootstrap the stubs + commit**

```bash
for m in 2026_07_20_000007_create_billing_economic_facts_table \
         2026_07_20_000008_create_billing_chargeability_schedules_table \
         2026_07_20_000009_create_billing_fiscal_obligations_table; do
  cp "database/migrations/$m.php" "database/migrations/$m.php.stub"
done
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_00000[789]* tests/Integration/Mysql/V7ObligationSchemaTest.php
git commit -m "feat(v7): migrations 7-9 — economic facts, chargeability schedules, fiscal obligations

Facts are append-only historical truth with idempotent registration; the
frozen schedule is the sole authority on chargeability; obligations are
ROWS, one per concrete accrual, each born with its legal issuance
deadline — blocked ones included, because the VAT accrued regardless.

Refs AID-459 (PR-1)"
```

---

### Task 9: Migration 10 — the fiscal submission outbox

The durable handoff intent towards `lara-verifactu`. The invoice column is the canonical business state; the outbox holds delivery mechanics (spec §6.7).

**Files:**
- Create: `database/migrations/2026_07_20_000010_create_fiscal_submission_outbox_table.php` (+ `.php.stub`)
- Test: `tests/Integration/Mysql/V7OutboxSchemaTest.php`

**Interfaces:**
- Consumes: `invoices`; enum `SubmissionOperationType`.
- Produces: `fiscal_submission_outbox` — uuid PK; `invoice_id` (FK invoices, restrict); `operation_type` tinyint; **`unique(invoice_id, operation_type)`**; `idempotency_key` (string, unique); `payload_hash` char(64); `payload_reference` (string, nullable); `attempts` (unsigned int, default 0); `last_outcome` (string, nullable); `last_attempted_at` (timestamp, nullable); `next_retry_at` (timestamp, nullable); `acknowledgment` (json, nullable); timestamps.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mysql/V7OutboxSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestCase::class);

it('creates the outbox', function (): void {
    expect(Schema::hasColumns('fiscal_submission_outbox', [
        'id', 'invoice_id', 'operation_type', 'idempotency_key', 'payload_hash',
        'attempts', 'last_outcome', 'next_retry_at', 'acknowledgment',
    ]))->toBeTrue();
});

it('makes two intents for the same invoice and operation impossible', function (): void {
    // Codex 61: unique(invoice_id, operation_type), on top of the unique idempotency key.
    $invoiceId = (string) Str::uuid7();

    DB::table('invoices')->insert([
        'id'                      => $invoiceId,
        'fiscal_number'           => 'FAC-2026-000001',
        'prefix'                  => 'FAC',
        'serie'                   => 1,
        'series_number'           => 1,
        'fiscal_year'             => 2026,
        'invoice_date'            => '2026-07-01',
        'issued_at'               => '2026-07-01 10:00:00',
        'status'                  => 0,
        'invoice_document_status' => 2, // ISSUED
        'fiscal_submission_status' => 1, // PENDING
        'currency'                => 'EUR',
        'user_id'                 => TestCase::USER_UUID_1,
        'created_at'              => now(),
        'updated_at'              => now(),
    ]);

    $row = [
        'id'              => (string) Str::uuid7(),
        'invoice_id'      => $invoiceId,
        'operation_type'  => 0, // REGISTRATION
        'idempotency_key' => 'larabill:'.$invoiceId.':registration',
        'payload_hash'    => str_repeat('a', 64),
        'attempts'        => 0,
        'created_at'      => now(),
        'updated_at'      => now(),
    ];

    DB::table('fiscal_submission_outbox')->insert($row);

    $second = array_merge($row, [
        'id'              => (string) Str::uuid7(),
        'idempotency_key' => 'a-different-key-entirely',
    ]);

    expect(fn () => DB::table('fiscal_submission_outbox')->insert($second))
        ->toThrow(UniqueConstraintViolationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7OutboxSchemaTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000010_create_fiscal_submission_outbox_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 10 of 13: the fiscal submission outbox (spec §6.7).
 *
 * issue() creates the invoice AND the outbox row in the same transaction, then
 * the external call happens OUTSIDE it. That is what makes a crash between the
 * two survivable: the intent is durable, and a retry with the same idempotency
 * key reconciles with whatever the authority already registered.
 *
 * Single authority: the invoice's fiscal_submission_status is the canonical
 * BUSINESS state; this table holds DELIVERY MECHANICS. Coordinated updates are
 * transactional.
 *
 * unique(invoice_id, operation_type) on top of the unique idempotency key: two
 * intents for the same invoice and the same operation are impossible (Codex 61).
 *
 * larabill stores the ACKNOWLEDGMENT. It never stores the chain — the chain and
 * its ordering belong to lara-verifactu (§2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_submission_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('UUID v7 primary key');

            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->unsignedTinyInteger('operation_type')->comment('SubmissionOperationType enum: 0=registration, 1=annulment');

            $table->string('idempotency_key')->unique()->comment('Stable across retries: the authority returns the EXISTING registration instead of double-registering');
            $table->char('payload_hash', 64)->comment('SHA-256 of the immutable payload. A resubmission with altered data over an immutable invoice is impossible by construction.');
            $table->string('payload_reference')->nullable()->comment('Where the payload lives, when it is not inlined');

            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_outcome')->nullable()->comment('The TYPED outcome returned by lara-verifactu (§6.7). larabill never parses messages to classify.');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->json('acknowledgment')->nullable()->comment('The authority acknowledgment. REGISTERED is set only on a reconciled confirmation.');

            $table->timestamps();

            $table->unique(['invoice_id', 'operation_type'], 'fiscal_outbox_invoice_operation_unique');
            $table->index('next_retry_at', 'fiscal_outbox_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_submission_outbox');
    }
};
```

- [ ] **Step 4: Run the test, bootstrap the stub, commit**

```bash
php83 vendor/bin/pest tests/Integration/Mysql/V7OutboxSchemaTest.php   # expect 2 passed
cp database/migrations/2026_07_20_000010_create_fiscal_submission_outbox_table.php \
   database/migrations/2026_07_20_000010_create_fiscal_submission_outbox_table.php.stub
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_000010* tests/Integration/Mysql/V7OutboxSchemaTest.php
git commit -m "feat(v7): migration 10 — the fiscal submission outbox

The durable handoff intent: issue() writes invoice + outbox in one
transaction and calls the authority outside it, so a crash between the two
reconciles by idempotency key instead of double-registering.

Refs AID-459 (PR-1)"
```

---

### Task 10: Migration 11 — backfill the invoice lifecycle state

Every existing row must land on a coherent v7 state, or the constraints of link 13 will (rightly) refuse to apply. This is the migration that decides what a pre-v7 database *meant*.

**Backfill precedence (spec §7.1) — first match wins:**

| # | Condition (proforma rows, `serie = 0`) | `proforma_status` |
|---|---|---|
| 1 | Has a valid conversion link (`converted_invoice_id` → an existing fiscal row) | `CONVERTED` (3) |
| 2 | `status = cancelled` (4) | `CANCELLED` (5) |
| 3 | `status = draft` (0) **and** `is_immutable = false` | `DRAFT` (0) |
| 4 | Anything else (immutable / non-editable legacy) | **`LEGACY`** (6) |

Rule 4 is the honest one: a pre-v7 immutable proforma has no freeze-time identity, no audited nature, no frozen classification and no known fact history. Calling it `FROZEN` would be a lie the resolver would later act on. `LEGACY` cannot determine or convert; `adopt()` (PR-2) promotes it with the missing data attested.

**Fiscal rows (`serie ≠ 0`):** every **numbered** row → `invoice_document_status = ISSUED` (a correlative was consumed; that is irreversible). `fiscal_submission_status` = the mapped verifactu state where a registration exists, else `LEGACY_UNKNOWN`. `proforma_id` is backfilled from the mirror (`converted_invoice_id`) **without ever overwriting** an existing value.

**Files:**
- Create: `database/migrations/2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php` (+ `.php.stub`)
- Test: `tests/Integration/UpgradePath/V7InvoiceBackfillTest.php`

**Interfaces:**
- Consumes: the columns of Task 6.
- Produces: every row in `invoices` carries exactly one non-null status axis (proforma XOR document), `currency = 'EUR'`, and a `proforma_id` consistent with the mirror.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/UpgradePath/V7InvoiceBackfillTest.php`. It seeds the **legacy** shape, runs the migration, and asserts the classification. Reuse the `legacyInvoiceRow()` helper by extracting it to `tests/Integration/UpgradePath/LegacyRowFactory.php` (a plain function file `require`d by both tests) — do not duplicate it.

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\ProformaStatus;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

uses(TestCase::class);

function runInvoiceBackfill(): void
{
    $migration = require __DIR__.'/../../../database/migrations/2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php';
    $migration->up();
}

it('classifies a converted proforma as CONVERTED and repairs the canonical link', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO']);
    $invoice  = legacyInvoiceRow();

    // The legacy defect D2: only the mirror was ever written.
    DB::table('invoices')->where('id', $proforma)->update(['converted_invoice_id' => $invoice]);

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('id', $proforma)->value('proforma_status'))
        ->toBe(ProformaStatus::CONVERTED->value)
        // proforma_id is canonical from v7 on: the backfill writes it from the mirror.
        ->and(DB::table('invoices')->where('id', $invoice)->value('proforma_id'))->toBe($proforma);
});

it('repairs the CANONICAL-ONLY shape: mirror rebuilt, proforma classified CONVERTED', function (): void {
    // The shape the first draft of this plan silently mishandled. Without the
    // mirror repair, the classification below never fires and the proforma lands
    // as DRAFT or LEGACY with a live invoice hanging off it.
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO', 'status' => 1, 'is_immutable' => true]);
    $invoice  = legacyInvoiceRow();

    DB::table('invoices')->where('id', $invoice)->update(['proforma_id' => $proforma]);
    // NOTE: converted_invoice_id deliberately left NULL.

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('id', $proforma)->value('converted_invoice_id'))->toBe($invoice)
        ->and(DB::table('invoices')->where('id', $proforma)->value('proforma_status'))
        ->toBe(ProformaStatus::CONVERTED->value);
});

it('leaves an already-coherent pair untouched', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO']);
    $invoice  = legacyInvoiceRow();

    DB::table('invoices')->where('id', $invoice)->update(['proforma_id' => $proforma]);
    DB::table('invoices')->where('id', $proforma)->update(['converted_invoice_id' => $invoice]);

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('id', $invoice)->value('proforma_id'))->toBe($proforma)
        ->and(DB::table('invoices')->where('id', $proforma)->value('proforma_status'))
        ->toBe(ProformaStatus::CONVERTED->value);
});

it('classifies a cancelled proforma as CANCELLED', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO', 'status' => 4]);

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('id', $proforma)->value('proforma_status'))
        ->toBe(ProformaStatus::CANCELLED->value);
});

it('classifies a mutable draft proforma as DRAFT', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO', 'status' => 0, 'is_immutable' => false]);

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('id', $proforma)->value('proforma_status'))
        ->toBe(ProformaStatus::DRAFT->value);
});

it('refuses to call an immutable pre-v7 proforma FROZEN and marks it LEGACY instead', function (): void {
    // It has no freeze-time identity, no audited nature, no frozen classification
    // and no known fact history. FROZEN would be a lie the resolver acts on.
    $proforma = legacyInvoiceRow([
        'serie'        => 0,
        'prefix'       => 'PRO',
        'status'       => 1,
        'is_immutable' => true,
    ]);

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('id', $proforma)->value('proforma_status'))
        ->toBe(ProformaStatus::LEGACY->value);
});

it('classifies every numbered fiscal row as ISSUED', function (): void {
    $invoice = legacyInvoiceRow();

    runInvoiceBackfill();

    $row = DB::table('invoices')->where('id', $invoice)->first();

    expect($row->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED->value)
        ->and($row->proforma_status)->toBeNull()
        // No pre-v7 row can prove its submission state.
        ->and($row->fiscal_submission_status)->toBe(FiscalSubmissionStatus::LEGACY_UNKNOWN->value)
        ->and($row->currency)->toBe('EUR');
});

it('leaves proformas with a NULL document axis and invoices with a NULL proforma axis', function (): void {
    legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO']);
    legacyInvoiceRow();

    runInvoiceBackfill();

    expect(DB::table('invoices')->where('serie', 0)->whereNotNull('invoice_document_status')->count())->toBe(0)
        ->and(DB::table('invoices')->where('serie', '!=', 0)->whereNotNull('proforma_status')->count())->toBe(0);
});

it('is idempotent: re-running the backfill changes nothing', function (): void {
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO', 'status' => 0]);

    runInvoiceBackfill();
    $first = DB::table('invoices')->where('id', $proforma)->first();

    runInvoiceBackfill();
    $second = DB::table('invoices')->where('id', $proforma)->first();

    expect($second->proforma_status)->toBe($first->proforma_status);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7InvoiceBackfillTest.php`
Expected: FAIL — migration file not found.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\ProformaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 11 of 13: backfill the lifecycle state (spec §7.1).
 *
 * This migration decides what a pre-v7 database MEANT. Its precedence is
 * declared in the spec and in UPGRADE-7.0.md, in this order:
 *
 *   1. valid conversion link   -> CONVERTED
 *   2. cancelled               -> CANCELLED
 *   3. draft AND mutable       -> DRAFT
 *   4. everything else         -> LEGACY
 *
 * Rule 4 is the honest one, and the reason the LEGACY state exists at all: a
 * pre-v7 immutable proforma has no freeze-time identity, no audited nature, no
 * frozen fiscal classification and no known fact history. Calling it FROZEN
 * would be a lie the resolver would later act on — it would let a proforma
 * convert on evidence nobody ever recorded. LEGACY can neither determine nor
 * convert; adopt() (PR-2, audited) promotes it once the missing truth is
 * supplied or attested.
 *
 * Fiscal rows: every NUMBERED row is ISSUED. A correlative was consumed; that is
 * irreversible, whatever the legacy collection status says. This is also what
 * makes the bidirectional CHECK of link 13 (numbered <=> ISSUED) satisfiable
 * without a legacy exception.
 *
 * fiscal_submission_status: no pre-v7 row can PROVE it was registered, so it
 * becomes LEGACY_UNKNOWN — which exits only through an explicit operator
 * reconciliation operation (Codex 63). NOT_REQUIRED is a positive, audited
 * decision and is never assigned by a backfill.
 *
 * proforma_id is backfilled from the mirror and NEVER overwritten: where both
 * exist, the canonical column already holds the truth (the preflight of link 1
 * already refused contradictory pairs).
 *
 * Idempotent by construction: every UPDATE is scoped by `WHERE ... IS NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-validate the precondition: a failed partial run must re-enter cleanly.
        if (! Schema::hasColumn('invoices', 'proforma_status')) {
            throw new RuntimeException(
                'v7 backfill cannot run: the lifecycle columns are missing. '
                .'Run the full migration chain in order.'
            );
        }

        DB::transaction(function (): void {
            $this->backfillCurrency();
            $this->backfillCanonicalLink();
            $this->backfillProformaStatus();
            $this->backfillDocumentStatus();
        });
    }

    private function backfillCurrency(): void
    {
        // Attested by the operator in the preflight (constitution 15).
        DB::table('invoices')->whereNull('currency')->update(['currency' => 'EUR']);
    }

    /**
     * D2: the canonical link was never written by conversion. Repair BOTH directions.
     *
     * The database can hold either half of the link on its own, and a backfill that
     * only reads the mirror leaves the canonical-only shape as a live invoice hanging
     * off a proforma that then gets classified DRAFT or LEGACY. Both halves, or the
     * classification below lies.
     *
     * NEVER overwrite an existing value — the preflight already refused contradictory
     * pairs, so whatever is there is the truth.
     */
    private function backfillCanonicalLink(): void
    {
        // mirror -> canonical (the common legacy shape).
        DB::table('invoices as i')
            ->join('invoices as p', 'p.converted_invoice_id', '=', 'i.id')
            ->whereNull('i.proforma_id')
            ->update(['i.proforma_id' => DB::raw('p.id')]);

        // canonical -> mirror (someone wrote proforma_id directly; the mirror is the
        // deprecated column, but v7 dual-writes it and v6 code still reads it).
        DB::table('invoices as p')
            ->join('invoices as i', 'i.proforma_id', '=', 'p.id')
            ->whereNull('p.converted_invoice_id')
            ->update([
                'p.converted_invoice_id' => DB::raw('i.id'),
                'p.converted_at'         => DB::raw('COALESCE(p.converted_at, i.created_at)'),
            ]);
    }

    private function backfillProformaStatus(): void
    {
        $proformas = DB::table('invoices')
            ->where('serie', 0) // InvoiceSerieType::PROFORMA
            ->whereNull('proforma_status');

        // 1. Valid conversion link -> CONVERTED.
        DB::table('invoices as p')
            ->join('invoices as i', 'i.id', '=', 'p.converted_invoice_id')
            ->where('p.serie', 0)
            ->whereNull('p.proforma_status')
            ->update(['p.proforma_status' => ProformaStatus::CONVERTED->value]);

        // 2. Cancelled -> CANCELLED.
        (clone $proformas)
            ->where('status', 4) // InvoiceStatus::CANCELLED
            ->update(['proforma_status' => ProformaStatus::CANCELLED->value]);

        // 3. Draft AND mutable -> DRAFT.
        (clone $proformas)
            ->where('status', 0) // InvoiceStatus::DRAFT
            ->where('is_immutable', false)
            ->update(['proforma_status' => ProformaStatus::DRAFT->value]);

        // 4. Everything else -> LEGACY. Never FROZEN: see the docblock.
        (clone $proformas)->update(['proforma_status' => ProformaStatus::LEGACY->value]);
    }

    private function backfillDocumentStatus(): void
    {
        DB::table('invoices')
            ->where('serie', '!=', 0)
            ->whereNull('invoice_document_status')
            ->update([
                // A numbered row consumed a correlative. That is irreversible.
                'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
                // No pre-v7 row can prove its submission state.
                'fiscal_submission_status' => FiscalSubmissionStatus::LEGACY_UNKNOWN->value,
            ]);
    }

    /**
     * The state columns are dropped by link 5's down(); there is nothing to undo
     * here. Rolling back does NOT restore the mirror-only link — proforma_id is
     * a repair, not a v7 invention, and keeping it is strictly more correct.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock.
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7InvoiceBackfillTest.php`
Expected: PASS — 7 passed.

- [ ] **Step 5: Bootstrap the stub + commit**

```bash
cp database/migrations/2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php \
   database/migrations/2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php.stub
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_000011* tests/Integration/UpgradePath/
git commit -m "feat(v7): migration 11 — backfill the invoice lifecycle state

Decides what a pre-v7 database meant, under a declared precedence. An
immutable pre-v7 proforma becomes LEGACY, never FROZEN: it has no
freeze-time identity, nature, classification or fact history, and calling
it frozen would be a lie the resolver would act on.

Repairs the canonical proforma_id link from the mirror (defect D2).

Refs AID-459 (PR-1)"
```

---

### Task 11: Migration 12 — backfill the frozen contract on legacy lines

Legacy lines carry a fiscal net and nothing else. v7 needs a commercial contract on every line, and it must be derived **without rewriting a single historical amount**.

**Rules (spec §4.3):**

- `contract_unit_price := unit_price`
- `contract_line_total := taxable_amount` — **the line total wins** (the preflight already reported the incoherent lines and declared this precedence)
- `price_tax_mode := TAX_EXCLUSIVE` — every legacy line was priced net
- `unit_price_base_adjustment := taxable_amount - round(quantity × unit_price / 100)` — the residue is **recorded**, not smoothed away
- `currency := 'EUR'` (attested)
- `operation_nature`: stays **NULL** on legacy lines. A documentary approximation from `item_type` is applied only to lines of **already-issued** invoices (where nothing will ever re-resolve them), and those rows are flagged in the report. Lines of DRAFT/LEGACY proformas keep NULL — `adopt()`/`freeze()` supply the audited value.

**Files:**
- Create: `database/migrations/2026_07_20_000012_backfill_v7_invoice_item_contract_terms.php` (+ `.php.stub`)
- Test: `tests/Integration/UpgradePath/V7InvoiceItemBackfillTest.php`

**Interfaces:**
- Consumes: the columns of Task 7 and the statuses written by Task 10.
- Produces: every existing line carries a frozen contract that reproduces its historical amounts exactly.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/UpgradePath/V7InvoiceItemBackfillTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\OperationNature;
use AichaDigital\Larabill\Enums\PriceTaxMode;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

uses(TestCase::class);

function runItemBackfill(): void
{
    $migration = require __DIR__.'/../../../database/migrations/2026_07_20_000012_backfill_v7_invoice_item_contract_terms.php';
    $migration->up();
}

/** @param array<string, mixed> $overrides */
function legacyLine(string $invoiceId, array $overrides = []): int
{
    return DB::table('invoice_items')->insertGetId(array_merge([
        'invoice_id'       => $invoiceId,
        'item_type'        => 1, // service
        'description'      => 'Hosting',
        'quantity'         => 100,  // Base-100: 1 unit
        'unit_price'       => 1000, // 10.00
        'taxable_amount'   => 1000,
        'total_tax_amount' => 210,
        'total_amount'     => 1210,
        'created_at'       => now(),
        'updated_at'       => now(),
    ], $overrides));
}

it('derives the frozen contract from the historical net without rewriting an amount', function (): void {
    $invoice = legacyInvoiceRow();
    $line    = legacyLine($invoice);

    runItemBackfill();

    $row = DB::table('invoice_items')->where('id', $line)->first();

    expect($row->contract_unit_price)->toBe(1000)
        ->and($row->contract_line_total)->toBe(1000)
        ->and($row->price_tax_mode)->toBe(PriceTaxMode::TAX_EXCLUSIVE->value)
        ->and($row->unit_price_base_adjustment)->toBe(0)
        ->and($row->currency)->toBe('EUR')
        // Nothing historical was touched.
        ->and($row->unit_price)->toBe(1000)
        ->and($row->taxable_amount)->toBe(1000);
});

it('records the residue of an incoherent legacy line instead of smoothing it away', function (): void {
    // The line total wins (§4.3): the customer was billed 29.99, not 30.00.
    $invoice = legacyInvoiceRow();
    $line    = legacyLine($invoice, [
        'quantity'       => 300,  // 3 units
        'unit_price'     => 1000, // 10.00
        'taxable_amount' => 2999, // 29.99
        'total_amount'   => 2999,
        'total_tax_amount' => 0,
    ]);

    runItemBackfill();

    $row = DB::table('invoice_items')->where('id', $line)->first();

    expect($row->contract_line_total)->toBe(2999)
        // 2999 - round(300 * 1000 / 100) = 2999 - 3000 = -1
        ->and($row->unit_price_base_adjustment)->toBe(-1)
        ->and($row->taxable_amount)->toBe(2999);
});

it('approximates the nature on issued lines only, and leaves proforma lines NULL', function (): void {
    $issued   = legacyInvoiceRow();                              // serie = 1, numbered -> ISSUED
    $proforma = legacyInvoiceRow(['serie' => 0, 'prefix' => 'PRO']);

    $issuedLine   = legacyLine($issued, ['item_type' => 0]);     // good
    $proformaLine = legacyLine($proforma, ['item_type' => 1]);   // service

    // The lifecycle backfill (link 11) must have run first.
    (require __DIR__.'/../../../database/migrations/2026_07_20_000011_backfill_v7_invoice_lifecycle_state.php')->up();
    runItemBackfill();

    expect(DB::table('invoice_items')->where('id', $issuedLine)->value('operation_nature'))
        ->toBe(OperationNature::GOODS_DELIVERY->value)
        // A proforma line's nature is an AUDITED decision (freeze/adopt), never a guess.
        ->and(DB::table('invoice_items')->where('id', $proformaLine)->value('operation_nature'))
        ->toBeNull();
});

it('is idempotent', function (): void {
    $invoice = legacyInvoiceRow();
    $line    = legacyLine($invoice, ['taxable_amount' => 2999, 'quantity' => 300]);

    runItemBackfill();
    runItemBackfill();

    expect(DB::table('invoice_items')->where('id', $line)->value('unit_price_base_adjustment'))->toBe(-1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7InvoiceItemBackfillTest.php`
Expected: FAIL — migration file not found.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000012_backfill_v7_invoice_item_contract_terms.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\OperationNature;
use AichaDigital\Larabill\Enums\PriceTaxMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 12 of 13: backfill the frozen contract (spec §4.3/§7.2).
 *
 * A legacy line carries a fiscal net and nothing else. v7 needs a commercial
 * contract on every line — and it must be derived WITHOUT REWRITING A SINGLE
 * HISTORICAL AMOUNT. The invoices are issued; they are what the customer got.
 *
 *   contract_unit_price := unit_price
 *   contract_line_total := taxable_amount     <- THE LINE TOTAL WINS
 *   price_tax_mode      := TAX_EXCLUSIVE      <- every legacy line was priced net
 *   unit_price_base_adjustment := taxable_amount - round(quantity x unit_price)
 *
 * That last line is the whole honesty of this migration. Where a legacy line's
 * stored base does not equal quantity x unit price (the preflight of link 1
 * reported them, and declared this precedence up front), the residue is
 * RECORDED, not smoothed away. The base equation reproduces the historical
 * amount exactly, and the difference is visible instead of being silently
 * rounded into a number nobody was ever billed.
 *
 * operation_nature: NULL stays NULL on proforma lines. Nature is an AUDITED
 * decision (freeze/adopt), never a guess — a wrong nature selects a wrong accrual
 * rule. The ONLY exception is lines of already-ISSUED invoices, where nothing
 * will ever re-resolve them and a documentary approximation from item_type is
 * harmless: good -> GOODS_DELIVERY, service -> ONE_OFF_SERVICE. Those rows are
 * flagged as approximations in UPGRADE-7.0.md.
 *
 * Idempotent: every UPDATE is scoped to rows still carrying the default/NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoice_items', 'contract_line_total')) {
            throw new RuntimeException(
                'v7 line backfill cannot run: the contract columns are missing. '
                .'Run the full migration chain in order.'
            );
        }

        DB::transaction(function (): void {
            $this->backfillContractTerms();
            $this->backfillIssuedLineNature();
        });
    }

    private function backfillContractTerms(): void
    {
        // quantity is Base-100, so the product is divided by 100.
        DB::table('invoice_items')
            ->where('contract_line_total', 0)
            ->update([
                'contract_unit_price'        => DB::raw('unit_price'),
                'contract_line_total'        => DB::raw('taxable_amount'),
                'price_tax_mode'             => PriceTaxMode::TAX_EXCLUSIVE->value,
                'unit_price_base_adjustment' => DB::raw('taxable_amount - ROUND(quantity * unit_price / 100)'),
                'currency'                   => 'EUR',
            ]);
    }

    /**
     * Documentary approximation, ISSUED lines only. Nothing will ever re-resolve
     * these; a proforma line keeps NULL and gets its audited nature at freeze/adopt.
     */
    private function backfillIssuedLineNature(): void
    {
        DB::table('invoice_items')
            ->whereNull('operation_nature')
            ->whereIn('invoice_id', function ($query): void {
                $query->select('id')
                    ->from('invoices')
                    ->where('invoice_document_status', InvoiceDocumentStatus::ISSUED->value);
            })
            ->update([
                'operation_nature' => DB::raw(sprintf(
                    'CASE WHEN item_type = 0 THEN %d ELSE %d END',
                    OperationNature::GOODS_DELIVERY->value,
                    OperationNature::ONE_OFF_SERVICE->value,
                )),
            ]);
    }

    /** The columns are dropped by link 6's down(); there is nothing to undo. */
    public function down(): void
    {
        // Intentionally empty.
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/UpgradePath/V7InvoiceItemBackfillTest.php`
Expected: PASS — 4 passed.

- [ ] **Step 5: Bootstrap the stub + commit**

```bash
cp database/migrations/2026_07_20_000012_backfill_v7_invoice_item_contract_terms.php \
   database/migrations/2026_07_20_000012_backfill_v7_invoice_item_contract_terms.php.stub
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_000012* tests/Integration/UpgradePath/V7InvoiceItemBackfillTest.php
git commit -m "feat(v7): migration 12 — backfill the frozen contract on legacy lines

Derives the commercial contract from the historical net without rewriting
a single amount: the line total wins, and the residue of an incoherent
legacy line is RECORDED in unit_price_base_adjustment rather than smoothed
into a number nobody was billed.

Nature is approximated only on already-issued lines; proforma lines keep
NULL and get their audited nature at freeze/adopt.

Refs AID-459 (PR-1)"
```

---

### Task 12: Migration 13 — the coherence constraints (and the gate release)

The last link. Now that every row is coherent, the database can start **refusing** incoherence: cross-axis CHECKs, the active-link unique, the single-active-epoch unique, the single-active-determination unique. Then it releases the write gate.

Constraints last, deliberately: applied before the backfill, they would abort the chain on legacy rows that the backfill was about to fix.

**Engine split (see Global Constraints):** CHECKs on MySQL only; the same invariants are enforced on every engine by the model guards of Task 13. Unique indexes are portable — MySQL 8 gets a stored generated column, SQLite gets a partial unique index. The technique is contractual (spec §7.1); these are the literals.

**Files:**
- Create: `database/migrations/2026_07_20_000013_add_v7_coherence_constraints.php` (+ `.php.stub`)
- Test: `tests/Integration/Mysql/V7ConstraintsTest.php`

**Interfaces:**
- Consumes: everything above; `UpgradeGate::release()` (Task 2).
- Produces: the database refuses — a numbered non-ISSUED row; an ISSUED row with no submission status; a proforma with a document status; two active conversions of one proforma; two open epochs; two active determinations for one line+obligation; a fact owned by both a proforma and an invoice.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Mysql/V7ConstraintsTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\UpgradeGate;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestCase::class);

/** @param array<string, mixed> $overrides */
function v7InvoiceRow(array $overrides = []): array
{
    return array_merge([
        'id'                       => (string) Str::uuid7(),
        'fiscal_number'            => null,
        'prefix'                   => 'FAC',
        'serie'                    => 1,
        'series_number'            => null,
        'fiscal_year'              => null,
        'invoice_date'             => null,
        'issued_at'                => null,
        'status'                   => 0,
        'invoice_document_status'  => 1, // PREPARED
        'fiscal_submission_status' => null,
        'currency'                 => 'EUR',
        'user_id'                  => TestCase::USER_UUID_1,
        'created_at'               => now(),
        'updated_at'               => now(),
    ], $overrides);
}

it('refuses a numbered row that is not ISSUED', function (): void {
    // The bidirectional CHECK (Codex 66): a correlative exists ONLY on an issued row.
    DB::table('invoices')->insert(v7InvoiceRow([
        'invoice_document_status' => 1, // PREPARED
        'fiscal_number'           => 'FAC-2026-000042',
        'series_number'           => 42,
        'fiscal_year'             => 2026,
        'invoice_date'            => '2026-07-01',
        'issued_at'               => '2026-07-01 10:00:00',
    ]));
})->throws(QueryException::class);

it('refuses an ISSUED row with no fiscal number', function (): void {
    DB::table('invoices')->insert(v7InvoiceRow([
        'invoice_document_status'  => 2, // ISSUED
        'fiscal_submission_status' => 1,
        'fiscal_number'            => null,
    ]));
})->throws(QueryException::class);

it('refuses an ISSUED row with no submission status', function (): void {
    DB::table('invoices')->insert(v7InvoiceRow([
        'invoice_document_status'  => 2,
        'fiscal_submission_status' => null, // ISSUED <=> submission status NOT NULL
        'fiscal_number'            => 'FAC-2026-000043',
        'series_number'            => 43,
        'fiscal_year'              => 2026,
        'invoice_date'             => '2026-07-01',
        'issued_at'                => '2026-07-01 10:00:00',
    ]));
})->throws(QueryException::class);

it('refuses a PREPARED row that carries a submission status', function (): void {
    // Before issuance there is neither a submission intent nor a positive decision (Codex 78).
    DB::table('invoices')->insert(v7InvoiceRow(['fiscal_submission_status' => 1]));
})->throws(QueryException::class);

it('refuses a proforma with a document status', function (): void {
    DB::table('invoices')->insert(v7InvoiceRow([
        'serie'                   => 0,
        'prefix'                  => 'PRO',
        'proforma_status'         => 1,
        'invoice_document_status' => 0, // proformas have NO document axis
    ]));
})->throws(QueryException::class);

it('refuses a fiscal row with a proforma status', function (): void {
    DB::table('invoices')->insert(v7InvoiceRow(['proforma_status' => 1]));
})->throws(QueryException::class);

it('allows a cancelled conversion to be re-converted, but never two active ones', function (): void {
    $proforma = (string) Str::uuid7();

    DB::table('invoices')->insert(v7InvoiceRow([
        'id'                      => $proforma,
        'serie'                   => 0,
        'prefix'                  => 'PRO',
        'proforma_status'         => 1, // FROZEN
        'invoice_document_status' => null,
    ]));

    // First conversion, then cancelled: the link is RETAINED (documentary trace, §6.4).
    DB::table('invoices')->insert(v7InvoiceRow([
        'proforma_id'             => $proforma,
        'invoice_document_status' => 3, // CANCELLED
    ]));

    // Re-conversion is legal: active-link uniqueness counts non-cancelled documents.
    DB::table('invoices')->insert(v7InvoiceRow([
        'proforma_id'             => $proforma,
        'invoice_document_status' => 1, // PREPARED
    ]));

    // A SECOND active conversion is not.
    expect(fn () => DB::table('invoices')->insert(v7InvoiceRow([
        'proforma_id'             => $proforma,
        'invoice_document_status' => 1,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second open epoch', function (): void {
    DB::table('tax_catalog_epochs')->insert([
        'id'            => (string) Str::uuid7(),
        'revision'      => 9001,
        'observed_from' => now(),
        'closed_at'     => null,
        'rule_set_hash' => str_repeat('b', 64),
        'integrity'     => 0,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(fn () => DB::table('tax_catalog_epochs')->insert([
        'id'            => (string) Str::uuid7(),
        'revision'      => 9002,
        'observed_from' => now(),
        'closed_at'     => null, // a SECOND active epoch
        'rule_set_hash' => str_repeat('c', 64),
        'integrity'     => 0,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('releases the write gate at the end of the chain', function (): void {
    expect(UpgradeGate::isHeld())->toBeFalse();
});
```

> The epoch test assumes the install bootstrap has NOT already opened an epoch in this connection. If `tax_catalog_epochs` is seeded by the chain, delete the seeded row at the top of the test — read the table first, do not guess.

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7ConstraintsTest.php`
Expected: FAIL — the inserts succeed (no constraints yet).

- [ ] **Step 3: Write the migration**

`database/migrations/2026_07_20_000013_add_v7_coherence_constraints.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\UpgradeGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7 upgrade chain — link 13 of 13: coherence constraints + gate release (spec §7.1).
 *
 * LAST on purpose. Applied before the backfill, these constraints would abort the
 * chain on the very legacy rows the backfill exists to make coherent.
 *
 * CHECK portability (a fixed decision of the v7 plan): MySQL 8 can ADD CONSTRAINT
 * CHECK on a populated table; SQLite cannot without a full table rebuild.
 * So the CHECKs are emitted on MySQL — the production engine, and the engine the
 * package's schema contract is proven against — and the SAME invariants are
 * enforced on EVERY engine by the model guards (Invoice/InvoiceItem). The unit
 * suite proves the guard; the MySQL integration suite proves the constraint.
 * Documented in UPGRADE-7.0.md.
 *
 * Active-link uniqueness (technique is contractual, §7.1): MySQL 8 gets a STORED
 * generated column that is NULL for cancelled documents; SQLite gets a partial
 * unique index. Same semantics: at most ONE non-cancelled invoice per proforma,
 * so a cancelled preparation can be re-converted (§6.4) but two live conversions
 * are impossible (defect D1/D9).
 *
 * Same technique for the single active epoch (§5.2) and the single active
 * determination per line+obligation (§5.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addActiveConversionLinkUnique();
        $this->addSingleActiveEpochUnique();
        $this->addSingleActiveDeterminationUnique();

        if (DB::getDriverName() === 'mysql') {
            $this->addMysqlCheckConstraints();
        }

        // The chain is complete: writes may resume.
        UpgradeGate::release();
    }

    /**
     * EVERY statement is guarded on its own. Guarding the index but not the
     * generated column it indexes is the classic mid-DDL-resume hole: the run dies
     * after ADD COLUMN, the retry sees no index, re-runs ADD COLUMN, and fails on
     * "duplicate column".
     */
    private function addActiveConversionLinkUnique(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (! Schema::hasColumn('invoices', 'active_proforma_link')) {
                // invoice_document_status 3 = CANCELLED.
                DB::statement(<<<'SQL'
                    ALTER TABLE invoices
                    ADD COLUMN active_proforma_link CHAR(36)
                    GENERATED ALWAYS AS (
                        CASE WHEN invoice_document_status <> 3 THEN proforma_id ELSE NULL END
                    ) STORED
                    COMMENT 'Generated: proforma_id while the document is not cancelled. Backs the active-link unique index.'
                SQL);
            }

            if (! $this->hasIndex('invoices', 'invoices_active_proforma_link_unique')) {
                DB::statement('CREATE UNIQUE INDEX invoices_active_proforma_link_unique ON invoices (active_proforma_link)');
            }

            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS invoices_active_proforma_link_unique
            ON invoices (proforma_id)
            WHERE proforma_id IS NOT NULL AND invoice_document_status <> 3
        SQL);
    }

    private function addSingleActiveEpochUnique(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (! Schema::hasColumn('tax_catalog_epochs', 'active_epoch_flag')) {
                DB::statement(<<<'SQL'
                    ALTER TABLE tax_catalog_epochs
                    ADD COLUMN active_epoch_flag TINYINT UNSIGNED
                    GENERATED ALWAYS AS (CASE WHEN closed_at IS NULL THEN 1 ELSE NULL END) STORED
                    COMMENT 'Generated: 1 on the open epoch, NULL otherwise. Backs the single-active-epoch unique.'
                SQL);
            }

            if (! $this->hasIndex('tax_catalog_epochs', 'tax_catalog_epochs_single_active_unique')) {
                DB::statement('CREATE UNIQUE INDEX tax_catalog_epochs_single_active_unique ON tax_catalog_epochs (active_epoch_flag)');
            }

            return;
        }

        // A constant-expression partial index: at most one row may satisfy closed_at IS NULL.
        // If your SQLite build rejects the `((1))` expression, fall back to indexing a real
        // nullable column written by the epoch service — do NOT drop the constraint.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS tax_catalog_epochs_single_active_unique
            ON tax_catalog_epochs ((1))
            WHERE closed_at IS NULL
        SQL);
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i): bool => $i['name'] === $index);
    }

    /** MySQL 8: CHECK constraints live in information_schema.table_constraints. */
    private function hasCheckConstraint(string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('constraint_name', $constraint)
            ->exists();
    }

    private function addSingleActiveDeterminationUnique(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE tax_determinations
                ADD COLUMN active_scope_key VARCHAR(80)
                GENERATED ALWAYS AS (
                    CASE WHEN is_active = 1
                        THEN CONCAT(invoice_item_id, ':', COALESCE(fiscal_obligation_id, 'none'))
                        ELSE NULL
                    END
                ) STORED
                COMMENT 'Generated: line+obligation scope while active. Backs the single-active-determination unique.'
            SQL);

            DB::statement('CREATE UNIQUE INDEX tax_determinations_single_active_unique ON tax_determinations (active_scope_key)');

            return;
        }

        // SQLite treats NULLs as DISTINCT in a unique index, so indexing
        // (invoice_item_id, fiscal_obligation_id) would happily allow N active
        // determinations for a line whose obligation is still NULL — the exact
        // hole AID-390 already paid for once, with the same remedy: a sentinel.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tax_determinations_single_active_unique
            ON tax_determinations (
                invoice_item_id,
                COALESCE(fiscal_obligation_id, '00000000-0000-0000-0000-000000000000')
            )
            WHERE is_active = 1
        SQL);
    }

    /**
     * The cross-axis coherence CHECKs (§7.1, Codex 66/78). MySQL only — see the docblock.
     *
     * Serie 0 = PROFORMA. Document status: 0=draft 1=prepared 2=issued 3=cancelled.
     *
     * Guarded per constraint: a chain killed between two ALTERs must re-run cleanly.
     */
    private function addMysqlCheckConstraints(): void
    {
        // Each constraint is guarded INDIVIDUALLY. A run killed between two ALTERs
        // must add exactly the ones still missing — an all-or-nothing early return
        // would skip the rest forever.
        $constraints = [
            // A row is a proforma IFF it carries a proforma status.
            'chk_invoices_proforma_axis' => <<<'SQL'
                ALTER TABLE invoices ADD CONSTRAINT chk_invoices_proforma_axis CHECK (
                    (serie = 0 AND proforma_status IS NOT NULL AND invoice_document_status IS NULL)
                    OR
                    (serie <> 0 AND proforma_status IS NULL AND invoice_document_status IS NOT NULL)
                )
            SQL,

            // ISSUED <=> numbered. BOTH directions: a numbered non-issued row is impossible.
            'chk_invoices_issued_numbering' => <<<'SQL'
                ALTER TABLE invoices ADD CONSTRAINT chk_invoices_issued_numbering CHECK (
                    invoice_document_status IS NULL
                    OR (
                        invoice_document_status = 2
                        AND fiscal_number IS NOT NULL AND series_number IS NOT NULL
                        AND fiscal_year IS NOT NULL AND invoice_date IS NOT NULL AND issued_at IS NOT NULL
                    )
                    OR (
                        invoice_document_status <> 2
                        AND fiscal_number IS NULL AND series_number IS NULL
                        AND fiscal_year IS NULL AND invoice_date IS NULL AND issued_at IS NULL
                    )
                )
            SQL,

            // ISSUED <=> a submission status exists. Before issuance there is no intent to record.
            'chk_invoices_submission_axis' => <<<'SQL'
                ALTER TABLE invoices ADD CONSTRAINT chk_invoices_submission_axis CHECK (
                    invoice_document_status IS NULL
                    OR (invoice_document_status = 2 AND fiscal_submission_status IS NOT NULL)
                    OR (invoice_document_status <> 2 AND fiscal_submission_status IS NULL)
                )
            SQL,

            // A fact belongs to exactly ONE billing aggregate: a proforma XOR a direct
            // invoice (spec §7.3, Codex 71). SQLite gets this invariant from the model
            // guard PR-2 ships with the EconomicFact model — nothing writes this table
            // before then.
            'chk_facts_single_owner' => <<<'SQL'
                ALTER TABLE billing_economic_facts ADD CONSTRAINT chk_facts_single_owner CHECK (
                    (proforma_id IS NOT NULL AND invoice_id IS NULL)
                    OR (proforma_id IS NULL AND invoice_id IS NOT NULL)
                )
            SQL,

            'chk_obligations_single_owner' => <<<'SQL'
                ALTER TABLE billing_fiscal_obligations ADD CONSTRAINT chk_obligations_single_owner CHECK (
                    (proforma_id IS NOT NULL AND invoice_id IS NULL)
                    OR (proforma_id IS NULL AND invoice_id IS NOT NULL)
                )
            SQL,

            // An obligation has an origin: a fact, or a reached schedule entry. Never neither.
            'chk_obligations_origin' => <<<'SQL'
                ALTER TABLE billing_fiscal_obligations ADD CONSTRAINT chk_obligations_origin CHECK (
                    economic_fact_id IS NOT NULL OR chargeability_schedule_id IS NOT NULL
                )
            SQL,
        ];

        foreach ($constraints as $name => $sql) {
            if (! $this->hasCheckConstraint($name)) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        // The gate protects the rollback too: link 13 is the FIRST to come down, so
        // releasing here would leave the other twelve down() calls running on an
        // unprotected database. Only link 1 — the LAST to come down — releases.
        UpgradeGate::ensureHeld();

        if (DB::getDriverName() === 'mysql') {
            foreach ([
                ['invoices', 'chk_invoices_proforma_axis'],
                ['invoices', 'chk_invoices_issued_numbering'],
                ['invoices', 'chk_invoices_submission_axis'],
                ['billing_economic_facts', 'chk_facts_single_owner'],
                ['billing_fiscal_obligations', 'chk_obligations_single_owner'],
                ['billing_fiscal_obligations', 'chk_obligations_origin'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }

            DB::statement('DROP INDEX invoices_active_proforma_link_unique ON invoices');
            DB::statement('ALTER TABLE invoices DROP COLUMN active_proforma_link');
            DB::statement('DROP INDEX tax_catalog_epochs_single_active_unique ON tax_catalog_epochs');
            DB::statement('ALTER TABLE tax_catalog_epochs DROP COLUMN active_epoch_flag');
            DB::statement('DROP INDEX tax_determinations_single_active_unique ON tax_determinations');
            DB::statement('ALTER TABLE tax_determinations DROP COLUMN active_scope_key');
        } else {
            DB::statement('DROP INDEX IF EXISTS invoices_active_proforma_link_unique');
            DB::statement('DROP INDEX IF EXISTS tax_catalog_epochs_single_active_unique');
            DB::statement('DROP INDEX IF EXISTS tax_determinations_single_active_unique');
        }
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Integration/Mysql/V7ConstraintsTest.php`
Expected: PASS — 9 passed.

If the SQLite partial-unique on `tax_catalog_epochs ((1))` is rejected by your SQLite version, replace the expression with a real column: add `active_epoch_flag` as a plain nullable tinyint written by the epoch service, and index `(active_epoch_flag) WHERE closed_at IS NULL`. Do not silently drop the constraint.

- [ ] **Step 5: Run the FULL chain end to end on a fresh MySQL database**

This is the moment the whole PR is really tested.

```bash
php83 vendor/bin/pest tests/Integration
```
Expected: green.

- [ ] **Step 6: Bootstrap the stub + commit**

```bash
cp database/migrations/2026_07_20_000013_add_v7_coherence_constraints.php \
   database/migrations/2026_07_20_000013_add_v7_coherence_constraints.php.stub
php83 bin/sync-migration-stubs && php83 vendor/bin/pint
git add database/migrations/2026_07_20_000013* tests/Integration/Mysql/V7ConstraintsTest.php
git commit -m "feat(v7): migration 13 — coherence constraints and gate release

The database now refuses what the guards refuse: a numbered non-issued
row, an issued row with no submission status, a proforma with a document
axis, two active conversions of one proforma, two open epochs, two active
determinations for one accrual, a fact owned by two aggregates.

Last in the chain on purpose: applied earlier, these would abort on the
very legacy rows the backfill exists to fix.

Refs AID-459 (PR-1)"
```

---

### Task 13: Model casts + the immutability guards (defect D8)

Two problems, one deliverable.

**D8:** `Invoice::update()` is overridden, but attribute assignment + `save()` walks straight past it, and `InvoiceItem` has no guard at all — the lines of an issued invoice are editable today. The fix is a `saving`/`deleting` guard on both models, which no assignment path can bypass.

**Casts:** the new columns need their enums, or every read returns a raw int.

The guard's allow-list is not a compromise, it is the domain: `status` and `paid_at` are **collection** state, not fiscal content (that is why `markAsPaidViaGroupedPayment()` exists and deliberately bypasses the old `update()` guard, AID-30). Fiscal verification fields are written by the verifactu bridge *after* issuance. Everything else on an issued invoice is frozen.

**Files:**
- Modify: `src/Models/Invoice.php`, `src/Models/InvoiceItem.php`
- Create: `src/Exceptions/ImmutableInvoiceException.php`
- Test: `tests/Unit/Models/InvoiceImmutabilityGuardTest.php`

**Interfaces:**
- Consumes: the enums of Task 1, the columns of Tasks 6–7.
- Produces:
  - `Invoice` casts: `proforma_status` → `ProformaStatus`, `invoice_document_status` → `InvoiceDocumentStatus`, `fiscal_submission_status` → `FiscalSubmissionStatus`.
  - `InvoiceItem` casts: `price_tax_mode` → `PriceTaxMode`, `operation_nature` → `OperationNature`, and `contract_unit_price` / `contract_line_total` / `unit_price_base_adjustment` → `FixedDecimalCast:2`.
  - `ImmutableInvoiceException` thrown from both guards.
  - `Invoice::MUTABLE_AFTER_ISSUANCE` — the allow-list constant (collection state + fiscal verification fields).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/InvoiceImmutabilityGuardTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\ImmutableInvoiceException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;

it('refuses to mutate the fiscal content of an issued invoice through save()', function (): void {
    // The bypass the old update() override never closed (defect D8).
    $invoice = Invoice::factory()->create(['is_immutable' => true]);

    $invoice->taxable_amount = 999_99;
    $invoice->save();
})->throws(ImmutableInvoiceException::class);

it('refuses to mutate the fiscal content of an issued invoice through update()', function (): void {
    $invoice = Invoice::factory()->create(['is_immutable' => true]);

    $invoice->update(['taxable_amount' => 999_99]);
})->throws(ImmutableInvoiceException::class);

it('still allows collection state to move on an issued invoice', function (): void {
    // status and paid_at are NOT fiscal content. Grouped payments depend on this.
    $invoice = Invoice::factory()->create(['is_immutable' => true]);

    $invoice->markAsPaidViaGroupedPayment(now());

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
});

it('refuses to edit a line of an issued invoice', function (): void {
    // The hole D8 left wide open: the header was guarded, the LINES were not.
    $invoice = Invoice::factory()->create(['is_immutable' => true]);
    $item    = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

    $item->unit_price = 1;
    $item->save();
})->throws(ImmutableInvoiceException::class);

it('refuses to delete a line of an issued invoice', function (): void {
    $invoice = Invoice::factory()->create(['is_immutable' => true]);
    $item    = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

    $item->delete();
})->throws(ImmutableInvoiceException::class);

it('refuses to delete an issued invoice', function (): void {
    Invoice::factory()->create(['is_immutable' => true])->delete();
})->throws(ImmutableInvoiceException::class);

it('leaves a draft invoice and its lines fully editable', function (): void {
    $invoice = Invoice::factory()->create(['is_immutable' => false]);
    $item    = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

    $invoice->taxable_amount = 500;
    $invoice->save();

    $item->unit_price = 250;
    $item->save();

    expect($invoice->fresh()->taxable_amount->toInt())->toBe(500)
        ->and($item->fresh()->unit_price->toInt())->toBe(250);
});
```

> `taxable_amount` is a `FixedDecimal` (lara100) on the Eloquent attribute — read the existing model before asserting on it; `->toInt()` is the Base-100 accessor. Confirm the exact API in `src/Models/Invoice.php` rather than trusting this line.

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Unit/Models/InvoiceImmutabilityGuardTest.php`
Expected: FAIL — the `save()` bypass succeeds; no exception class exists.

- [ ] **Step 3: Write the exception**

`src/Exceptions/ImmutableInvoiceException.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * Thrown when any path — update(), save(), delete(), on a header OR on a line —
 * attempts to mutate the fiscal content of an issued invoice (spec §6.3 step 6,
 * defect D8).
 *
 * Bulk query-builder writes and external SQL are documented OUTSIDE this
 * guarantee (spec §10). The database-level enforcement of immutability is its
 * own ticket.
 *
 * @api
 */
class ImmutableInvoiceException extends RuntimeException
{
    public static function forHeader(string $invoiceId, string $attribute): self
    {
        return new self(
            "Invoice {$invoiceId} is fiscally immutable; `{$attribute}` cannot be modified. "
            .'Corrections to an issued invoice go through a rectificative invoice, never through mutation.'
        );
    }

    public static function forLine(string $invoiceId): self
    {
        return new self(
            "The lines of invoice {$invoiceId} are fiscally immutable; they cannot be modified or deleted. "
            .'Corrections to an issued invoice go through a rectificative invoice, never through mutation.'
        );
    }

    public static function forDeletion(string $invoiceId): self
    {
        return new self(
            "Invoice {$invoiceId} is fiscally immutable and cannot be deleted."
        );
    }
}
```

- [ ] **Step 4: Add the casts and the guard to `Invoice`**

In `src/Models/Invoice.php`:

1. Add to `casts()` (or `$casts`, matching the existing style — read it first):

```php
'proforma_status'          => ProformaStatus::class,
'invoice_document_status'  => InvoiceDocumentStatus::class,
'fiscal_submission_status' => FiscalSubmissionStatus::class,
```

2. Add the allow-list constant and the guard:

```php
/**
 * The only attributes that may change on a fiscally immutable invoice.
 *
 * `status` and `paid_at` are COLLECTION state, not fiscal content — that is
 * why grouped payments can mark an issued invoice paid (AID-30). The
 * fiscal_verification_* fields are written by the lara-verifactu bridge AFTER
 * issuance. Everything else on an issued invoice is frozen: corrections go
 * through a rectificative invoice.
 *
 * @var array<int, string>
 */
public const MUTABLE_AFTER_ISSUANCE = [
    'status',
    'paid_at',
    'updated_at',
    'fiscal_verification_id',
    'fiscal_verification_qr',
    'fiscal_verification_hash',
    'fiscal_verified_at',
    'fiscal_verification_metadata',
    // The submission axis moves after issuance by design (PENDING -> REGISTERED ...).
    'fiscal_submission_status',
    // CONVERSION LINKAGE. A proforma is marked immutable at conversion time, and the
    // conversion then writes its links — the old update() override allowed exactly
    // this set, and removing it would break conversion on day one. These fields say
    // WHICH invoice descends from this document; they are not its fiscal content.
    'converted_invoice_id',
    'converted_at',
    'proforma_id',
    'proforma_status',
];

/**
 * Was this invoice fiscally frozen BEFORE the current mutation was staged?
 *
 * Two properties, both learned the hard way:
 *
 * 1. The authority is the PERSISTED state, never the in-memory one. Reading
 *    `$this->is_immutable` would let a caller stage `is_immutable = false` next
 *    to the fiscal change it wants and walk straight through the guard — the
 *    same class of bypass D8 already was.
 *
 * 2. The authority is the DOCUMENT STATE, not only the legacy boolean. The
 *    backfill classifies every numbered legacy row as ISSUED but does not rewrite
 *    `is_immutable`, so a historical issued invoice can carry `is_immutable =
 *    false` — and a guard that trusted only the boolean would leave it, and its
 *    lines, editable. ISSUED is the fiscal truth; the boolean is kept as
 *    transitional compatibility (v6 code still writes it) and dies with D6 in PR-3.
 */
public function wasImmutable(): bool
{
    return (bool) $this->getOriginal('is_immutable', false)
        || $this->getOriginal('invoice_document_status') === InvoiceDocumentStatus::ISSUED->value;
}

protected static function booted(): void
{
    // Closes defect D8: update() was overridden, but attribute assignment +
    // save() walked straight past it. A saving hook cannot be bypassed.
    static::saving(function (self $invoice): void {
        if (! $invoice->exists || ! $invoice->wasImmutable()) {
            return;
        }

        foreach (array_keys($invoice->getDirty()) as $attribute) {
            if (! in_array($attribute, self::MUTABLE_AFTER_ISSUANCE, true)) {
                throw ImmutableInvoiceException::forHeader((string) $invoice->id, $attribute);
            }
        }
    });

    static::deleting(function (self $invoice): void {
        if ($invoice->wasImmutable()) {
            throw ImmutableInvoiceException::forDeletion((string) $invoice->id);
        }
    });
}
```

> **`is_immutable` itself is NOT in the allow-list** — that is the point of `wasImmutable()`. Un-freezing an issued invoice is not an operation this domain has; if some legacy code path does it, it must surface as a failing test and be discussed, not accommodated.

- [ ] **Step 4b: Make `update()` throw the typed exception**

`src/Models/Invoice.php:301` currently throws `new \Exception('Cannot update an immutable invoice')`. The new tests expect `ImmutableInvoiceException`, and a generic `\Exception` is impossible for a consumer to catch precisely.

```php
throw ImmutableInvoiceException::forHeader((string) $this->id, implode(', ', array_keys($attributes)));
```

`ImmutableInvoiceException extends RuntimeException extends Exception`, so any consumer already catching `\Exception` keeps working. Note the narrowed type in `UPGRADE-7.0.md`.

> If `Invoice` already declares `booted()`, merge into it — do not add a second one.

- [ ] **Step 5: Add the casts and the guard to `InvoiceItem`**

In `src/Models/InvoiceItem.php`:

```php
'price_tax_mode'             => PriceTaxMode::class,
'operation_nature'           => OperationNature::class,
'contract_unit_price'        => FixedDecimalCast::class.':2',
'contract_line_total'        => FixedDecimalCast::class.':2',
'unit_price_base_adjustment' => FixedDecimalCast::class.':2',
```

```php
/**
 * Is the invoice this line belongs to fiscally frozen?
 *
 * Queried FRESH from the database, not through `$item->invoice`: a cached
 * relation can hold a stale header loaded before issuance, and a line guard
 * that trusts a stale parent guards nothing.
 */
protected function parentIsImmutable(): bool
{
    if ($this->invoice_id === null) {
        return false;
    }

    $header = Invoice::query()
        ->whereKey($this->invoice_id)
        ->first(['is_immutable', 'invoice_document_status']);

    if ($header === null) {
        return false;
    }

    // ISSUED is the fiscal truth. The legacy boolean is transitional compatibility:
    // the backfill does not rewrite it, so a historical issued invoice may carry
    // is_immutable = false, and a guard that trusted only the boolean would leave
    // its lines editable.
    return (bool) $header->getRawOriginal('is_immutable')
        || $header->getRawOriginal('invoice_document_status') === InvoiceDocumentStatus::ISSUED->value;
}

protected static function booted(): void
{
    // The other half of D8: the header was guarded, the LINES were not — the
    // lines of an issued invoice were editable in every released version.
    //
    // `saving` fires for INSERT as well as UPDATE, and that is deliberate:
    // ADDING a line to an issued invoice is exactly as illegal as editing one.
    // The first draft of this plan gated on $item->exists and left that door open.
    static::saving(function (self $item): void {
        if ($item->parentIsImmutable()) {
            throw ImmutableInvoiceException::forLine((string) $item->invoice_id);
        }
    });

    static::deleting(function (self $item): void {
        if ($item->parentIsImmutable()) {
            throw ImmutableInvoiceException::forLine((string) $item->invoice_id);
        }
    });
}
```

> This costs one `SELECT` per line write. That is the price of a guard that cannot be lied to; the conversion path writes lines in a loop inside a transaction, so measure before optimizing, and if it ever matters, pass the already-locked header down explicitly rather than trusting a cached relation.

- [ ] **Step 5b: Add the axis coherence validator (the SQLite half of the CHECK strategy)**

The CHECKs of link 13 exist only on MySQL. The invariants must hold on every engine, and `invoices` HAS a live writer (the v6 runtime). Add to `Invoice`:

```php
/**
 * The cross-axis invariants the MySQL CHECK constraints enforce (spec §7.1).
 *
 * SQLite cannot ADD CONSTRAINT CHECK to an existing table, so on that engine
 * THIS is the enforcement — not a nicety, the actual contract:
 *
 *   serie = PROFORMA  <=>  proforma_status IS NOT NULL, document axis NULL
 *   ISSUED            <=>  numbered (all five fields), and vice versa
 *   ISSUED            <=>  a submission status exists
 */
protected function assertAxisCoherence(): void
{
    $isProforma = $this->serie === InvoiceSerieType::PROFORMA;

    if ($isProforma !== ($this->proforma_status !== null) || ($isProforma && $this->invoice_document_status !== null)) {
        throw new FiscalIntegrityException(
            'Axis incoherence: a row is a proforma if and only if it carries a proforma_status '
            .'and no invoice_document_status.'
        );
    }

    if ($isProforma) {
        return;
    }

    $isIssued = $this->invoice_document_status === InvoiceDocumentStatus::ISSUED;

    // The MySQL CHECK demands ALL FIVE numbering fields move together, in both
    // directions. Validating only fiscal_number would let a half-numbered row
    // through on SQLite and blow up on MySQL in production — the worst possible
    // place to discover the difference.
    $numbering = [
        'fiscal_number' => $this->fiscal_number,
        'series_number' => $this->series_number,
        'fiscal_year'   => $this->fiscal_year,
        'invoice_date'  => $this->invoice_date,
        'issued_at'     => $this->issued_at,
    ];

    $set     = array_filter($numbering, fn ($value): bool => $value !== null);
    $allSet  = count($set) === count($numbering);
    $noneSet = $set === [];

    if ($isIssued && ! $allSet) {
        throw new FiscalIntegrityException(
            'Axis incoherence: an ISSUED invoice must carry all five numbering fields '
            .'(fiscal_number, series_number, fiscal_year, invoice_date, issued_at). Missing: '
            .implode(', ', array_keys(array_diff_key($numbering, $set))).'.'
        );
    }

    if (! $isIssued && ! $noneSet) {
        throw new FiscalIntegrityException(
            'Axis incoherence: a non-ISSUED invoice must carry NO numbering fields — a '
            .'correlative is consumed at issuance and nowhere else. Present: '
            .implode(', ', array_keys($set)).'.'
        );
    }

    if ($isIssued !== ($this->fiscal_submission_status !== null)) {
        throw new FiscalIntegrityException(
            'Axis incoherence: fiscal_submission_status is set at issuance and only at issuance.'
        );
    }
}
```

Call it from the `saving` hook, **before** the immutability check. `FiscalIntegrityException` already exists in `src/Exceptions/`.

Test it in `tests/Unit/Models/InvoiceAxisCoherenceTest.php`: each of the three invariants, violated on SQLite, must throw. These are the SQLite twins of the MySQL constraint tests in Task 12 — the pair is the contract.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php83 vendor/bin/pest tests/Unit/Models/InvoiceImmutabilityGuardTest.php`
Expected: PASS — 7 passed.

- [ ] **Step 7: Run the FULL suite — this guard will break existing tests, and that is information**

Run: `php83 vendor/bin/pest`

Any test that fails here was mutating an issued invoice. For each failure, decide honestly:

- The test was exercising the D8 hole → **fix the test**: it should now expect `ImmutableInvoiceException`.
- Production code was mutating an issued invoice through a path the domain actually needs → **the allow-list is incomplete**: add the attribute with a comment justifying why it is not fiscal content. Do not widen the list to make a test pass without that justification.

- [ ] **Step 8: Write the CHANGELOG entry, THEN regenerate the contract snapshots**

`Invoice` and `InvoiceItem` are contract models (AID-412): new casts change their snapshot, and `bin/sync-contract-snapshots` **refuses to run without a CHANGELOG entry describing the surface change** (that gate is the whole point of the script). The CHANGELOG therefore lands here, not in Task 15 — Task 15 only completes it.

Add to `CHANGELOG.md` under `## [Unreleased]` the `### Fixed` block for D8 and the `### Changed` block for the casts and the narrowed `update()` exception (the full text is in Task 15, step 4 — write it now, extend it there).

```bash
php83 bin/sync-contract-snapshots
php83 vendor/bin/pest tests/Contract
```
Expected: snapshots updated, contract tests green.

- [ ] **Step 9: Quality gates + commit**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
git add src/Models src/Exceptions/ImmutableInvoiceException.php tests/Unit/Models/InvoiceImmutabilityGuardTest.php tests/Contract/snapshots
git commit -m "feat(v7)!: close defect D8 — issued invoices and their lines are immutable

update() was overridden, but attribute assignment + save() walked straight
past it, and InvoiceItem had no guard at all: the lines of an issued
invoice were editable in every released version.

Both models now guard saving/deleting. Collection state (status, paid_at)
and the post-issuance fiscal verification fields stay mutable — they are
not fiscal content.

Refs AID-459 (PR-1)"
```

---

### Task 14: Transitional coherence — the v6 runtime on the v7 schema

The constraints of Task 12 are now live. A v6 code path that creates an invoice **without** stamping the new axes violates them instantly. This task is what makes "every PR leaves the runtime coherent with the new schema" (constitution 16) true instead of aspirational.

It also closes the two conversion defects that need no redesign: **D1** (no row lock) and **D2** (`proforma_id` never written).

**Explicitly NOT in this task — the FULL list of transitional exceptions still standing after PR-1** (corrected during review; the first draft named only three):

| Defect | Still live after PR-1 | Dies in |
|---|---|---|
| D3 | conversion still copies only 4 line fields, discarding `item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata` and the `taxes_applied` snapshot | PR-3 |
| D4 | taxes still recalculated against the live catalog | PR-3 |
| D5 | no target series selectable at conversion | PR-3 |
| D6 | conversion and issuance still collapsed | PR-3 |
| D7 | conversion still requires no freeze and no payment | PR-2/PR-3 |
| D10 | `service_date` still populated by nothing | PR-3 |

**Do not fix them here**, and name them in the PR body: between PR-1 and PR-3, every conversion performed in production still loses the line fields of D3. That is a known, time-boxed cost of a phased release, and it must be visible to whoever runs conversions during the window — not buried.

**Files:**
- Modify: `src/Models/Invoice.php` (a stamping `saving` hook), `src/Models/InvoiceItem.php` (a stamping `saving` hook), `src/Services/InvoiceService.php`
- Test: `tests/Unit/Models/InvoiceV7StampingTest.php`, `tests/Unit/Models/InvoiceItemV7StampingTest.php`, `tests/Feature/Services/ConversionTransitionalTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: every invoice created by v6 code carries a coherent axis; `convertProformaToInvoice()` locks and writes the canonical link.

- [ ] **Step 1: Write the failing test for the stamping hook**

Create `tests/Unit/Models/InvoiceV7StampingTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\ProformaStatus;
use AichaDigital\Larabill\Models\Invoice;

it('stamps a new proforma with the DRAFT proforma axis and no document axis', function (): void {
    $proforma = Invoice::factory()->create(['serie' => InvoiceSerieType::PROFORMA]);

    expect($proforma->proforma_status)->toBe(ProformaStatus::DRAFT)
        ->and($proforma->invoice_document_status)->toBeNull()
        ->and($proforma->fiscal_submission_status)->toBeNull()
        ->and($proforma->currency)->toBe('EUR');
});

it('stamps a new numbered invoice as ISSUED on the document axis', function (): void {
    // v6 creates a numbered, immutable invoice in ONE step (defect D6, killed in
    // PR-3). Until then, the transitional truth is: it IS issued, and the
    // bidirectional CHECK (numbered <=> ISSUED) demands we say so.
    $invoice = Invoice::factory()->create(['serie' => InvoiceSerieType::INVOICE]);

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoice->proforma_status)->toBeNull()
        // ISSUED <=> a submission status exists.
        ->and($invoice->fiscal_submission_status)->toBeInstanceOf(FiscalSubmissionStatus::class);
});

it('never overwrites an explicitly provided axis', function (): void {
    $invoice = Invoice::factory()->create([
        'serie'                   => InvoiceSerieType::INVOICE,
        'invoice_document_status' => InvoiceDocumentStatus::PREPARED,
        'fiscal_number'           => null,
        'series_number'           => null,
        'fiscal_year'             => null,
        'invoice_date'            => null,
        'issued_at'               => null,
    ]);

    expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($invoice->fiscal_submission_status)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php83 vendor/bin/pest tests/Unit/Models/InvoiceV7StampingTest.php`
Expected: FAIL — the axes are NULL.

- [ ] **Step 3: Add the stamping hook to `Invoice::booted()`**

Merge into the `booted()` written in Task 13:

**It must run in `saving`, NOT in `creating`.** Eloquent fires `saving` **before** `creating` (`Model::save()` → `fireModelEvent('saving')` → `performInsert()` → `fireModelEvent('creating')`). A `creating` hook would stamp the axes *after* `assertAxisCoherence()` had already rejected the row for having none — every v6 create would fail. Register the stamping hook **first**, so the coherence validator runs on a stamped model:

```php
static::saving(function (self $invoice): void {
    // Order matters: this hook is registered BEFORE the coherence validator and
    // BEFORE the immutability guard, and Eloquent fires them in registration order.
    // (`creating` would be too late — it fires AFTER `saving`.)
    if ($invoice->exists) {
        return; // Only new rows are stamped; an existing row's axis is already set.
    }

    // TRANSITIONAL (spec §9, PR-1): v6 code paths do not know about the v7 axes,
    // but the v7 CHECK constraints do. This keeps the running system coherent with
    // the new schema until PR-3 rewrites the write paths properly.
    //
    // It NEVER overwrites an explicitly provided value: PR-2/PR-3 code sets the
    // axes deliberately, and this hook must not fight it.
    $invoice->currency ??= 'EUR';

    if ($invoice->serie === InvoiceSerieType::PROFORMA) {
        $invoice->proforma_status ??= ProformaStatus::DRAFT;

        return;
    }

    if ($invoice->invoice_document_status !== null) {
        return;
    }

    // v6 creates numbered invoices in one step (defect D6). A numbered row IS
    // issued — the bidirectional CHECK says so, and it is the truth.
    $invoice->invoice_document_status = InvoiceDocumentStatus::ISSUED;

    $invoice->fiscal_submission_status ??= config('larabill.verifactu.enabled', false)
        ? FiscalSubmissionStatus::PENDING
        : FiscalSubmissionStatus::NOT_REQUIRED;
});
```

> Check the real config key for the verifactu bridge before writing that line (`grep -rn "verifactu" config/larabill.php`). If none exists, use `FiscalSubmissionStatus::NOT_REQUIRED` unconditionally and note it in `UPGRADE-7.0.md`.

- [ ] **Step 4: Run the stamping test to verify it passes**

Run: `php83 vendor/bin/pest tests/Unit/Models/InvoiceV7StampingTest.php`
Expected: PASS — 3 passed.

- [ ] **Step 4b: Stamp the frozen contract on every NEW line (review finding 5)**

Without this, every line written by v6 code lands with `contract_line_total = 0` and `price_tax_mode = TAX_EXCLUSIVE` by default — a "frozen contract" of zero euros on live invoices. The stamping must happen wherever lines are born, and the only place that catches them all is the model.

Add to `InvoiceItem::booted()` (merging with the guard from Task 13):

```php
static::saving(function (self $item): void {
    // Registered BEFORE the immutability guard. `saving` fires before `creating`,
    // so a `creating` hook would stamp the contract too late for any validator
    // that runs in `saving`.
    if ($item->exists) {
        return; // Only new lines are stamped.
    }

    // TRANSITIONAL (spec §9, PR-1): v6 code paths know nothing about the frozen
    // commercial contract. Until PR-2/PR-3 write it deliberately, derive it from
    // the fiscal values the line already carries — the same rule the legacy
    // backfill applies, so a line created today and a line migrated yesterday are
    // indistinguishable.
    $item->currency ??= 'EUR';
    $item->price_tax_mode ??= PriceTaxMode::TAX_EXCLUSIVE;
    $item->contract_unit_price ??= $item->unit_price;
    $item->contract_line_total ??= $item->taxable_amount;

    // taxable_amount = round(quantity x unit_price) + adjustment  (quantity is Base-100)
    $item->unit_price_base_adjustment ??= $item->taxable_amount
        - (int) round($item->quantity * $item->unit_price / 100);
});
```

> These attributes are `FixedDecimal`-cast (lara100). Read `src/Models/InvoiceItem.php` and use the same arithmetic the model already uses for `taxable_amount` — do NOT mix a raw int expression with a `FixedDecimal` attribute. If the cast makes `??=` awkward, use `isset()` checks; what matters is: never overwrite a value the caller set.

Test in `tests/Unit/Models/InvoiceItemV7StampingTest.php`: a line created through the v6 path carries a contract equal to its fiscal values, a zero adjustment for a coherent line, and the correct residue for an incoherent one; an explicitly-provided contract is never overwritten.

- [ ] **Step 5: Write the failing test for D1 + D2**

Create `tests/Feature/Services/ConversionTransitionalTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;

it('writes the canonical proforma_id link on conversion (defect D2)', function (): void {
    // The link the model ALREADY read through convertedInvoices() and that
    // conversion never wrote: broken from birth, in every released version.
    $proforma = Invoice::factory()->create(['serie' => InvoiceSerieType::PROFORMA]);

    $invoice = app(InvoiceService::class)->convertProformaToInvoice($proforma);

    expect($invoice->proforma_id)->toBe($proforma->id)
        // The mirror stays dual-written in v7, and dies in v8.
        ->and($proforma->fresh()->converted_invoice_id)->toBe($invoice->id)
        ->and($proforma->fresh()->convertedInvoices)->toHaveCount(1);
});

it('is idempotent under a second conversion attempt', function (): void {
    $proforma = Invoice::factory()->create(['serie' => InvoiceSerieType::PROFORMA]);
    $service  = app(InvoiceService::class);

    $first  = $service->convertProformaToInvoice($proforma);
    $second = $service->convertProformaToInvoice($proforma->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::where('proforma_id', $proforma->id)->count())->toBe(1);
});
```

Adjust `convertProformaToInvoice()`'s argument list to the real signature — read it first.

- [ ] **Step 6: Fix D1 and D2 in `InvoiceService::convertProformaToInvoice()`**

At `src/Services/InvoiceService.php:346` the method calls `refresh()` and checks idempotency — a TOCTOU race that lets two concurrent conversions mint two invoices (D1). Inside the existing transaction:

```php
// D1: refresh() is a READ. Two concurrent conversions both passed this check and
// both created an invoice. lockForUpdate() serializes them on the proforma row —
// the aggregate root of the lock order (spec §6.8).
$proforma = Invoice::query()
    ->whereKey($proforma->id)
    ->lockForUpdate()
    ->firstOrFail();

if ($proforma->converted_invoice_id !== null) {
    return Invoice::findOrFail($proforma->converted_invoice_id);
}
```

**D2 — three traps, all of which the first draft of this plan walked into. Read `src/Services/InvoiceService.php:107-150` before writing a line of this.**

**Trap A — the creation payload is a CLOSED array.** `createInvoice()` calls `Invoice::create([...])` with an explicit, hardcoded list of attributes (`InvoiceService.php:107`). Adding `$invoiceData['proforma_id']` in the caller does **nothing**: the key is never read. The column must be added to that array:

```php
// In createInvoice(), inside the Invoice::create([...]) literal:
'proforma_id' => $invoiceData['proforma_id'] ?? null,
```

**Trap B — the signature.** `createInvoice(array $invoiceData, array $options = [])`. The **items live inside `$invoiceData['items']`**; the second argument is options (`make_immutable`, `verify_fiscally`). Do not invent a `$items` parameter.

**Trap C — immutability ordering.** `createInvoice()` ends in `makeImmutable()` when `$options['make_immutable']` is set (`InvoiceService.php:146`). Any `save()` of `proforma_id` *after* that point hits the new guard and throws. The link must be present at creation, which Trap A's fix achieves.

So, in `convertProformaToInvoice()`:

```php
$invoiceData['proforma_id'] = $proforma->id;

$invoice = $this->createInvoice($invoiceData, $options);
```

**And the proforma side (`InvoiceService.php:411`) needs the `update()` OVERRIDE fixed, not just the allow-list.** `Invoice::update()` (`src/Models/Invoice.php:282`) carries its OWN hardcoded conversion allow-list:

```php
$conversionFields = ['is_immutable', 'converted_invoice_id', 'converted_at', 'status'];
```

Adding `proforma_status` to `MUTABLE_AFTER_ISSUANCE` does not help: the override rejects the call **before** the `saving` hook ever runs. Extend that list — and keep it **exactly** the conversion transition, not a general licence to rewrite the proforma axis after issuance:

```php
$conversionFields = [
    'is_immutable', 'converted_invoice_id', 'converted_at', 'status',
    // v7: the canonical link and the proforma's document transition travel with
    // the conversion. NOT a general post-issuance licence — these four are only
    // ever written together, by conversion.
    'proforma_id', 'proforma_status',
];
```

Then:

```php
$proforma->update([
    'is_immutable'         => true,
    'converted_invoice_id' => $invoice->id,   // deprecated mirror, dual-written in v7
    'converted_at'         => now(),
    // TRANSITIONAL: without this the proforma stays DRAFT while a live invoice
    // hangs off it — an incoherence the v7 lifecycle would then have to explain.
    'proforma_status'      => ProformaStatus::CONVERTED,
]);
```

Keep everything else as it is. D3/D4/D5/D6/D7/D10 stay until PR-2/PR-3 — the **named transitional exceptions** above.

- [ ] **Step 7: Run the conversion test to verify it passes**

Run: `php83 vendor/bin/pest tests/Feature/Services/ConversionTransitionalTest.php`
Expected: PASS — 2 passed.

- [ ] **Step 8: Full suite + quality gates + commit**

```bash
php83 vendor/bin/pest
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
git add src/Models/Invoice.php src/Services/InvoiceService.php tests/Unit/Models/InvoiceV7StampingTest.php tests/Feature/Services/ConversionTransitionalTest.php
git commit -m "feat(v7): transitional coherence — v6 runtime on the v7 schema

Every invoice created by an existing code path now stamps a coherent state
axis, so the v7 CHECK constraints hold on a running v6 system.

Closes defect D1 (conversion took no row lock: two concurrent conversions
could mint two invoices) and D2 (the canonical proforma_id link was never
written, so the bidirectional link was broken from birth).

The live-catalog recalculation (D4) and the collapsed conversion/issuance
(D6) remain until PR-3 — the named transitional exception of spec §9.

Refs AID-459 (PR-1)"
```

---

### Task 15: The upgrade document and the final verification

The contract pieces (`$migrationOrder`, manifest, install count) were closed **migration by migration**, in the commit that added each one — no commit in this PR ever left the gates red. What remains is the operator-facing document and the end-to-end proof.

**Files:**
- Modify: `CHANGELOG.md`
- Create: `UPGRADE-7.0.md` (repository root — **in the dist**; `docs/` is export-ignored, lesson AID-324)

**Interfaces:**
- Consumes: everything.
- Produces: green `MigrationOrderConsistencyTest`, `ShippedMigrationImmutabilityTest`, `InstallCommandSchemaTest`.

- [ ] **Step 1: Verify the contract gates are ALREADY green**

If any of these is red, a previous task committed a broken state and must be fixed before continuing.

```bash
php83 vendor/bin/pest tests/Unit/Console/MigrationOrderConsistencyTest.php
php83 vendor/bin/pest tests/Contract
```
Expected: PASS.

- [ ] **Step 2: Verify the `$migrationOrder` keys and the final count**

`src/Console/LarabillInstallCommand.php` must now end with **exactly** these 13 keys, appended after the pre-existing `'038'`:

```php
// === V7 LIFECYCLE (AID-459) — order is the chain order; FKs depend on it ===
'039' => 'v7_preflight_gate',
'040' => 'create_tax_catalog_epochs_table',
'041' => 'create_tax_determinations_table',
'042' => 'create_tax_determination_components_table',
'043' => 'add_v7_lifecycle_columns_to_invoices',
'044' => 'add_v7_contract_columns_to_invoice_items',
'045' => 'create_billing_economic_facts_table',
'046' => 'create_billing_chargeability_schedules_table',
'047' => 'create_billing_fiscal_obligations_table',
'048' => 'create_fiscal_submission_outbox_table',
'049' => 'backfill_v7_invoice_lifecycle_state',
'050' => 'backfill_v7_invoice_item_contract_terms',
'051' => 'add_v7_coherence_constraints',
```

Confirm with `git diff main -- src/Console/LarabillInstallCommand.php` that the diff is **purely additive**: 13 new lines, nothing removed, nothing renumbered. The array must hold **48** entries (35 + 13) and the pinned install count must be **48**.

```bash
grep -cE "^\s+'[0-9]{3}' =>" src/Console/LarabillInstallCommand.php   # expect 48
```

- [ ] **Step 3: Write `UPGRADE-7.0.md`**

Repository root. This ships **in the dist**. It must contain, in this order:

1. **The ritual**, verbatim and copy-pasteable.

   **The ordering trap:** `larabill:v7-preflight` ships WITH v7. It does not exist before `composer update`, so it cannot be run "before the window" on the old code. And running `composer update` while the system still serves traffic puts the v7 runtime (which stamps v7 columns) on top of the v6 schema — instant breakage. Therefore **update and preflight both happen INSIDE the maintenance window**, in this order:

   ```bash
   # 1. Stop the writers. `php artisan down` stops HTTP ONLY — not workers, not
   #    cron, not CLI. Stop them explicitly. larabill's own write gate refuses
   #    package writes during the chain, but it cannot stop your raw SQL.
   php artisan down
   #    ... stop queue workers and cron ...

   # 2. Attest the currency premise (see below):
   #    LARABILL_V7_ATTEST_CURRENCY_EUR=true in .env
   #
   #    A cached config does NOT see .env changes. If you run config:cache
   #    (most production deploys do), the preflight would read the OLD value and
   #    block on an attestation you already made.
   php artisan config:clear

   # 3. Update, then check, then migrate.
   composer update aichadigital/larabill
   php artisan larabill:v7-preflight      # read-only. Fix every BLOCKER, then re-run.
   php artisan larabill:install           # idempotent: publishes only the NEW migrations
   php artisan migrate

   # 4. Rebuild the caches, restart workers, then `php artisan up`.
   php artisan config:cache
   ```

   Never `migrate:fresh` on real data.

   **Want the preflight before the window?** Its detections are pure SQL over the v6 schema. Publish them as a standalone script (or a v6.4 backport) if you need the answer while the system is live — but the authoritative run is the one inside the window, because only there is the data frozen.

2. **The currency attestation is a gate, not a note.** `larabill.upgrade.attest_currency_eur` (env `LARABILL_V7_ATTEST_CURRENCY_EUR`) must be `true` or the preflight fails. v7 writes `EUR` on every historical row; larabill cannot verify that premise for you, and a warning nobody has to acknowledge is not consent.

3. **What the backfill decided**, as a table: the four proforma precedence rules, the "every numbered row is ISSUED" rule, `LEGACY_UNKNOWN` for submission state, `EUR` for currency, `TAX_EXCLUSIVE` + line-total precedence for lines, and the `operation_nature` approximation on already-issued lines only.

4. **The LEGACY state, explained honestly:** why an immutable pre-v7 proforma is `LEGACY` and not `FROZEN`, that it can neither determine nor convert, that its obligation projection is `UNKNOWN`, and that `adopt()` (arriving in PR-2) promotes it with an attested fact history.

5. **Behavior changes visible today:**
   - Issued invoices **and their lines** are immutable through `save()` and `delete()` too (D8). Code that mutated them now throws `ImmutableInvoiceException`. **Adding** a line to an issued invoice also throws.
   - `Invoice::update()` on an immutable invoice now throws `ImmutableInvoiceException` instead of a bare `\Exception` (a narrowing — `catch (\Exception)` still works).
   - `convertProformaToInvoice()` locks the proforma, writes `proforma_id`, and transitions the proforma to `CONVERTED`.

6. **Still broken until PR-3, and say so plainly:** conversion still discards line fields (D3: `item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata`, `taxes_applied`), still recalculates taxes against the live catalog (D4), still cannot select a target series (D5), still collapses conversion and issuance (D6), still requires no freeze (D7), and still leaves `service_date` unpopulated (D10). A consumer converting proformas between v7.0-PR1 and PR-3 keeps paying those costs.

7. **The declared limits:** the axis CHECK constraints exist at the database level on MySQL only (an `Invoice` validator enforces them on every engine); the single-owner CHECKs on facts/obligations exist on MySQL only, and their engine-agnostic guard arrives with the models in PR-2 (nothing writes those tables before then); bulk query-builder writes and external SQL are outside the immutability guarantee; the `down()` of the lifecycle migration cannot restore NOT NULL once any invoice has been PREPARED.

- [ ] **Step 7: Update the CHANGELOG**

Under `## [Unreleased]`, with the breaking change in bold:

```markdown
### Added

- **v7 schema foundations (AID-459, PR-1 of the proforma→invoice lifecycle redesign).** Three separate state axes (`proforma_status`, `invoice_document_status`, `fiscal_submission_status`); the frozen commercial contract on every line (`contract_unit_price`, `contract_line_total`, `price_tax_mode`, `unit_price_base_adjustment`); catalog epochs, tax determinations and their components; economic facts, chargeability schedules and fiscal obligations; the fiscal submission outbox; explicit ISO-4217 currency everywhere.
- `larabill:v7-preflight` — a read-only check that answers "can this database be migrated to v7?" without touching a row.

### Fixed

- **Defect D8: issued invoices and their lines were mutable.** `Invoice::update()` was overridden, but attribute assignment + `save()` bypassed it, and `InvoiceItem` had no guard at all. Both models now guard `saving`/`deleting`. Collection state (`status`, `paid_at`) and post-issuance fiscal verification fields remain mutable — they are not fiscal content.
- **Defect D1: `convertProformaToInvoice()` took no row lock.** Two concurrent conversions could mint two invoices from one proforma. The proforma row is now locked for update.
- **Defect D2: the canonical `proforma_id` link was never written.** `Invoice::convertedInvoices()` read a column conversion never populated — the bidirectional link was broken from birth. v7 dual-writes it; the `converted_invoice_id` mirror is deprecated and removed in v8.

### Changed

- **Fiscal numbering fields on `invoices` are now nullable** (`fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`): a PREPARED invoice exists without having consumed a correlative. A CHECK constraint (MySQL) and a model guard (every engine) make a numbered non-ISSUED row impossible.
- `invoices.proforma_id`'s foreign key is hardened from `nullOnDelete` to `restrict`.

See `UPGRADE-7.0.md`.
```

- [ ] **Step 8: The whole gate, end to end**

```bash
php83 vendor/bin/pint
php83 vendor/bin/phpstan analyse --memory-limit=1G
php83 vendor/bin/pest
php83 vendor/bin/pest tests/Integration      # MySQL: the real contract
```
Expected: all green, including `MigrationOrderConsistencyTest`, `ShippedMigrationImmutabilityTest`, `InstallCommandSchemaTest`, the surface taxonomy and the contract snapshots.

- [ ] **Step 9: Commit**

```bash
git add src/Console/LarabillInstallCommand.php tests/Contract/release-migration-manifest.json \
        tests/Integration/InstallMysql/InstallCommandSchemaTest.php UPGRADE-7.0.md CHANGELOG.md
git commit -m "chore(v7): close the migration contract gates and document the upgrade

Registers the 13 v7 migrations in \$migrationOrder and the release
manifest, bumps the pinned install count to 48, and ships UPGRADE-7.0.md
in the dist with the ritual, the backfill precedence, the LEGACY rationale
and the declared limits.

Refs AID-459 (PR-1)"
```

- [ ] **Step 10: Open the PR**

```bash
gh pr create --base main --title "feat(v7)!: schema foundations and transitional coherence (PR-1 of 4)" --body "..."
```

The body must name: the epic (AID-459), the three defects closed here (D1, D2, D8), the **named transitional exceptions still standing** (D4 live-catalog recalculation, D6 collapsed conversion/issuance, D7 no freeze precondition — all die in PR-3), and the fact that this PR is additive-only for consumers who do not touch the new columns, except for the immutability guard, which will throw where code previously mutated an issued invoice.

---

## Self-review — before opening the PR

Run this checklist yourself. It is not optional and it is not a subagent's job.

1. **Every spec §7 element has a migration.** Walk §7.1, §7.2 and §7.3 line by line and point at the migration that implements each. Missing: nothing may be left for "PR-2 will add the column" — PR-2 adds behavior, not schema.
2. **The five-piece migration contract holds for all 13.** `.php` + byte-identical `.php.stub` + `$migrationOrder` + manifest entry (`in_base: false`) + the bumped install count. Run `php83 bin/sync-migration-stubs` and `php83 bin/sync-upgrade-manifest` one final time and confirm both report no changes.
3. **The chain re-runs cleanly after a mid-chain kill — including mid-DDL.** On a scratch MySQL database: (a) kill `migrate` between links 6 and 7 and re-run; (b) kill it **inside** link 13, between the generated column and its index (add a temporary `throw` to force it), and re-run. Both must succeed. Every DDL statement is guarded individually; guarding the index but not the column it indexes is the hole this test exists to find.
4. **The write gate actually refuses, from another process.** With the chain paused mid-run, open a SECOND connection (`php artisan tinker` in another terminal) and attempt a package write. It must throw `UpgradeInProgressException`. A gate that only refuses the migrating process protects nobody.
5. **The chain survives a resumed migrate.** After the mid-chain kill of (3), confirm the gate is re-established by link N (`ensureHeld()`), not silently absent because link 1 was already recorded as applied.
6. **All four link shapes backfill correctly**: mirror-only, canonical-only, both-agreeing, both-contradicting (blocked), plus the non-proforma target and the self-link. The canonical-only shape is the one that was silently mishandled — prove it.
7. **The transitional claim is TRUE, not aspirational.** Create an invoice and a line through the plain v6 path, then assert in the database: the invoice has a coherent axis and a currency; the line has a non-zero `contract_line_total` matching its `taxable_amount`. If either is zero or null, "every write path stamps the new columns" is a lie and the CHECKs will prove it in production.
8. **No behavior was rewritten.** `git diff main -- src/Services/` must show ONLY the lock, the `proforma_id` write, the `CONVERTED` transition and the `UpgradeGate::assertNotUpgrading()` calls. Tax resolution, issuance and conversion semantics are untouched — that is PR-2/PR-3. If the diff is bigger, you did too much.
9. **The enums' integer values match every migration comment and every backfill literal.** A `ProformaStatus::LEGACY = 6` in the enum and a `6` in the backfill are the same 6; grep and confirm.
10. **Every PHP snippet in this plan was checked against the real file before it was pasted.** The literals that BIND are the DDL, the index names, the `down()` strategies and the test criteria. The PHP fragments are intent: signatures, cast APIs (`FixedDecimal`), relation names and line numbers drift, and this plan has already been caught with stale ones (`Invoice::update()` throwing `\Exception`, `createInvoice()` freezing the row before the link could be written). Read the file, then write the code.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-13-larabill-v7-pr1-schema-foundations.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — execute tasks in this session using `superpowers:executing-plans`, batch execution with checkpoints.

**Which approach?**
