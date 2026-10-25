# Payment Allocations and Customer Credit (larabill 8.3.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship larabill v8.3.0 (MINOR): a received payment allocated by parts to invoices (full, partial, several), unallocated money kept as customer credit with FIFO movements, `InvoiceStatus::PARTIALLY_PAID` derived from the active allocations, and the card path (`register()`/`reverse()`) untouched in contract.

**Architecture:** `GroupedPayment` + the pivot `grouped_payment_invoice` become "payment with allocations" (the pivot gains `status`, both UNIQUEs are dropped, legacy reversed rows are backfilled). A new `credit_movements` table holds entries (SURPLUS/TOP_UP) and exits (APPLIED/REFUNDED) linked by `consumes_movement_id`; "balance" is always computed. `Invoice::recomputeCollectionState()` is the single in-package writer of `status`/`paid_at`, invoked only on the `lockForUpdate()` instance inside the operation's transaction. `PaymentService` and `CustomerCredit` own the writes; `GroupedPaymentService::register()` delegates to `receive()` with a legacy replay policy.

**Tech Stack:** PHP 8.3 (`"$HOME/Library/Application Support/Herd/bin/php83"`), Laravel 13.35 installed (`^12||^13`), Pest 4 (`mutates()`), PHPStan level 8, Pint, lara100 `FixedDecimal`/`FixedDecimalCast:2`/`AichaMoneyCast:2`, MySQL 8.4 (3384) and MariaDB 11.4 (3307) harnesses.

**Spec:** `docs/superpowers/specs/2026-10-24-aid-962-payment-allocations-customer-credit.md` (rev 10, approved 2026-10-24). Section numbers below (§) refer to it. Read it first; this plan decides files, names, signatures and tests, the spec decides behaviour.

## Global Constraints

- Branch `feat/aid-962-payment-allocations-credit` (already holds the spec commit `427c85b`). One commit per task, conventional, `Refs: AID-962`, attribution trailer of the session.
- Every command runs with `PHP83="$HOME/Library/Application Support/Herd/bin/php83"`; never the default `php` (PHP 8.4 SQLite bug; snapshot drift under 8.5).
- Money is base-100 `FixedDecimal` everywhere; the plan writes amounts as cents (`cents(12100)` = 121.00). Every monetary input is rejected when `$amount->toScale(2)->compareTo($amount) !== 0` (§4.5).
- UUID-first: user FKs via `MigrationHelper::userIdColumn($table, 'col', nullable: true)`; fixtures use `TestCase::USER_UUID_1/2/3` (SQLite) or `$this->seedUser()` (MySQL harness).
- A new migration is FIVE pieces in the same MR: `.php`, byte-identical `.php.stub` (bootstrap by copy, then `php bin/sync-migration-stubs` keeps it), `$migrationOrder` `'053'`, manifest entry (`$PHP83 bin/sync-upgrade-manifest`, `in_base: false` under `8.2.0`), pinned install count 49 → 50.
- `ShippedMigrationImmutabilityTest` pins the manifest sha: re-run `bin/sync-upgrade-manifest` after ANY later edit of the new migration.
- `tests/Contract/snapshots/Invoice.json` is regenerated ONLY with `LARABILL_PHP_BIN=php83 bin/sync-contract-snapshots`, which refuses without a non-empty `## [Unreleased]` CHANGELOG section (write the entry first, AID-601).
- Every new `src/` classlike carries exactly one class-level `@api` or `@internal` tag (`SurfaceTaxonomyTest`). Bands per §8.
- Pest file-level helpers live in the global namespace: prefix them `aid962…` or put them on a class under `tests/Support/`.
- Gated harnesses (`tests/Concurrency`, `tests/Integration/{Mysql,InstallMysql,UpgradePath}`) run locally against 3384 AND 3307 before every push (`.claude/CRITICAL_RULES.md` §A); never port 3380; never print credentials. A green run that reports skips proved nothing.
- Sensitivity proofs mutate a copy and restore from a backup file, never `git checkout`.
- The six operator decisions and the owner's proforma decision (§12) are settled; the plan implements them, it does not revisit them.

## Review Focus

Inputs the spec implies but no §9 test names; each line's test is added to the owning task.

1. **An `is_immutable = true` ISSUED invoice receiving a partial allocation:** the `updating` guard (`Invoice.php:160-162`) must let the `status`/`paid_at` write through, because they are outside `FISCAL_CONTENT_FIELDS`. Test in Task 7.
2. **`due_date` equal to today with zero paid:** the table says OVERDUE only when `due_date` is strictly before today, so today reads `SENT`. Test in Task 6.
3. **An invoice whose `companyFiscalConfig` is null:** `register()` skips the currency check when the config currency is null (`GroupedPaymentService.php:179-182`); `receive()`/`allocate()`/`apply()` must keep that parity, not reject. Test in Task 7.
4. **Replay of `receive()` after one of its allocations was reversed:** the comparison reads the direct allocations active OR reversed, so the replay returns the same payment and creates nothing. Test in Task 7.
5. **A legacy ACTIVE pivot row with `applied_at = NULL` driving a PAID recompute:** `paid_at` falls back to the payment's `paid_at`, never to null. Test in Task 6.

## File Structure

Create:

- `database/migrations/2026_10_24_000001_add_allocations_and_credit_movements.php` + `database/migrations/add_allocations_and_credit_movements.php.stub` — the one migration (§3): pivot columns, UNIQUE relaxation, backfill, `credit_movements`, `invoice_items.deducts_invoice_id`, `grouped_payments.method/recorded_by`.
- `src/Enums/PaymentMethod.php`, `src/Enums/AllocationStatus.php`, `src/Enums/CreditMovementKind.php` — `@api` int enums (§4.1).
- `src/ValueObjects/Allocation.php` — `@api` readonly request value object (§4.5).
- `src/Exceptions/AllocationExceedsPaymentException.php`, `AllocationExceedsInvoiceException.php`, `InsufficientCreditException.php`, `InvalidAllocationException.php`, `CreditMovementAlreadyLinkedException.php`, `InvalidCreditMovementException.php` — `@api` (§5.8).
- `src/Models/PaymentAllocation.php` — `@api` `Pivot` over `grouped_payment_invoice` (§4.5).
- `src/Models/CreditMovement.php` — `@api` (§4.5).
- `src/Database/Factories/CreditMovementFactory.php` — `@internal`.
- `src/Services/Payments/ReplayPolicy.php` — `@internal` enum `Legacy | Full` (§5.2).
- `src/Services/Payments/ReceiveRequest.php` — `@internal` readonly DTO: the validated input of `receive()`, owns key derivation and the request's allocation map. (Plan call: the spec says `receive()` "receives" an internal policy; a public `@api` signature cannot grow an `@internal` parameter, so the policy travels with an internal entry point that `receive()` and `register()` both call.)
- `src/Services/PaymentService.php` — `@api`: `receive()`, `allocate()`, `reverse()`, `reverseAllocation()` + `@internal` `process()`/`replayMatches()` (§5.1, §5.3, §5.5, §5.6).
- `src/Services/CustomerCredit.php` — `@api` (§5.7).
- `resources/lang/en/pdf.php`, `resources/lang/es/pdf.php` — `collection.*` strings (§6).
- `tests/Support/PaymentFixtures.php` — SQLite fixtures shared by the Feature tests.
- `tests/Feature/Payments/{ReceiveTest,ReplayPolicyTest,AllocateTest,ReverseTest,ReverseAllocationTest,CustomerCreditTest,CollectionStateTest,AnnulWithAllocationsTest,AdvanceDeductionLineTest,CollectionPdfBlockTest}.php`.
- `tests/Unit/{Enums/PaymentMethodTest,Enums/AllocationStatusTest,Enums/CreditMovementKindTest,ValueObjects/AllocationTest,Models/CreditMovementTest}.php`.
- `tests/Integration/UpgradePath/AddAllocationsAndCreditMovementsTest.php`, `AddAllocationsAndCreditMovementsDownRefusesDuplicatesTest.php` (§3.5).
- `tests/Integration/Mysql/CreditMovementsConstraintsTest.php`, `ReplayBackstopRaceTest.php` (§9 ii, §10).
- `tests/Concurrency/PaymentAllocationConcurrencyTest.php` (§10).

Modify:

- `src/Enums/InvoiceStatus.php` (+`PARTIALLY_PAID = 8`), `resources/lang/{en,es}/enums.php`.
- `src/Console/LarabillInstallCommand.php` (`'053'`), `tests/Integration/InstallMysql/InstallCommandSchemaTest.php` (49 → 50, `credit_movements` in `$expectedTables`), `tests/Contract/release-migration-manifest.json` (script).
- `src/Models/GroupedPayment.php`, `src/Models/Invoice.php`, `src/Models/InvoiceItem.php`, `src/Database/Factories/GroupedPaymentFactory.php`.
- `src/Exceptions/GroupedPaymentValidationException.php` (+`notIssued`, `notPayableAmount`, `nonPositiveAmount`, `subCentAmount`), `src/Exceptions/InvoiceNotAnnullableException.php` (+`hasActiveAllocations`).
- `src/Services/GroupedPaymentService.php` (register over `receive()`, reverse delegating), `src/Services/SettlementService.php` (`annul()` locked), `src/Services/InvoiceService.php` (`deducts_invoice_id` in both item paths; `PARTIALLY_PAID` not birth-eligible).
- `src/Services/PDF/DomPDFService.php` (+`getCollectionData()`), the six `resources/views/pdf/invoice/*.blade.php`.
- `tests/Integration/Mysql/GroupedPaymentConstraintsTest.php` (rewritten for the relaxed contract), `tests/Unit/Enums/InvoiceStatusTest.php` (9 cases), `tests/ArchTest.php` (+single-writer rule), `tests/Contract/snapshots/Invoice.json` (script).
- `CHANGELOG.md` (`## [Unreleased]`, later `[8.3.0]`), `docs/api-surface.md` (recounts).

---

### Task 1: Enums and translations

**Files:**
- Modify: `src/Enums/InvoiceStatus.php`, `resources/lang/en/enums.php`, `resources/lang/es/enums.php`, `tests/Unit/Enums/InvoiceStatusTest.php:40`
- Create: `src/Enums/PaymentMethod.php`, `src/Enums/AllocationStatus.php`, `src/Enums/CreditMovementKind.php`, `tests/Unit/Enums/PaymentMethodTest.php`, `tests/Unit/Enums/AllocationStatusTest.php`, `tests/Unit/Enums/CreditMovementKindTest.php`

**Interfaces:**
- Produces: `InvoiceStatus::PARTIALLY_PAID = 8`; `PaymentMethod: int { BankTransfer = 0; Card = 1; Cash = 2; Other = 3 }` with `label(): string`, `static toArray(): array<int, string>`; `AllocationStatus: int { ACTIVE = 0; REVERSED = 1 }`; `CreditMovementKind: int { SURPLUS = 0; TOP_UP = 1; APPLIED = 2; REFUNDED = 3 }` with `isEntry(): bool`, `isExit(): bool`, `label()`, `toArray()`.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Enums/InvoiceStatusTest.php — change the existing count assertion to 9 and add:
it('derives PARTIALLY_PAID as value 8 with labels in both locales', function () {
    expect(InvoiceStatus::PARTIALLY_PAID->value)->toBe(8)
        ->and(InvoiceStatus::PARTIALLY_PAID->canBeEdited())->toBeFalse()
        ->and(InvoiceStatus::toArray())->toHaveKey(8);
    app()->setLocale('es');
    expect(InvoiceStatus::PARTIALLY_PAID->label())->toBe('Cobrada parcialmente');
});
// tests/Unit/Enums/CreditMovementKindTest.php
it('splits kinds into entries and exits', function () {
    expect(CreditMovementKind::SURPLUS->isEntry())->toBeTrue()
        ->and(CreditMovementKind::TOP_UP->isEntry())->toBeTrue()
        ->and(CreditMovementKind::APPLIED->isExit())->toBeTrue()
        ->and(CreditMovementKind::REFUNDED->isExit())->toBeTrue()
        ->and(CreditMovementKind::APPLIED->isEntry())->toBeFalse();
});
// PaymentMethodTest / AllocationStatusTest: value pins (0..3 / 0,1) and a non-empty label per case, as GroupedPaymentStatusTest does.
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP83" vendor/bin/pest tests/Unit/Enums`
Expected: FAIL (`Undefined constant`, `Class not found`).

- [ ] **Step 3: Implement the enums and the strings**

`InvoiceStatus`: add the case, the `label()` arm (`larabill::enums.status.partially_paid`), the `toArray()` row. Strings: en `Partially paid`, es `Cobrada parcialmente`. New lang groups `payment_method`, `allocation_status`, `credit_movement_kind` in both files (en/es: `Bank transfer`/`Transferencia`, `Card`/`Tarjeta`, `Cash`/`Efectivo`, `Other`/`Otro`; `Active`/`Activa`, `Reversed`/`Revertida`; `Surplus`/`Sobrante`, `Top-up`/`Recarga`, `Applied`/`Aplicado`, `Refunded`/`Devuelto`). Each new enum carries `@api`.

- [ ] **Step 4: Run the tests and PHPStan**

Run: `"$PHP83" vendor/bin/pest tests/Unit/Enums && composer phpstan`
Expected: PASS; PHPStan green (the only `match` over `InvoiceStatus` lives in the enum).

- [ ] **Step 5: Commit**

```bash
git add src/Enums resources/lang tests/Unit/Enums
git commit -m "feat(enums): add PARTIALLY_PAID, PaymentMethod, AllocationStatus and CreditMovementKind"
```

---

### Task 2: The migration, its five pieces and the upgrade-path tests

**Files:**
- Create: `database/migrations/2026_10_24_000001_add_allocations_and_credit_movements.php`, `database/migrations/add_allocations_and_credit_movements.php.stub`, `tests/Integration/UpgradePath/AddAllocationsAndCreditMovementsTest.php`, `tests/Integration/UpgradePath/AddAllocationsAndCreditMovementsDownRefusesDuplicatesTest.php`
- Modify: `src/Console/LarabillInstallCommand.php:99` (append `'053' => 'add_allocations_and_credit_movements'`), `tests/Integration/InstallMysql/InstallCommandSchemaTest.php:92` (50) and its `$expectedTables` (+`credit_movements`), `tests/Contract/release-migration-manifest.json` (script output)

**Interfaces:**
- Produces the schema of §3.1–§3.4. Index names: `grouped_payment_invoice_payment_invoice_index` on `(grouped_payment_id, invoice_id)`, `grouped_payment_invoice_active_invoice_id_index`; the UNIQUEs dropped by their Laravel default names `grouped_payment_invoice_grouped_payment_id_invoice_id_unique` and `grouped_payment_invoice_active_invoice_id_unique`. CHECK name `larabill_money_domain_credit_movements_amount` with the same bound as `2026_10_18_000001` (`AEAT_MAX_CENTS`). Backfill writes `status = 1` (REVERSED) where `active_invoice_id IS NULL`.

- [ ] **Step 1: Write the failing upgrade-path test**

Pattern: `tests/Integration/UpgradePath/DropCustomerArticleInstanceUniqueTest.php` (base = every manifest entry except the one under test; invoice seed shape from `UpgradeFromPreviousReleaseTest.php:285-300`, ISSUED + `fiscal_submission_status` NOT_REQUIRED). Seed under the 8.2.0 schema: invoices I1 (12100), I2 (5000), I3 (5000) for one billable user; payment P1 POSTED with pivot row (I1, applied 12100, `active_invoice_id = I1`); payment P2 REVERSED (`reversed_at`, `reversed_by` = a seeded user id, `reverse_reason = 'customer dispute'`) with pivot row (I2, 5000, `active_invoice_id = NULL`); payment P3 POSTED with pivot row (I3, 5000, `active_invoice_id = NULL`) — the writer-less anomaly.

```php
it('relaxes the pivot, backfills reversed rows and creates credit_movements', function () {
    runAid962Migration();
    expect($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_grouped_payment_id_invoice_id_unique'))->toBe([])
        ->and($this->getUniqueIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_unique'))->toBe([])
        ->and($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_payment_invoice_index'))->toBe(['grouped_payment_id', 'invoice_id'])
        ->and($this->getIndexColumns('grouped_payment_invoice', 'grouped_payment_invoice_active_invoice_id_index'))->toBe(['active_invoice_id']);
    expect(DB::table('grouped_payments')->pluck('method')->unique()->all())->toBe([1]);   // Card
    $p1Row = DB::table('grouped_payment_invoice')->where('grouped_payment_id', $p1)->first();
    expect((int) $p1Row->status)->toBe(0)->and($p1Row->active_invoice_id)->toBe($i1);
    $p2Row = DB::table('grouped_payment_invoice')->where('grouped_payment_id', $p2)->first();
    expect((int) $p2Row->status)->toBe(1)
        ->and($p2Row->reversed_at)->toBe($p2ReversedAt)->and($p2Row->reversed_by)->toBe($reverser)->and($p2Row->reverse_reason)->toBe('customer dispute');
    $p3Row = DB::table('grouped_payment_invoice')->where('grouped_payment_id', $p3)->first();
    expect((int) $p3Row->status)->toBe(1)->and($p3Row->reverse_reason)->toBe('backfill-8.3')->and($p3Row->reversed_at)->toBe($p3Row->updated_at);
    expect(Schema::hasTable('credit_movements'))->toBeTrue()
        ->and(aid962CheckExists('credit_movements', 'larabill_money_domain_credit_movements_amount'))->toBeTrue()
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'grouped_payment_id'))->toBe('RESTRICT')
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'allocation_id'))->toBe('RESTRICT')
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'consumes_movement_id'))->toBe('RESTRICT')
        ->and(aid962ForeignKeyDeleteRule('credit_movements', 'advance_invoice_id'))->toBe('SET NULL')
        ->and(Schema::hasColumn('invoice_items', 'deducts_invoice_id'))->toBeTrue();
});
it('rolls back cleanly over legacy data (UNIQUEs restored)', function () { runAid962Migration(); rollbackAid962Migration(); /* both getUniqueIndexColumns non-empty, credit_movements gone */ });
```

Helpers (file-local, prefixed): `runAid962Migration()` / `rollbackAid962Migration()` (artisan `migrate` / `migrate:rollback --step=1` with `--path --realpath`), `aid962CheckExists()` (information_schema `TABLE_CONSTRAINTS`), `aid962ForeignKeyDeleteRule()` (`REFERENTIAL_CONSTRAINTS.DELETE_RULE` by `CONSTRAINT_NAME` of the column's FK from `KEY_COLUMN_USAGE`). The model-level assertions of §3.5 (`amountPaid()`, `amountOutstanding()`, `unallocatedAmount()`) are added to this file in Task 4 once the models exist.

Second file, two independent `it()`s per §3.5: after the migration, (i) two ACTIVE rows with the same `active_invoice_id` from two payments → `migrate:rollback` throws `QueryException` and both rows survive; (ii) same (payment, invoice) pair twice (one REVERSED with NULL marker, one ACTIVE) → same outcome. Each case seeds only its own duplicate.

- [ ] **Step 2: Run against MySQL 8.4 to verify they fail**

Run the harness command of `.claude/CRITICAL_RULES.md` §A (3384 variant) restricted to `tests/Integration/UpgradePath/AddAllocations*`.
Expected: FAIL (`migration not found` on the path).

- [ ] **Step 3: Write the migration `up()`**

Every DDL step guarded by `Schema::hasColumn`/`hasTable` and by index-presence probes via `information_schema.STATISTICS` (SQLite: `Schema::getIndexes()`); order per §3.2: add pivot columns (`status` tinyint default 0, `applied_at`, `reversed_at`, `reversed_by` via `userIdColumn(nullable: true)`, `reverse_reason`) → create the plain composite index → `dropUnique` pair → create the plain `active_invoice_id` index → `dropUnique('active_invoice_id')` → backfill (two query-builder UPDATEs: join on `grouped_payments` where `status = 1` copying the three audit fields; then residual `active_invoice_id IS NULL AND status = 0` → `status = 1, reversed_at = updated_at, reverse_reason = 'backfill-8.3'`) → `grouped_payments.method` default 1 + `recorded_by` → `credit_movements` per the §3.3 table (`amount` bigint; CHECK via raw `ALTER TABLE` on mysql/mariadb only, as `2026_10_18_000001` does; FKs `restrictOnDelete` ×3, `nullOnDelete` on `advance_invoice_id`; the four indexes) → `invoice_items.deducts_invoice_id` (`foreignUuid()->nullable()->constrained('invoices')->nullOnDelete()` + index). Docblock states the relaxation and the loud `down()` posture (copy the tone of `2026_10_12_000001`).

- [ ] **Step 4: Write `down()`**

Drops in reverse: `deducts_invoice_id`, `credit_movements`, `method`/`recorded_by`, the five pivot columns, then re-creates both UNIQUEs by their original names and drops the two plain indexes. No dedup; the duplicate-key `QueryException` is the contract.

- [ ] **Step 5: Run both files against 3384 and 3307**

Expected: PASS on both engines, zero skips.

- [ ] **Step 6: The other four pieces**

```bash
cp database/migrations/2026_10_24_000001_add_allocations_and_credit_movements.php database/migrations/add_allocations_and_credit_movements.php.stub
"$PHP83" bin/sync-migration-stubs
"$PHP83" bin/sync-upgrade-manifest
```

Edit `$migrationOrder` (`'053'`, comment `// === PAYMENT ALLOCATIONS + CUSTOMER CREDIT (AID-962) ===`), the install test (50, `credit_movements`). Then: `"$PHP83" vendor/bin/pest tests/Unit/Console tests/Unit/Contract` (order consistency, immutability manifest) and the InstallMysql harness on 3384.
Expected: all PASS; `git diff tests/Contract/release-migration-manifest.json` shows one added entry with `"in_base": false`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations src/Console tests/Integration tests/Contract/release-migration-manifest.json
git commit -m "feat(schema): add payment allocations, credit movements and the pivot relaxation (migration 053)"
```

---

### Task 3: Exceptions and the `Allocation` value object

**Files:**
- Create: the six exception classes listed in File Structure, `src/ValueObjects/Allocation.php`, `tests/Unit/ValueObjects/AllocationTest.php`
- Modify: `src/Exceptions/GroupedPaymentValidationException.php`, `src/Exceptions/InvoiceNotAnnullableException.php`

**Interfaces:**
- Produces: `final readonly class Allocation { public function __construct(public string $invoiceId, public FixedDecimal $amount) }` throwing `InvalidAllocationException::nonPositive(FixedDecimal)` / `::subCentAmount(FixedDecimal)`.
- `AllocationExceedsPaymentException::forPayment(string $paymentId, int $availableUnscaled, int $requestedUnscaled)`; `AllocationExceedsInvoiceException::forInvoice(string $invoiceId, int $outstandingUnscaled, int $requestedUnscaled)`; `InsufficientCreditException::forBalance(string $billableUserId, string $currency, int $balanceUnscaled, int $requestedUnscaled)` and `::entryHasActiveExits(string $paymentId, string $movementId)`; `CreditMovementAlreadyLinkedException::forMovement(string $movementId, string $advanceInvoiceId)`; `InvalidCreditMovementException::notAnEntry(string $movementId)`; `GroupedPaymentValidationException::notIssued(string $invoiceId)`, `::notPayableAmount(string $invoiceId, int $totalUnscaled)`, `::nonPositiveAmount(int $unscaled)`, `::subCentAmount(string $amount)`, `::creditKindNotAnEntry(CreditMovementKind)`; `InvoiceNotAnnullableException::hasActiveAllocations(Invoice $invoice, int $count)`.
- All `@api`, `Exception`/`RuntimeException` bases as their siblings, messages carry base-100 integers.

- [ ] **Step 1: Write the failing tests**

```php
it('rejects a non-positive amount', fn () => new Allocation('x', cents(0)))->throws(InvalidAllocationException::class);
it('rejects a sub-cent amount', fn () => new Allocation('x', FixedDecimal::ofUnscaled(5, 3)))->throws(InvalidAllocationException::class, 'sub-cent');
it('accepts an exact cent amount', fn () => expect((new Allocation('x', cents(5000)))->amount->unscaledValue())->toBe(5000));
```

- [ ] **Step 2: Run, expect FAIL (class not found)**
- [ ] **Step 3: Implement the value object and the exceptions** (constructor property promotion; exactness test `$amount->toScale(2)->compareTo($amount) !== 0`, §4.5).
- [ ] **Step 4: Run `tests/Unit/ValueObjects` + `tests/Unit/Contract/SurfaceTaxonomyTest.php`, expect PASS**
- [ ] **Step 5: Commit** `feat(payments): add allocation value object and payment exceptions`

---

### Task 4: Models — `PaymentAllocation`, `CreditMovement`, `GroupedPayment`, `Invoice`, `InvoiceItem`, factories, snapshot

**Files:**
- Create: `src/Models/PaymentAllocation.php`, `src/Models/CreditMovement.php`, `src/Database/Factories/CreditMovementFactory.php`, `tests/Unit/Models/CreditMovementTest.php`, `tests/Support/PaymentFixtures.php`
- Modify: `src/Models/GroupedPayment.php`, `src/Models/Invoice.php`, `src/Models/InvoiceItem.php:127-150,154-170`, `src/Database/Factories/GroupedPaymentFactory.php`, `CHANGELOG.md` (new `## [Unreleased]` section), `tests/Contract/snapshots/Invoice.json` (script), `tests/Integration/UpgradePath/AddAllocationsAndCreditMovementsTest.php` (model-level assertions)

**Interfaces:**
- `PaymentAllocation extends Illuminate\Database\Eloquent\Relations\Pivot`: `$table = 'grouped_payment_invoice'`, `$incrementing = true`, `$keyType = 'int'`; casts `applied_amount` `FixedDecimalCast:2`, `status` `AllocationStatus`, `applied_at`/`reversed_at`/`previous_paid_at` datetime; `payment(): BelongsTo<GroupedPayment>`, `invoice(): BelongsTo<Invoice>`, `exits(): HasMany<CreditMovement>` (by `allocation_id`), `isActive(): bool`, `scopeActive(Builder): Builder`. Docblock marks `previous_status`/`previous_paid_at`/`active_invoice_id` `@deprecated` (removal 9.0).
- `CreditMovement` (`HasUuid`, `HasFactory`): casts `amount` `AichaMoneyCast:2`, `kind` `CreditMovementKind`, `method` `PaymentMethod` nullable, `occurred_at`/`reversed_at` datetime; `payment()`, `allocation()`, `consumes(): BelongsTo<CreditMovement>`, `consumedBy(): HasMany<CreditMovement>`, `advanceInvoice(): BelongsTo<Invoice>`; `isEntry()`, `isExit()`, `isReversed()`, `remaining(): FixedDecimal` (entries only: `amount` + Σ active `consumedBy` amounts, fresh query), `scopeActive()`, `scopeEntries()`, `linkAdvanceInvoice(Invoice $advance): void` (conditional `UPDATE … WHERE id = ? AND advance_invoice_id IS NULL AND kind IN (0,1)`; affected 0 → re-read: exit → `InvalidCreditMovementException::notAnEntry`, else `CreditMovementAlreadyLinkedException`; then `refresh()`).
- `GroupedPayment`: fillable + casts `method` (`PaymentMethod`), `recorded_by`; `allocations(): HasMany<PaymentAllocation>`; `creditMovements(): HasMany<CreditMovement>`; `unallocatedAmount(): FixedDecimal`; `availableCredit(): FixedDecimal`; `invoices()` adds the five new pivot columns to `withPivot` and its docblock states "one row per allocation" (§4.5).
- `Invoice`: `allocations(): HasMany<PaymentAllocation>`; `amountPaid(): FixedDecimal` (fresh `DB::table` SUM over `status = 0`); `amountOutstanding(): FixedDecimal`; `isPartiallyPaid(): bool`; `recomputeCollectionState(): void` `@internal` (Task 6 fills the body; here a stub that throws `LogicException('not implemented')` is NOT acceptable — implement the §4.4 table and guards here, Task 6 only tests it); `@deprecated` on the two legacy writers.
- `InvoiceItem`: `deducts_invoice_id` fillable; `deductsInvoice(): BelongsTo<Invoice>`.
- `PaymentFixtures::sentInvoice(int $totalCents, string $billable = TestCase::USER_UUID_2, array $overrides = []): Invoice` (pinned `is_immutable => false`, `paid_at => null`, `due_date => null`, `user_id => USER_UUID_1`), `::user()`.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Models/CreditMovementTest.php
it('computes remaining as amount plus active exits', function () { /* entry 2900, exits -1500 and -1400 (one reversed) → remaining 1500 */ });
it('links the advance invoice once and refuses a second link', function () {
    $entry = CreditMovement::factory()->create(['kind' => CreditMovementKind::SURPLUS, 'advance_invoice_id' => null]);
    $twin  = CreditMovement::find($entry->id);          // loaded before the first link
    $entry->linkAdvanceInvoice($advanceA);
    expect(fn () => $twin->linkAdvanceInvoice($advanceB))->toThrow(CreditMovementAlreadyLinkedException::class)
        ->and($entry->fresh()->advance_invoice_id)->toBe($advanceA->id);
});
it('refuses to link an exit', fn () => /* APPLIED row → InvalidCreditMovementException */);
// Invoice sums: two ACTIVE rows 5000 + 2100 and one REVERSED 4000 on a 12100 invoice → amountPaid 7100, amountOutstanding 5000, isPartiallyPaid true (rows inserted raw via DB::table with status values).
// GroupedPayment: amount 10000, active allocations 6000 → unallocatedAmount 4000; entries 4000 with exits −1000 → availableCredit 3000.
```

- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement the models and factories** (`CreditMovementFactory` defaults: SURPLUS, positive amount 2900, `occurred_at` fixed `2026-10-01 10:00:00`, `billable_user_id` ordered uuid, `grouped_payment_id` from `GroupedPayment::factory()`; `GroupedPaymentFactory` gains `'method' => PaymentMethod::Card->value, 'recorded_by' => null`, deterministic).
- [ ] **Step 4: Implement `Invoice::recomputeCollectionState()`** per §4.4: guards (`! isIssued()` → return; status in DRAFT/CANCELLED/CONVERTED/CONVERTING → return; no pivot rows at all → return), then the table with `paid = amountPaid()`, `due_date` strictly before `today()` for OVERDUE, PAID `paid_at` = max over active rows of `COALESCE(applied_at, grouped_payments.paid_at)`; writes `status`/`paid_at` on `$this` and `save()`.
- [ ] **Step 5: CHANGELOG `## [Unreleased]` skeleton and snapshot regen**

Add `## [Unreleased]` above `## [8.2.0]` with the first bullet of the final entry (the `Invoice` surface: `allocations()`, `amountPaid()`, `amountOutstanding()`, `isPartiallyPaid()`, the `@deprecated` writers). Then `LARABILL_PHP_BIN=php83 bin/sync-contract-snapshots`; inspect `git diff tests/Contract/snapshots/Invoice.json` (only additions).
- [ ] **Step 6: Extend the upgrade test with the model assertions of §3.5** (P1: `amountPaid() = 12100`, `unallocatedAmount() = 0`; P2's and P3's invoices: `amountPaid() = 0`, `amountOutstanding() = total`). Run on 3384.
- [ ] **Step 7: Run `pest --parallel` and PHPStan, expect green**
- [ ] **Step 8: Commit** `feat(models): payment allocations, credit movements and invoice collection state`

---

### Task 5: Birth guard and locked `annul()`

**Files:**
- Modify: `src/Services/InvoiceService.php:755-765`, `src/Services/SettlementService.php:166-174` (+`use Illuminate\Support\Facades\DB`)
- Create: `tests/Feature/Payments/AnnulWithAllocationsTest.php`

**Interfaces:**
- `assertStatusIsBirthEligible()` rejects `PARTIALLY_PAID` with `InvalidInvoiceStatusException::forbiddenBirthState`.
- `SettlementService::annul(Invoice $original): Invoice` runs in `DB::transaction`, re-reads `Invoice::whereKey($original->getKey())->lockForUpdate()->firstOrFail()`, calls `assertAnnullable($locked)` (which now also throws `hasActiveAllocations` when `$locked->allocations()->active()->exists()`), writes CANCELLED on `$locked`, returns `$locked`.

- [ ] **Step 1: Write the failing tests**

```php
it('rejects PARTIALLY_PAID as a birth status', fn () => app(InvoiceService::class)->createInvoice([... 'status' => 'partially_paid' ...]))->throws(InvalidInvoiceStatusException::class);
it('refuses to annul an invoice with an active allocation and accepts it once reversed', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);
    $payment = app(PaymentService::class)->receive(...one allocation 5000...);   // available from Task 7 — until then seed the pivot row raw
    expect(fn () => app(SettlementService::class)->annul($invoice))->toThrow(InvoiceNotAnnullableException::class);
    DB::table('grouped_payment_invoice')->update(['status' => 1, 'active_invoice_id' => null]);
    expect(app(SettlementService::class)->annul($invoice->fresh())->status)->toBe(InvoiceStatus::CANCELLED);
});
```

The fork proof `annul()` vs `receive()` belongs to Task 11 (scenario f).

- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement** (seed the pivot row raw in this task's test; swap to `receive()` in Task 7).
- [ ] **Step 4: Run `tests/Feature/Payments/AnnulWithAllocationsTest.php` and the existing settlement tests (`"$PHP83" vendor/bin/pest --filter=Settlement`), expect PASS**
- [ ] **Step 5: Commit** `fix(settlement): annul() locks the invoice and refuses active allocations`

---

### Task 6: Collection-state dataset

**Files:**
- Create: `tests/Feature/Payments/CollectionStateTest.php`
- Modify (only if a case fails): `src/Models/Invoice.php` recompute body

**Interfaces:**
- Consumes `Invoice::recomputeCollectionState()` from Task 4.

- [ ] **Step 1: Write the dataset test** — rows seeded raw in `grouped_payment_invoice` (with a `GroupedPayment::factory()` parent), then `$invoice->recomputeCollectionState()`, then assert `fresh()->status` and `paid_at`:

| case | total | rows (status, applied, applied_at) | due_date | expected |
|---|---|---|---|---|
| paid zero, no due date | 12100 | one REVERSED 12100 | null | SENT, null |
| paid zero, overdue | 12100 | one REVERSED 12100 | yesterday | OVERDUE, null |
| paid zero, due today (Review Focus 2) | 12100 | one REVERSED 12100 | today | SENT, null |
| partial | 12100 | ACTIVE 5000 | yesterday | PARTIALLY_PAID, null |
| completing | 12100 | ACTIVE 5000 @10:00, ACTIVE 7100 @12:00 | any | PAID, 12:00 |
| legacy row without applied_at (Review Focus 5) | 12100 | ACTIVE 12100, applied_at null, payment.paid_at 09:00 | any | PAID, 09:00 |
| PREPARED document | 12100 | ACTIVE 5000 | null | unchanged (no-op) |
| CANCELLED / CONVERTED / CONVERTING | 12100 | ACTIVE 5000 | null | unchanged |
| PAID with zero rows (third guard) | 12100 | none | null | stays PAID |
| PENDING reversed back | 12100 | one REVERSED 12100 | null | SENT (not PENDING) |

- [ ] **Step 2: Run, fix the recompute until all rows pass**
- [ ] **Step 3: Sensitivity:** comment out the third guard in a copy → the "PAID with zero rows" row must go red; restore from the backup copy.
- [ ] **Step 4: Commit** `test(payments): pin the collection-state recompute table and its guards`

---

### Task 7: `PaymentService::receive()`, the replay policy and `register()` over it

**Files:**
- Create: `src/Services/Payments/ReplayPolicy.php`, `src/Services/Payments/ReceiveRequest.php`, `src/Services/PaymentService.php`, `tests/Feature/Payments/ReceiveTest.php`, `tests/Feature/Payments/ReplayPolicyTest.php`
- Modify: `src/Services/GroupedPaymentService.php:26-97,211-223`

**Interfaces:**
- `enum ReplayPolicy { case Legacy; case Full; }` `@internal`.
- `final readonly class ReceiveRequest` `@internal`: `__construct(string $billableUserId, FixedDecimal $amount, string $currency, CarbonImmutable $receivedAt, PaymentMethod $method, ?string $reference, ?string $recordedBy, array $allocations /* list<Allocation> */, CreditMovementKind $creditKind, string $idempotencyKey)`; `static fromReceive(...)` validates (positive, cent-exact, `creditKind->isEntry()`, no duplicate invoice ids, Σ ≤ amount) and derives the key when null: `sha256(billable|currency|amountUnscaled|receivedAt ISO-8601 UTC|method->value|reference ?? ''|creditKind->value|invoiceId:amountUnscaled,… sorted by invoiceId)`; `allocationMap(): array<string, int>`; `invoiceIds(): list<string>` sorted.
- `PaymentService` `@api`: `receive(string $billableUserId, FixedDecimal $amount, string $currency, DateTimeInterface $receivedAt, PaymentMethod $method, ?string $reference, ?string $recordedBy, array $allocations, CreditMovementKind $creditKind = CreditMovementKind::SURPLUS, ?string $idempotencyKey = null): GroupedPayment` → `process(ReceiveRequest::fromReceive(...), ReplayPolicy::Full)`.
- `/** @internal */ public function process(ReceiveRequest $request, ReplayPolicy $policy): GroupedPayment` — the §5.1 transaction (pre-check with `replayMatches`, lock invoices by id, per-invoice validation list of §5.1.3 including the null-config currency parity of Review Focus 3, create payment, pivot rows with `previous_*` snapshotted from the locked instance before recompute, recompute, one entry movement for the remainder with `billable_user_id`/`currency` copied from the payment), the `QueryException` backstop re-reading with the SAME `replayMatches($raced, $request, $policy)`; returns `->load(['invoices', 'allocations', 'creditMovements'])`.
- `/** @internal */ public function replayMatches(GroupedPayment $existing, ReceiveRequest $request, ReplayPolicy $policy): bool` — Legacy: payer, currency, amount, distinct invoice ids of the DIRECT allocations (rows with no APPLIED exits, active or reversed) vs `$request->invoiceIds()`; Full: Legacy ∧ `receivedAt` to the second UTC ∧ `method` ∧ `reference` ∧ `creditKind` only when the payment has an entry movement ∧ direct allocation map == `$request->allocationMap()`.
- `GroupedPaymentService::register()` keeps its signature and `deriveIdempotencyKey()`; body: pre-check the key exactly as today but via `app(PaymentService::class)->replayMatches($existing, $legacyRequest, ReplayPolicy::Legacy)`; `assertEligible()` runs unchanged (so `amountMismatch`, `emptyInvoiceList`, `PARTIALLY_PAID → notPayableStatus`, `alreadyActivelyPaid` keep firing before any write); creation delegates to `process($legacyRequest, ReplayPolicy::Legacy)` where `$legacyRequest` = one full allocation per invoice, `method = Card`, `recordedBy = null`, `receivedAt = paidAt`, the legacy key. Remove `payloadMatches()`.

- [ ] **Step 1: Write the failing feature tests** (`ReceiveTest.php`; fixtures via `PaymentFixtures`; the eight-flow rows 1–5 and 8 live here):

```php
it('flow 1: one transfer pays one invoice', function () {
    $inv = PaymentFixtures::sentInvoice(12100);
    $p = app(PaymentService::class)->receive(TestCase::USER_UUID_2, cents(12100), 'EUR', $at = now()->subDay(), PaymentMethod::BankTransfer, 'TRF-1', TestCase::USER_UUID_1, [new Allocation($inv->id, cents(12100))]);
    expect($inv->fresh()->status)->toBe(InvoiceStatus::PAID)->and($inv->fresh()->paid_at->equalTo($at))->toBeTrue()
        ->and($p->creditMovements)->toHaveCount(0)->and($p->unallocatedAmount()->unscaledValue())->toBe(0)
        ->and($p->allocations->first()->previous_status)->toBe(InvoiceStatus::SENT->value);
});
it('flow 2: one transfer pays two invoices in one transaction', ...);          // both PAID; atomicity pinned by a sibling case: a CANCELLED second invoice leaves the first untouched and creates no payment
it('flow 3: a partial payment leaves PARTIALLY_PAID with the outstanding', ...); // 5000 on 12100 → amountPaid 5000, amountOutstanding 7100, paid_at null
it('flow 4: an overpayment leaves a SURPLUS entry', ...);                       // 15000 on 12100 → one SURPLUS 2900, occurred_at = receivedAt, billable/currency copied
it('flow 5: a payment with no allocations is a TOP_UP', ...);                   // creditKind TOP_UP 30000 → one TOP_UP; register() with [] still throws emptyInvoiceList
it('rejects: foreign invoice, not issued (PREPARED), proforma, DRAFT, CANCELLED, total <= 0, currency mismatch, sum > amount, allocation > outstanding, sub-cent amount, non-positive amount, exit creditKind, duplicate ids', ...)->with(dataset);
it('accepts an invoice whose fiscal config is null (currency parity with register)', ...);   // Review Focus 3
it('writes collection state on an is_immutable invoice', ...);                                // Review Focus 1
it('replays an explicit key exactly → same payment', ...);
it('rejects a replay that differs in one field', ...)->with(['amount per invoice', 'method', 'receivedAt', 'reference', 'creditKind on a payment with remainder']);  // each → IdempotencyConflictException forKey
it('treats a different creditKind as the same payload when there was no remainder', ...);
it('replays after one allocation was reversed → same payment, nothing created', ...);        // Review Focus 4 (needs reverseAllocation from Task 8: mark REVERSED raw here)
it('throws keySpentByReversal on a reversed key', ...);
it('flow 8: register() records method Card and replays its legacy key with another paidAt/reference', ...);
it('register() rejects a PARTIALLY_PAID invoice with notPayableStatus', ...);
```

`ReplayPolicyTest.php` — unit over `replayMatches()` with a persisted payment: for each policy × each distinguishing field (date, reference, method, creditKind, per-invoice amount) assert Legacy → true, Full → false; identical request → true in both.

- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement `ReplayPolicy`, `ReceiveRequest`, `PaymentService::receive()/process()/replayMatches()`**
- [ ] **Step 4: Rewrite `register()` over `process()`; delete `payloadMatches()`**
- [ ] **Step 5: Run the new files AND `tests/Feature/GroupedPayment` unchanged** — `"$PHP83" vendor/bin/pest tests/Feature/Payments tests/Feature/GroupedPayment`. Expected: PASS with `git diff --stat tests/Feature/GroupedPayment` empty.
- [ ] **Step 6: `composer phpstan`, expect green**
- [ ] **Step 7: Commit** `feat(payments): PaymentService::receive() with replay policies; register() delegates`

---

### Task 8: `allocate()`, `reverse()`, `reverseAllocation()`; `GroupedPaymentService::reverse()` delegates

**Files:**
- Modify: `src/Services/PaymentService.php`, `src/Services/GroupedPaymentService.php:99-147`
- Create: `tests/Feature/Payments/AllocateTest.php`, `tests/Feature/Payments/ReverseTest.php`, `tests/Feature/Payments/ReverseAllocationTest.php`

**Interfaces:**
- `allocate(GroupedPayment $payment, Allocation $allocation, ?string $recordedBy = null, ?DateTimeInterface $appliedAt = null): PaymentAllocation` (§5.3: lock payment then invoice; POSTED; same customer; payable list of §5.1.3; `≤ amountOutstanding()`; `≤ availableCredit()` else `AllocationExceedsPaymentException`; FIFO over THIS payment's entries → one APPLIED exit per entry pointing at the new allocation; `previous_*` snapshot; recompute).
- `reverse(GroupedPayment $payment, string $reason, ?string $reversedBy = null): GroupedPayment` (§5.5: lock payment, no-op if REVERSED, then lock its invoices by id; any active entry with an active exit → `InsufficientCreditException::entryHasActiveExits`; payment REVERSED with audit; allocations ACTIVE → REVERSED + `active_invoice_id = NULL` + audit; entries reversed; recompute each invoice).
- `reverseAllocation(int $allocationId, string $reason, ?string $reversedBy = null): PaymentAllocation` (§5.6 sequence: lock payment, lock invoice, no-op if REVERSED, read Σ active APPLIED exits first, flip the row, then either reverse the exits (sum = applied) or create one SURPLUS (sum = 0) with `allocation_id`, `occurred_at = payment.paid_at`; recompute).
- `GroupedPaymentService::reverse()` becomes `return app(PaymentService::class)->reverse($payment, $reason, $reversedBy);` (no `previous_*` reads).

- [ ] **Step 1: Write the failing tests**

```php
// AllocateTest
it('allocates from a payment remainder, consuming its entries FIFO', ...);       // payment 15000 → inv A 12100 (SURPLUS 2900); allocate 2900 to inv B (5000) → B PARTIALLY_PAID, one APPLIED −2900 consuming the SURPLUS, remaining 0
it('rejects more than the available credit of the payment', ...);              // AllocationExceedsPaymentException (a refund took part of the entry: remainder arithmetic 2900 vs available 1900)
it('rejects more than the invoice outstanding', ...);
it('uses appliedAt for applied_at and occurred_at', ...);
// ReverseTest (flow 7)
it('reverses allocations and entries and recomputes every touched invoice', ...); // invoice with ANOTHER live payment stays PARTIALLY_PAID, not SENT
it('refuses when an entry has an active APPLIED exit', ...);
it('refuses when an entry has an active REFUNDED exit', ...);
it('is a stable no-op on a second reverse', ...);
// ReverseAllocationTest
it('reverts a credit-funded allocation back to its entries without creating a SURPLUS', ...);  // Σ APPLIED active = 0 afterwards, entries' remaining restored, credit_movements count unchanged
it('reverts a direct allocation into one SURPLUS of the exact applied amount', ...);
it('keeps the invariant Σ active APPLIED ∈ {0, applied_amount} after every operation', ...);  // helper aid962AssertFundingInvariant() called at the end of each test above
it('sets active_invoice_id to null on the reversed row and recomputes the invoice', ...);
```

- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement the three methods; delegate `GroupedPaymentService::reverse()`**
- [ ] **Step 4: Run `tests/Feature/Payments tests/Feature/GroupedPayment` — `ReverseTest` of the card path must pass without edits**
- [ ] **Step 5: Sensitivity:** in a copy, make `reverseAllocation()` also create a SURPLUS on the credit-funded branch → the first ReverseAllocation test goes red; restore.
- [ ] **Step 6: Commit** `feat(payments): allocate, reverse and reverseAllocation with FIFO credit return`

---

### Task 9: `CustomerCredit`

**Files:**
- Create: `src/Services/CustomerCredit.php`, `tests/Feature/Payments/CustomerCreditTest.php`

**Interfaces:**
- `final class CustomerCredit` `@api`: `public static function for(string $billableUserId, string $currency): self` (bound read object); `movements(): Collection<int, CreditMovement>` (active, `occurred_at`, `id`); `balance(): FixedDecimal`; `outstanding(): FixedDecimal` (Σ `amountOutstanding()` of ISSUED non-proforma invoices of that customer whose `companyFiscalConfig.currency` is the bound currency OR null, status ∈ {SENT, OVERDUE, PENDING, PARTIALLY_PAID}; rectificatives included with sign); `position(): FixedDecimal` = balance − outstanding. The read methods throw `LogicException` when called on an unbound instance. (Plan call: the write methods below take the customer explicitly, as the spec signatures do, and never read the binding — `app(CustomerCredit::class)->apply(...)` works unbound.)
- `apply(string $billableUserId, Invoice $invoice, FixedDecimal $amount, ?string $recordedBy = null, ?DateTimeInterface $appliedAt = null): Collection<int, PaymentAllocation>` (§5.7 + §5.4 locking: select source payment ids without lock, lock ALL by id asc, lock the invoice by id, re-read remainders, FIFO across entries grouped by payment → one allocation per payment + one APPLIED exit per entry; `InsufficientCreditException::forBalance` / `AllocationExceedsInvoiceException`; validations of §5.1.3 on the locked invoice; recompute).
- `refund(string $billableUserId, FixedDecimal $amount, string $currency, DateTimeInterface $refundedAt, PaymentMethod $method, ?string $reference = null, ?string $recordedBy = null): Collection<int, CreditMovement>` (same lock-all rule; one REFUNDED exit per entry with `occurred_at = refundedAt`, `method`, `reference`, `recorded_by`).

- [ ] **Step 1: Write the failing tests**

```php
it('flow 6: applies credit FIFO across three entries of two payments', function () {
    // Entries: P1 (receivedAt day 1) TOP_UP 1500; P2 (day 2) TOP_UP 1400; P2 also holds a SURPLUS 1000 born from reverseAllocation (occurred_at = P2.paid_at, day 2, later id).
    // apply 3900 to invoice X (5000) → two allocations (P1 1500, P2 2400) and three APPLIED exits (−1500, −1400, −1000), one per entry;
    // assert the allocation per payment, the exit per entry, balance 0, X PARTIALLY_PAID with amountOutstanding 1100.
});
it('follows the real money date, not the posting order', ...);   // an older receivedAt entry posted later is consumed first
it('rejects apply above the balance and above the outstanding', ...);
it('flow 6b: refunds FIFO with one REFUNDED exit per entry and rejects when short', ...);
it('reports balance, outstanding (rectificatives with sign) and position', ...);  // SURPLUS 2900; open invoices 12100 PARTIALLY_PAID with 5000 paid and a rectificative −2000 → outstanding 5100, position −2200
it('rejects a non-positive or sub-cent amount in apply and refund', ...);
```

- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run the file + Task 8's invariant helper over every apply/refund test, expect PASS**
- [ ] **Step 5: Commit** `feat(payments): CustomerCredit balance, outstanding, apply and refund`

---

### Task 10: Arch rule, mutation scope and the MySQL constraint tests

**Files:**
- Modify: `tests/ArchTest.php`, `tests/Integration/Mysql/GroupedPaymentConstraintsTest.php`, the three `tests/Feature/Payments/*` files that own `mutates()`
- Create: `tests/Integration/Mysql/CreditMovementsConstraintsTest.php`, `tests/Integration/Mysql/ReplayBackstopRaceTest.php`

**Interfaces:**
- Arch: `arch('invoice collection state has one in-package writer')->expect('AichaDigital\Larabill\Services')->not->toUse(['markAsPaidViaGroupedPayment', 'restoreStateViaGroupedPaymentReversal'])` — if Pest's `toUse` does not see method names, implement as a `test()` that greps `src/` with `rg -n "markAsPaidViaGroupedPayment|restoreStateViaGroupedPaymentReversal" src/ --glob '!src/Models/Invoice.php'` via `Process` and asserts empty output.
- `mutates(PaymentService::class)` in `ReceiveTest.php`, `mutates(CustomerCredit::class)` in `CustomerCreditTest.php`, `mutates(Invoice::class)` is too wide: add `covers()`-style scoping by `mutates(Invoice::class)->filter('recomputeCollectionState')` if the installed Pest supports method filters; otherwise scope with `mutates(Invoice::class)` in `CollectionStateTest.php` and accept the wider surface.

- [ ] **Step 1: `CreditMovementsConstraintsTest`** — `bootstrap()`; `getMysqlColumnType('credit_movements', 'amount') === 'bigint'`; the CHECK rejects `amount = 100000000000000` (QueryException); deleting a `grouped_payments` row referenced by a movement throws (RESTRICT); deleting the advance invoice nulls `advance_invoice_id`.
- [ ] **Step 2: Rewrite `GroupedPaymentConstraintsTest`** — keep the type test; replace the two UNIQUE tests by: "two ACTIVE rows with the same `active_invoice_id` coexist" and "the same (payment, invoice) pair twice coexists"; add a docblock pointing at §5.4 (the guarantee moved to the invoice row lock).
- [ ] **Step 3: `ReplayBackstopRaceTest`** (§9 ii, §10): register a second connection `testing_second` by cloning `config('database.connections.testing')`; arm `DB::connection('testing')->listen()` with a once-flag that, when `$query->sql` contains `idempotency_key` and is a SELECT (the pre-check), inserts through `DB::connection('testing_second')` a POSTED `grouped_payments` row with the same key and a different `paid_at`/`reference` PLUS its pivot rows (full allocations, `status = 0`) and commits (autocommit). **Plan decision, deviating from the spec's wording (`GroupedPayment::creating`) with the same mechanism:** `creating` fires AFTER `lockForUpdate()` on the invoices (`GroupedPaymentService.php:55-65`), and the second connection's pivot `INSERT` needs a shared lock on the referenced `invoices` rows for its FK check, so it would block against the first transaction's exclusive locks until `innodb_lock_wait_timeout`; hooking the pre-check query keeps the duplicate committed before the lock and still forces the UNIQUE collision at the payment `INSERT`. Record this as row 39 in spec §13 in this task's commit. Assertions: via `register()` (Legacy) the returned payment id is the listener's; via `receive()` with the explicit key and another `receivedAt` (Full) → `IdempotencyConflictException` with the `forKey` message; in both runs the listener fired exactly once and exactly one `grouped_payments` row exists.
- [ ] **Step 4: Arch rule + `mutates()`**
- [ ] **Step 5: Run `tests/ArchTest.php`, the SQLite suite, then `tests/Integration/Mysql` on 3384 and 3307** — expect PASS, zero skips in the MySQL files. Then `composer test-mutate` scoped to the three files: `"$PHP83" vendor/bin/pest --mutate --parallel --min=68 tests/Feature/Payments` — record the score.
- [ ] **Step 6: Sensitivity for the race test:** make the backstop ignore the policy (always Full) in a copy → the Legacy assertion goes red; restore.
- [ ] **Step 7: Commit** `test(payments): single-writer arch rule, mutation scope and MySQL contract tests`

---

### Task 11: Fork concurrency test (scenarios a–f) with sensitivity proofs

**Files:**
- Create: `tests/Concurrency/PaymentAllocationConcurrencyTest.php`

**Interfaces:**
- Consumes `PaymentService`, `CustomerCredit`, `SettlementService::annul()`. Pattern: `tests/Concurrency/GroupedPaymentIdempotencyConcurrencyTest.php` (gate on `RUN_CONCURRENCY_IT`, `pcntl_fork`, `DB::purge('testing')` in the child, exit codes 0/2/1, `time_sleep_until()` barrier shared by all children). Child count 6 (below the `innodb_lock_wait_timeout` ceiling measured for the existing fork tests; `ad-concurrency-gate`).

- [ ] **Step 1: Write the six scenarios**

- (a) six `receive()` of 10000 on one invoice of 10000, each child with its own `reference` (`"race-{$i}"`, so the derived keys differ and nobody replays) → exactly one ACTIVE allocation, five `AllocationExceedsInvoiceException`, `amountPaid() === 10000`.
- (b) balance 2900 in two source payments (entries 1500 + 1400), six `apply()` of 2900, each child on a DIFFERENT invoice of the same customer → one winner, balance 0, never negative.
- (c) `apply()` vs `refund()` of the full balance → at most one wins.
- (d) `reverse()` vs `apply()` on the same payment → either the reversal wins and `apply()` throws `InsufficientCreditException`, or `apply()` wins and `reverse()` throws; never credit spent from a reversed payment.
- (e) six `register()` with the same legacy key, each with a distinct `paidAt` second and `reference` → exactly one payment, every child exit 0 (none `forKey`).
- (f) `annul()` vs `receive()` on the same invoice → either CANCELLED with zero allocations and the payment threw `notPayableStatus`, or PARTIALLY_PAID/PAID with the annulment throwing `hasActiveAllocations`; never both.

- [ ] **Step 2: Run on 3384 and 3307, expect PASS and no skip**
- [ ] **Step 3: Sensitivity, one scenario at a time, on copies restored from backup files:** (a) remove `lockForUpdate()` on the invoice re-read → red; (b)–(d) remove the lock-all of source payments (or read remainders before locking) → red; (f) remove the lock in `annul()` → red. Record which engine showed each red in the test docblock (lesson 2026-08-06: sensitivity is per engine).
- [ ] **Step 4: Commit** `test(concurrency): fork proofs for allocations, credit, reversal and annulment`

---

### Task 12: PDF collection block in the six templates

**Files:**
- Modify: `src/Services/PDF/DomPDFService.php:294-330` (+`getCollectionData()`), `resources/views/pdf/invoice/{fiscal,fiscal-minimal,fiscal-modern,exempt,reverse-charge,proforma}.blade.php` (block right after each `.totals-section` closing `</div>`, same nesting as the totals block in that file)
- Create: `resources/lang/en/pdf.php`, `resources/lang/es/pdf.php`, `tests/Feature/Payments/CollectionPdfBlockTest.php`

**Interfaces:**
- `protected function getCollectionData(Invoice $invoice): ?array` — returns null for proformas and when nothing is paid; otherwise `['credit_applied' => string, 'amount_paid' => string, 'amount_due' => string]` from ONE query of the active allocations with their active APPLIED exits (`credit_applied` = Σ applied_amount of rows that have at least one active APPLIED exit; `amount_paid` = the rest; `amount_due` = total − both), `toDecimalString()`.
- Template data key `'collection'`. Block:

```blade
@if($collection)
<div class="collection-section">
    <table class="totals-table">
        <tr><th colspan="2">{{ __('larabill::pdf.collection.title') }}</th></tr>
        <tr><td>{{ __('larabill::pdf.collection.credit_applied') }}:</td><td class="text-right">{{ $collection['credit_applied'] }} €</td></tr>
        <tr><td>{{ __('larabill::pdf.collection.amount_paid') }}:</td><td class="text-right">{{ $collection['amount_paid'] }} €</td></tr>
        <tr class="total-row"><td>{{ __('larabill::pdf.collection.amount_due') }}:</td><td class="text-right">{{ $collection['amount_due'] }} €</td></tr>
    </table>
</div>
@endif
```

plus a `.collection-section { margin-top: 6mm; }` rule in each template's `<style>`. Strings: en `Collection`/`Credit applied`/`Amount paid`/`Amount due`; es `Cobro`/`Saldo aplicado`/`Importe cobrado`/`Pendiente`.

- [ ] **Step 1: Write the failing tests** — per template variant (dataset of the six `TemplateInvoiceType`/fixture states used by `FiscalTemplateRenderTest`): an invoice of 12100 with a direct allocation 4000 and a credit-funded allocation 2100 (three distinct values: credit 21.00, paid 40.00, due 60.00) → `PDFService::generatePDF()` succeeds (validator green) and the PDF text (`Smalot\PdfParser\Parser`, as `InvoicePdfEndToEndTest`) contains `21.00`, `40.00`, `60.00` in that order after the collection title; an unpaid invoice → text does not contain the collection title; a proforma with raw pivot rows → no block.
- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement data + six templates + lang files**
- [ ] **Step 4: Run `tests/Unit/Services/PDF tests/Feature/PDF tests/Feature/Payments/CollectionPdfBlockTest.php`, expect PASS**
- [ ] **Step 5: Sensitivity:** swap `credit_applied` and `amount_paid` in `getCollectionData()` → the order assertion goes red; restore.
- [ ] **Step 6: Commit** `feat(pdf): collection block under the totals in the six templates`

---

### Task 13: DECISION 4 — advance-deduction line and `deducts_invoice_id`

**Files:**
- Modify: `src/Services/InvoiceService.php:1113-1162` (both `InvoiceItem::create()` arrays gain `'deducts_invoice_id' => $itemData['deducts_invoice_id'] ?? null`; the `InvoiceItemData` shape docblock gains the key)
- Create: `tests/Feature/Payments/AdvanceDeductionLineTest.php`

- [ ] **Step 1: Write the failing test** — an F1 created through `InvoiceService::createInvoice()` with one positive recalculated line (10000 + 21%) and one FROZEN negative line (`total_amount -6050`, `taxable_amount -5000`, `total_tax_amount -1050`, `taxes_applied` copied with negative amount, `deducts_invoice_id` = an issued advance invoice id): totals = 6050 taxable 5000 tax 1050; `hasRealTax()` true; `VerifactuAdapter` maps F1; `InvoiceItem::deductsInvoice` resolves; the deduction line's VAT equals the frozen one even after the article's rate changes.
- [ ] **Step 2: Run, expect FAIL (column not persisted)**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Run, expect PASS**
- [ ] **Step 5: Commit** `feat(invoices): persist deducts_invoice_id for advance-deduction lines`

---

### Task 14: CHANGELOG, api-surface, final snapshots

**Files:**
- Modify: `CHANGELOG.md` (`## [Unreleased]` complete per §11.6), `docs/api-surface.md` (Models 23 → 25, Enums 19 → 22, ValueObjects 2 → 3, Exceptions 29 → 35, the `Services/Payments` internals noted), `tests/Contract/snapshots/Invoice.json` if the surface moved since Task 4

- [ ] **Step 1: Write the `[Unreleased]` entry** with every item of §11.6: `Ships migrations: yes (AID-962)` header with the `composer update` + `larabill:install` (publishes 053) + `migrate` route and "additive except the relaxation of the two pivot UNIQUEs: no maintenance window"; Added (PaymentService, CustomerCredit, models, enums, exceptions, PDF block, `deducts_invoice_id`); Changed (`register()` rejects PARTIALLY_PAID with `notPayableStatus`; `annul()` locks and refuses active allocations; "active pivot row" no longer means "fully paid"; `invoices()` is one row per allocation); Deprecated (the two `Invoice` writers, `previous_*`/`active_invoice_id` columns, removal 9.0); Fixed (`reverse()` recomputes instead of restoring a snapshot; PENDING reverts to SENT/OVERDUE); the consumer release dependency with `clientes` (`PayableInvoices.php:66-70,98-111`); the §7 note (advance deduction is not an ADR-014 credit line; AEAT sandbox unverified).
- [ ] **Step 2: Recount `docs/api-surface.md`**
- [ ] **Step 3: `LARABILL_PHP_BIN=php83 bin/sync-contract-snapshots`; `"$PHP83" bin/sync-upgrade-manifest` (idempotent re-run; diff must be empty unless the migration changed)**
- [ ] **Step 4: Full local gates:** `composer pint && composer phpstan && "$PHP83" vendor/bin/pest --parallel`, the four gated harnesses on 3384 and 3307 (zero skips), `composer test-mutate`.
- [ ] **Step 5: Commit** `docs: changelog, api surface and contract snapshots for 8.3.0`

---

### Task 15: Delivery gates and release (process, per §11)

- [ ] `/ad-laravel-antipatterns` over the touched files.
- [ ] **Implementation adversarial gate** (`/ad-adversarial-gate`, loop with `/ad-gate-loop` if it returns findings) on the branch head; adjudication in the MR body; `Reviewed-by: Codex` on the revision commits.
- [ ] **Conformance gate** against `clientes` on the candidate commit (`/ad-conformance-gate`, `symlink: false`).
- [ ] Push the branch (owner's word first), `glab mr create` WITHOUT `--remove-source-branch`, pipeline green (11 jobs), merge by full SHA, verify `force_remove_source_branch = false` on the MR.
- [ ] Promote `[Unreleased]` → `## [8.3.0] - <date>`; `chore(release)` stamps the manifest; `LARABILL_PHP_BIN=php83 bin/tag-release v8.3.0`; `git ls-remote --tags origin refs/tags/v8.3.0` (push by hand if the `read -rp` died); GitHub mirror carries the tag; `curl -s https://repo.packagist.org/p2/aichadigital/larabill.json | jq '.packages["aichadigital/larabill"][0].version'` → `v8.3.0`.
- [ ] One line in `~/SitesLR12/clientes/docs/WORK.md` § En Progreso; Linear trace on AID-962.

## Self-review notes

- **Spec coverage:** §3 → Task 2; §4.1 → Task 1; §4.2–§4.5 → Tasks 3, 4, 8, 9; §4.4 guards and `annul()` → Tasks 4, 5, 6, 11(f); §5.1–§5.2 → Task 7; §5.3, §5.5, §5.6 → Task 8; §5.4 → Tasks 9, 11; §5.7 → Task 9; §5.8 → Task 3; §6 → Task 12; §7 → Task 13; §8 → Tasks 2, 4, 10, 14; §9 → Tasks 6–13 (each row has a named test above); §10 → Tasks 10, 11; §11 → Tasks 14, 15.
- **One deviation from the spec text, flagged:** the race test's hook point (Task 10, step 3) — same mechanism (second connection, real engine, the `catch` path), different trigger, for the FK shared-lock reason given there. It is to be recorded as §13 row 39 when applied.
- **Type consistency checked:** `Allocation(string $invoiceId, FixedDecimal $amount)`; `PaymentService::process(ReceiveRequest, ReplayPolicy)`; `replayMatches(GroupedPayment, ReceiveRequest, ReplayPolicy): bool`; `reverseAllocation(int …)`; `CustomerCredit::for()` static, write methods instance with explicit customer.
